/**
 * Colour for a CSS field - a post's Additional CSS - painted by the same backdrop as the markdown
 *
 * `code-backdrop.js` owns the painting: the `<pre>` behind the textarea, the metrics copied
 * across, the scroll kept in step. What a second language needs is only a tokenizer, so this is
 * one, handed to `Dpress.backdrop.attach()` as a grammar. It knows enough CSS to colour what
 * somebody types into a box for one post - selectors, properties, values, the at-rules around
 * them - and nothing about validating it: a highlighter that refused a rule would be a browser's
 * job done worse.
 *
 * **A pass with a stack**, because the one question that decides a colour is where the text
 * stands: a word before `{` is a selector, the same word after `{` is a property. `@media { }`
 * holds rules and `a { }` holds declarations, so a block remembers which it is.
 */
(function (global) {
    'use strict';

    var Dpress = global.Dpress || {};
    global.Dpress = Dpress;

    // the at-rules whose block holds rules rather than declarations
    var RULE_BLOCKS = /^@(media|supports|layer|container|document|scope|starting-style)$/i;

    var IDENT = /[-\w]/;
    var NUMBER = /^-?(?:\d+\.?\d*|\.\d+)(?:[a-z]+|%)?/i;
    var HEX = /^#[0-9a-f]{3,8}\b/i;
    var IMPORTANT = /^!\s*important\b/i;

    /**
     * The tokens of a stylesheet, as non-overlapping `{start, end, type}` in order
     *
     * Types: `comment`, `string`, `at`, `selector`, `property`, `number`, `color`, `important`,
     * `punct`. Anything else - a keyword value, a function name - is left to the ink colour.
     */
    function tokenize(text) {
        text = text === null || text === undefined ? '' : String(text);
        var spans = [];
        var stack = [];          // 'rules' or 'decls', one per open brace
        var atPrelude = null;    // the name of the at-rule whose prelude is being read
        var inValue = false;     // in a declaration block, after the colon
        var i = 0;
        var n = text.length;

        function push(start, end, type) {
            if (end > start) {
                spans.push({start: start, end: end, type: type});
            }
        }

        function inDecls() {
            return stack.length > 0 && stack[stack.length - 1] === 'decls';
        }

        while (i < n) {
            var c = text[i];
            var rest;

            // comments and strings mean the same wherever they stand
            if (c === '/' && text[i + 1] === '*') {
                var close = text.indexOf('*/', i + 2);
                var end = close < 0 ? n : close + 2;
                push(i, end, 'comment');
                i = end;
                continue;
            }
            if (c === '"' || c === "'") {
                var j = i + 1;
                while (j < n && text[j] !== c && text[j] !== '\n') {
                    j += text[j] === '\\' ? 2 : 1;
                }
                j = Math.min(n, j + 1);
                push(i, j, 'string');
                i = j;
                continue;
            }
            if (c === '@') {
                var k = i + 1;
                while (k < n && IDENT.test(text[k])) {
                    k++;
                }
                push(i, k, 'at');
                atPrelude = text.slice(i, k);
                inValue = false;
                i = k;
                continue;
            }
            if (c === '{') {
                push(i, i + 1, 'punct');
                // `@media {` holds rules; a selector's block, and `@font-face {`, declarations
                stack.push(atPrelude !== null && RULE_BLOCKS.test(atPrelude) ? 'rules' : 'decls');
                atPrelude = null;
                inValue = false;
                i++;
                continue;
            }
            if (c === '}') {
                push(i, i + 1, 'punct');
                stack.pop();
                atPrelude = null;
                inValue = false;
                i++;
                continue;
            }
            if (c === ';') {
                push(i, i + 1, 'punct');
                atPrelude = null;
                inValue = false;
                i++;
                continue;
            }

            if (/\s/.test(c)) {
                i++;
                continue;
            }

            // the prelude of an at-rule - `screen and (max-width: 600px)` - is its own thing:
            // numbers are numbers there, and nothing else is a selector or a property
            if (atPrelude !== null) {
                rest = text.slice(i);
                var preludeNumber = NUMBER.exec(rest);
                if (preludeNumber && (i === 0 || !IDENT.test(text[i - 1]))) {
                    push(i, i + preludeNumber[0].length, 'number');
                    i += preludeNumber[0].length;
                } else {
                    i++;
                }
                continue;
            }

            if (!inDecls()) {
                // a selector: everything up to the brace, less the space before it
                var s = i;
                while (i < n && '{};@'.indexOf(text[i]) < 0
                        && !(text[i] === '/' && text[i + 1] === '*')
                        && text[i] !== '"' && text[i] !== "'") {
                    i++;
                }
                var e = i;
                while (e > s && /\s/.test(text[e - 1])) {
                    e--;
                }
                push(s, e, 'selector');
                continue;
            }

            // a declaration block
            if (!inValue) {
                if (IDENT.test(c)) {
                    var p = i;
                    while (i < n && IDENT.test(text[i])) {
                        i++;
                    }
                    push(p, i, 'property');
                    continue;
                }
                if (c === ':') {
                    push(i, i + 1, 'punct');
                    inValue = true;
                    i++;
                    continue;
                }
                i++;
                continue;
            }

            rest = text.slice(i);
            var match;
            if (c === '#' && (match = HEX.exec(rest))) {
                push(i, i + match[0].length, 'color');
                i += match[0].length;
                continue;
            }
            if (c === '!' && (match = IMPORTANT.exec(rest))) {
                push(i, i + match[0].length, 'important');
                i += match[0].length;
                continue;
            }
            if ((match = NUMBER.exec(rest)) && (i === 0 || !IDENT.test(text[i - 1]))) {
                push(i, i + match[0].length, 'number');
                i += match[0].length;
                continue;
            }
            // a word in a value - `solid`, `rgba` - runs to its end, so a digit inside it is not
            // read as the start of a number
            if (IDENT.test(c)) {
                while (i < n && IDENT.test(text[i])) {
                    i++;
                }
                continue;
            }
            i++;
        }
        return spans;
    }

    Dpress.css = {
        tokenize: tokenize,
        grammar: {tokenize: tokenize, prefix: 'css-'}
    };

})(typeof window !== 'undefined' ? window : this);
