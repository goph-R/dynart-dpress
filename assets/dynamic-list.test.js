/**
 * A test for the list, with no toolchain at all
 *
 *   node assets/dynamic-list.test.js
 *
 * The PHP suite covers what the server sends; nothing covered what the browser does with it, and
 * the list went out once with a constructor that called a method it had not assigned yet. The DOM
 * here is a stub - about forty lines, no dependency, no build step - which is enough to run the
 * whole thing end to end and look at what came out.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const assert = require('assert');

// --- the smallest DOM this needs ---

function makeNode(tag) {
    return {
        tagName: tag.toUpperCase(),
        children: [],
        style: {},
        dataset: {},
        attributes: {},
        listeners: {},
        classList: {
            set: new Set(),
            add(...names) { names.forEach(n => n && this.set.add(n)); },
            remove(...names) { names.forEach(n => this.set.delete(n)); },
            toggle(name, on) { on ? this.set.add(name) : this.set.delete(name); },
            contains(name) { return this.set.has(name); }
        },
        set className(value) {
            this.classNameValue = value;
            String(value).split(' ').forEach(n => n && this.classList.add(n));
        },
        get className() { return this.classNameValue || ''; },
        set innerHTML(value) { this.html = value; if (value === '') { this.children = []; } },
        get innerHTML() { return this.html || ''; },
        set textContent(value) { this.text = value; },
        get textContent() { return this.text || ''; },
        appendChild(child) { this.children.push(child); child.parentNode = this; return child; },
        insertBefore(child) { this.children.unshift(child); return child; },
        after() {},
        remove() {},
        setAttribute(name, value) { this.attributes[name] = value; },
        getAttribute(name) { return this.attributes[name]; },
        addEventListener(type, fn) { (this.listeners[type] = this.listeners[type] || []).push(fn); },
        dispatch(type) { (this.listeners[type] || []).forEach(fn => fn({target: this})); },
        querySelector() { return null; },
        querySelectorAll() { return []; },
        get firstChild() { return this.children[0]; }
    };
}

global.document = {
    createElement: makeNode,
    documentElement: {lang: 'en'},
    addEventListener() {},
    querySelector() { return null; },
    querySelectorAll() { return []; },
    body: makeNode('body')
};

global.FormData = class {
    constructor(form) { this.form = form; }
    forEach(callback) {
        (this.form.children || []).forEach(input => {
            if (input.name) { callback(input.value, input.name); }
        });
    }
};

global.window = global;
eval(fs.readFileSync(path.join(__dirname, 'dynamic-list.js'), 'utf8'));

// --- helpers ---

function find(node, predicate) {
    return node.children.find(predicate);
}

function build(options, result) {
    const container = makeNode('div');
    let sent = null;
    const list = new global.DynamicList(container, Object.assign({
        findItems(filters, done) {
            sent = filters;
            done(result);
        }
    }, options));
    const root = find(container, n => n.classList.contains('dynamic-list'));
    return {
        list,
        container,
        root,
        filters: () => sent,
        tbody: () => find(find(root, n => n.tagName === 'TABLE'), n => n.tagName === 'TBODY'),
        paging: () => find(find(root, n => n.classList.contains('list-footer')),
                           n => n.classList.contains('paging')),
        status: () => find(root, n => n.classList.contains('list-status'))
    };
}

const COLUMNS = {
    title: {label: 'Title', view: global.DynamicListColumnView.link, options: {hrefProperty: 'edit_url'}},
    status: {label: 'Status', view: global.DynamicListColumnView.badge},
    created_at: {label: 'Created', view: global.DynamicListColumnView.dateTime}
};

const TWO_ROWS = {
    items: [
        {id: 1, title: '<script>alert(1)</script>', status: 'draft',
         created_at: '2026-08-04 13:50:42', edit_url: '/admin/content/post/edit/1'},
        {id: 2, title: 'Second', status: 'published',
         created_at: '2026-08-04 14:00:00', edit_url: '/admin/content/post/edit/2'}
    ],
    total: 7
};

// --- the tests ---

const tests = {

    /**
     * The methods are function expressions assigned to `this`, so none of them exist until the
     * constructor has run past them. Asking for the rows before that point threw.
     */
    'it asks for its rows on construction'() {
        const it = build({columnViews: COLUMNS, pageSize: 2, orderBy: 'title'}, TWO_ROWS);
        assert.deepStrictEqual(it.filters(), {sort: 'title', order: 'asc', offset: '0', max: '2'});
        assert.strictEqual(it.list.items().length, 2);
        assert.strictEqual(it.list.count(), 7);
    },

    /**
     * A list screen used to cost two requests: the page, and then the rows the moment the list
     * had built itself. The page already knows what the rows are.
     */
    'a seeded first page is rendered without asking for anything'() {
        const it = build({columnViews: COLUMNS, pageSize: 2, firstPage: TWO_ROWS}, {items: [], total: 0});
        assert.strictEqual(it.filters(), null, 'the rows came with the page and it asked anyway');
        assert.strictEqual(it.list.items().length, 2);
        assert.strictEqual(it.list.count(), 7);
        assert.deepStrictEqual(it.paging().children.map(b => b.textContent), ['1', '2', '3', '4', 'Next']);
    },

    /**
     * The seed is a head start, not a second source of truth: the first sort, filter or page
     * change goes to the endpoint like any other.
     */
    'a seeded list still asks the endpoint when something changes'() {
        const it = build(
            {columnViews: COLUMNS, pageSize: 2, orderBy: 'title', firstPage: TWO_ROWS},
            {items: [TWO_ROWS.items[1]], total: 1}
        );
        assert.strictEqual(it.filters(), null);
        it.list.applyFilters();
        assert.deepStrictEqual(it.filters(), {sort: 'title', order: 'asc', offset: '0', max: '2'});
        assert.strictEqual(it.list.items().length, 1);
    },

    /**
     * A title is whatever an editor typed. Rendering it raw would put one person's markup into
     * every other person's browser.
     */
    'a column view escapes'() {
        const it = build({columnViews: COLUMNS, pageSize: 2}, TWO_ROWS);
        const cell = it.tbody().children[0].children[0];
        assert.ok(!cell.innerHTML.includes('<script>'), 'a title was rendered unescaped');
        assert.ok(cell.innerHTML.includes('&lt;script&gt;'));
        assert.ok(cell.innerHTML.includes('href="/admin/content/post/edit/1"'));
    },

    'the html view is the opt out'() {
        const view = global.DynamicListColumnView.html;
        assert.strictEqual(view({cell: '<b>bold</b>'}, 'cell'), '<b>bold</b>');
        assert.strictEqual(view({}, 'missing'), '');
    },

    /**
     * `link` escapes its text, which is right for a name and wrong for a thumbnail. This is the
     * pair of them: markup through, href still escaped - the href is the half that comes from a
     * file name somebody uploaded.
     */
    'the htmlLink view wraps markup in a link without escaping it'() {
        const view = global.DynamicListColumnView.htmlLink;
        const item = {cell: '<img src="/u/a.jpg">', url: '/u/a.jpg'};
        assert.strictEqual(
            view(item, 'cell', {hrefProperty: 'url'}),
            '<a href="/u/a.jpg"><img src="/u/a.jpg"></a>'
        );
    },

    'the htmlLink view escapes the href and survives having none'() {
        const view = global.DynamicListColumnView.htmlLink;
        const nasty = view({cell: '<img>', url: '/u/a".jpg'}, 'cell', {hrefProperty: 'url'});
        assert.ok(!nasty.includes('href="/u/a".jpg"'), 'a quote in the URL broke out of the attribute');
        assert.ok(nasty.includes('&quot;'));
        // no link to give: the markup still has to arrive, or the row loses its picture
        assert.strictEqual(view({cell: '<img>'}, 'cell', {hrefProperty: 'url'}), '<img>');
    },

    'it pages by the total, not by the rows it was given'() {
        const it = build({columnViews: COLUMNS, pageSize: 2}, TWO_ROWS);
        const labels = it.paging().children.map(b => b.textContent);
        assert.deepStrictEqual(labels, ['1', '2', '3', '4', 'Next'], 'seven rows at two a page is four pages');
    },

    'no rows means no table and a message'() {
        const it = build({columnViews: COLUMNS}, {items: [], total: 0});
        assert.strictEqual(it.list.items().length, 0);
        assert.ok(it.status().classList.contains('no-results'));
        assert.strictEqual(it.paging().children.length, 0);
    },

    /**
     * `label: ''` is a column that wants no heading - a thumbnail, a checkbox. `||` read that as
     * "no label given" and printed the property name, so a column asking for a blank header got
     * `thumbnail_html` written across the top of it.
     */
    'a column asking for no heading gets none, rather than its property name'() {
        const it = build({columnViews: {title: {label: '', sortable: false}}}, TWO_ROWS);
        const header = find(find(it.root, n => n.tagName === 'TABLE'), n => n.tagName === 'THEAD')
            .children[0].children.find(n => n.getAttribute('data-property') === 'title');
        assert.strictEqual(header.textContent, '');
    },

    'a column with no label at all still falls back to its property name'() {
        const it = build({columnViews: {title: {sortable: false}}}, TWO_ROWS);
        const header = find(find(it.root, n => n.tagName === 'TABLE'), n => n.tagName === 'THEAD')
            .children[0].children.find(n => n.getAttribute('data-property') === 'title');
        assert.strictEqual(header.textContent, 'title');
    },

    'one page needs no pager'() {
        const it = build({columnViews: COLUMNS, pageSize: 25}, {items: TWO_ROWS.items, total: 2});
        assert.strictEqual(it.paging().children.length, 0);
    },

    /**
     * A row action declared as `post` is not a link: a link that changes something can be
     * followed by a prefetcher or by an `<img>` on somebody else's page.
     */
    'a post row action renders a button, a link action renders an anchor'() {
        const it = build({
            columnViews: COLUMNS,
            rowActions: [
                {type: 'edit', title: 'Edit', link: '/admin/content/post/edit/'},
                {type: 'delete', title: 'Delete', action() {}}
            ]
        }, TWO_ROWS);
        const actions = it.tbody().children[0].children[3];
        assert.strictEqual(actions.children[0].tagName, 'A');
        assert.strictEqual(actions.children[0].href, '/admin/content/post/edit/1');
        assert.strictEqual(actions.children[1].tagName, 'BUTTON');
    },

    /**
     * An icon has no accessible name of its own, so an action that is only an icon has to carry
     * one. `title` alone is not it - a screen reader cannot be relied on to announce it.
     */
    'an icon row action is still named'() {
        const it = build({
            columnViews: COLUMNS,
            rowActions: [{type: 'delete', title: 'Delete', icon: '<svg></svg>', action() {}}]
        }, TWO_ROWS);
        const action = it.tbody().children[0].children[3].children[0];
        assert.strictEqual(action.innerHTML, '<svg></svg>', 'the icon is markup, not escaped text');
        assert.strictEqual(action.title, 'Delete');
        assert.strictEqual(action.getAttribute('aria-label'), 'Delete');
    },

    'an action with no icon still reads as its title, escaped'() {
        const it = build({
            columnViews: COLUMNS,
            rowActions: [{type: 'edit', title: '<b>Edit</b>', link: '/e/'}]
        }, TWO_ROWS);
        const action = it.tbody().children[0].children[3].children[0];
        assert.strictEqual(action.innerHTML, '&lt;b&gt;Edit&lt;/b&gt;');
        assert.strictEqual(action.getAttribute('aria-label'), undefined, 'the text is the name already');
    },

    'a row action can be hidden per row'() {
        const it = build({
            columnViews: COLUMNS,
            rowActions: [{
                type: 'publish', title: 'Publish', action() {},
                visible: item => item.status === 'draft'
            }]
        }, TWO_ROWS);
        assert.strictEqual(it.tbody().children[0].children[3].children.length, 1, 'the draft can be published');
        assert.strictEqual(it.tbody().children[1].children[3].children.length, 0, 'the published one cannot');
    },

    'a failed request says so rather than showing an empty list'() {
        const container = makeNode('div');
        const list = new global.DynamicList(container, {
            columnViews: COLUMNS,
            findItems(filters, done, failed) { failed(new Error('nope')); }
        });
        const root = find(container, n => n.classList.contains('dynamic-list'));
        assert.ok(find(root, n => n.classList.contains('list-status')).classList.contains('failed'));
        assert.strictEqual(list.items().length, 0);
    },

    /**
     * The rows can come back in any order; only the newest request may render, or a slow answer
     * to an abandoned filter overwrites what the person is looking at now.
     */
    'a stale answer is dropped'() {
        const container = makeNode('div');
        const pending = [];
        const list = new global.DynamicList(container, {
            columnViews: COLUMNS,
            findItems(filters, done) { pending.push(done); }
        });
        list.refresh();
        const [first, second] = pending;
        second({items: TWO_ROWS.items, total: 2});
        first({items: [], total: 0}); // the older request, answering late
        assert.strictEqual(list.items().length, 2, 'the older answer overwrote the newer one');
    },

    'the date view reads what the server writes'() {
        const view = global.DynamicListColumnView.dateTime;
        assert.strictEqual(view({at: '2026-08-04 13:50:42'}, 'at'), '2026-08-04 13:50');
        assert.ok(view({at: null}, 'at').includes('empty'));
    },

    'the bytes view counts in the units a person reads'() {
        const view = global.DynamicListColumnView.bytes;
        assert.strictEqual(view({size: 512}, 'size'), '512 B');
        assert.strictEqual(view({size: 2048}, 'size'), '2.0 kB');
        assert.ok(view({size: 0}, 'size').includes('empty'));
    },

    /**
     * The checkbox column exists to feed a group action. `Dpress.list()` maps whatever the server
     * declared, which is `[]` for a list that declared none - and `[]` is not falsy, so the
     * attachments panel drew a checkbox on every row, a select-all above them, and nothing
     * anywhere to do with a selection.
     */
    'no group actions means no checkbox column'() {
        const it = build({columnViews: COLUMNS, groupActions: []}, TWO_ROWS);
        const headers = find(find(it.root, n => n.tagName === 'TABLE'), n => n.tagName === 'THEAD')
            .children[0].children;
        assert.ok(!headers.some(h => h.classList.contains('checkbox-column')), 'a select-all with nothing to select for');
        const cells = it.tbody().children[0].children;
        assert.ok(!cells.some(c => c.classList.contains('checkbox-column')), 'a checkbox with nothing to do');
    },

    'group actions bring the checkbox column back'() {
        const it = build({columnViews: COLUMNS, groupActions: [{label: 'Delete', action() {}}]}, TWO_ROWS);
        const cells = it.tbody().children[0].children;
        assert.ok(cells.some(c => c.classList.contains('checkbox-column')));
    },

    'selection survives a refresh'() {
        const it = build({
            columnViews: COLUMNS,
            groupActions: [{label: 'Delete', action() {}}]
        }, TWO_ROWS);
        const checkbox = it.tbody().children[0].children[0].children[0];
        checkbox.checked = true;
        checkbox.dispatch('change');
        assert.deepStrictEqual(it.list.selection(), ['1']);
        it.list.refresh();
        assert.deepStrictEqual(it.list.selection(), ['1'], 'the selection was lost on refresh');
        it.list.clearSelection();
        assert.deepStrictEqual(it.list.selection(), []);
    },

    /**
     * The group action is how things are deleted now, so it has to get the selection
     *
     * The row delete buttons are gone: selecting and pressing this is the only way, which makes
     * "the button did nothing" and "the button deleted the wrong rows" both worse than they were.
     */
    'a group action is handed the selected ids and is dead without them'() {
        let given = null;
        const remove = {label: 'Delete selected', action(ids) { given = ids; }};
        const it = build({columnViews: COLUMNS, groupActions: [remove]}, TWO_ROWS);

        assert.strictEqual(remove.button.disabled, true, 'it was live with nothing selected');
        remove.button.dispatch('click');
        assert.strictEqual(given, null, 'it ran on an empty selection');

        const checkbox = it.tbody().children[1].children[0].children[0];
        checkbox.checked = true;
        checkbox.dispatch('change');
        assert.strictEqual(remove.button.disabled, false);
        remove.button.dispatch('click');
        assert.deepStrictEqual(given, ['2'], 'it was given something other than what was ticked');
    }
};

let failures = 0;
for (const name of Object.keys(tests)) {
    try {
        tests[name]();
        console.log('  ok  ' + name);
    } catch (error) {
        failures++;
        console.log('FAIL  ' + name + '\n      ' + error.message);
    }
}

const total = Object.keys(tests).length;
console.log(failures ? `\n${failures} of ${total} failed` : `\nOK (${total} tests)`);
process.exit(failures ? 1 : 0);
