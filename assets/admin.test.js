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

// --- the tests ---

const tests = {

    'an image is inserted as an image'() {
        const textarea = field('');
        window.Dpress.insertMedia(IMAGE, textarea);
        assert.strictEqual(textarea.value, '![A sunset](/uploads/2026/08/sunset-a1b2c3.jpg)');
    },

    /**
     * The library holds documents too, and a PDF is a link rather than a picture - `![]()` on one
     * renders as a broken image in every reader there is
     */
    'anything that is not an image is inserted as a link'() {
        const textarea = field('');
        window.Dpress.insertMedia(DOCUMENT, textarea);
        assert.strictEqual(textarea.value, '[notes.txt](/uploads/2026/08/notes-d4e5f6.txt)');
    },

    /**
     * An image with no alt text is invisible to somebody using a screen reader, and the item's
     * own alt is the best guess anybody has at the moment of insertion
     */
    'the alt text is the items own, falling back to what there is'() {
        const withTitle = field('');
        window.Dpress.insertMedia({category: 'image', title: 'Just a title', file_name: 'x.jpg', url: '/u/x.jpg'}, withTitle);
        assert.ok(withTitle.value.startsWith('![Just a title]'));

        const withNeither = field('');
        window.Dpress.insertMedia({category: 'image', file_name: 'x.jpg', url: '/u/x.jpg'}, withNeither);
        assert.strictEqual(withNeither.value, '![x.jpg](/u/x.jpg)');
    },

    /**
     * A `]` would close the label early and leave the rest of it loose in the paragraph - and the
     * alt text is whatever somebody typed into the media library
     */
    'a bracket in the alt text is escaped'() {
        const textarea = field('');
        window.Dpress.insertMedia({category: 'image', alt: 'A [very] odd name', file_name: 'x.jpg', url: '/u/x.jpg'}, textarea);
        assert.strictEqual(textarea.value, '![A \\[very\\] odd name](/u/x.jpg)');
    },

    'it lands at the cursor, not at the end'() {
        const textarea = field('before after', 7, 7);
        window.Dpress.insertMedia(IMAGE, textarea);
        assert.strictEqual(textarea.value, 'before ![A sunset](/uploads/2026/08/sunset-a1b2c3.jpg)after');
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
        assert.strictEqual(textarea.value, 'keep ![A sunset](/uploads/2026/08/sunset-a1b2c3.jpg)THIS keep');
    },

    /**
     * Called with nothing to write into, it has to do nothing rather than throw: the button is
     * only rendered next to a markdown field, but nothing enforces that from here
     */
    'no field and no item are both survivable'() {
        window.Dpress.insertMedia(IMAGE, null);
        window.Dpress.insertMedia(null, field(''));
        assert.ok(true);
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
