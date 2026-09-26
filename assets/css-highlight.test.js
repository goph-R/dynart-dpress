/**
 * A test for the CSS field's colouring, with no toolchain at all
 *
 *   node assets/css-highlight.test.js
 *
 * `tokenize()` is a pure function over a string, like the markdown one, and the painter it is
 * handed to is shared and tested there. What is asserted here is the one judgement a CSS
 * tokenizer makes: where the text stands - before a brace or after it, before a colon or after.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const assert = require('assert');

global.window = global;
eval(fs.readFileSync(path.join(__dirname, 'markdown-highlight.js'), 'utf8'));
eval(fs.readFileSync(path.join(__dirname, 'css-highlight.js'), 'utf8'));

const css = window.Dpress.css;
const markdown = window.Dpress.markdown;

/** Every span of one type, in order, as its text */
function typed(text, type) {
    return css.tokenize(text).filter(span => span.type === type).map(span => text.slice(span.start, span.end));
}

const tests = {

    'a selector is the text before the brace, without the space'() {
        assert.deepStrictEqual(typed('.entry-content table, th > td { color: red }', 'selector'),
            ['.entry-content table, th > td']);
    },

    'a property is the word before the colon, inside a block'() {
        assert.deepStrictEqual(typed('a { color: red; --gap: 4px; }', 'property'), ['color', '--gap']);
    },

    'the same word is a selector outside and a property inside'() {
        const text = 'color { color: red }';
        assert.deepStrictEqual(typed(text, 'selector'), ['color']);
        assert.deepStrictEqual(typed(text, 'property'), ['color']);
    },

    'numbers, colours and important in a value'() {
        const text = 'a { margin: 0 -4px 1.5em 50%; color: #ff79c6 !important; }';
        assert.deepStrictEqual(typed(text, 'number'), ['0', '-4px', '1.5em', '50%']);
        assert.deepStrictEqual(typed(text, 'color'), ['#ff79c6']);
        assert.deepStrictEqual(typed(text, 'important'), ['!important']);
    },

    'a digit inside a word is not a number'() {
        assert.deepStrictEqual(typed('a { font-family: h1font, x2 }', 'number'), []);
    },

    'comments and strings win wherever they are'() {
        const text = '/* a { b: c } */ a::after { content: "} x: y {"; }';
        assert.deepStrictEqual(typed(text, 'comment'), ['/* a { b: c } */']);
        assert.deepStrictEqual(typed(text, 'string'), ['"} x: y {"']);
        // the braces inside the string did not close or open anything
        assert.deepStrictEqual(typed(text, 'property'), ['content']);
    },

    'an unfinished comment runs to the end rather than throwing'() {
        assert.deepStrictEqual(typed('a { } /* still typing', 'comment'), ['/* still typing']);
    },

    '@media holds rules, so what is inside it is selectors again'() {
        const text = '@media (max-width: 600px) { table { width: 100% } }';
        assert.deepStrictEqual(typed(text, 'at'), ['@media']);
        assert.deepStrictEqual(typed(text, 'number'), ['600px', '100%']);
        assert.deepStrictEqual(typed(text, 'selector'), ['table']);
        assert.deepStrictEqual(typed(text, 'property'), ['width']);
    },

    '@font-face holds declarations'() {
        const text = '@font-face { font-family: X; }';
        assert.deepStrictEqual(typed(text, 'property'), ['font-family']);
        assert.deepStrictEqual(typed(text, 'selector'), []);
    },

    'the spans never overlap and never leave the text'() {
        const text = '@media screen { a:hover, b { c: 1px #fff "s" /* x */ } } d { e: f }';
        const spans = css.tokenize(text);
        for (let i = 0; i < spans.length; i++) {
            assert.ok(spans[i].start < spans[i].end && spans[i].end <= text.length);
            if (i > 0) {
                assert.ok(spans[i].start >= spans[i - 1].end, 'overlap at ' + i);
            }
        }
    },

    'the shared painter paints it with its own classes, and escapes the text'() {
        const painted = markdown.render('a { content: "</style>" }', css.grammar);
        assert.ok(painted.includes('<span class="css-selector">a</span>'));
        assert.ok(painted.includes('&lt;/style&gt;'));
        assert.ok(!painted.includes('md-'));
    },

    'the markdown field still paints as markdown'() {
        assert.ok(markdown.render('# Title').includes('class="md-heading"'));
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
