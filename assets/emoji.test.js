/**
 * A test for the emoji list and its search, with no toolchain at all
 *
 *   node assets/emoji.test.js
 *
 * The picker is a dialog and is left to the browser; this covers the two parts with mistakes in
 * them. One is a hand-written list of several hundred characters — every failure here is one
 * somebody would otherwise find by opening the picker and seeing a blank square, a duplicate, or
 * a family torn into three people. The other is the search, which is a string in and a list out.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const assert = require('assert');

global.window = global;
eval(fs.readFileSync(path.join(__dirname, 'emoji-words.js'), 'utf8'));
eval(fs.readFileSync(path.join(__dirname, 'emoji.js'), 'utf8'));

const emoji = window.Dpress.emoji;
const groups = emoji.groups();
const every = groups.reduce((all, group) => all.concat(group.items), []);
const characters = every.map(item => item.character);

/** The characters a query answers with, flattened out of its groups */
const found = query => emoji.search(query).reduce((all, g) => all.concat(g.items), [])
    .map(item => item.character);

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
        characters.forEach(one => {
            assert.ok(one.length > 0, 'an empty entry');
            assert.ok(!/\s/.test(one), `whitespace inside ${JSON.stringify(one)}`);
        });
    },

    'nothing appears twice'() {
        const seen = new Map();
        const twice = [];
        groups.forEach(group => group.items.forEach(item => {
            if (seen.has(item.character)) {
                twice.push(`${item.character} in ${seen.get(item.character)} and ${group.name}`);
            } else {
                seen.set(item.character, group.name);
            }
        }));
        assert.deepStrictEqual(twice, []);
    },

    'nothing is ordinary text that wandered in'() {
        characters.forEach(one => {
            assert.ok(!/^[\x00-\x7F]+$/.test(one), `${JSON.stringify(one)} is ASCII, not an emoji`);
        });
    },

    /**
     * Splitting a run of emoji by *character* is the bug the list is written to avoid: a family
     * is several code points joined by a zero-width joiner, and `Array.from` would tear it into
     * pieces that render as separate people. Splitting on a space keeps them whole.
     */
    'a joined sequence survives as one entry'() {
        const joined = characters.filter(one => one.indexOf('‍') !== -1);
        assert.ok(joined.length > 0, 'the list no longer exercises the case it is written for');
        joined.forEach(one => {
            assert.ok(Array.from(one).length > 1, `${one} should be more than one code point`);
        });
    },

    'the ones that need a variation selector still have it'() {
        assert.ok(characters.indexOf('❤️') !== -1, 'the red heart lost its variation selector');
        assert.ok(characters.indexOf('✔️') !== -1, 'the check mark lost its variation selector');
    },

    /**
     * Not an oversight: a curated flag list means choosing which countries are on it, and that is
     * not a decision a CMS should make on a site owner's behalf. The field takes any character.
     */
    'there are no country flags to have to curate'() {
        const flags = characters.filter(one => /[\u{1F1E6}-\u{1F1FF}]/u.test(one));
        assert.deepStrictEqual(flags, []);
    },

    'the list is big enough to be worth a dialog and small enough to read'() {
        assert.ok(characters.length > 300, `only ${characters.length} emoji`);
        // Unicode has around 1,900. The bound is here to catch somebody pasting the whole table
        // in, not to cap a list a person wrote and can still read.
        assert.ok(characters.length < 1300, `${characters.length} looks like a generated table`);
    },

    'the groups are computed once'() {
        assert.strictEqual(emoji.groups(), groups);
    },

    // --- the words, which are what the search searches ---

    /**
     * The map is keyed by the character precisely so it cannot drift out of step with the list.
     * This is what makes that true: an emoji added to `GROUPS` and forgotten in `emojiWords` is a
     * failed test rather than a face that silently answers to nothing.
     */
    'every emoji has words to be found by'() {
        const wordless = every.filter(item => item.words.trim() === '').map(item => item.character);
        assert.deepStrictEqual(wordless, [], 'these have no keywords');
    },

    /** A word left in the map for an emoji no longer on the list is dead weight nobody will spot */
    'no words are left over for an emoji that is gone'() {
        const orphans = Object.keys(window.Dpress.emojiWords).filter(c => characters.indexOf(c) === -1);
        assert.deepStrictEqual(orphans, []);
    },

    'the words are lower case, so a capitalised query still matches'() {
        const shouty = every.filter(item => item.words !== item.words.toLowerCase());
        assert.deepStrictEqual(shouty.map(i => i.character), []);
    },

    // --- the search ---

    'a word finds the obvious thing'() {
        assert.ok(found('sparkle').indexOf('✨') !== -1);
        assert.ok(found('rocket').indexOf('🚀') !== -1);
        assert.ok(found('laugh').indexOf('😂') !== -1);
        assert.ok(found('pear').indexOf('🍐') !== -1);
    },

    /**
     * A prefix, not a substring: `art` answering with `heart` reads as the picker not
     * understanding the question
     */
    'a term matches the start of a word and not the middle of one'() {
        assert.ok(found('hea').indexOf('❤️') !== -1, 'a prefix should match');
        assert.strictEqual(found('art').indexOf('❤️'), -1, 'the middle of "heart" should not');
    },

    /** Terms narrow rather than widen, or `red heart` is every red thing plus every heart */
    'two terms both have to match'() {
        assert.deepStrictEqual(found('red heart'), ['❤️']);
        assert.ok(found('heart').length > 10, 'one term on its own is broad');
    },

    'case and stray spaces do not matter'() {
        assert.deepStrictEqual(found('  ROCKET  '), found('rocket'));
    },

    /**
     * A group with nothing in it is dropped whole, which is what lets the tab row and the sections
     * be the same answer - the tabs cannot show a group the list is not showing
     */
    'a group with no hits is not returned at all'() {
        const result = emoji.search('rocket');
        assert.strictEqual(result.length, 1);
        assert.strictEqual(result[0].name, 'Travel');
        result.forEach(group => assert.ok(group.items.length > 0));
    },

    'a query that matches nothing returns no groups'() {
        assert.deepStrictEqual(emoji.search('xyzzy'), []);
    },

    /** An empty box is not a search: it is the whole list, and the same object every time */
    'an empty query is every group'() {
        assert.strictEqual(emoji.search(''), groups);
        assert.strictEqual(emoji.search('   '), groups);
        assert.strictEqual(emoji.search(null), groups);
        assert.strictEqual(emoji.search(undefined), groups);
    },

    /** Searching must not hand out the arrays the picker will redraw from next time */
    'a search does not alter the groups it filtered'() {
        const before = groups.map(g => g.items.length);
        emoji.search('heart');
        emoji.search('xyzzy');
        assert.deepStrictEqual(groups.map(g => g.items.length), before);
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
console.log(failed ? `\n${failed} of ${count} failed` : `\nOK (${count} tests, ${characters.length} emoji)`);
process.exit(failed ? 1 : 0);
