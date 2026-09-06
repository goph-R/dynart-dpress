/**
 * A test for the markdown field's colouring, with no toolchain at all
 *
 *   node assets/markdown-highlight.test.js
 *
 * `tokenize()` is a pure function over a string, so nothing here builds a DOM: the part of this
 * feature with judgement in it is which characters belong to which token, and that is answerable
 * with an array. The backdrop that paints them is wiring and is left to the browser.
 *
 * The separator cases are the ones to keep: they are the same document `MarkdownRenderer` splits,
 * and a highlighter that disagrees with the splitter tells an author their post breaks somewhere
 * it does not.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const assert = require('assert');

global.window = global;
eval(fs.readFileSync(path.join(__dirname, 'markdown-highlight.js'), 'utf8'));

const markdown = window.Dpress.markdown;

/** The tokens of `text`, as `type:the exact characters` - readable enough to assert against */
function tokens(text) {
    return markdown.tokenize(text).map(span => span.type + ':' + text.slice(span.start, span.end));
}

/** Every span of one type, in order */
function typed(text, type) {
    return markdown.tokenize(text).filter(span => span.type === type).map(span => text.slice(span.start, span.end));
}

function overlaps(text) {
    const spans = markdown.tokenize(text);
    for (let i = 1; i < spans.length; i++) {
        if (spans[i].start < spans[i - 1].end) {
            return true;
        }
    }
    return false;
}

const tests = {

    // --- the separator, which is the one that changes what a reader sees ---

    /**
     * `MarkdownRenderer::separatorLines()` starts at line 1: a `---` on line zero is somebody
     * opening front matter, not cutting the post in half
     */
    'a separator on the first line is not a separator'() {
        assert.deepStrictEqual(typed('---\ntitle: x\n---\nbody', 'separator'), ['---']);
    },

    'every later separator is one'() {
        assert.deepStrictEqual(typed('lead\n---\npage two\n---\npage three', 'separator'), ['---', '---']);
    },

    /**
     * The rule is `rtrim($line) === '---'`, so four dashes is a thematic break and an indented
     * three is nothing. Both stay visibly *not* the thing that breaks the page.
     */
    'only exactly three dashes at the margin count'() {
        assert.deepStrictEqual(typed('a\n----\nb\n   ---\nc\n--- \nd', 'separator'), ['---'],
            'the only separator here is the one with trailing space, which rtrim removes');
    },

    /**
     * A post about YAML front matter has `---` inside a fence, and splitting there would tear the
     * document at the exact place its author was writing about
     */
    'a separator inside a fenced block is not a separator'() {
        const text = 'intro\n```yaml\n---\nkey: value\n---\n```\nafter';
        assert.deepStrictEqual(typed(text, 'separator'), []);
        assert.deepStrictEqual(typed(text, 'code'), ['---', 'key: value', '---']);
    },

    'a tilde fence closes on tildes and not on backticks'() {
        const text = 'a\n~~~\n```\n---\n~~~\nb';
        assert.deepStrictEqual(typed(text, 'separator'), []);
        assert.deepStrictEqual(typed(text, 'fence'), ['~~~', '~~~']);
    },

    'an unclosed fence runs to the end of the document'() {
        assert.deepStrictEqual(typed('a\n```\n---\nstill code', 'separator'), []);
    },

    // --- this site's own syntax ---

    'an internal reference is not coloured as a plain URL'() {
        assert.deepStrictEqual(typed('see ![A sunset](media#12) here', 'ref'), ['media#12']);
        assert.deepStrictEqual(typed('see ![A sunset](media#12) here', 'url'), []);
    },

    'every internal kind counts, and a fragment rides along'() {
        const text = '[a](post#1) [b](page#2) [c](category#3) [d](tag#4) [e](content#5#top)';
        assert.deepStrictEqual(typed(text, 'ref'), ['post#1', 'page#2', 'category#3', 'tag#4', 'content#5#top']);
    },

    'a destination that is not one of ours is a URL'() {
        assert.deepStrictEqual(typed('[x](https://example.com)', 'url'), ['https://example.com']);
        assert.deepStrictEqual(typed('[x](media#notanumber)', 'ref'), []);
    },

    'a bare URL is coloured, because this site links it'() {
        assert.deepStrictEqual(typed('go to https://example.com/a?b=1 now', 'url'), ['https://example.com/a?b=1']);
    },

    'a shortcode is one token, arguments and all'() {
        assert.deepStrictEqual(typed("a {{ video('media#13') }} b", 'shortcode'), ["{{ video('media#13') }}"]);
        assert.deepStrictEqual(typed('{{ reading_time }}', 'shortcode'), ['{{ reading_time }}']);
    },

    /**
     * The renderer parses shortcodes as an inline parser, so a code span already claimed the text
     * before one is offered it. The colouring says the same thing, by claiming code first.
     */
    'a shortcode inside backticks stays code'() {
        const text = "write `{{ video('media#13') }}` to embed";
        assert.deepStrictEqual(typed(text, 'shortcode'), []);
        assert.deepStrictEqual(typed(text, 'code-inline'), ["`{{ video('media#13') }}`"]);
    },

    'a callout label is its own token'() {
        assert.deepStrictEqual(typed('> [!WARNING] mind the gap', 'callout'), ['[!WARNING]']);
    },

    'a bracket at the start of an ordinary quote is not a callout'() {
        assert.deepStrictEqual(typed('> [a](https://example.com) said so', 'callout'), []);
    },

    // --- ordinary markdown ---

    'a heading is its marker and then the line'() {
        assert.deepStrictEqual(tokens('## Title'), ['marker:##', 'heading: Title']);
    },

    'a link inside a heading keeps its own colour'() {
        assert.deepStrictEqual(typed('# See [this](https://example.com)', 'link'), ['this']);
    },

    'bold and italic are told apart'() {
        assert.deepStrictEqual(typed('**one** and _two_ and *three*', 'strong'), ['**one**']);
        assert.deepStrictEqual(typed('**one** and _two_ and *three*', 'em'), ['_two_', '*three*']);
    },

    'a list marker is coloured and its text is not'() {
        assert.deepStrictEqual(typed('- one\n* two\n3. three', 'list'), ['-', '*', '3.']);
    },

    /** Two markers side by side come out as one span, which is what the painter wants */
    'a quote marker nests'() {
        assert.deepStrictEqual(typed('>> deep', 'marker'), ['>>']);
        assert.deepStrictEqual(typed('> > deep', 'marker'), ['>', '>']);
    },

    'a table delimiter row is one marker and the pipes above it are markers'() {
        assert.deepStrictEqual(typed('| a | b |\n| --- | --- |', 'marker'), ['|', '|', '|', '| --- | --- |']);
    },

    'an em dash of underscores is a rule and not a heading'() {
        assert.deepStrictEqual(typed('text\n\n___\n\nmore', 'marker'), ['___']);
    },

    'a reference-style link and its definition both colour'() {
        const text = 'see [the docs][d]\n\n[d]: https://example.com';
        assert.deepStrictEqual(typed(text, 'link'), ['the docs']);
        assert.deepStrictEqual(typed(text, 'url'), ['d', 'https://example.com']);
    },

    // --- the properties the backdrop depends on ---

    /**
     * A `<pre>` cannot paint two colours on one character. If two tokens ever overlapped the
     * spans would come out of order and the paint would drop or double text.
     */
    'no two tokens claim the same character'() {
        const document = [
            '---', 'title: Everything', '---', '',
            '# A heading with `code` and **bold**', '',
            '> [!NOTE] a callout with [a link](post#3)', '',
            '- a list item with {{ shortcode() }}',
            '- and ![a picture](media#7 "titled")', '',
            '```php', '$x = "---";', '```', '',
            'Bare https://example.com/x and | a pipe |', '', '---', '', 'page two'
        ].join('\n');
        assert.strictEqual(overlaps(document), false);
    },

    /**
     * Every span has to sit inside the text, or `slice` in the painter silently produces a
     * document shorter than the one in the field
     */
    'the spans stay inside the document'() {
        const text = '# h\n> q\n- l\n`c`\n[a](media#1)\n---\n';
        markdown.tokenize(text).forEach(span => {
            assert.ok(span.start >= 0 && span.end <= text.length && span.start < span.end,
                `${span.type} ${span.start}..${span.end} is outside 0..${text.length}`);
        });
    },

    'the painted text is the document, character for character'() {
        const text = '# A & B <tag>\n\n> quote\n\n```\ncode & more\n```\n';
        const painted = markdown.render(text).replace(/<\/?span[^>]*>/g, '')
            .replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
        assert.strictEqual(painted.replace(/ $/, ''), text);
    },

    /**
     * The field holds a post, which is somebody else's text going into `innerHTML`
     */
    'markup in the document is escaped'() {
        const painted = markdown.render('<img src=x onerror="alert(1)">');
        assert.ok(painted.indexOf('<img') === -1, 'a tag survived into the backdrop');
        assert.ok(painted.indexOf('&lt;img') !== -1);
    },

    /** A `<pre>` swallows the last newline, and the caret then sits a row below the paint */
    'a document ending in a newline keeps its last line'() {
        assert.ok(/ $/.test(markdown.render('a\n')));
        assert.ok(!/ $/.test(markdown.render('a')));
    },

    'an empty document paints nothing and does not throw'() {
        assert.strictEqual(markdown.render(''), '');
        assert.deepStrictEqual(markdown.tokenize(''), []);
        assert.deepStrictEqual(markdown.tokenize(null), []);
    },

    /** Above the limit the text is still the text, just without the colours */
    'a very long document is painted plain'() {
        const text = '# heading\n'.repeat(Math.ceil(markdown.LIMIT / 10) + 1);
        assert.ok(text.length > markdown.LIMIT);
        assert.strictEqual(markdown.render(text).indexOf('<span'), -1);
    },

    /**
     * A textarea hands back whatever the document had in it, and Windows puts `\r\n` in one -
     * counted as two, every line after the first is out by its own number and the separator on
     * line one would be read as the one on line zero
     */
    'a CRLF is one line break'() {
        assert.deepStrictEqual(typed('---\r\ntitle\r\n---\r\nbody', 'separator'), ['---']);
        assert.deepStrictEqual(typed('lead\r\n---\r\nbody', 'separator'), ['---']);
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
