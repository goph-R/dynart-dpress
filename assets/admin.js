/**
 * The admin's small pieces of behaviour
 *
 * Everything here is an enhancement of markup the server already rendered. No build step, no
 * framework: a `<script>` tag, and each piece finds its own elements by a `data-` attribute -
 * which is also what lets a screen that arrived as a partial be bound exactly like one that
 * arrived with the page, since inserted markup never runs a `<script>` of its own.
 */
(function (global) {
    'use strict';

    var Dpress = global.Dpress || {};
    global.Dpress = Dpress;

    /**
     * A `findItems` that asks an endpoint
     *
     * The filters go on as query parameters exactly as the filter form serialized them, so the
     * server sees the same names whether the browser or a person submitted the form.
     */
    Dpress.endpoint = function (url) {
        return function (filters, done, failed) {
            var query = new URLSearchParams();
            Object.keys(filters).forEach(function (name) {
                var value = filters[name];
                if (Array.isArray(value)) {
                    value.forEach(function (one) {
                        query.append(name, one);
                    });
                } else if (value !== '' && value !== null && value !== undefined) {
                    query.append(name, value);
                }
            });
            fetch(url + (url.indexOf('?') === -1 ? '?' : '&') + query.toString(), {
                headers: {'Accept': 'application/json'},
                credentials: 'same-origin'
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            }).then(done).catch(function (error) {
                console.error('dpress: the list could not be loaded', error);
                if (typeof failed === 'function') {
                    failed(error);
                }
            });
        };
    };

    /**
     * Sends a state change as a real POST
     *
     * Deletes and publishes are not links: a link that changes something can be followed by a
     * prefetcher, a crawler or an `<img>` on another site. The page renders one hidden form with
     * a CSRF token in it, and this points it at the action and submits it.
     */
    Dpress.post = function (url, confirmMessage, ids) {
        if (confirmMessage && !global.confirm(confirmMessage)) {
            return;
        }
        var form = document.querySelector('form[data-action-form]');
        if (!form) {
            console.error('dpress: no action form on this page');
            return;
        }
        form.querySelectorAll('[data-action-id]').forEach(function (input) {
            input.remove(); // a previous action's selection is not this one's
        });
        (ids || []).forEach(function (id) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'ids[]';
            input.value = id;
            input.setAttribute('data-action-id', '');
            form.appendChild(input);
        });
        form.action = url;
        form.submit();
    };


    /**
     * Builds a list from a configuration the server rendered
     *
     * A list screen is then a filter form, a container and one JSON object - no per-screen
     * JavaScript at all. That matters for the same reason the forms go through a factory: a
     * plugin adding a column or a row action changes data, not code.
     *
     * Column views arrive as *names* (`"dateTime"`), and row actions as `link` or `post` rather
     * than as callbacks, because none of it can be a function once it has been through JSON. A
     * screen that genuinely needs a callback still constructs `DynamicList` itself.
     */
    Dpress.list = function (container, config) {
        if (typeof container === 'string') {
            container = document.querySelector(container);
        }
        if (!container) {
            return null;
        }
        var columnViews = {};
        Object.keys(config.columns || {}).forEach(function (property) {
            var column = Object.assign({}, config.columns[property]);
            if (typeof column.view === 'string') {
                column.view = global.DynamicListColumnView[column.view] || global.DynamicListColumnView.text;
            }
            columnViews[property] = column;
        });

        var filterForm = config.filterForm
            ? (typeof config.filterForm === 'string' ? document.querySelector(config.filterForm) : config.filterForm)
            : null;

        var list = new global.DynamicList(container, {
            filterForm: filterForm,
            idProperty: config.idProperty || 'id',
            pageSize: config.pageSize || 25,
            orderBy: config.orderBy || '',
            orderDir: config.orderDir || 'asc',
            allOrderDisabled: config.allOrderDisabled || false,
            orderDisabled: config.orderDisabled || [],
            texts: config.texts || {},
            firstPage: config.firstPage || null,
            columnViews: columnViews,
            rowActions: (config.rowActions || []).map(declaredRowAction),
            groupActions: (config.groupActions || []).map(declaredGroupAction),
            findItems: config.findItems || Dpress.endpoint(config.endpoint)
        });

        if (filterForm) {
            Dpress.bindFilters(filterForm, list);
        }
        return list;
    };

    /**
     * Builds every list on a piece of the page that has not been built yet
     *
     * `<div data-list="{…}">`, rather than a `<script>` next to it, because a screen that arrived
     * as a partial was *inserted*, and inserted HTML does not run its scripts. This is called on
     * the first load and after every navigation, and the flag on the element is what keeps a
     * second call from building the same list twice.
     */
    function initLists(root) {
        root.querySelectorAll('[data-list]').forEach(function (element) {
            if (element.dataset.listBound) {
                return;
            }
            element.dataset.listBound = '1';
            try {
                Dpress.list(element, JSON.parse(element.getAttribute('data-list')));
            } catch (error) {
                console.error('dpress: the list configuration could not be read', error);
            }
        });
    }

    function declaredRowAction(declared) {
        var action = Object.assign({}, declared);
        if (declared.post) {
            action.action = function (id) {
                Dpress.post(declared.post + id, declared.confirm || null);
            };
            delete action.link;
        }
        if (declared.visibleWhen) {
            // `{"status": "draft"}` - shown only on rows where every named property matches
            action.visible = function (item) {
                return Object.keys(declared.visibleWhen).every(function (property) {
                    var expected = declared.visibleWhen[property];
                    return Array.isArray(expected)
                        ? expected.indexOf(item[property]) !== -1
                        : item[property] === expected;
                });
            };
        }
        return action;
    }

    function declaredGroupAction(declared) {
        var action = Object.assign({}, declared);
        if (declared.post) {
            action.action = function (ids) {
                Dpress.post(declared.post, declared.confirm || null, ids);
            };
        }
        return action;
    }

    /**
     * A confirm dialog on any element that asks for one
     *
     * `<button data-confirm="Delete this?">` - works on a submit button inside a real form, so
     * the protection does not depend on the script having loaded.
     */
    function initConfirms(root) {
        root.querySelectorAll('[data-confirm]').forEach(function (element) {
            if (element.dataset.confirmBound) {
                return;
            }
            element.dataset.confirmBound = '1';
            element.addEventListener('click', function (event) {
                if (!global.confirm(element.getAttribute('data-confirm'))) {
                    event.preventDefault();
                }
            });
        });
    }

    /**
     * A toolbar over a markdown textarea
     *
     * Deliberately not an editor. A markdown field whose value is anything other than exactly
     * what the author typed is a field that eventually rewrites somebody's document on save, and
     * the whole content model here is "the markdown is the truth". This wraps a selection and
     * gets out of the way; the textarea keeps working with the script disabled.
     */
    var MARKDOWN_ACTIONS = [
        {label: 'B', title: 'Bold', prefix: '**', suffix: '**'},
        {label: 'I', title: 'Italic', prefix: '_', suffix: '_'},
        {label: 'H', title: 'Heading', line: '## '},
        {label: '“”', title: 'Quote', line: '> '},
        {label: '•', title: 'List', line: '- '},
        {label: '</>', title: 'Code', prefix: '`', suffix: '`'},
        {label: '🔗', title: 'Link', prefix: '[', suffix: '](https://)'},
        {label: '—', title: 'Lead separator', block: '\n---\n'}
    ];

    function initMarkdown(root) {
        root.querySelectorAll('textarea.markdown-editor').forEach(function (textarea) {
            if (textarea.dataset.markdownBound) {
                return;
            }
            textarea.dataset.markdownBound = '1';

            var toolbar = document.createElement('div');
            toolbar.className = 'markdown-toolbar';
            MARKDOWN_ACTIONS.forEach(function (action) {
                var button = document.createElement('button');
                button.type = 'button';
                button.title = action.title;
                button.textContent = action.label;
                button.addEventListener('click', function () {
                    apply(textarea, action);
                });
                toolbar.appendChild(button);
            });
            textarea.parentNode.insertBefore(toolbar, textarea);

            // a tab in a code block should indent, not leave the field
            textarea.addEventListener('keydown', function (event) {
                if (event.key === 'Tab' && !event.shiftKey && !event.ctrlKey && !event.altKey) {
                    event.preventDefault();
                    replaceSelection(textarea, '    ', '', false);
                }
            });
        });
    }

    function apply(textarea, action) {
        if (action.block) {
            var at = textarea.selectionEnd;
            textarea.value = textarea.value.slice(0, at) + action.block + textarea.value.slice(at);
            textarea.selectionStart = textarea.selectionEnd = at + action.block.length;
            textarea.focus();
            return;
        }
        if (action.line) {
            var start = textarea.value.lastIndexOf('\n', Math.max(0, textarea.selectionStart - 1)) + 1;
            textarea.value = textarea.value.slice(0, start) + action.line + textarea.value.slice(start);
            textarea.selectionStart = textarea.selectionEnd = textarea.selectionEnd + action.line.length;
            textarea.focus();
            return;
        }
        replaceSelection(textarea, action.prefix, action.suffix, true);
    }

    function replaceSelection(textarea, prefix, suffix, keepSelection) {
        var start = textarea.selectionStart;
        var end = textarea.selectionEnd;
        var selected = textarea.value.slice(start, end);
        textarea.value = textarea.value.slice(0, start) + prefix + selected + suffix + textarea.value.slice(end);
        if (keepSelection && selected) {
            textarea.selectionStart = start + prefix.length;
            textarea.selectionEnd = end + prefix.length;
        } else {
            textarea.selectionStart = textarea.selectionEnd = start + prefix.length + selected.length;
        }
        textarea.focus();
    }

    /**
     * The media picker behind a `media` form field
     *
     * The dialog is the media list again - the same endpoint, the same rendering - so a filter
     * added to the library shows up in the picker without anybody wiring it twice.
     */
    function initMediaFields(root) {
        root.querySelectorAll('[data-media-field]').forEach(function (field) {
            if (field.dataset.mediaBound) {
                return;
            }
            field.dataset.mediaBound = '1';
            var input = field.querySelector('[data-media-input]');
            var preview = field.querySelector('[data-media-preview]');
            var clear = field.querySelector('[data-media-clear]');

            field.querySelector('[data-media-pick]').addEventListener('click', function () {
                Dpress.pickMedia(function (item) {
                    input.value = item.id;
                    preview.innerHTML = '';
                    if (item.thumbnail_url) {
                        var image = document.createElement('img');
                        image.src = item.thumbnail_url;
                        image.alt = item.alt || '';
                        preview.appendChild(image);
                    } else {
                        preview.textContent = item.file_name;
                    }
                    clear.hidden = false;
                });
            });

            clear.addEventListener('click', function () {
                input.value = '';
                preview.innerHTML = '';
                clear.hidden = true;
            });
        });
    }

    /**
     * Opens the library in a dialog and calls back with the chosen item
     */
    Dpress.pickMedia = function (chosen) {
        var endpoint = document.body.getAttribute('data-media-endpoint');
        if (!endpoint) {
            console.error('dpress: the page does not say where the media list is');
            return;
        }
        var dialog = document.createElement('dialog');
        dialog.className = 'media-picker';
        dialog.innerHTML =
            '<header><h2>Media</h2><button type="button" class="close" title="Close">&times;</button></header>' +
            '<form class="picker-filters"><input type="search" name="search" placeholder="Search…"></form>' +
            '<div class="picker-list"></div>';
        document.body.appendChild(dialog);

        var filterForm = dialog.querySelector('.picker-filters');
        filterForm.addEventListener('input', debounce(function () {
            list.applyFilters();
        }, 250));

        var list = new global.DynamicList(dialog.querySelector('.picker-list'), {
            filterForm: filterForm,
            pageSize: 12,
            columnViews: {
                thumbnail_html: {label: '', view: global.DynamicListColumnView.html, sortable: false, width: '52px'},
                file_name: {label: 'File'},
                title: {label: 'Title'},
                created_at: {label: 'Uploaded', view: global.DynamicListColumnView.dateTime}
            },
            rowActions: [{
                // the one row action that stays a word: choosing is what the dialog is open for,
                // and `icon` means markup everywhere else
                type: 'choose', title: 'Choose',
                action: function (id, item) {
                    close();
                    chosen(item);
                }
            }],
            findItems: Dpress.endpoint(endpoint)
        });

        function close() {
            dialog.close();
            dialog.remove();
        }

        dialog.querySelector('.close').addEventListener('click', close);
        dialog.addEventListener('cancel', function () {
            dialog.remove();
        });
        dialog.showModal();
    };

    function debounce(callback, wait) {
        var timer = null;
        return function () {
            var args = arguments;
            clearTimeout(timer);
            timer = setTimeout(function () {
                callback.apply(null, args);
            }, wait);
        };
    }

    /**
     * A filter form refreshes its list as it is typed in, rather than on a submit button
     */
    Dpress.bindFilters = function (form, list) {
        var refresh = debounce(function () {
            list.applyFilters();
        }, 250);
        form.addEventListener('input', refresh);
        form.addEventListener('change', function () {
            list.applyFilters();
        });
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            list.applyFilters();
        });
    };

    // --- moving between screens without leaving the page ---

    /** The parameter that asks a screen for its `<main>` element rather than the whole page */
    var PARTIAL = 'ajax';

    /**
     * Loads an admin screen into this page instead of going to it
     *
     * The chrome - the header, the navigation, the stylesheet, this script - is the same on every
     * screen, so a navigation that fetches all of it again is throwing away what the browser
     * already has. This asks the same URL for the same screen with the layout left off and puts
     * the answer where the old one was.
     *
     * **Anything unexpected is a real navigation.** An expired session redirects to the login
     * page, a deleted row answers 404, a screen a plugin serves may not be a fragment at all:
     * in every one of those cases the browser renders the URL properly, which is both the honest
     * answer and the one that cannot leave somebody looking at half a page.
     */
    /** Counts the navigations, so an answer that arrives after a later one is dropped */
    var navigation = 0;

    Dpress.navigate = function (url, push) {
        var target = withoutPartial(url);
        var main = document.querySelector('.admin-main');
        if (!main) {
            global.location.href = target;
            return;
        }
        var asked = new URL(target);
        asked.searchParams.set(PARTIAL, '1');
        var id = ++navigation;
        main.setAttribute('aria-busy', 'true');
        fetch(asked.href, {headers: {'Accept': 'text/html'}, credentials: 'same-origin'})
            .then(function (response) {
                if (!response.ok || withoutPartial(response.url) !== target) {
                    throw new Error(response.status + ' ' + response.url);
                }
                return response.text();
            })
            .then(function (html) {
                if (id !== navigation) {
                    return; // two clicks, and this is the older one: the newer answer is the page
                }
                html = html.trim();
                if (!/^<main[\s>]/i.test(html)) {
                    throw new Error('the answer was not a screen');
                }
                swap(html, target, push !== false);
            })
            .catch(function (error) {
                if (id !== navigation) {
                    return; // and a stale failure must not drag the browser off the newer screen
                }
                console.warn('dpress: ' + target + ' did not load into the page, going there', error);
                global.location.href = target;
            });
    };

    function withoutPartial(url) {
        var without = new URL(url, global.location.href);
        without.searchParams.delete(PARTIAL);
        return without.href;
    }

    function swap(html, target, push) {
        var main = document.querySelector('.admin-main');
        // `outerHTML` deliberately. It parses in *this* document, where a `<script>` in inserted
        // markup stays inert - which is the point - while an `onclick` attribute still becomes a
        // working handler. Markup parsed anywhere else, a `DOMParser` document or a `<template>`,
        // comes from a document with scripting disabled, and its inline handlers never wake up
        // even after the elements are adopted here.
        main.outerHTML = html;
        main = document.querySelector('.admin-main');
        if (!main) {
            global.location.href = target;
            return;
        }
        document.title = main.getAttribute('data-title') || document.title;
        markSection(main.getAttribute('data-section') || '');
        if (push) {
            history.pushState({dpress: true}, '', target);
        }
        global.scrollTo(0, 0);
        Dpress.init(main);
        // the page changed under somebody who may not be able to see that it did: without this
        // the focus stays on a link that no longer exists, and the next Tab starts from the top
        main.focus({preventScroll: true});
    }

    function markSection(key) {
        document.querySelectorAll('.admin-nav a[data-section]').forEach(function (link) {
            var current = key !== '' && link.getAttribute('data-section') === key;
            link.classList.toggle('current', current);
            if (current) {
                link.setAttribute('aria-current', 'page');
            } else {
                link.removeAttribute('aria-current');
            }
        });
    }

    function initNavigation() {
        if (!global.fetch || !global.history || !global.history.pushState
            || !document.querySelector('.admin-main')) {
            return; // without any one of these the admin is simply the admin it always was
        }
        // so that going back to the screen this page started on is ours to answer as well
        history.replaceState({dpress: true}, '', global.location.href);

        document.addEventListener('click', function (event) {
            var url = clickedScreen(event);
            if (url) {
                event.preventDefault();
                Dpress.navigate(url, true);
            }
        });

        global.addEventListener('popstate', function (event) {
            // an entry this never pushed belongs to somebody else: let the browser have it
            if (event.state && event.state.dpress) {
                Dpress.navigate(global.location.href, false);
            }
        });
    }

    /**
     * The admin screen a click is asking for, or nothing when the browser should handle it
     */
    function clickedScreen(event) {
        if (event.defaultPrevented || event.button !== 0
            || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return null; // a new tab, a new window, or a `data-confirm` that has already said no
        }
        var link = event.target.closest ? event.target.closest('a[href]') : null;
        if (!link || link.target || link.hasAttribute('download') || link.hasAttribute('data-full-load')) {
            return null;
        }
        var url = new URL(link.getAttribute('href'), global.location.href);
        if (url.origin !== global.location.origin || url.hash || !isAdminUrl(url)) {
            return null; // another site, another part of this one, or a place on this page
        }
        return url.href;
    }

    /**
     * Does this URL name a screen of this admin?
     *
     * There are two ways of writing the same thing. With rewriting on the route is the path; with
     * it off - which is the framework's default - every screen there is shares `index.php` and
     * the route travels in a parameter. The server says which, because only it knows.
     *
     * Being wrong either way costs a partial load and nothing more: a link that fails this is
     * followed the ordinary way, and one that passes it but answers with something other than a
     * screen falls back to exactly that.
     */
    function isAdminUrl(url) {
        var declared = document.body.getAttribute('data-admin-url');
        if (!declared) {
            return false;
        }
        var admin = new URL(declared, global.location.href);
        var param = document.body.getAttribute('data-route-param');
        if (param) {
            return url.pathname === admin.pathname
                && under(url.searchParams.get(param) || '', admin.searchParams.get(param) || '');
        }
        return under(url.pathname, admin.pathname);
    }

    function under(path, base) {
        return base !== '' && (path === base || path.indexOf(base + '/') === 0);
    }

    Dpress.init = function (root) {
        root = root || document;
        initConfirms(root);
        initMarkdown(root);
        initMediaFields(root);
        initLists(root);
    };

    document.addEventListener('DOMContentLoaded', function () {
        if (global.DynamicListColumnView) {
            global.DynamicListColumnView.locale = document.documentElement.lang || 'en';
        }
        Dpress.init(document);
        initNavigation();
    });

}(window));
