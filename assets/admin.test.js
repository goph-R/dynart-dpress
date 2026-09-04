/**
 * A test for the admin's own behaviour, with no toolchain at all
 *
 *   node assets/admin.test.js
 *
 * `dynamic-list.test.js` covers the list; this covers the pieces in `admin.js` that have logic
 * of their own rather than wiring. Right now that is inserting a library item into the markdown
 * field - the one place where a string is built that somebody's document then contains forever.
 *
 * The DOM here is smaller than the list's, because the only element these touch is a textarea.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const assert = require('assert');

// --- the smallest DOM this needs ---

global.window = global;
global.document = {
    addEventListener() {},          // swallows the DOMContentLoaded hook
    querySelector() { return null; },
    querySelectorAll() { return []; },
    createElement() { return {style: {}, dataset: {}, classList: {add() {}, toggle() {}}, appendChild() {}, addEventListener() {}}; },
    body: {getAttribute() { return null; }}
};

eval(fs.readFileSync(path.join(__dirname, 'admin.js'), 'utf8'));

/**
 * A textarea with a cursor: `value`, a selection and `focus()` is all `replaceSelection` uses
 */
function field(value, start, end) {
    return {
        value: value,
        selectionStart: start === undefined ? value.length : start,
        selectionEnd: end === undefined ? (start === undefined ? value.length : start) : end,
        focus() {}
    };
}

const IMAGE = {
    id: 1, category: 'image', file_name: 'sunset.jpg', alt: 'A sunset', title: 'Sunset Photo',
    url: '/uploads/2026/08/sunset-a1b2c3.jpg'
};
const DOCUMENT = {
    id: 2, category: 'document', file_name: 'notes.txt', alt: '', title: '',
    url: '/uploads/2026/08/notes-d4e5f6.txt'
};

/** The target select as the server renders it: every value says which kind it is */
const TARGETS = [
    {value: '', text: '(none)'},
    {value: 'content:12', text: 'Post: Something'},
    {value: 'content:120', text: 'Page: Something else'},
    {value: 'category:3', text: 'Category: Retro'},
    {value: 'category:4', text: 'Category: AI'},
    {value: 'tag:7', text: 'Tag: DOS'}
];


// --- a filter form, its events, and control over the clock ---

/**
 * `bindFilters` schedules through `setTimeout`, so the test owns the clock rather than waiting
 */
let pending = [];
global.setTimeout = function (fn) { pending.push(fn); return pending.length; };
global.clearTimeout = function (id) { if (id) { pending[id - 1] = null; } };
function runTimers() {
    const due = pending;
    pending = [];
    due.forEach(fn => fn && fn());
}

global.FormData = class {
    constructor(form) { this.form = form; }
    forEach(callback) { (this.form.fields || []).forEach(f => callback(f.value, f.name)); }
};

function filterForm(fields) {
    const listeners = {};
    return {
        fields,
        addEventListener(type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
        fire(type, target) { (listeners[type] || []).forEach(fn => fn({target: target})); }
    };
}

function countingList() {
    return {asked: 0, applyFilters() { this.asked++; }};
}

const SELECT = {tagName: 'SELECT'};
const SEARCH = {tagName: 'INPUT', type: 'search'};

// --- the tests ---

const tests = {

    /**
     * A `<select>` fires `input` and then `change`. Two listeners meant two requests for one
     * choice - one at once and one 250 ms later - which is what somebody watching the network
     * tab on the media page saw.
     */
    'choosing a filter asks once'() {
        const form = filterForm([{name: 'category', value: 'image'}]);
        const list = countingList();
        window.Dpress.bindFilters(form, list);
        form.fire('input', SELECT);
        form.fire('change', SELECT);
        runTimers();
        assert.strictEqual(list.asked, 1);
    },

    /**
     * Typing fires `input` per keystroke and `change` on blur, and the blur adds nothing: the
     * filters are already what they were when the debounce ran
     */
    'typing and then leaving the box asks once'() {
        const field = {name: 'search', value: ''};
        const form = filterForm([field]);
        const list = countingList();
        window.Dpress.bindFilters(form, list);
        field.value = 'f';   form.fire('input', SEARCH);
        field.value = 'fo';  form.fire('input', SEARCH);
        field.value = 'fox'; form.fire('input', SEARCH);
        runTimers();
        assert.strictEqual(list.asked, 1, 'the keystrokes did not coalesce');
        form.fire('change', SEARCH);
        runTimers();
        assert.strictEqual(list.asked, 1, 'leaving the box asked again for what was on screen');
    },

    'a filter that actually moves asks again'() {
        const field = {name: 'category', value: 'image'};
        const form = filterForm([field]);
        const list = countingList();
        window.Dpress.bindFilters(form, list);
        form.fire('change', SELECT);
        runTimers();
        field.value = 'video';
        form.fire('change', SELECT);
        runTimers();
        assert.strictEqual(list.asked, 2);
    },

    /**
     * `DynamicList` binds `submit` itself when it is handed a form, so binding it here too put
     * two handlers on Enter
     */
    'submit is left to the list'() {
        const form = filterForm([]);
        const list = countingList();
        window.Dpress.bindFilters(form, list);
        form.fire('submit', SEARCH);
        runTimers();
        assert.strictEqual(list.asked, 0);
    },

    'an image is inserted as an image'() {
        const textarea = field('');
        window.Dpress.insertMedia(IMAGE, textarea);
        assert.strictEqual(textarea.value, '![A sunset](media#1)');
    },

    /**
     * The row carries a finished URL and it is deliberately not used
     *
     * A document names what it points at, and the server works out where that is when it renders.
     * Writing the URL instead would put this site's hostname inside somebody's markdown, and
     * moving from a test domain to a real one would mean rewriting every post that has a picture.
     */
    'the destination is the reference, never the URL the row also carries'() {
        const textarea = field('');
        window.Dpress.insertMedia(IMAGE, textarea);
        assert.ok(!textarea.value.includes(IMAGE.url), 'the URL was written into the document');
        assert.ok(textarea.value.includes('media#' + IMAGE.id));
    },

    /**
     * The library holds documents too, and a PDF is a link rather than a picture - `![]()` on one
     * renders as a broken image in every reader there is
     */
    'anything that is not an image is inserted as a link'() {
        const textarea = field('');
        window.Dpress.insertMedia(DOCUMENT, textarea);
        assert.strictEqual(textarea.value, '[notes.txt](media#2)');
    },

    /**
     * An image with no alt text is invisible to somebody using a screen reader, and the item's
     * own alt is the best guess anybody has at the moment of insertion
     */
    'the alt text is the items own, falling back to what there is'() {
        const withTitle = field('');
        window.Dpress.insertMedia({id: 3, category: 'image', title: 'Just a title', file_name: 'x.jpg'}, withTitle);
        assert.ok(withTitle.value.startsWith('![Just a title]'));

        const withNeither = field('');
        window.Dpress.insertMedia({id: 3, category: 'image', file_name: 'x.jpg'}, withNeither);
        assert.strictEqual(withNeither.value, '![x.jpg](media#3)');
    },

    /**
     * A `]` would close the label early and leave the rest of it loose in the paragraph - and the
     * alt text is whatever somebody typed into the media library
     */
    'a bracket in the alt text is escaped'() {
        const textarea = field('');
        window.Dpress.insertMedia({id: 4, category: 'image', alt: 'A [very] odd name', file_name: 'x.jpg'}, textarea);
        assert.strictEqual(textarea.value, '![A \\[very\\] odd name](media#4)');
    },

    'it lands at the cursor, not at the end'() {
        const textarea = field('before after', 7, 7);
        window.Dpress.insertMedia(IMAGE, textarea);
        assert.strictEqual(textarea.value, 'before ![A sunset](media#1)after');
    },

    /**
     * A selection is kept, not overwritten
     *
     * Inserting a picture is not a reason to delete what somebody had highlighted - which may
     * simply be where they last dragged the mouse. It goes in at the start of the selection and
     * the text stays put; the alternative loses work silently and is not undoable in a textarea
     * in every browser.
     */
    'a selection survives the insert'() {
        const textarea = field('keep THIS keep', 5, 9);
        window.Dpress.insertMedia(IMAGE, textarea);
        assert.strictEqual(textarea.value, 'keep ![A sunset](media#1)THIS keep');
    },

    /**
     * Called with nothing to write into, it has to do nothing rather than throw: the button is
     * only rendered next to a markdown field, but nothing enforces that from here
     */
    'no field and no item are both survivable'() {
        window.Dpress.insertMedia(IMAGE, null);
        window.Dpress.insertMedia(null, field(''));
        assert.ok(true);
    },

    // --- the menu item editor: what "Points at" decides about the other two fields ---

    /**
     * The question that started it: choosing *A category* should leave the categories
     */
    'a kind narrows the target list to its own'() {
        const shown = window.Dpress.targetOptionsFor('category', TARGETS).map(o => o.value);
        assert.deepStrictEqual(shown, ['', 'category:3', 'category:4']);
    },

    /**
     * `(none)` belongs to every kind - a target is not required, and an item with nothing
     * chosen has to be something the editor can still say
     */
    'every kind keeps the empty option'() {
        ['content', 'category', 'tag', 'url', 'home'].forEach(kind => {
            const shown = window.Dpress.targetOptionsFor(kind, TARGETS);
            assert.strictEqual(shown[0].value, '', kind + ' lost (none)');
        });
    },

    /**
     * `content:12` must not be read as a prefix of `content:120`, and no kind's name may be
     * a prefix of another's - the colon is what makes both true
     */
    'a kind matches on the whole word'() {
        const shown = window.Dpress.targetOptionsFor('tag', TARGETS).map(o => o.value);
        assert.deepStrictEqual(shown, ['', 'tag:7']);
    },

    /**
     * The front page and an external address point at nothing in the library, so the target
     * select is not narrowed - it goes
     */
    'the kinds that point at nothing local offer no target at all'() {
        assert.deepStrictEqual(window.Dpress.targetFieldsFor('home'), {target: false, url: false});
        assert.deepStrictEqual(window.Dpress.targetFieldsFor('url'), {target: false, url: true});
    },

    /**
     * And the reverse, which is the bug it was reported as: Address is only ever for an
     * external address, so it is not there to be filled in under a kind that ignores it
     */
    'only an external address offers an address'() {
        ['content', 'category', 'tag'].forEach(kind => {
            assert.deepStrictEqual(window.Dpress.targetFieldsFor(kind), {target: true, url: false});
        });
    },

    'an unknown kind offers neither, rather than everything'() {
        assert.deepStrictEqual(window.Dpress.targetFieldsFor(''), {target: false, url: false});
        assert.deepStrictEqual(window.Dpress.targetOptionsFor('', TARGETS).map(o => o.value), ['']);
    }
};

// --- runner ---

let failed = 0;
Object.keys(tests).forEach(name => {
    try {
        tests[name]();
        console.log('  ok  ' + name);
    } catch (error) {
        failed++;
        console.log('  FAIL  ' + name);
        console.log('        ' + error.message);
    }
});
const count = Object.keys(tests).length;
console.log(failed ? `\n${failed} of ${count} failed` : `\nOK (${count} tests)`);
process.exit(failed ? 1 : 0);
