/**
 * A test for the emoji list, with no toolchain at all
 *
 *   node assets/emoji.test.js
 *
 * The picker is a dialog and is left to the browser; this covers the part with the mistakes in
 * it, which is a hand-written list of several hundred characters. Every failure here is one
 * somebody would otherwise find by opening the picker and seeing a blank square, a duplicate, or
 * a family torn into three people.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const assert = require('assert');

global.window = global;
eval(fs.readFileSync(path.join(__dirname, 'emoji.js'), 'utf8'));

const groups = window.Dpress.emoji.groups();
const every = groups.reduce((all, group) => all.concat(group.items), []);

const tests = {

    'every group has a name and something in it'() {
        assert.ok(groups.length >= 6, 'a picker with a handful of groups is a list');
        groups.forEach(group => {
            assert.ok(typeof group.name === 'string' && group.name !== '', 'a group with no name');
            assert.ok(group.items.length > 20, `${group.name} has only ${group.items.length}`);
        });
    },

    /**
     * The list is split on a space, so an entry containing one would have been two entries and
     * an entry containing none is what every emoji actually is
     */
    'no entry is empty or contains whitespace'() {
        every.forEach(one => {
            assert.ok(one.length > 0, 'an empty entry');
            assert.ok(!/\s/.test(one), `whitespace inside ${JSON.stringify(one)}`);
        });
    },

    /**
     * A duplicate is a curation slip and shows as the same face twice in one grid
     */
    'nothing appears twice'() {
        const seen = new Map();
        const twice = [];
        groups.forEach(group => group.items.forEach(one => {
            if (seen.has(one)) {
                twice.push(`${one} in ${seen.get(one)} and ${group.name}`);
            } else {
                seen.set(one, group.name);
            }
        }));
        assert.deepStrictEqual(twice, []);
    },

    /**
     * An entry that is plain ASCII is a typo - a stray letter left in the string reads as an
     * emoji nobody can see
     */
    'nothing is ordinary text that wandered in'() {
        every.forEach(one => {
            assert.ok(!/^[\x00-\x7F]+$/.test(one), `${JSON.stringify(one)} is ASCII, not an emoji`);
        });
    },

    /**
     * Splitting a run of emoji by *character* is the bug this list is written to avoid: a family
     * is several code points joined by a zero-width joiner, and `Array.from` would tear it into
     * pieces that render as separate people. Splitting on a space keeps them whole.
     */
    'a joined sequence survives as one entry'() {
        const joined = every.filter(one => one.indexOf('‍') !== -1);
        assert.ok(joined.length > 0, 'the list no longer exercises the case it is written for');
        joined.forEach(one => {
            assert.ok(Array.from(one).length > 1, `${one} should be more than one code point`);
        });
    },

    /**
     * A variation selector is what makes a character render as the colour emoji rather than the
     * black-and-white glyph, and it is the thing most easily lost copying a list around
     */
    'the ones that need a variation selector still have it'() {
        assert.ok(every.indexOf('❤️') !== -1, 'the red heart lost its variation selector');
        assert.ok(every.indexOf('✔️') !== -1, 'the check mark lost its variation selector');
    },

    /**
     * Not an oversight: a curated flag list means choosing which countries are on it, and that is
     * not a decision a CMS should make on a site owner's behalf. The field takes any character.
     */
    'there are no country flags to have to curate'() {
        const flags = every.filter(one => /[\u{1F1E6}-\u{1F1FF}]/u.test(one));
        assert.deepStrictEqual(flags, []);
    },

    'the list is big enough to be worth a dialog and small enough to read'() {
        assert.ok(every.length > 300, `only ${every.length} emoji`);
        // Unicode has around 1,900. The bound is here to catch somebody pasting the whole table
        // in, not to cap a list a person wrote and can still read.
        assert.ok(every.length < 1300, `${every.length} looks like a generated table`);
    },

    /** Split once and kept, because the picker asks for them every time it opens */
    'the groups are computed once'() {
        assert.strictEqual(window.Dpress.emoji.groups(), groups);
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
console.log(failed ? `\n${failed} of ${count} failed` : `\nOK (${count} tests, ${every.length} emoji)`);
process.exit(failed ? 1 : 0);
