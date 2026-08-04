/**
 * A list that renders itself
 *
 * Modelled on dynart-micro-js/dynamic-list.js, rewritten for dpress: no jQuery, no build step,
 * and no globals from the surrounding application. The option names are deliberately the same,
 * so the two are the same idea in two dialects.
 *
 * The server renders the *page* - the filter form, the buttons, the permissions that decide
 * which of them exist. This renders the rows, and asks for them again whenever a filter, a sort
 * or a page changes.
 *
 *   new DynamicList(document.querySelector('#list'), {
 *       findItems: function (filters, done) { ... done({items: [...], total: 42}) },
 *       columnViews: {
 *           title:  {label: 'Title', view: DynamicListColumnView.link},
 *           status: {label: 'Status'},
 *           created_at: {label: 'Created', view: DynamicListColumnView.dateTime}
 *       },
 *       rowActions: [{type: 'edit', title: 'Edit', link: '/admin/content/edit/'}]
 *   })
 *
 * The one deliberate difference from the original: **a column view escapes by default.** A post
 * title is whatever somebody typed, and `text` returning it raw would put an editor's markup
 * into every other editor's browser. `DynamicListColumnView.html` is the opt out, and it is
 * spelled out at the call site.
 */
(function (global) {
    'use strict';

    function escapeHtml(value) {
        if (value === null || value === undefined) {
            return '';
        }
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function pad(number) {
        return String(number).padStart(2, '0');
    }

    /**
     * How a cell turns a value into HTML
     *
     * Every one takes `(item, property, options)` and returns an HTML string. Anything that puts
     * a value into the output escapes it; the ones that build markup escape the parts.
     */
    var ColumnViews = {

        /** The locale the date and number views format for. Set it once, from the page. */
        locale: 'en',

        /** Escaped, which is why this is the default */
        text: function (item, property) {
            return escapeHtml(item[property]);
        },

        /** Raw. Only for a value the server built on purpose. */
        html: function (item, property) {
            return item[property] === null || item[property] === undefined ? '' : String(item[property]);
        },

        nonBreakingText: function (item, property) {
            return escapeHtml(item[property]).replace(/ /g, '&nbsp;');
        },

        check: function (item, property) {
            return item[property] ? '<span class="yes">&check;</span>' : '<span class="no">&times;</span>';
        },

        /**
         * A link to the item, from `options.link` plus the id, or from `options.hrefProperty`
         */
        link: function (item, property, options) {
            options = options || {};
            var href = options.hrefProperty
                ? item[options.hrefProperty]
                : (options.link || '') + item[options.idProperty || 'id'];
            if (!href) {
                return escapeHtml(item[property]);
            }
            return '<a href="' + escapeHtml(href) + '">' + escapeHtml(item[property]) + '</a>';
        },

        dateTime: function (item, property) {
            var value = item[property];
            if (!value) {
                return '<span class="empty">–</span>';
            }
            var d = parseDate(value);
            return escapeHtml(
                d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) +
                ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes())
            );
        },

        date: function (item, property) {
            var value = item[property];
            if (!value) {
                return '<span class="empty">–</span>';
            }
            var d = parseDate(value);
            return escapeHtml(d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()));
        },

        number: function (item, property, options) {
            var value = item[property];
            if (value === null || value === undefined || value === '') {
                return '<span class="empty">–</span>';
            }
            options = options || {};
            var text = new Intl.NumberFormat(ColumnViews.locale, {
                minimumFractionDigits: options.decimals || 0,
                maximumFractionDigits: options.decimals || 0
            }).format(value);
            return escapeHtml(options.unit ? text + ' ' + options.unit : text);
        },

        /** A file size, in the units a person reads */
        bytes: function (item, property) {
            var value = Number(item[property]);
            if (!value) {
                return '<span class="empty">–</span>';
            }
            var units = ['B', 'kB', 'MB', 'GB', 'TB'];
            var i = 0;
            while (value >= 1024 && i < units.length - 1) {
                value /= 1024;
                i++;
            }
            return escapeHtml((i === 0 ? value : value.toFixed(1)) + ' ' + units[i]);
        },

        list: function (item, property) {
            var values = item[property];
            if (!values || !values.length) {
                return '<span class="empty">–</span>';
            }
            return values.map(escapeHtml).join(', ');
        },

        /**
         * A small coloured label, e.g. a status. `options.classes` maps a value to a class name.
         */
        badge: function (item, property, options) {
            var value = item[property];
            if (value === null || value === undefined || value === '') {
                return '<span class="empty">–</span>';
            }
            options = options || {};
            var label = (options.labels || {})[value] || value;
            var className = (options.classes || {})[value] || value;
            return '<span class="badge badge-' + escapeHtml(className) + '">' + escapeHtml(label) + '</span>';
        }
    };

    /**
     * A date the way the server writes it
     *
     * `2026-08-04 11:02:00` is not something every browser parses, and Safari refuses it - so the
     * space becomes a `T` before `Date` sees it. No zone is appended: the server sends UTC and
     * the admin shows what the server said, which is what an editor comparing two rows expects.
     */
    function parseDate(value) {
        if (value instanceof Date) {
            return value;
        }
        return new Date(String(value).replace(' ', 'T'));
    }

    function element(tag, className, html) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (html !== undefined) {
            node.innerHTML = html;
        }
        return node;
    }

    /**
     * @param {Element} container Emptied and filled with the list
     * @param {Object} options
     */
    function DynamicList(container, options) {

        var that = this;
        options = options || {};

        var orderByName = options.orderByName || 'sort';
        var orderDirName = options.orderDirName || 'order';
        var offsetName = options.offsetName || 'offset';
        var maxName = options.maxName || 'max';

        var idProperty = options.idProperty || 'id';
        var columnViews = options.columnViews || {};
        var rowActions = options.rowActions || [];
        var groupActions = options.groupActions || null;
        var rowDetail = options.rowDetail || null;
        var orderDisabled = options.orderDisabled || [];
        var allOrderDisabled = options.allOrderDisabled || false;
        var pageSize = options.pageSize || 25;
        var pageRange = options.pageRange || 5;
        var findItems = options.findItems || function (filters, done) {
            done({items: [], total: 0});
        };

        var texts = Object.assign({
            noResults: 'Nothing here yet.',
            failed: 'The list could not be loaded.',
            loading: 'Loading…',
            records: 'Showing {from}–{to} of {total}',
            selected: '{count} selected',
            clearSelection: 'Clear',
            previous: 'Previous',
            next: 'Next'
        }, options.texts || {});

        /**
         * The filter form is the state
         *
         * Sort, direction, offset and page size live in it as hidden inputs next to whatever the
         * server rendered, so one `serialize` produces the whole request and a plugin adding a
         * filter field needs to tell this nothing.
         */
        var filterForm = options.filterForm || null;
        var ownFilterForm = false;
        if (!filterForm) {
            filterForm = element('form', 'dynamic-list-filters');
            filterForm.style.display = 'none';
            ownFilterForm = true;
        }
        var orderByInput = hiddenInput(orderByName, options.orderBy || '');
        var orderDirInput = hiddenInput(orderDirName, options.orderDir || 'asc');
        var offsetInput = hiddenInput(offsetName, '0');
        var maxInput = hiddenInput(maxName, String(pageSize));

        var selectedIds = [];
        var totalItems = 0;
        var currentItems = [];
        var rowCheckboxes = [];
        var loading = false;
        var requestId = 0;

        var root = element('div', 'dynamic-list');
        var groupActionBar = element('div', 'group-actions');
        var table = element('table');
        var thead = element('thead');
        var headRow = element('tr');
        var tbody = element('tbody');
        var status = element('p', 'list-status');
        var footer = element('div', 'list-footer');
        var recordsLabel = element('div', 'records');
        var paging = element('nav', 'paging');
        var headCheckbox = null;

        // --- public ---

        /**
         * Asks for the rows again with whatever the filter form now says
         */
        this.refresh = function (done) {
            var filters = serialize();
            var id = ++requestId;
            setLoading(true);
            findItems(filters, function (result) {
                if (id !== requestId) {
                    return; // a newer request has already been sent, this answer is stale
                }
                setLoading(false);
                render(result || {items: [], total: 0});
                if (typeof done === 'function') {
                    done(result);
                }
            }, function () {
                if (id !== requestId) {
                    return;
                }
                setLoading(false);
                renderFailure();
            });
        };

        /** Back to the first page, which is what a changed filter means */
        this.applyFilters = function () {
            offsetInput.value = '0';
            that.refresh();
        };

        this.selection = function () {
            return selectedIds.slice();
        };

        this.clearSelection = function () {
            selectedIds = [];
            updateCheckboxes();
            updateFooter();
        };

        this.items = function () {
            return currentItems.slice();
        };

        this.count = function () {
            return totalItems;
        };

        this.filterForm = function () {
            return filterForm;
        };

        // Last, not first: everything above is a function *expression* assigned to `this`, so
        // none of it exists until the constructor has run past it. A list that asked for its
        // rows before that point called a method that was still undefined.
        build();
        this.refresh();

        // --- building ---

        function hiddenInput(name, value) {
            var existing = filterForm.querySelector('input[name="' + name + '"]');
            if (existing) {
                return existing;
            }
            var input = element('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            filterForm.appendChild(input);
            return input;
        }

        function build() {
            container.innerHTML = '';
            if (ownFilterForm) {
                container.appendChild(filterForm);
            }
            buildHead();
            table.appendChild(thead);
            table.appendChild(tbody);
            root.appendChild(groupActionBar);
            root.appendChild(table);
            root.appendChild(status);
            footer.appendChild(recordsLabel);
            footer.appendChild(paging);
            root.appendChild(footer);
            container.appendChild(root);

            buildGroupActions();
            updateSortIndicator();

            filterForm.addEventListener('submit', function (event) {
                event.preventDefault();
                that.applyFilters();
            });
        }

        function buildHead() {
            if (groupActions) {
                var checkboxHeader = element('th', 'checkbox-column');
                headCheckbox = element('input');
                headCheckbox.type = 'checkbox';
                headCheckbox.addEventListener('change', function () {
                    var checked = headCheckbox.checked;
                    currentItems.forEach(function (item) {
                        select(item[idProperty], checked);
                    });
                    updateCheckboxes();
                    updateFooter();
                });
                checkboxHeader.appendChild(headCheckbox);
                headRow.appendChild(checkboxHeader);
            }
            Object.keys(columnViews).forEach(function (property) {
                var column = columnViews[property];
                var header = element('th');
                header.setAttribute('data-property', property);
                if (column.align) {
                    header.style.textAlign = column.align;
                }
                if (column.width) {
                    header.style.width = column.width;
                }
                if (allOrderDisabled || orderDisabled.indexOf(property) !== -1 || column.sortable === false) {
                    header.textContent = column.label || property;
                } else {
                    var button = element('button', 'sort');
                    button.type = 'button';
                    button.textContent = column.label || property;
                    button.addEventListener('click', function () {
                        sortBy(property);
                    });
                    header.appendChild(button);
                }
                headRow.appendChild(header);
            });
            if (rowActions.length) {
                headRow.appendChild(element('th', 'actions-column'));
            }
            thead.appendChild(headRow);
        }

        function buildGroupActions() {
            if (!groupActions || !groupActions.length) {
                groupActionBar.style.display = 'none';
                return;
            }
            groupActions.forEach(function (groupAction) {
                // an externally rendered button wins, so the server can decide whether it exists
                var button = groupAction.element || element('button', 'button ' + (groupAction.type || ''));
                if (!groupAction.element) {
                    button.type = 'button';
                    button.textContent = groupAction.label || '';
                    groupActionBar.appendChild(button);
                }
                button.disabled = true;
                button.addEventListener('click', function () {
                    if (selectedIds.length) {
                        groupAction.action(selectedIds.slice(), that);
                    }
                });
                groupAction.button = button;
            });
        }

        // --- state ---

        function serialize() {
            var filters = {};
            new FormData(filterForm).forEach(function (value, key) {
                if (filters.hasOwnProperty(key)) {
                    filters[key] = [].concat(filters[key], value);
                } else {
                    filters[key] = value;
                }
            });
            return filters;
        }

        function sortBy(property) {
            if (orderByInput.value === property) {
                orderDirInput.value = orderDirInput.value === 'asc' ? 'desc' : 'asc';
            } else {
                orderByInput.value = property;
                orderDirInput.value = 'asc';
            }
            offsetInput.value = '0';
            updateSortIndicator();
            that.refresh();
        }

        function updateSortIndicator() {
            headRow.querySelectorAll('th').forEach(function (header) {
                header.classList.remove('sorted', 'asc', 'desc');
            });
            if (!orderByInput.value) {
                return;
            }
            var header = headRow.querySelector('th[data-property="' + cssEscape(orderByInput.value) + '"]');
            if (header) {
                header.classList.add('sorted', orderDirInput.value === 'desc' ? 'desc' : 'asc');
            }
        }

        function cssEscape(value) {
            return String(value).replace(/["\\]/g, '\\$&');
        }

        function currentPage() {
            return Math.floor(Number(offsetInput.value) / pageSize);
        }

        function goToPage(page) {
            offsetInput.value = String(page * pageSize);
            that.refresh();
        }

        function isSelected(id) {
            return selectedIds.indexOf(String(id)) !== -1;
        }

        function select(id, checked) {
            var value = String(id);
            var index = selectedIds.indexOf(value);
            if (checked && index === -1) {
                selectedIds.push(value);
            } else if (!checked && index !== -1) {
                selectedIds.splice(index, 1);
            }
        }

        function setLoading(value) {
            loading = value;
            root.classList.toggle('loading', value);
            if (value && !currentItems.length) {
                status.textContent = texts.loading;
                status.style.display = '';
            }
        }

        // --- rendering ---

        function render(result) {
            currentItems = result.items || [];
            totalItems = result.total === undefined ? currentItems.length : Number(result.total);
            tbody.innerHTML = '';
            rowCheckboxes = [];

            if (!currentItems.length) {
                table.style.display = 'none';
                status.className = 'list-status no-results';
                status.textContent = texts.noResults;
                status.style.display = '';
            } else {
                table.style.display = '';
                status.style.display = 'none';
                currentItems.forEach(function (item, index) {
                    addRow(item, index);
                });
            }
            renderPaging();
            updateFooter();
        }

        function renderFailure() {
            tbody.innerHTML = '';
            table.style.display = 'none';
            status.className = 'list-status failed';
            status.textContent = texts.failed;
            status.style.display = '';
            paging.innerHTML = '';
            recordsLabel.innerHTML = '';
        }

        function addRow(item, index) {
            var row = element('tr', index % 2 === 0 ? 'odd' : 'even');
            if (groupActions) {
                row.appendChild(rowCheckboxCell(item));
            }
            Object.keys(columnViews).forEach(function (property) {
                row.appendChild(cell(item, property));
            });
            if (rowActions.length) {
                row.appendChild(rowActionsCell(item));
            }
            tbody.appendChild(row);
            addRowDetail(row, item);
        }

        function cell(item, property) {
            var column = columnViews[property];
            var view = column.view || ColumnViews.text;
            var td = element('td');
            td.setAttribute('data-property', property);
            if (column.align) {
                td.style.textAlign = column.align;
            }
            if (column.label) {
                td.setAttribute('data-label', column.label); // the narrow layout shows it
            }
            td.innerHTML = view(item, property, column.options || {});
            return td;
        }

        function rowCheckboxCell(item) {
            var td = element('td', 'checkbox-column');
            var checkbox = element('input');
            checkbox.type = 'checkbox';
            checkbox.value = String(item[idProperty]);
            checkbox.checked = isSelected(item[idProperty]);
            checkbox.addEventListener('change', function () {
                select(checkbox.value, checkbox.checked);
                updateFooter();
                updateHeadCheckbox();
            });
            rowCheckboxes.push(checkbox);
            td.appendChild(checkbox);
            return td;
        }

        function rowActionsCell(item) {
            var td = element('td', 'actions-column');
            rowActions.forEach(function (rowAction) {
                if (typeof rowAction.visible === 'function' && !rowAction.visible(item)) {
                    return;
                }
                var node;
                if (rowAction.link) {
                    node = element('a');
                    node.href = rowAction.link + item[idProperty];
                } else {
                    node = element('button');
                    node.type = 'button';
                    node.addEventListener('click', function () {
                        rowAction.action(item[idProperty], item, that);
                    });
                }
                node.className = 'action ' + (rowAction.type || '');
                node.title = rowAction.title || '';
                node.innerHTML = rowAction.icon || escapeHtml(rowAction.title || '');
                if (rowAction.icon && rowAction.title) {
                    // an icon has no accessible name of its own, and a `title` is not one a
                    // screen reader can be relied on to announce
                    node.setAttribute('aria-label', rowAction.title);
                }
                td.appendChild(node);
            });
            return td;
        }

        /**
         * An opt in detail row underneath. `rowDetail(item)` returning nothing leaves that row
         * alone, so "expandable" can be a property of the item rather than of the list.
         */
        function addRowDetail(row, item) {
            if (typeof rowDetail !== 'function') {
                return;
            }
            var content = rowDetail(item);
            if (content === null || content === undefined || content === false) {
                return;
            }
            var detailRow = element('tr', 'row-detail');
            detailRow.style.display = 'none';
            var td = element('td');
            td.colSpan = headRow.children.length;
            if (typeof content === 'string') {
                td.innerHTML = content;
            } else {
                td.appendChild(content);
            }
            detailRow.appendChild(td);
            row.classList.add('expandable-row');
            row.insertBefore(element('span', 'expand-indicator'), row.firstChild.firstChild);
            row.addEventListener('click', function (event) {
                if (event.target.closest('.actions-column, .checkbox-column, a, input, button, label')) {
                    return;
                }
                var expand = !row.classList.contains('expanded');
                row.classList.toggle('expanded', expand);
                detailRow.style.display = expand ? '' : 'none';
            });
            row.after(detailRow);
        }

        function renderPaging() {
            paging.innerHTML = '';
            var totalPages = Math.ceil(totalItems / pageSize);
            if (!isFinite(totalPages) || totalPages <= 1) {
                return;
            }
            var page = currentPage();
            if (page > totalPages - 1) {
                page = 0;
                offsetInput.value = '0';
            }

            var start = 0;
            var end = totalPages;
            if (totalPages > pageRange) {
                var half = Math.floor(pageRange / 2);
                start = Math.max(0, page - half);
                end = Math.min(totalPages, start + pageRange);
                start = Math.max(0, end - pageRange);
            }

            if (page > 0) {
                paging.appendChild(pageLink(page - 1, texts.previous, 'previous'));
            }
            if (start > 0) {
                paging.appendChild(pageLink(0, '1', ''));
                if (start > 1) {
                    paging.appendChild(element('span', 'dots', '…'));
                }
            }
            for (var i = start; i < end; i++) {
                paging.appendChild(pageLink(i, String(i + 1), i === page ? 'current' : ''));
            }
            if (end < totalPages) {
                if (end < totalPages - 1) {
                    paging.appendChild(element('span', 'dots', '…'));
                }
                paging.appendChild(pageLink(totalPages - 1, String(totalPages), ''));
            }
            if (page < totalPages - 1) {
                paging.appendChild(pageLink(page + 1, texts.next, 'next'));
            }
        }

        function pageLink(page, label, className) {
            var button = element('button', ('page ' + className).trim());
            button.type = 'button';
            button.textContent = label;
            if (className === 'current') {
                button.disabled = true;
            } else {
                button.addEventListener('click', function () {
                    goToPage(page);
                });
            }
            return button;
        }

        function updateCheckboxes() {
            rowCheckboxes.forEach(function (checkbox) {
                checkbox.checked = isSelected(checkbox.value);
            });
            updateHeadCheckbox();
        }

        function updateHeadCheckbox() {
            if (!headCheckbox) {
                return;
            }
            var checked = rowCheckboxes.length > 0 && rowCheckboxes.every(function (checkbox) {
                return checkbox.checked;
            });
            headCheckbox.checked = checked;
            headCheckbox.indeterminate = !checked && rowCheckboxes.some(function (checkbox) {
                return checkbox.checked;
            });
        }

        function updateFooter() {
            recordsLabel.innerHTML = '';
            if (totalItems) {
                var from = currentPage() * pageSize + 1;
                var to = Math.min(totalItems, from + currentItems.length - 1);
                recordsLabel.appendChild(element('span', 'records-count', escapeHtml(
                    texts.records.replace('{from}', from).replace('{to}', to).replace('{total}', totalItems)
                )));
            }
            if (groupActions && selectedIds.length) {
                recordsLabel.appendChild(element('span', 'selected-items', escapeHtml(
                    texts.selected.replace('{count}', selectedIds.length)
                )));
                var clear = element('button', 'link clear-selection');
                clear.type = 'button';
                clear.textContent = texts.clearSelection;
                clear.addEventListener('click', function () {
                    that.clearSelection();
                });
                recordsLabel.appendChild(clear);
            }
            if (groupActions) {
                groupActions.forEach(function (groupAction) {
                    if (groupAction.button) {
                        groupAction.button.disabled = selectedIds.length === 0;
                    }
                });
            }
        }
    }

    global.DynamicList = DynamicList;
    global.DynamicListColumnView = ColumnViews;

}(window));
