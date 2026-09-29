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
                console.error('Dpress: the list could not be loaded', error);
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
            console.error('Dpress: no action form on this page');
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
     * The same POST, without leaving the page
     *
     * `Dpress.post()` submits a real form, which is right for a row action on a list screen: the
     * page it lands on is the list again with a notice on it. It is wrong inside an *editor*,
     * where leaving the page throws away everything typed since the last save. This sends the
     * same thing with `fetch` and hands back a promise.
     *
     * The CSRF token comes from the same hidden form, so there is one mechanism and a partial
     * navigation refreshes it for both.
     */
    Dpress.send = function (url, params) {
        var form = document.querySelector('form[data-action-form]');
        if (!form) {
            return Promise.reject(new Error('no action form on this page'));
        }
        var body = new FormData();
        new FormData(form).forEach(function (value, name) {
            body.append(name, value);   // the token, under whatever name the form gave it
        });
        Object.keys(params || {}).forEach(function (name) {
            body.append(name, params[name]);
        });
        return fetch(url, {method: 'POST', body: body, credentials: 'same-origin'})
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json().catch(function () { return {}; });
            })
            .then(function (answer) {
                Dpress.keepToken(answer);
                return answer;
            });
    };

    /**
     * Takes the token an action handed back and puts it in the hidden form
     *
     * Validating an action mints a new token and stores it in the session, so the one printed on
     * the page is spent the moment it is used. That is invisible while every action reloads the
     * page - the new page carries the new token - and fatal for two actions in a row without
     * one, which is what uploading a file and then attaching it is. The second was refused as a
     * forgery, and the message blamed the attach.
     *
     * **Every answer, including a failed one.** An upload the server rejected still validated
     * the token to get that far, so the next attempt needs the new one or the error becomes
     * permanent for as long as the page is open.
     */
    Dpress.keepToken = function (answer) {
        if (!answer || !answer.csrf) {
            return;
        }
        var input = document.querySelector('form[data-action-form] input[name$="[_csrf]"]');
        if (input) {
            input.value = answer.csrf;
        }
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
        // A list with no filters still has a page and a sort to remember, and the form is where
        // `DynamicList` keeps them - so it gets one, hidden, rather than making its own
        if (!filterForm && container.id && container.parentNode) {
            filterForm = document.createElement('form');
            filterForm.hidden = true;
            container.parentNode.insertBefore(filterForm, container);
        }

        // Where this list was left, in this tab: the filters, the sort and the page come back when
        // the screen does - after an edit, or from the navigation. The seeded first page is the
        // default one, so a list that is restored asks for its rows instead.
        // without URL rewriting the screen is the route parameter, and every screen one path
        var routeParam = (document.body && document.body.getAttribute('data-route-param')) || '';
        var screen = routeParam
            ? (new URLSearchParams(global.location.search).get(routeParam) || '/')
            : global.location.pathname;
        var stateKey = container.id ? Dpress.listStateKey(screen, container.id) : '';
        var restored = stateKey !== '' && filterForm && !Dpress.listAddressed(global.location.search, routeParam)
            && restoreListState(filterForm, stateKey, config);
        var findItems = config.findItems || Dpress.endpoint(config.endpoint);
        var retried = false;

        // declared before the constructor, because a row action built now may need to refresh
        // the list it belongs to when it is clicked long afterwards
        var list;
        list = new global.DynamicList(container, {
            filterForm: filterForm,
            idProperty: config.idProperty || 'id',
            pageSize: config.pageSize || 25,
            orderBy: config.orderBy || '',
            orderDir: config.orderDir || 'asc',
            allOrderDisabled: config.allOrderDisabled || false,
            orderDisabled: config.orderDisabled || [],
            texts: config.texts || {},
            firstPage: restored ? null : (config.firstPage || null),
            columnViews: columnViews,
            rowActions: (config.rowActions || []).map(function (declared) {
                return declaredRowAction(declared, function () { return list; });
            }),
            groupActions: (config.groupActions || []).map(declaredGroupAction),
            findItems: !stateKey ? findItems : function (filters, done, failed) {
                findItems(filters, function (result) {
                    // a remembered page that is not there any more - the rows went to the trash
                    // since - is the first page, rather than an empty table with no way back
                    var offset = filterForm.querySelector('input[name="offset"]');
                    if (!retried && offset && parseInt(offset.value, 10) > 0 && result && (result.items || []).length === 0) {
                        retried = true;
                        offset.value = '0';
                        list.refresh();
                        return;
                    }
                    retried = false;
                    saveListState(filterForm, stateKey);
                    done(result);
                }, failed);
            }
        });

        if (filterForm) {
            Dpress.bindFilters(filterForm, list);
        }
        return list;
    };

    // --- remembering where a list was ---

    /** The prefix of the keys, so the whole of it can be found and cleared */
    var LIST_STATE = 'dpress-list:';

    /** One list's key: the screen's path and the list's id, so two lists on a screen are two */
    Dpress.listStateKey = function (path, id) {
        return LIST_STATE + String(path || '') + '#' + String(id || '');
    };

    /**
     * Whether the address already says what the list shows - a sort or a filter somebody linked
     * to, which the server rendered and which wins over anything remembered
     */
    Dpress.listAddressed = function (search, routeParam) {
        var params = new URLSearchParams(search || '');
        var addressed = false;
        params.forEach(function (value, name) {
            // the partial load's flag, and the screen itself where there is no URL rewriting
            if (name !== 'ajax' && name !== routeParam) {
                addressed = true;
            }
        });
        return addressed;
    };

    function saveListState(form, key) {
        var pairs = [];
        new FormData(form).forEach(function (value, name) {
            if (typeof value === 'string') {
                pairs.push([name, value]);
            }
        });
        try {
            global.sessionStorage.setItem(key, JSON.stringify(pairs));
        } catch (error) {
            // a convenience: without storage the list simply starts at its first page
        }
    }

    /**
     * Puts a remembered state back into the form, before the list reads it
     *
     * The fields the server rendered get their values back; the list's own hidden ones - sort,
     * order, offset, page size - are created here with theirs, and `DynamicList` takes over an
     * input that is already there rather than adding a second. A state that is the one the
     * screen starts in anyway is not a restore, so that screen keeps its seeded first page.
     *
     * @return {boolean} whether anything was restored
     */
    function restoreListState(form, key, config) {
        var pairs;
        try {
            pairs = JSON.parse(global.sessionStorage.getItem(key) || 'null');
        } catch (error) {
            pairs = null;
        }
        if (!Array.isArray(pairs) || pairs.length === 0) {
            return false;
        }
        var defaults = {sort: config.orderBy || '', order: config.orderDir || 'asc', offset: '0'};
        var changed = pairs.some(function (pair) {
            if (Object.prototype.hasOwnProperty.call(defaults, pair[0])) {
                return pair[1] !== defaults[pair[0]];
            }
            if (pair[0] === 'max') {
                return false;
            }
            var field = form.elements.namedItem(pair[0]);
            var initial = field && field.type !== 'checkbox' && field.type !== 'radio' ? field.value : '';
            return pair[1] !== initial;
        });
        if (!changed) {
            return false;
        }
        var byName = {};
        pairs.forEach(function (pair) {
            (byName[pair[0]] = byName[pair[0]] || []).push(pair[1]);
        });
        var placed = {};
        Array.prototype.forEach.call(form.elements, function (field) {
            if (!field.name || !(field.name in byName)) {
                if (field.type === 'checkbox' && field.name) {
                    field.checked = false;   // not in a saved form means it was not ticked
                }
                return;
            }
            placed[field.name] = true;
            var values = byName[field.name];
            if (field.type === 'checkbox' || field.type === 'radio') {
                field.checked = values.indexOf(field.value) !== -1;
            } else if (field.multiple && field.options) {
                Array.prototype.forEach.call(field.options, function (option) {
                    option.selected = values.indexOf(option.value) !== -1;
                });
            } else {
                field.value = values[0];
            }
        });
        Object.keys(byName).filter(function (name) { return !placed[name]; }).forEach(function (name) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = byName[name][0];
            form.appendChild(input);
        });
        return true;
    }

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
                // kept on the element so anything else on the screen can refresh it - the
                // attachments panel is changed by two buttons that are not part of the list
                element.dpressList = Dpress.list(element, JSON.parse(element.getAttribute('data-list')));
            } catch (error) {
                console.error('Dpress: the list configuration could not be read', error);
            }
        });
    }

    function declaredRowAction(declared, listOf) {
        var action = Object.assign({}, declared);
        if (declared.post) {
            action.action = function (id) {
                Dpress.post(declared.post + id, declared.confirm || null);
            };
            delete action.link;
        }
        if (declared.ajax) {
            // the same change without leaving the page, for a list that lives inside an editor.
            // `params` is whatever the endpoint needs beyond the row - which of the two states a
            // hide/show pair is asking for, say. The row's own id goes as `idParam`, which is
            // `media_id` unless the action says otherwise - the attachment list came first.
            action.action = function (id) {
                if (declared.confirm && !global.confirm(declared.confirm)) {
                    return;
                }
                var sent = {};
                sent[declared.idParam || 'media_id'] = id;
                Dpress.send(declared.ajax, Object.assign(sent, declared.params || {}))
                    .then(function () {
                        var list = listOf ? listOf() : null;
                        if (list) {
                            list.refresh();
                        }
                    })
                    .catch(function (error) {
                        console.error('Dpress: the action failed', error);
                        global.alert('That did not work. Reload the page and try again.');
                    });
            };
            delete action.link;
        }
        if (declared.insert) {
            // purely client side: it writes into the field the author is typing in, and there is
            // nothing to save until they save the post
            action.action = function (id, item) {
                Dpress.insertMedia(item);
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
     * Which line of a text a position falls on, counting from 0
     *
     * Sent to the server instead of `selectionStart` itself, because that counts UTF-16 units
     * while PHP counts bytes - the two agree until the first accented letter and then quietly
     * do not, which on a Hungarian post would open the preview on the wrong page. A line number
     * means the same thing in both languages.
     */
    Dpress.lineOfCursor = function (value, position) {
        var upTo = String(value == null ? '' : value).slice(0, Math.max(0, position || 0));
        return upTo.split(/\r\n|\r|\n/).length - 1;
    };

    /**
     * Tells Preview where the cursor was, so the tab opens on the part being written
     *
     * A body written in `---` parts is served a page at a time, and a preview that always opened
     * at the top meant paging back to what you were looking at. Read on the way out rather than
     * on every keystroke: the button submits the form, so this is the last moment the cursor is
     * still where somebody left it.
     */
    function initPreviewCursor(root) {
        root.querySelectorAll('[data-preview-cursor]').forEach(function (button) {
            if (button.dataset.previewCursorBound) {
                return;
            }
            button.dataset.previewCursorBound = '1';
            button.addEventListener('click', function () {
                // `button.form` is the form the `form` attribute names, which is how this button
                // can stand in the page head and still submit the editor below it
                var form = button.form;
                var textarea = form && form.querySelector('textarea.markdown-editor');
                var field = form && form.querySelector('[data-cursor-line]');
                if (!textarea || !field) {
                    return; // no markdown box, or a form without the field: page one, as before
                }
                field.value = Dpress.lineOfCursor(textarea.value, textarea.selectionStart);
            });
        });
    }

    /**
     * The menu item editor: three fields that only make sense together
     *
     * "Points at" chooses a kind, and until now the other two ignored it - "Target" offered every
     * post, category and tag at once, and "Address" sat there under all five kinds. Filling in
     * Address while Points at still said *A post or page* is the obvious way to add an external
     * link, and what it saved was a post link with no post.
     *
     * So the kind decides what the other two are: the target list narrows to the kind chosen, and
     * a field a kind has no use for goes away. Every option value carries its own kind -
     * `content:12` - which is the same word `target_type` uses, so this is reading the server's
     * vocabulary rather than a second one that has to be kept in step.
     *
     * The server still decides. `itemProblem()` refuses the same combinations with the script off.
     */
    var TARGET_KINDS = ['content', 'category', 'tag'];

    /** Which of the two dependent fields a kind has any use for */
    Dpress.targetFieldsFor = function (kind) {
        return {target: TARGET_KINDS.indexOf(kind) !== -1, url: kind === 'url'};
    };

    /**
     * The options belonging to a kind, out of the whole list
     *
     * `(none)` is the empty value and belongs to every kind: a target is not required, and an
     * item with nothing chosen is a thing the editor has to be able to say.
     */
    Dpress.targetOptionsFor = function (kind, options) {
        var prefix = kind + ':';
        return (options || []).filter(function (option) {
            return option.value === '' || option.value.indexOf(prefix) === 0;
        });
    };

    function fieldOf(element) {
        // the wrapper the label and the error live in, so the whole row goes rather than the
        // input on its own under a label for something that is no longer there
        return (element.closest && element.closest('.form-field')) || element;
    }

    function showField(element, visible) {
        var field = fieldOf(element);
        field.hidden = !visible;
        // hidden, not removed: a kind chosen by mistake and put back has to find what was typed,
        // and a hidden input still posts - so nothing typed is thrown away by looking elsewhere
        if (field.style) {
            field.style.display = visible ? '' : 'none';
        }
    }

    function applyTargetKind(type, target, url, options) {
        var kind = type.value;
        var wanted = Dpress.targetFieldsFor(kind);
        if (target) {
            var chosen = target.value;
            var allowed = Dpress.targetOptionsFor(kind, options);
            target.innerHTML = '';
            allowed.forEach(function (option) {
                var element = document.createElement('option');
                element.value = option.value;
                element.textContent = option.text;
                target.appendChild(element);
            });
            // what was chosen is kept when the new kind still has it, and dropped when it does
            // not - leaving it selected would post a target of a kind nobody asked for
            target.value = allowed.some(function (o) { return o.value === chosen; }) ? chosen : '';
            showField(target, wanted.target);
        }
        if (url) {
            showField(url, wanted.url);
        }
    }

    function initTargetFields(root) {
        root.querySelectorAll('[data-target-type]').forEach(function (type) {
            if (type.dataset.targetBound) {
                return;
            }
            type.dataset.targetBound = '1';
            var form = type.closest ? type.closest('form') : null;
            if (!form) {
                return;
            }
            var target = form.querySelector('[data-target-id]');
            var url = form.querySelector('[data-target-url]');
            // read once, before anything is taken out of the select
            var options = target ? Array.prototype.map.call(target.options, function (option) {
                return {value: option.value, text: option.text};
            }) : [];
            type.addEventListener('change', function () {
                applyTargetKind(type, target, url, options);
            });
            applyTargetKind(type, target, url, options);
        });
    }

    /**
     * What sits around a code field - the markdown and the CSS alike: colour behind it, a bar over it
     *
     * One setup for every `textarea[data-code]` (and `textarea.markdown-editor`, the markdown
     * field's older name for the same thing), so a field is wrapped, numbered, made full size and
     * given its keys the same way whatever language it holds; only the colours and the insert
     * buttons depend on it.
     *
     * Deliberately not a formatting toolbar, since 0.67.0. The
     * formatting buttons - bold, italic, heading, quote, list, code, link, separator - are gone.
     * They wrote marks that are shorter to type than to reach for, which is the point of markdown
     * and the reason nobody used them: a whole blog was migrated through this field and only the
     * one button that is *not* a formatting mark was ever pressed. What is left is that button,
     * because writing `![alt](media#12)` means knowing an id the library has and the author
     * does not.
     *
     * The colouring is `code-backdrop.js` and changes nothing about the field: a `<pre>`
     * behind it, painted from the value, unable to write back. A field whose value is anything
     * other than exactly what the author typed eventually rewrites somebody's document on save,
     * and the content model here is "the markdown is the truth".
     */
    /** A toolbar button, because two of them differing only in their text is not two things */
    function button(label, title, className, clicked) {
        var element = document.createElement('button');
        element.type = 'button';
        element.className = className;
        element.textContent = label;
        element.title = title;
        element.setAttribute('aria-label', title);   // an emoji face is not an accessible name
        element.addEventListener('click', clicked);
        return element;
    }

    /**
     * Where the Markdown field wraps, in characters: Settings > Admin UI > Line length, which the
     * layout puts on the body - so it is there for a screen reached by a partial navigation too
     */
    function editorColumns() {
        var columns = parseInt(document.body && document.body.dataset.editorColumns, 10);
        return columns > 0 ? columns : 100;
    }

    /** Whether *Wrap text* is ticked: remembered in this browser, for every Markdown field alike */
    var WRAP_KEY = 'dpress-editor-wrap';

    function wrapRemembered(fallback) {
        try {
            var stored = global.localStorage.getItem(WRAP_KEY);
            return stored === null ? fallback : stored === '1';
        } catch (error) {
            return fallback;
        }
    }

    function rememberWrap(on) {
        try {
            global.localStorage.setItem(WRAP_KEY, on ? '1' : '0');
        } catch (error) {
            // a convenience: the box simply starts as the field says next time
        }
    }

    /**
     * Wraps the field at `editorColumns()` characters, or lets its lines run - and puts the
     * dotted line at that column either way
     *
     * **The field keeps its whole width.** A textarea wraps at the edge of its text, so the room to
     * the right of the column is made padding: the width, less the left padding (and the line
     * numbers in it), the borders, the scrollbar, and that many characters - `ch` is one character
     * of the field's own monospaced font, so the column is counted in characters, not guessed in
     * pixels. `100%` is the field's width, and the arithmetic stays the browser's: a window made
     * narrower moves nothing out of place, and one narrower than the column wraps at its edge.
     *
     * Unticked, the lines do not wrap at all and the field scrolls sideways; the dotted line
     * still shows where the column is.
     */
    function placeColumns(textarea, wrap) {
        var columns = editorColumns();
        textarea.style.paddingRight = '';
        textarea.classList.toggle('no-wrap', !wrap);
        textarea.setAttribute('wrap', wrap ? 'soft' : 'off');
        var style = global.getComputedStyle(textarea);
        var px = function (name) { return parseFloat(style[name]) || 0; };
        if (wrap) {
            var borders = px('borderLeftWidth') + px('borderRightWidth');
            var scrollbar = Math.max(0, textarea.offsetWidth - textarea.clientWidth - borders);
            textarea.style.paddingRight = 'max(' + px('paddingRight') + 'px, calc(100% - '
                + (px('paddingLeft') + borders + scrollbar) + 'px - ' + columns + 'ch))';
        }
        var backdrop = textarea.dpressHighlight && textarea.dpressHighlight.element;
        if (backdrop) {
            backdrop.classList.add('has-ruler');
            backdrop.style.setProperty('--wrap-at', 'calc(' + px('paddingLeft') + 'px + ' + columns + 'ch)');
        }
        if (textarea.dpressHighlight) {
            textarea.dpressHighlight.measure();
            textarea.dpressHighlight.repaint();
        }
    }

    /** The grammar a field is coloured with, by its `data-code` - none for a language unknown here */
    function grammarOf(language) {
        if (language === 'markdown') {
            return Dpress.markdown ? Dpress.markdown.grammar : null;
        }
        if (language === 'css') {
            return Dpress.css ? Dpress.css.grammar : null;
        }
        if (language === 'html') {
            return Dpress.html ? Dpress.html.grammar : null;
        }
        return null;
    }

    function initCodeEditors(root) {
        // A screen left while a field was full size took its field with it, and would leave the
        // page it arrives at unable to scroll
        if (!document.querySelector('.code-frame.is-full')) {
            document.documentElement.classList.remove('code-full-open');
        }
        root.querySelectorAll('textarea[data-code], textarea.markdown-editor').forEach(function (textarea) {
            if (textarea.dataset.editorBound) {
                return;
            }
            textarea.dataset.editorBound = '1';
            var language = textarea.dataset.code || 'markdown';
            textarea.classList.add('code-editor');

            var toolbar = document.createElement('div');
            toolbar.className = 'code-toolbar';

            // Both buttons write something the keyboard cannot, which is the whole test for
            // being on this bar - the formatting buttons went in 0.67.0 because typing `**` is
            // quicker than reaching for a button that types `**`. Both write markdown, so a CSS
            // field has neither.
            if (Dpress.emoji && language === 'markdown') {
                // A pictogram here, where "Insert from library" is words, and the difference is
                // the point: this button's face is a sample of what it inserts, so the drawing
                // *is* the label. A picture of a frame was standing in for one.
                toolbar.appendChild(button('🙂', 'Insert an emoji', 'code-emoji', function () {
                    Dpress.emoji.pick(function (character) {
                        replaceSelection(textarea, character, '', false);
                    });
                }));
            }

            // It picks a file from the library and writes a reference to it here. It attaches
            // nothing and needs no post id, so it works on something that has never been saved.
            if (textarea.hasAttribute('data-insert-media')) {
                toolbar.appendChild(button('Insert from library', 'Insert a file from the library',
                    'code-insert', function () {
                        Dpress.pickMedia(function (item) {
                            Dpress.insertMedia(item, textarea);
                        });
                    }));
            }

            // Preview media: what the reference the caret is in points at, over the page. Always
            // on the bar and only enabled in a reference, so the bar does not shift as the caret
            // moves; Alt+P is the same without the mouse.
            var previewButton = null;
            function previewTarget() {
                return Dpress.mediaTargetAt(textarea.value, textarea.selectionStart);
            }
            function preview() {
                var found = previewTarget();
                if (found) {
                    Dpress.previewMedia(found, textarea);
                }
            }
            if (language === 'markdown') {
                previewButton = button('Preview media', 'Preview media (Alt+P)', 'code-preview', preview);
                previewButton.disabled = true;
                toolbar.appendChild(previewButton);
                var refresh = function () {
                    var found = previewTarget();
                    previewButton.disabled = found === null;
                    previewButton.title = found === null
                        ? 'Preview media (Alt+P) - put the cursor in an image or a media#123 reference'
                        : 'Preview ' + found.target + ' (Alt+P)';
                };
                ['keyup', 'click', 'input', 'select', 'focus'].forEach(function (name) {
                    textarea.addEventListener(name, refresh);
                });
            }

            // Full size: the field over the whole window - and, under it, Save, since the form's
            // own buttons are underneath the field. At the bottom right, where Save is on every
            // other screen. It presses the form's primary button rather than submitting the form,
            // so it is exactly the Save the author would have pressed.
            var frame = null;
            var saveButton = button('Save', 'Save', 'code-full-save', function () {
                var save = textarea.form && textarea.form.querySelector('button[type=submit].primary');
                if (save) {
                    save.click();
                }
            });
            // the form's Save as it is drawn - its icon and its words - so the two are one button
            saveButton.classList.add('primary', 'with-icon');
            var formSave = textarea.form && textarea.form.querySelector('button[type=submit].primary');
            if (formSave) {
                saveButton.innerHTML = formSave.innerHTML;
            }
            var saveBar = document.createElement('div');
            saveBar.className = 'code-full-bar';
            saveBar.appendChild(saveButton);
            var fullButton = button('Full size', 'Edit in the whole window - Esc to come back',
                'code-full', function () {
                    setFull(!frame.classList.contains('is-full'));
                });
            toolbar.appendChild(fullButton);

            // Wrap text, at the bar's left: wrapped at the Line length setting, or lines that run
            // on and a field that scrolls sideways. Ticked unless the field asked not to be
            // (`'wrap' => false`) - and after that as this browser last left it.
            var wrapBox = document.createElement('input');
            wrapBox.type = 'checkbox';
            wrapBox.checked = wrapRemembered(!textarea.classList.contains('no-wrap'));
            var wrapLabel = document.createElement('label');
            wrapLabel.className = 'code-wrap';
            wrapLabel.appendChild(wrapBox);
            wrapLabel.appendChild(document.createTextNode(' Wrap text'));
            toolbar.insertBefore(wrapLabel, toolbar.firstChild);
            textarea.parentNode.insertBefore(toolbar, textarea);

            // Last, because it wraps the textarea and the toolbar belongs above the wrapper
            var grammar = grammarOf(language);
            if (Dpress.backdrop && grammar) {
                Dpress.backdrop.attach(textarea, grammar);
            }

            // One frame around the bars and the field, which is what goes full size
            var field = textarea.parentNode.classList.contains('code-field') ? textarea.parentNode : textarea;
            frame = document.createElement('div');
            frame.className = 'code-frame';
            toolbar.parentNode.insertBefore(frame, toolbar);
            frame.appendChild(toolbar);
            frame.appendChild(field);
            frame.appendChild(saveBar);

            placeColumns(textarea, wrapBox.checked);
            wrapBox.addEventListener('change', function () {
                rememberWrap(wrapBox.checked);
                placeColumns(textarea, wrapBox.checked);
                textarea.focus();
            });

            function setFull(on) {
                frame.classList.toggle('is-full', on);
                document.documentElement.classList.toggle('code-full-open', on);
                fullButton.textContent = on ? 'Exit full size' : 'Full size';
                fullButton.setAttribute('aria-pressed', on ? 'true' : 'false');
                // the field's width changed, and the scrollbar's room with it
                placeColumns(textarea, wrapBox.checked);
                textarea.focus();
            }

            textarea.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && frame.classList.contains('is-full')) {
                    event.preventDefault();
                    setFull(false);
                    return;
                }
                // Alt+P, by the key's place rather than its letter, so it is the same key on every
                // layout; AltGr is Ctrl+Alt, and is left to type what it types
                if (previewButton && event.altKey && !event.ctrlKey && !event.metaKey && event.code === 'KeyP') {
                    event.preventDefault();
                    preview();
                    return;
                }
                if (event.ctrlKey || event.altKey || event.metaKey || event.isComposing) {
                    return;
                }
                // a tab should indent, not leave the field - and Shift+Tab and Home work as
                // they do in any editor
                if (indentKeys(textarea, event)) {
                    return;
                }
                // Enter starts the next line under this one. Shift+Enter is left a plain newline,
                // for the one time the indent is not wanted.
                if (event.key === 'Enter' && !event.shiftKey) {
                    event.preventDefault();
                    var start = textarea.selectionStart;
                    var newline = Dpress.codeNewline(
                        textarea.value.slice(0, start), textarea.value.slice(textarea.selectionEnd), language
                    );
                    typeInto(textarea, newline.text);
                    textarea.selectionStart = textarea.selectionEnd = start + newline.caret;
                    return;
                }
                // Shift is left alone: shift+PageDown selects a page, and taking that away to
                // stop the window moving would be trading a real editing key for a scroll bug.
                if ((event.key === 'PageDown' || event.key === 'PageUp') && !event.shiftKey) {
                    event.preventDefault();
                    Dpress.pageField(textarea, event.key === 'PageUp');
                }
            });
        });
    }

    /**
     * Pages a code field, and never the page behind it
     *
     * The field is 540px inside a screen that scrolls, so the browser's own PageDown spends what
     * the field cannot absorb on the window - and not only at the boundary: measured in Chrome,
     * the *first* press took the field to its bottom and moved the window 127px in the same
     * keystroke, and two presses had the admin 592px down the page. `overscroll-behavior` fixes
     * the wheel and does not fix this, so the key is done here instead: prevented always, so
     * there is no remainder for the window to spend, and the scrolling is ours.
     *
     * **A page less a tenth**, because a reader who loses the line they were on has to find it
     * again. When there is nowhere left to go the caret goes to that end of the document rather
     * than the key doing nothing - a key that is dead at the bottom of a long post reads as the
     * editor having hung.
     *
     * The caret is otherwise left where it was, which is what PageDown does in anything being
     * read rather than typed into. Typing brings the view back to it, as it always did.
     */
    var PAGE_KEEP = 0.9;

    Dpress.pageField = function (textarea, up) {
        var height = textarea.clientHeight;
        var max = Math.max(0, textarea.scrollHeight - height);
        var step = Math.max(1, Math.round(height * PAGE_KEEP));
        var before = textarea.scrollTop;
        var next = Math.max(0, Math.min(max, up ? before - step : before + step));
        textarea.scrollTop = next;
        if (next === before) {
            var at = up ? 0 : (textarea.value || '').length;
            textarea.selectionStart = textarea.selectionEnd = at;
        }
        return next;
    };

    /**
     * Writes at the cursor, and says so
     *
     * Assigning `value` fires no `input` event - the browser only raises one for a person - so
     * anything watching the field goes stale the moment something here writes into it. The
     * colouring behind the field was the first such watcher and would have shown the text as it
     * was before the insert; announcing it here rather than calling the highlighter directly
     * means the next watcher needs no change at this end either.
     */
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
        notifyInput(textarea);
    }

    function notifyInput(element) {
        if (global.Event && typeof element.dispatchEvent === 'function') {
            element.dispatchEvent(new global.Event('input', {bubbles: true}));
        }
    }

    // --- the keys of a code field ---

    /**
     * What Enter should type in a code field, and where the caret goes after it
     *
     * The indent of the line the caret is on, so the next line starts under this one; one step
     * more after an opening brace; and between a pair of braces - `{|}` - the closing one goes to
     * a line of its own at the outer indent, the caret on the line between. The braces are CSS's:
     * in any other language (`markdown`) it is the indent and nothing more - a list item is not
     * continued with its bullet, which is a guess about what the next line is. Pure, so it is the
     * part a test can ask about.
     *
     * @return {{text: string, caret: number}} the caret as an offset into `text`
     */
    var CODE_INDENT = '    ';

    Dpress.codeNewline = function (before, after, language) {
        var line = before.slice(before.lastIndexOf('\n') + 1);
        var indent = /^[ \t]*/.exec(line)[0];
        var opens = (language || 'css') === 'css' && /\{\s*$/.test(line);
        var inner = opens ? indent + CODE_INDENT : indent;
        var text = '\n' + inner;
        if (opens && /^[ \t]*\}/.test(after)) {
            return {text: text + '\n' + indent, caret: text.length};
        }
        return {text: text, caret: text.length};
    };

    /**
     * Tab and Shift+Tab over whole lines: what the lines become, and where the selection goes
     *
     * The lines are every one the selection touches - except the line a selection merely *ends*
     * at the start of, which is what dragging to the left edge of the next line gives and never
     * what somebody meant to indent. An empty line is left empty rather than given four spaces
     * of nothing. Outdenting takes up to one step of spaces, or one tab, and never more than a
     * line has.
     *
     * Answers the range to replace and the text to put there, so the caller can do it in one
     * `insertText` - one step of undo - and the selection afterwards, which still covers the same
     * text it did.
     *
     * @return {{from: number, to: number, text: string, start: number, end: number}}
     */
    Dpress.codeIndent = function (value, start, end, outdent) {
        var from = value.lastIndexOf('\n', start - 1) + 1;
        var last = end > start && value[end - 1] === '\n' ? end - 1 : end;
        var to = value.indexOf('\n', last);
        if (to < 0) {
            to = value.length;
        }
        var firstDelta = 0;
        var total = 0;
        var lines = value.slice(from, to).split('\n').map(function (line, index) {
            var delta;
            var changed;
            if (outdent) {
                var removed = /^(?: {1,4}|\t)/.exec(line);
                delta = removed ? -removed[0].length : 0;
                changed = line.slice(-delta);
            } else {
                delta = line === '' ? 0 : CODE_INDENT.length;
                changed = line === '' ? line : CODE_INDENT + line;
            }
            if (index === 0) {
                firstDelta = delta;
            }
            total += delta;
            return changed;
        });
        return {
            from: from, to: to, text: lines.join('\n'),
            // a selection that began at the start of its line keeps the whole line in it
            start: start === from ? from : Math.max(from, start + firstDelta),
            end: Math.max(from, end + total)
        };
    };

    /**
     * Where Home goes: the first character of the line that is not indent - and when the caret
     * is already on it, the very start of the line, so a second press gets there
     */
    Dpress.codeHome = function (value, position) {
        var from = value.lastIndexOf('\n', position - 1) + 1;
        var indent = /^[ \t]*/.exec(value.slice(from))[0].length;
        var text = from + indent;
        return position === text ? from : text;
    };

    /**
     * Types into a textarea the way a keystroke would
     *
     * `insertText` rather than writing `value`, because it is what keeps the browser's undo
     * stack - Ctrl+Z after Enter takes the newline back rather than the whole field. The value
     * write is for a browser that no longer has the command.
     */
    function typeInto(textarea, text) {
        textarea.focus();
        var done = false;
        try {
            done = document.execCommand && document.execCommand('insertText', false, text);
        } catch (error) {
            done = false;
        }
        if (!done) {
            replaceSelection(textarea, text, '', false);
        }
    }

    /**
     * Tab, Shift+Tab and Home, the way an editor has them - for both fields somebody types
     * structure into, the markdown and the CSS
     *
     * Tab types four spaces at the caret, unless the selection spans lines - then it is the lines
     * that move, and Shift+Tab always moves lines. Home goes to where the line's text starts, and
     * from there to where the line does; Shift+Home selects the same way. Done here rather than in
     * each field's handler, because a key that behaves one way in one box and another way in the
     * box under it is a key nobody learns.
     *
     * @return {boolean} whether the key was one of these, and so is done with
     */
    function indentKeys(textarea, event) {
        if (event.key === 'Tab') {
            event.preventDefault();
            var from = textarea.selectionStart;
            var to = textarea.selectionEnd;
            if (!event.shiftKey && textarea.value.slice(from, to).indexOf('\n') < 0) {
                typeInto(textarea, CODE_INDENT);
                return true;
            }
            var change = Dpress.codeIndent(textarea.value, from, to, event.shiftKey);
            if (change.text !== textarea.value.slice(change.from, change.to)) {
                textarea.setSelectionRange(change.from, change.to);
                typeInto(textarea, change.text);
            }
            textarea.setSelectionRange(change.start, change.end);
            return true;
        }
        if (event.key === 'Home') {
            event.preventDefault();
            // the end that moves: the caret, which is the start of a backwards selection
            var backwards = textarea.selectionDirection === 'backward';
            var moving = backwards ? textarea.selectionStart : textarea.selectionEnd;
            var anchor = backwards ? textarea.selectionEnd : textarea.selectionStart;
            var target = Dpress.codeHome(textarea.value, moving);
            if (!event.shiftKey) {
                textarea.setSelectionRange(target, target);
            } else if (target < anchor) {
                textarea.setSelectionRange(target, anchor, 'backward');
            } else {
                textarea.setSelectionRange(anchor, target, 'forward');
            }
            return true;
        }
        return false;
    }

    // --- attachments, in the content editor ---

    /**
     * Picks a library item and attaches it to this post
     *
     * Attaching is all it does. The toolbar's image button is a different thing entirely - it
     * writes a `media#<id>` into the text and touches no attachment - so the two no longer share
     * anything, and neither of them decides what the other means. A file can be attached and
     * shown in the body, either, or both; that stays the author's, and nothing recalculates it.
     */
    function pickAndAttach(attachUrl) {
        Dpress.pickMedia(function (item) {
            Dpress.send(attachUrl, {media_id: item.id})
                .then(refreshAttachments)
                .catch(function (error) {
                    console.error('Dpress: the file could not be attached', error);
                    global.alert('That file could not be attached. Reload the page and try again.');
                });
        });
    }

    function refreshAttachments() {
        var element = document.querySelector('[data-attachment-list]');
        if (element && element.dpressList) {
            element.dpressList.refresh();
        }
    }

    // --- dragging a tree into order ---

    /**
     * Reordering and re-nesting a tree that is rendered as a flat table
     *
     * The rows carry the tree: `data-id`, `data-parent` and `data-depth`, and the order they sit
     * in is the order they render in. Everything below reads those and nothing else, so the same
     * code drives the menu items screen and the categories one.
     *
     * **Pointer events, not HTML5 drag and drop.** The native one cannot say where *inside* a row
     * the pointer is without a `dragover` handler on every row, it drags a ghost image nobody
     * asked for, and on a table row it behaves differently in every browser. This needs one
     * number - how far down the row the pointer is - and pointer capture hands it over directly.
     *
     * Three zones per row: the top quarter drops **before** it, the bottom quarter **after** it,
     * and the middle **inside** it. That is the whole vocabulary, and it can express any move.
     */
    Dpress.sortableTree = function (tbody, options) {
        options = options || {};
        var dragging = null;   // the row being moved
        var moving = [];       // it and its descendants, which travel with it
        var target = null;     // the row under the pointer
        var zone = '';         // 'before' | 'after' | 'inside'

        function rows() {
            return Array.prototype.slice.call(tbody.querySelectorAll('tr[data-id]'));
        }

        function depthOf(row) {
            return parseInt(row.getAttribute('data-depth'), 10) || 0;
        }

        /**
         * A row and everything nested under it: every following row deeper than it, until one is
         * not. They move together or the tree tears.
         */
        function branch(row) {
            var all = rows();
            var start = all.indexOf(row);
            var depth = depthOf(row);
            var out = [row];
            for (var i = start + 1; i < all.length && depthOf(all[i]) > depth; i++) {
                out.push(all[i]);
            }
            return out;
        }

        function childrenOf(parentId) {
            return rows().filter(function (row) {
                return (row.getAttribute('data-parent') || '') === (parentId || '');
            });
        }

        function clearZones() {
            rows().forEach(function (row) {
                row.classList.remove('drop-before', 'drop-after', 'drop-inside');
            });
        }

        function over(event) {
            clearZones();
            target = null;
            zone = '';
            var under = document.elementFromPoint(event.clientX, event.clientY);
            var row = under && under.closest ? under.closest('tr[data-id]') : null;
            // dropping a branch inside itself is the one move that destroys it: the rows stay in
            // the table with a parent chain that loops, and nothing walking down ever reaches them
            if (!row || moving.indexOf(row) !== -1) {
                return;
            }
            var box = row.getBoundingClientRect();
            var where = (event.clientY - box.top) / box.height;
            // a flat list has two answers rather than three, and the whole row is the target: the
            // narrow middle band that means "inside" in a tree would be dead space in a sidebar
            zone = options.flat
                ? (where < 0.5 ? 'before' : 'after')
                : (where < 0.25 ? 'before' : (where > 0.75 ? 'after' : 'inside'));
            target = row;
            row.classList.add('drop-' + zone);
        }

        /**
         * Where the drop lands, in the terms the server wants: a parent, and an index among that
         * parent's children
         */
        function destination() {
            if (zone === 'inside') {
                return {
                    parent: target.getAttribute('data-id'),
                    position: childrenOf(target.getAttribute('data-id')).length
                };
            }
            var parent = target.getAttribute('data-parent') || '';
            var siblings = childrenOf(parent).filter(function (row) {
                return moving.indexOf(row) === -1;
            });
            var index = siblings.indexOf(target);
            return {parent: parent, position: zone === 'before' ? index : index + 1};
        }

        /**
         * Moves the branch in the table, so the screen shows the answer before the server gives
         * one. The server renumbers from the same order, so the two agree.
         */
        function place(to) {
            var previous = zone === 'before' ? previousOf(target) : lastOf(branch(target));
            var shift = (zone === 'inside' ? depthOf(target) + 1 : depthOf(target)) - depthOf(dragging);
            var at = previous;
            moving.forEach(function (row) {
                if (at) {
                    at.after(row);
                } else {
                    tbody.insertBefore(row, tbody.firstChild);
                }
                at = row;
                setDepth(row, depthOf(row) + shift);
            });
            dragging.setAttribute('data-parent', to.parent || '');
        }

        function lastOf(list) {
            return list[list.length - 1];
        }

        function previousOf(row) {
            var all = rows();
            var index = all.indexOf(row);
            return index > 0 ? all[index - 1] : null;
        }

        function setDepth(row, depth) {
            row.setAttribute('data-depth', String(depth));
            var cell = row.querySelector('[data-tree-label]');
            if (cell) {
                cell.style.paddingLeft = (14 + depth * 22) + 'px';
            }
        }

        function finish() {
            clearZones();
            moving.forEach(function (row) { row.classList.remove('dragging'); });
            dragging = null;
            moving = [];
            target = null;
            zone = '';
        }

        function drop() {
            if (!target || !zone || !dragging) {
                return finish();
            }
            var to = destination();
            var id = dragging.getAttribute('data-id');
            place(to);
            finish();
            if (options.onMove) {
                options.onMove(id, to.parent, to.position);
            }
        }

        tbody.addEventListener('pointerdown', function (event) {
            var handle = event.target.closest ? event.target.closest('[data-drag-handle]') : null;
            if (!handle || event.button !== 0) {
                return;
            }
            event.preventDefault();
            dragging = handle.closest('tr[data-id]');
            moving = branch(dragging);
            moving.forEach(function (row) { row.classList.add('dragging'); });
            handle.setPointerCapture(event.pointerId);
        });
        tbody.addEventListener('pointermove', function (event) {
            if (dragging) {
                over(event);
            }
        });
        tbody.addEventListener('pointerup', function () {
            if (dragging) {
                drop();
            }
        });
        tbody.addEventListener('pointercancel', finish);
    };

    /**
     * The tree tables that declare where a move should be posted
     */
    function initSortableTrees(root) {
        root.querySelectorAll('[data-sortable-tree]').forEach(function (table) {
            if (table.dataset.sortableBound) {
                return;
            }
            table.dataset.sortableBound = '1';
            var url = table.getAttribute('data-sortable-tree');
            var tbody = table.querySelector('tbody');
            if (!tbody) {
                return;
            }
            Dpress.sortableTree(tbody, {
                // a list that does not nest - the blocks in a place - offers no "inside" zone
                flat: table.hasAttribute('data-sortable-flat'),
                onMove: function (id, parentId, position) {
                    Dpress.send(url + id, {parent_id: parentId || '', position: position})
                        .then(function (answer) {
                            if (answer && answer.error) {
                                throw new Error(answer.error);
                            }
                        })
                        .catch(function (error) {
                            console.error('Dpress: that move was refused', error);
                            // the screen is showing a move the server did not make, so it is the
                            // screen that is wrong: ask for it again rather than guess it back
                            Dpress.navigate(global.location.href, false);
                        });
                }
            });
        });
    }

    /**
     * The "Add attachment" button beside the list
     */
    function initAttachments(root) {
        root.querySelectorAll('[data-attach]').forEach(function (button) {
            if (button.dataset.attachBound) {
                return;
            }
            button.dataset.attachBound = '1';
            button.addEventListener('click', function () {
                pickAndAttach(button.getAttribute('data-attach'));
            });
        });
    }

    /**
     * Writes a library item into the markdown field, at the cursor
     *
     * **What gets written is decided by the category**, because the kinds of file the library
     * holds are different things to put in a document:
     *
     * | category | written |
     * |---|---|
     * | `image` | `![alt](media#12)` - shown where it sits |
     * | `video` | `{{ video('media#13') }}` - a player |
     * | `audio` | `{{ audio('media#5') }}` - a player |
     * | anything else | `[label](media#7)` - a link, because a PDF is a download |
     *
     * A video used to be written as `![alt](media#13)`, which renders an `<img>` pointing at an
     * mp4: a broken picture on the page with nothing to say why. Both shortcodes are core
     * (`VideoShortcode`, `AudioShortcode`), so this never writes something the site cannot
     * render - a plugin's shortcode would be a different question.
     *
     * The label is the item's own alt text: an image with no alt is invisible to somebody using a
     * screen reader, and this is the moment anybody knows what the picture is *for*. A `]` in that
     * text would end the label early and leave the rest loose in the paragraph. **A shortcode
     * carries no label**, so there is nothing to escape into one - the player reads the alt text
     * out of the library when it renders, which is the same text and one fewer copy of it living
     * in somebody's document.
     *
     * The destination is `media#<id>`, not the URL the row also carries. A document says *what*
     * it points at and the server works out where that is when it renders, so moving the site
     * from a test domain to a real one leaves every stored document exactly as it was.
     */
    var MEDIA_PLAYERS = {video: 'video', audio: 'audio'};

    Dpress.insertMedia = function (item, textarea) {
        textarea = textarea || document.querySelector('textarea.markdown-editor');
        if (!textarea || !item) {
            return;
        }
        replaceSelection(textarea, Dpress.mediaMarkdown(item), '', false);
    };

    /**
     * The markdown for one library item
     *
     * Its own function because it is the part with the decision in it, and the one thing here
     * that ends up inside somebody's document forever.
     */
    Dpress.mediaMarkdown = function (item) {
        var player = MEDIA_PLAYERS[item.category];
        if (player) {
            return '{{ ' + player + "('media#" + item.id + "') }}";
        }
        var label = String(item.alt || item.title || item.file_name || '').replace(/([\[\]])/g, '\\$1');
        return (item.category === 'image' ? '!' : '') + '[' + label + '](media#' + item.id + ')';
    };

    // --- Preview media, in the markdown field ---

    /** What a relative path is previewed as, by its extension - no SVG, which the Docs build refuses */
    var PREVIEW_TYPES = {
        png: 'image', jpg: 'image', jpeg: 'image', gif: 'image', webp: 'image', avif: 'image',
        mp4: 'video', webm: 'video', mp3: 'audio', m4a: 'audio', ogg: 'audio', wav: 'audio'
    };

    /**
     * The media reference the caret is in, or null - what *Preview media* would show
     *
     * The caret is in one when it is anywhere in the destination of `![alt](here)` or
     * `[text](here)`, of a reference definition `[x]: here`, or on a `media#12` wherever it is
     * written - which is also how the video and audio shortcodes name their file. A `media#<id>`
     * is always one; a relative path only when its extension is an image, a video or audio, so a
     * link to another page or to a heading is not. An address with a scheme is somewhere else's.
     *
     * Pure, so it is the part a test can ask about.
     *
     * @return {?{target: string, kind: string, id: ?number, path: ?string, type: ?string}}
     *         `kind` is `media` (with `id`) or `path` (with `path` and `type`)
     */
    Dpress.mediaTargetAt = function (value, position) {
        value = String(value || '');
        var lineStart = value.lastIndexOf('\n', position - 1) + 1;
        var lineEnd = value.indexOf('\n', position);
        var line = value.slice(lineStart, lineEnd < 0 ? value.length : lineEnd);
        var column = position - lineStart;
        var spans = [];
        var match;
        var destination = /\]\(\s*(<[^>\n]*>|[^\s()]+)/g;
        while ((match = destination.exec(line)) !== null) {
            var at = match.index + match[0].length - match[1].length;
            spans.push({start: at, end: at + match[1].length, text: match[1]});
        }
        var definition = /^(\s{0,3}\[[^\]\n]+\]:[ \t]*)(<[^>\n]*>|\S+)/.exec(line);
        if (definition) {
            spans.push({start: definition[1].length, end: definition[1].length + definition[2].length, text: definition[2]});
        }
        var library = /media#\d+/g;
        while ((match = library.exec(line)) !== null) {
            spans.push({start: match.index, end: match.index + match[0].length, text: match[0]});
        }
        for (var i = 0; i < spans.length; i++) {
            if (column < spans[i].start || column > spans[i].end) {
                continue;
            }
            var target = spans[i].text.replace(/^<|>$/g, '');
            var reference = /^media#(\d+)(?:[#?].*)?$/.exec(target);
            if (reference) {
                return {target: target, kind: 'media', id: parseInt(reference[1], 10), path: null, type: null};
            }
            if (/^[a-z][a-z0-9+.-]*:/i.test(target) || target.indexOf('//') === 0 || target.charAt(0) === '#') {
                continue;
            }
            var bare = target.replace(/[?#].*$/, '');
            var name = bare.split('/').pop();
            var type = PREVIEW_TYPES[name.slice(name.lastIndexOf('.') + 1).toLowerCase()];
            if (name.lastIndexOf('.') > 0 && type) {
                return {target: target, kind: 'path', id: null, path: bare, type: type};
            }
        }
        return null;
    };

    /**
     * Where a relative path is fetched from for a preview
     *
     * A field can say (`data-relative-preview` - the Docs editor's, which answers from the page's
     * own folder in the source); otherwise the path is read the way the site would read it,
     * against the site's address.
     */
    function previewUrlOf(found, textarea) {
        var own = textarea && textarea.getAttribute('data-relative-preview');
        if (own) {
            return own + (own.indexOf('?') === -1 ? '?' : '&') + 'path=' + encodeURIComponent(found.path);
        }
        try {
            return new URL(found.path, document.body.getAttribute('data-site-url') || global.location.href).href;
        } catch (error) {
            return found.path;
        }
    }

    /**
     * Shows a media reference over the page, dimmed behind it
     *
     * Esc, a click on the dimmed part or the close button end it, and the field gets the focus
     * back with the caret where it was. Nothing in it changes the document.
     */
    Dpress.previewMedia = function (found, textarea) {
        var dialog = document.createElement('dialog');
        dialog.className = 'media-preview';
        dialog.innerHTML = '<button type="button" class="close" title="Close" aria-label="Close">&times;</button>'
            + '<figure><div class="media-preview-body"><p class="media-preview-note">Loading…</p></div>'
            + '<figcaption></figcaption></figure>';
        document.body.appendChild(dialog);
        var body = dialog.querySelector('.media-preview-body');
        var caption = dialog.querySelector('figcaption');
        caption.textContent = found.target;

        function note(text) {
            body.innerHTML = '';
            var p = document.createElement('p');
            p.className = 'media-preview-note';
            p.textContent = text;
            body.appendChild(p);
        }

        function show(type, url, alt, missing) {
            var element;
            if (type === 'image') {
                element = document.createElement('img');
                element.alt = alt || '';
            } else if (type === 'video' || type === 'audio') {
                element = document.createElement(type);
                element.controls = true;
                element.preload = 'metadata';
            } else {
                element = document.createElement('a');
                element.href = url;
                element.target = '_blank';
                element.rel = 'noopener';
                element.textContent = 'Open the file';
                body.innerHTML = '';
                body.appendChild(element);
                return;
            }
            element.addEventListener('error', function () {
                note(missing);
            });
            element.src = url;
            body.innerHTML = '';
            body.appendChild(element);
        }

        if (found.kind === 'media') {
            var endpoint = document.body.getAttribute('data-media-preview') || '';
            fetch(endpoint + (endpoint.indexOf('?') === -1 ? '?' : '&') + 'id=' + found.id, {
                headers: {'Accept': 'application/json'},
                credentials: 'same-origin'
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error(response.status === 404 ? 'missing' : 'HTTP ' + response.status);
                }
                return response.json();
            }).then(function (item) {
                var parts = [found.target, item.file_name];
                if (item.width && item.height) {
                    parts.push(item.width + ' × ' + item.height);
                }
                if (item.deleted) {
                    parts.push('in the trash');
                }
                caption.textContent = parts.join(' · ');
                show(item.category, item.url, item.alt, item.file_name + ' could not be loaded.');
            }).catch(function (error) {
                note(error.message === 'missing'
                    ? found.target + ' is not in the library.'
                    : 'The library could not be asked about ' + found.target + '.');
            });
        } else {
            show(found.type, previewUrlOf(found, textarea), '', found.path + ' is not there.');
        }

        function close() {
            if (dialog.open) {
                dialog.close();
            }
        }

        dialog.addEventListener('close', function () {
            dialog.remove();
            if (textarea) {
                textarea.focus();
            }
        });
        dialog.querySelector('.close').addEventListener('click', close);
        // a click on the dimmed part is a click on the dialog itself; on what it holds, it is not
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) {
                close();
            }
        });
        // the focus on the dialog, not on its first button: opened with Alt+P, a focused × is a
        // ringed ×, and nothing is to be pressed yet - Esc closes it either way
        dialog.tabIndex = -1;
        dialog.showModal();
        dialog.focus();
        return dialog;
    };

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

            // The thumbnail is the way to the big picture: a click - or Enter or Space on it, since
            // it takes the focus - shows the chosen item in Preview media's dialog. Asked of the
            // library by its id, so a picture chosen a moment ago works as well as a saved one.
            preview.tabIndex = 0;
            preview.setAttribute('role', 'button');
            preview.title = 'Preview';
            function showChosen() {
                var id = parseInt(input.value, 10);
                if (id > 0 && preview.childNodes.length > 0) {
                    Dpress.previewMedia({target: 'media#' + id, kind: 'media', id: id, path: null, type: null}, preview);
                }
            }
            preview.addEventListener('click', showChosen);
            preview.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    showChosen();
                }
            });

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
            console.error('Dpress: the page does not say where the media list is');
            return;
        }
        var uploadUrl = document.body.getAttribute('data-media-upload') || '';
        var dialog = document.createElement('dialog');
        dialog.className = 'media-picker';
        dialog.innerHTML =
            '<header><h2>Media</h2><button type="button" class="close" title="Close">&times;</button></header>' +
            (uploadUrl
                ? '<div class="picker-upload" data-drop>' +
                  '<p>Drop a file here, or <button type="button" class="button small" data-choose-file>choose one</button></p>' +
                  '<input type="file" hidden data-file>' +
                  '<progress hidden max="100" value="0"></progress>' +
                  '<p class="form-error" hidden data-upload-error></p>' +
                  '</div>'
                : '') +
            '<form class="picker-filters"><input type="search" name="search" placeholder="Search…"></form>' +
            '<div class="picker-list"></div>';
        document.body.appendChild(dialog);

        if (uploadUrl) {
            initPickerUpload(dialog, uploadUrl, function (item) {
                close();
                chosen(item);
            });
        }

        var filterForm = dialog.querySelector('.picker-filters');

        var list = new global.DynamicList(dialog.querySelector('.picker-list'), {
            filterForm: filterForm,
            pageSize: 12,
            columnViews: {
                thumbnail_html: {label: 'Icon', view: global.DynamicListColumnView.html, sortable: false, width: '52px'},
                file_name: {label: 'File'},
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

        // the same binder the list screens use, rather than a second one that happens to do
        // nearly the same thing - the dialog gets the dedupe and the immediate select for free
        Dpress.bindFilters(filterForm, list);

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

    /**
     * The upload half of the picker
     *
     * A file that lands here goes straight to the callback the dialog was opened with, which is
     * the same one a chosen row goes to - so uploading and picking are one path out of the
     * dialog, and whatever opened it does not have to know which happened.
     *
     * `XMLHttpRequest` rather than `fetch`, for the one thing it can still do that `fetch`
     * cannot: report progress. A 20 MB photo over a phone connection with no feedback reads as
     * broken, and somebody will press the button again.
     */
    function initPickerUpload(dialog, uploadUrl, uploaded) {
        var zone = dialog.querySelector('[data-drop]');
        var input = dialog.querySelector('[data-file]');
        var bar = dialog.querySelector('progress');
        var error = dialog.querySelector('[data-upload-error]');

        dialog.querySelector('[data-choose-file]').addEventListener('click', function () {
            input.click();
        });
        input.addEventListener('change', function () {
            if (input.files && input.files[0]) {
                send(input.files[0]);
            }
        });
        ['dragenter', 'dragover'].forEach(function (name) {
            zone.addEventListener(name, function (event) {
                event.preventDefault();
                zone.classList.add('over');
            });
        });
        ['dragleave', 'drop'].forEach(function (name) {
            zone.addEventListener(name, function (event) {
                event.preventDefault();
                zone.classList.remove('over');
            });
        });
        zone.addEventListener('drop', function (event) {
            if (event.dataTransfer && event.dataTransfer.files[0]) {
                send(event.dataTransfer.files[0]);
            }
        });

        function send(file) {
            var form = document.querySelector('form[data-action-form]');
            if (!form) {
                return show('This page cannot upload.');
            }
            var body = new FormData();
            new FormData(form).forEach(function (value, name) {
                body.append(name, value);   // the CSRF token, as every other action sends it
            });
            body.append('file', file);

            show('');
            bar.hidden = false;
            bar.value = 0;

            var request = new XMLHttpRequest();
            request.open('POST', uploadUrl);
            request.upload.addEventListener('progress', function (event) {
                if (event.lengthComputable) {
                    bar.value = Math.round((event.loaded / event.total) * 100);
                }
            });
            request.addEventListener('load', function () {
                bar.hidden = true;
                var answer = {};
                try {
                    answer = JSON.parse(request.responseText);
                } catch (ignore) {
                    // an HTML error page rather than an answer - the message below is all we know
                }
                Dpress.keepToken(answer);   // even a rejected file spent the token getting here
                if (request.status !== 200 || !answer.item) {
                    return show(answer.error || 'That file could not be uploaded.');
                }
                uploaded(answer.item);
            });
            request.addEventListener('error', function () {
                bar.hidden = true;
                show('The upload did not finish. Check the connection and try again.');
            });
            request.send(body);
        }

        function show(message) {
            error.textContent = message;
            error.hidden = message === '';
        }
    }

    /**
     * A filter form refreshes its list as it is typed in, rather than on a submit button
     *
     * **One change, one request.** Three things made that not so. A `<select>` fires `input` and
     * then `change`, and those were two separate listeners, so choosing a category asked the
     * server twice - once at once and once 250 ms later. A text field fires `change` on blur as
     * well as `input` while typing, so tabbing out of a search box asked again for what was
     * already on the screen. And `DynamicList` binds `submit` itself when it is given a form, so
     * pressing Enter went through two handlers.
     *
     * So: one timer that every event reschedules, and a guard on what was last asked. Typing
     * waits 250 ms; anything else goes at the end of the tick, which is late enough for a
     * select's two events to collapse into one and soon enough to feel immediate. Submit is not
     * bound here at all - the list already has it.
     */
    Dpress.bindFilters = function (form, list) {
        var timer = null;
        var lastAsked = null;

        function apply() {
            timer = null;
            var now = serializeForm(form);
            if (now === lastAsked) {
                return; // nothing about the filters moved, so the answer would be the one on screen
            }
            lastAsked = now;
            list.applyFilters();
        }

        function schedule(event) {
            clearTimeout(timer);
            timer = setTimeout(apply, isTyped(event.target) ? 250 : 0);
        }

        form.addEventListener('input', schedule);
        form.addEventListener('change', schedule);
    };

    /**
     * Is this a control somebody types into, rather than one they choose from?
     *
     * A select, a checkbox and a radio are done the moment they are touched; a text box is not,
     * which is the whole reason for the wait.
     */
    function isTyped(target) {
        if (!target || !target.tagName) {
            return false;
        }
        if (target.tagName !== 'INPUT' && target.tagName !== 'TEXTAREA') {
            return false;
        }
        var type = (target.type || 'text').toLowerCase();
        return type !== 'checkbox' && type !== 'radio';
    }

    /**
     * The filter state as a string, for comparing one against the last
     */
    function serializeForm(form) {
        var parts = [];
        new FormData(form).forEach(function (value, name) {
            parts.push(name + '=' + value);
        });
        return parts.join('&');
    }

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
                console.warn('Dpress: ' + target + ' did not load into the page, going there', error);
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
        // before the binders run, so this screen's Back buttons see the trail that led here
        rememberScreen(target);
        Dpress.init(main);
        // the page changed under somebody who may not be able to see that it did: without this
        // the focus stays on a link that no longer exists, and the next Tab starts from the top
        main.focus({preventScroll: true});
    }

    // --- where Back goes ---

    /**
     * The trail of admin screens this tab has shown, newest last
     *
     * What makes Back mean "back": History reached from the list goes back to the list, and
     * reached from the editor goes back to the editor - which one fixed address on the button
     * could never say. Kept per tab in `sessionStorage`, so two tabs keep two trails and a closed
     * tab forgets its own.
     *
     * A visit to the screen already on top changes nothing - a save or a restore that lands back
     * where it was is not a step - and a visit to the one under it is a step back, so it is taken
     * off rather than piled on. Which is also what a Back button's own click does to it.
     */
    var TRAIL_KEY = 'dpress-trail';
    var TRAIL_MAX = 30;

    Dpress.trailVisit = function (trail, url) {
        trail = (trail || []).slice();
        if (trail[trail.length - 1] === url) {
            return trail;
        }
        if (trail[trail.length - 2] === url) {
            trail.pop();
            return trail;
        }
        trail.push(url);
        return trail.slice(-TRAIL_MAX);
    };

    /** The screen before the current one, or null when this tab has not been anywhere before it */
    Dpress.trailBack = function (trail) {
        return trail && trail.length > 1 ? trail[trail.length - 2] : null;
    };

    function readTrail() {
        try {
            var stored = JSON.parse(global.sessionStorage.getItem(TRAIL_KEY) || '[]');
            return Array.isArray(stored) ? stored : [];
        } catch (error) {
            return []; // storage off or full: Back is its own link, as it was before this existed
        }
    }

    function rememberScreen(url) {
        try {
            global.sessionStorage.setItem(TRAIL_KEY, JSON.stringify(Dpress.trailVisit(readTrail(), withoutPartial(url))));
        } catch (error) {
            // nothing to do: without a trail every Back button keeps the address it was given
        }
    }

    /**
     * Points each Back button at the screen before this one
     *
     * The link keeps its own address in the markup, which is where it goes with the scripts off
     * or in a fresh tab. Only an admin screen on this site replaces it - a trail somebody's
     * storage was tampered with does not get to send a click anywhere else.
     */
    function initBackLinks(root) {
        var previous = Dpress.trailBack(readTrail());
        if (!previous) {
            return;
        }
        var url;
        try {
            url = new URL(previous, global.location.href);
        } catch (error) {
            return;
        }
        if (url.origin !== global.location.origin || !isAdminUrl(url) || url.href === withoutPartial(global.location.href)) {
            return;
        }
        root.querySelectorAll('a[data-back]').forEach(function (link) {
            link.setAttribute('href', url.href);
        });
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

    /**
     * Binders a plugin has added
     *
     * A plugin shipping a field type usually ships behaviour for it, and every binder here runs
     * on load *and* after each partial navigation - which is the part that is easy to get wrong
     * on your own, because an admin screen arrives without a page load and a listener bound to
     * `DOMContentLoaded` never fires again.
     *
     * Guard against binding twice, the way the built in ones do: `if (el.dataset.myBound) return;`
     * `Dpress.init(root)` is called with the swapped element, but a binder that queries the whole
     * document will still see what it bound last time.
     */
    var extraInits = [];

    Dpress.addInit = function (fn) {
        if (typeof fn === 'function') {
            extraInits.push(fn);
        }
    };

    /**
     * A thumbnail in a list that says which library item it is (`data-media-preview-id`, the
     * `htmlLink` view's `previewProperty`) shows it in Preview media's dialog on a plain click
     *
     * Once, on the document, since lists draw their rows long after `init()` and again on every
     * page of them. A click with a modifier, or with the middle button, is left to the link - a
     * new tab with the file is still what it asks for.
     */
    var listPreviewsBound = false;

    function initListPreviews() {
        if (listPreviewsBound || typeof document.addEventListener !== 'function') {
            return;
        }
        listPreviewsBound = true;
        document.addEventListener('click', function (event) {
            var link = event.target && event.target.closest ? event.target.closest('a[data-media-preview-id]') : null;
            if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
                return;
            }
            var id = parseInt(link.getAttribute('data-media-preview-id'), 10);
            if (id > 0) {
                event.preventDefault();
                Dpress.previewMedia({target: 'media#' + id, kind: 'media', id: id, path: null, type: null}, link);
            }
        });
    }

    Dpress.init = function (root) {
        root = root || document;
        initListPreviews();
        initConfirms(root);
        initCodeEditors(root);
        initMediaFields(root);
        initTargetFields(root);
        initPreviewCursor(root);
        initSortableTrees(root);
        initLists(root);
        initAttachments(root);
        initBackLinks(root);
        extraInits.forEach(function (fn) {
            // one plugin throwing must not stop the next one binding, nor the admin working
            try {
                fn(root);
            } catch (error) {
                console.error('Dpress: a plugin initialiser failed', error);
            }
        });
    };

    document.addEventListener('DOMContentLoaded', function () {
        if (global.DynamicListColumnView) {
            global.DynamicListColumnView.locale = document.documentElement.lang || 'en';
        }
        // a full load is a screen too - after a save's redirect, most often
        if (document.querySelector('.admin-main')) {
            rememberScreen(global.location.href);
        }
        Dpress.init(document);
        initNavigation();
    });

}(window));
