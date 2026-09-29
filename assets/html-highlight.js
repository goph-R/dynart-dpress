/**
 * Colour for an HTML field - a theme's `.phtml` template - painted by the same backdrop as the rest
 *
 * `code-backdrop.js` owns the painting; this is the tokenizer, handed to it as a grammar the way
 * `css-highlight.js` is. It reads what a template is: HTML with **PHP in it** - `<?php ... ?>`
 * and `<?= ... ?>` between the tags and inside an attribute's value, which is where a template
 * prints most of what it prints (`href="<?= esc_attr($url) ?>"`).
 *
 * **One pass, and nothing it does not need.** A tag, its attributes and their values, comments,
 * the doctype, character references; in PHP the strings, the variables, the comments and the
 * keywords a template uses. The body of a `<script>` or a `<style>` is left in the ink colour and
 * read to its closing tag, so a `<` in a comparison is not taken for the start of a tag. It
 * validates nothing: a highlighter that refused markup would be a browser's job done worse.
 */
(function (global) {
    'use strict';

    var Dpress = global.Dpress || {};
    global.Dpress = Dpress;

    var ENTITY = /^&(?:#\d+|#x[0-9a-f]+|[a-z][a-z0-9]*);/i;
    var TAG_NAME = /^[a-z][a-z0-9:-]*/i;
    // what a template's PHP is made of - the control words of the alternative syntax above all
    var KEYWORDS = /^(?:if|else|elseif|endif|foreach|endforeach|for|endfor|while|endwhile|as|echo|print|return|function|fn|use|new|null|true|false|isset|empty|array|and|or|not|instanceof|match|switch|case|default|break|continue|include|require|include_once|require_once)$/i;

    /**
     * The tokens of a template, as non-overlapping `{start, end, type}` in order
     *
     * Types: `tag`, `attr`, `value`, `punct`, `comment`, `doctype`, `entity`, and in PHP `php`
     * (the `<?php` and `?>` themselves), `string`, `variable`, `keyword`, `comment`.
     */
    function tokenize(text) {
        text = text === null || text === undefined ? '' : String(text);
        var spans = [];
        var n = text.length;
        var i = 0;

        function push(start, end, type) {
            if (end > start) {
                spans.push({start: start, end: end, type: type});
            }
        }

        /**
         * A PHP block from its `<?` to its `?>` - or to the end of the file, as PHP reads a file
         * that ends in one. The end is found the way PHP's lexer finds it: a `?>` inside a
         * string is text, and one inside a `//` comment ends the block, comment and all.
         */
        function php(start) {
            var open = /^<\?(?:php\b|=)?/i.exec(text.slice(start, start + 5))[0];
            push(start, start + open.length, 'php');
            var j = start + open.length;
            while (j < n) {
                var c = text[j];
                if (c === '?' && text[j + 1] === '>') {
                    push(j, j + 2, 'php');
                    return j + 2;
                }
                if (c === "'" || c === '"') {
                    var k = j + 1;
                    while (k < n && text[k] !== c) {
                        k += text[k] === '\\' ? 2 : 1;
                    }
                    k = Math.min(n, k + 1);
                    push(j, k, 'string');
                    j = k;
                } else if ((c === '/' && text[j + 1] === '/') || c === '#') {
                    var stop = j;
                    while (stop < n && text[stop] !== '\n' && !(text[stop] === '?' && text[stop + 1] === '>')) {
                        stop++;
                    }
                    push(j, stop, 'comment');
                    j = stop;
                } else if (c === '/' && text[j + 1] === '*') {
                    var shut = text.indexOf('*/', j + 2);
                    var over = shut < 0 ? n : shut + 2;
                    push(j, over, 'comment');
                    j = over;
                } else if (c === '$' && /[a-z_]/i.test(text[j + 1] || '')) {
                    var v = j + 1;
                    while (v < n && /\w/.test(text[v])) {
                        v++;
                    }
                    push(j, v, 'variable');
                    j = v;
                } else if (/[a-z_]/i.test(c) && (j === 0 || !/[\w$]/.test(text[j - 1]))) {
                    var w = j;
                    while (w < n && /\w/.test(text[w])) {
                        w++;
                    }
                    if (KEYWORDS.test(text.slice(j, w))) {
                        push(j, w, 'keyword');
                    }
                    j = w;
                } else {
                    j++;
                }
            }
            return n;
        }

        function isPhp(at) {
            return text[at] === '<' && text[at + 1] === '?';
        }

        /** A quoted attribute value, its PHP taken out of it and coloured as PHP */
        function quoted(start) {
            var quote = text[start];
            var j = start + 1;
            var from = start;
            while (j < n && text[j] !== quote) {
                if (isPhp(j)) {
                    push(from, j, 'value');
                    j = php(j);
                    from = j;
                    continue;
                }
                j++;
            }
            j = Math.min(n, j + 1);
            push(from, j, 'value');
            return j;
        }

        /** A tag from its `<`: the name, the attributes, the end - and where the text resumes */
        function tag(start) {
            var closing = text[start + 1] === '/';
            var j = start + (closing ? 2 : 1);
            push(start, j, 'punct');
            var name = TAG_NAME.exec(text.slice(j, j + 64));
            var tagName = name ? name[0].toLowerCase() : '';
            push(j, j + tagName.length, 'tag');
            j += tagName.length;
            while (j < n) {
                var c = text[j];
                if (isPhp(j)) {
                    j = php(j);
                } else if (c === '>' || (c === '/' && text[j + 1] === '>')) {
                    var end = j + (c === '>' ? 1 : 2);
                    push(j, end, 'punct');
                    j = end;
                    break;
                } else if (c === '"' || c === "'") {
                    j = quoted(j);
                } else if (c === '=') {
                    push(j, j + 1, 'punct');
                    j++;
                    // an unquoted value runs to a space or the end of the tag
                    if (j < n && !/[\s"'>]/.test(text[j]) && !isPhp(j)) {
                        var u = j;
                        while (u < n && !/[\s>]/.test(text[u]) && !isPhp(u)) {
                            u++;
                        }
                        push(j, u, 'value');
                        j = u;
                    }
                } else if (/\s/.test(c)) {
                    j++;
                } else {
                    var a = j;
                    while (a < n && !/[\s=>"']/.test(text[a]) && !(text[a] === '/' && text[a + 1] === '>') && !isPhp(a)) {
                        a++;
                    }
                    if (a > j) {
                        push(j, a, 'attr');
                        j = a;
                    } else {
                        // a stray quote-less `"` or the like: one character, and on
                        push(j, j + 1, 'punct');
                        j++;
                    }
                }
            }
            // the body of a script or a style is not markup: read it to its closing tag, with
            // only the PHP in it coloured
            if (!closing && (tagName === 'script' || tagName === 'style')) {
                var lower = text.toLowerCase();
                var endTag = lower.indexOf('</' + tagName, j);
                var limit = endTag < 0 ? n : endTag;
                while (j < limit) {
                    j = isPhp(j) ? php(j) : j + 1;
                }
            }
            return j;
        }

        while (i < n) {
            if (isPhp(i)) {
                i = php(i);
                continue;
            }
            var c = text[i];
            if (c === '<') {
                if (text.startsWith('<!--', i)) {
                    var close = text.indexOf('-->', i + 4);
                    var end = close < 0 ? n : close + 3;
                    push(i, end, 'comment');
                    i = end;
                    continue;
                }
                if (text[i + 1] === '!') {
                    var shut = text.indexOf('>', i);
                    var over = shut < 0 ? n : shut + 1;
                    push(i, over, 'doctype');
                    i = over;
                    continue;
                }
                if (/[a-z/]/i.test(text[i + 1] || '')) {
                    i = tag(i);
                    continue;
                }
            }
            if (c === '&') {
                var entity = ENTITY.exec(text.slice(i, i + 32));
                if (entity) {
                    push(i, i + entity[0].length, 'entity');
                    i += entity[0].length;
                    continue;
                }
            }
            i++;
        }
        return spans;
    }

    Dpress.html = {
        tokenize: tokenize,
        grammar: {tokenize: tokenize, prefix: 'html-'}
    };

})(typeof window !== 'undefined' ? window : this);
