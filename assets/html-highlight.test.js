/**
 * A test for the HTML field's colouring, with no toolchain at all
 *
 *   node assets/html-highlight.test.js
 *
 * `tokenize()` is a pure function over a string. What is asserted is what a theme's template
 * is: HTML with PHP in it, between the tags and inside the attribute values.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const assert = require('assert');

global.window = global;
eval(fs.readFileSync(path.join(__dirname, 'code-backdrop.js'), 'utf8'));
eval(fs.readFileSync(path.join(__dirname, 'html-highlight.js'), 'utf8'));

const html = window.Dpress.html;

/** Every span of one type, in order, as its text */
function typed(text, type) {
    return html.tokenize(text).filter(span => span.type === type).map(span => text.slice(span.start, span.end));
}

const tests = {

    'a tag, its attributes and their values'() {
        const text = '<a class="read-more" href=/x data-id=\'7\'>More</a>';
        assert.deepStrictEqual(typed(text, 'tag'), ['a', 'a']);
        assert.deepStrictEqual(typed(text, 'attr'), ['class', 'href', 'data-id']);
        assert.deepStrictEqual(typed(text, 'value'), ['"read-more"', '/x', "'7'"]);
        assert.deepStrictEqual(typed(text, 'punct'), ['<', '=', '=', '=', '>', '</', '>']);
    },

    'the text between the tags is left alone'() {
        assert.strictEqual(typed('<p>Hello, world</p>', 'value').length, 0);
        assert.ok(!html.tokenize('<p>Hello</p>').some(span => '<p>Hello</p>'.slice(span.start, span.end).includes('Hello')));
    },

    'PHP inside an attribute value is PHP, and the quotes around it are the value'() {
        const text = '<a href="<?= esc_attr($url) ?>">';
        assert.deepStrictEqual(typed(text, 'value'), ['"', '"']);
        assert.deepStrictEqual(typed(text, 'php'), ['<?=', '?>']);
        assert.deepStrictEqual(typed(text, 'variable'), ['$url']);
    },

    'a template\'s control words, strings and comments'() {
        const text = "<?php foreach ($posts as $post): // each one ?><?php endforeach ?>";
        assert.deepStrictEqual(typed(text, 'keyword'), ['foreach', 'as', 'endforeach']);
        assert.deepStrictEqual(typed(text, 'variable'), ['$posts', '$post']);
        assert.deepStrictEqual(typed(text, 'comment'), ['// each one ']);
    },

    /** as PHP reads it: `?>` in a string is text, and in a `//` comment it ends the block */
    'a ?> in a string does not end the block, and in a line comment it does'() {
        const inString = "<?php $a = 'x ?> y'; ?><p>";
        assert.deepStrictEqual(typed(inString, 'string'), ["'x ?> y'"]);
        assert.deepStrictEqual(typed(inString, 'php'), ['<?php', '?>']);
        assert.deepStrictEqual(typed(inString, 'tag'), ['p']);
        const inComment = '<?php // note ?><b>';
        assert.deepStrictEqual(typed(inComment, 'comment'), ['// note ']);
        assert.deepStrictEqual(typed(inComment, 'tag'), ['b']);
    },

    'a word that only contains a keyword is not one'() {
        assert.deepStrictEqual(typed('<?php $asset = format(); ?>', 'keyword'), []);
    },

    'comments, the doctype and character references'() {
        const text = '<!doctype html><!-- the <b>head</b> --><p>&amp; &#169; &nbsp</p>';
        assert.deepStrictEqual(typed(text, 'doctype'), ['<!doctype html>']);
        assert.deepStrictEqual(typed(text, 'comment'), ['<!-- the <b>head</b> -->']);
        assert.deepStrictEqual(typed(text, 'entity'), ['&amp;', '&#169;']);
        assert.deepStrictEqual(typed(text, 'tag'), ['p', 'p'], 'nothing inside the comment is a tag');
    },

    /** `if (a < b)` in a script is a comparison, not a tag */
    'the body of a script is not markup, but its PHP is PHP'() {
        const text = '<script>if (a <b) { x = "<?= $y ?>"; }</script><p>';
        assert.deepStrictEqual(typed(text, 'tag'), ['script', 'script', 'p']);
        assert.deepStrictEqual(typed(text, 'variable'), ['$y']);
    },

    'a self-closing tag ends at its slash'() {
        assert.deepStrictEqual(typed('<img src="a.png" alt=""/>', 'punct'), ['<', '=', '=', '/>']);
    },

    'an unclosed PHP block runs to the end, the way PHP reads a file that ends in one'() {
        const text = '<p><?php $x = 1;\n$y = 2;';
        assert.deepStrictEqual(typed(text, 'variable'), ['$x', '$y']);
        assert.deepStrictEqual(typed(text, 'php'), ['<?php']);
    },

    'the spans never overlap and never run backwards'() {
        const text = fs.readFileSync(path.join(__dirname, '..', 'views', 'layout.phtml'), 'utf8');
        let at = 0;
        for (const span of html.tokenize(text)) {
            assert.ok(span.start >= at && span.end > span.start, 'a span at ' + span.start);
            at = span.end;
        }
    },

    'the backdrop paints it character for character'() {
        const text = '<a href="<?= $u ?>">x &amp; y</a>\n';
        const painted = window.Dpress.backdrop.render(text, html.grammar);
        const back = painted.replace(/<\/?span[^>]*>/g, '').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
        assert.strictEqual(back, text + ' ');
        assert.ok(painted.includes('class="html-line"'));
        assert.ok(painted.includes('class="html-php"'));
    }
};

let failed = 0;
for (const [name, test] of Object.entries(tests)) {
    try {
        test();
        console.log('  ok  ' + name);
    } catch (error) {
        failed++;
        console.log('  FAIL  ' + name + '\n        ' + error.message);
    }
}
console.log(failed ? '\n' + failed + ' of ' + Object.keys(tests).length + ' failed' : '\nOK (' + Object.keys(tests).length + ' tests)');
process.exitCode = failed ? 1 : 0;
