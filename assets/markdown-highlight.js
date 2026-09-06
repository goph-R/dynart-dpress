/**
 * Colour for the markdown field, painted behind the textarea
 *
 * The textarea stays the element. It keeps the value, the selection, the undo stack, the
 * spellchecker and the form; what this adds is a `<pre>` sitting exactly underneath it holding
 * the same characters in colour, with the textarea's own text turned transparent over the top.
 * Nothing here can write into the field, which is the whole reason it is built this way: the
 * content model is "the markdown is the truth", and a highlighter that owns a document is one
 * `save` away from rewriting somebody's post. With the script off, the field is what it was.
 *
 * **The grammar is this site's markdown, not markdown in general.** An off-the-shelf mode knows
 * `**bold**` and nothing about `media#12`, `{{ shortcode() }}`, `> [!WARNING]`, or that a line
 * that is exactly `---` cuts the document in two. Those are the marks worth confirming while
 * typing, so the tokenizer is ours and small enough to read.
 *
 * The separator rule is `MarkdownRenderer::separatorLines()` transcribed - trailing whitespace
 * trimmed, exactly three dashes, no indent, not inside a fence, never line zero. A highlighter
 * that disagreed with the splitter about where a post breaks would be worse than no highlighter.
 */
(function (global) {
    'use strict';

    var Dpress = global.Dpress || {};
    global.Dpress = Dpress;

    /**
     * Above this many characters the text is painted plain
     *
     * The tokenizer is one pass and the paint is one `innerHTML`, both cheap at the size of a
     * post. Somebody pasting a novel in still gets a working field rather than a stuttering one.
     */
    var LIMIT = 100000;

    var SEPARATOR = '---';

    // Mirrors `InternalLinks::PATTERN`: what makes a destination a reference into this site
    var INTERNAL = /^(media|post|page|content|category|tag)#\d+([#?][\s\S]*)?$/;

    // Mirrors `ShortcodeParser::REGEX`
    var SHORTCODE = /\{\{\s*[a-z_][a-z0-9_]*\s*(\((?:[^{}]*)\))?\s*\}\}/g;

    var FENCE = /^\s{0,3}(`{3,}|~{3,})/;
    var FENCE_CLOSE = /^\s{0,3}(`{3,}|~{3,})\s*$/;
    var CODE_SPAN = /(`+)[\s\S]*?\1(?!`)/g;
    var LINK = /(!?)\[((?:[^\[\]\\]|\\[\s\S])*)\]\((<[^>\n]*>|[^\s()]*)((?:\s+"[^"]*")?)\)/g;
    var REFERENCE = /\[((?:[^\[\]\\]|\\[\s\S])*)\]\[([^\]\n]*)\]/g;
    var DEFINITION = /^(\s{0,3})(\[[^\]\n]+\]:)[ \t]*(\S+)/;
    var STRONG = /(\*\*|__)(?=\S)[\s\S]*?\S\1/g;
    var EM = /\*(?!\*)(?=\S)[^*\n]*?\S\*(?!\*)|_(?!_)(?=\S)[^_\n]*?\S_(?!_)/g;
    var URL = /https?:\/\/[^\s<>()\[\]"'`]+/g;
    var HEADING = /^(\s{0,3})(#{1,6})(?=[ \t]|$)/;
    var BULLET = /^(\s*)([-*+]|\d{1,9}[.)])(?=[ \t])/;
    var QUOTE = /^(\s{0,3})>[ \t]?/;
    var CALLOUT = /^\[![A-Za-z]+\]/;
    var RULE = /^\s{0,3}((?:\*[ \t]*){3,}|(?:-[ \t]*){3,}|(?:_[ \t]*){3,}|=+)[ \t]*$/;
    var TABLE_RULE = /^\s{0,3}\|?[ \t:|-]*-[ \t:|-]*$/;

    // --- claiming characters ---

    /**
     * Which token owns each character, and nothing owns one twice
     *
     * A per-character array rather than a list of spans, because the spans have to come out
     * non-overlapping - a `<pre>` cannot render two colours on one letter - and the cheapest way
     * to guarantee that is to make the second claimant lose. Order of the passes below is
     * therefore priority: code claims first, which is why `` `{{ x }}` `` stays code here for the
     * same reason it stays text in the renderer.
     */
    function Marks(length) {
        this.owner = new Array(length);
    }

    Marks.prototype.free = function (start, end) {
        for (var i = start; i < end; i++) {
            if (this.owner[i] !== undefined) {
                return false;
            }
        }
        return true;
    };

    /** All of it or none of it: half a claimed `[link](url)` would colour the brackets of a code span */
    Marks.prototype.claim = function (start, end, type) {
        if (start >= end || start < 0 || end > this.owner.length || !this.free(start, end)) {
            return false;
        }
        for (var i = start; i < end; i++) {
            this.owner[i] = type;
        }
        return true;
    };

    /** Whatever is still bare, for the line-level colours that sit under the inline ones */
    Marks.prototype.fill = function (start, end, type) {
        for (var i = Math.max(0, start); i < Math.min(end, this.owner.length); i++) {
            if (this.owner[i] === undefined) {
                this.owner[i] = type;
            }
        }
    };

    Marks.prototype.spans = function () {
        var out = [];
        var n = this.owner.length;
        var i = 0;
        while (i < n) {
            var type = this.owner[i];
            if (type === undefined) {
                i++;
                continue;
            }
            var start = i;
            while (i < n && this.owner[i] === type) {
                i++;
            }
            out.push({start: start, end: i, type: type});
        }
        return out;
    };

    // --- reading the document ---

    /**
     * The lines, by offset, with the break left out
     *
     * A textarea hands back whatever the document had in it, so `\r\n` is one break and not two -
     * the same thing `lineOfCursor` is careful about, and for the same reason: the separator rule
     * counts lines and being out by one would move where the post breaks.
     */
    function lines(text) {
        var out = [];
        var start = 0;
        var i = 0;
        var n = text.length;
        while (i < n) {
            var code = text.charCodeAt(i);
            if (code === 10) {
                out.push({start: start, end: i});
                i += 1;
                start = i;
            } else if (code === 13) {
                out.push({start: start, end: i});
                i += text.charCodeAt(i + 1) === 10 ? 2 : 1;
                start = i;
            } else {
                i++;
            }
        }
        out.push({start: start, end: n});
        return out;
    }

    function each(pattern, text, callback) {
        pattern.lastIndex = 0;
        var match;
        while ((match = pattern.exec(text)) !== null) {
            if (match[0] === '') {
                pattern.lastIndex++;
                continue;
            }
            callback(match, match.index);
        }
    }

    /**
     * Everything that lives inside a line, in priority order
     */
    function inline(text, marks, from, to) {
        var line = text.slice(from, to);

        // First, so that a shortcode or a link written inside backticks stays code
        each(CODE_SPAN, line, function (match, at) {
            marks.claim(from + at, from + at + match[0].length, 'code-inline');
        });

        each(SHORTCODE, line, function (match, at) {
            marks.claim(from + at, from + at + match[0].length, 'shortcode');
        });

        // A link definition - `[label]: /where` - is a line, so it is anchored rather than swept
        var definition = DEFINITION.exec(line);
        if (definition) {
            var labelAt = from + definition[1].length;
            var target = from + definition[0].length - definition[3].length;
            marks.claim(labelAt, labelAt + definition[2].length, 'marker');
            marks.claim(target, target + definition[3].length, INTERNAL.test(definition[3]) ? 'ref' : 'url');
        }

        each(LINK, line, function (match, at) {
            var bang = match[1].length;
            var textStart = at + bang + 1;
            var textEnd = textStart + match[2].length;
            var destStart = textEnd + 2;
            var destEnd = destStart + match[3].length;
            var titleEnd = destEnd + match[4].length;
            var destination = match[3].replace(/^<|>$/g, '');
            marks.claim(from + at, from + textStart, 'marker');                    // `![`
            marks.claim(from + textStart, from + textEnd, 'link');
            marks.claim(from + textEnd, from + destStart, 'marker');               // `](`
            marks.claim(from + destStart, from + destEnd, INTERNAL.test(destination) ? 'ref' : 'url');
            marks.claim(from + destEnd, from + titleEnd + 1, 'marker');            // the title and `)`
        });

        each(REFERENCE, line, function (match, at) {
            var textEnd = at + 1 + match[1].length;
            marks.claim(from + at, from + at + 1, 'marker');
            marks.claim(from + at + 1, from + textEnd, 'link');
            marks.claim(from + textEnd, from + textEnd + 2, 'marker');             // `][`
            marks.claim(from + textEnd + 2, from + textEnd + 2 + match[2].length, 'url');
            marks.claim(from + at + match[0].length - 1, from + at + match[0].length, 'marker');
        });

        each(STRONG, line, function (match, at) {
            marks.claim(from + at, from + at + match[0].length, 'strong');
        });

        each(EM, line, function (match, at) {
            marks.claim(from + at, from + at + match[0].length, 'em');
        });

        // Whatever is left over is a bare URL, which this site links for the author
        each(URL, line, function (match, at) {
            marks.claim(from + at, from + at + match[0].length, 'url');
        });

        if (/^\s{0,3}\|/.test(line)) {
            each(/\|/g, line, function (match, at) {
                marks.claim(from + at, from + at + 1, 'marker');
            });
        }
    }

    /**
     * One line that is not code and not a separator
     *
     * The quote markers come off first so that `> ## Heading` is both, and the line-level colour
     * goes on last with `fill` so an inline token inside a heading keeps its own.
     */
    function block(text, marks, start, end) {
        var rest = text.slice(start, end);
        var offset = 0;
        var quoted = false;
        var match;

        while ((match = QUOTE.exec(rest)) !== null) {
            marks.claim(start + offset + match[1].length, start + offset + match[1].length + 1, 'marker');
            offset += match[0].length;
            rest = rest.slice(match[0].length);
            quoted = true;
        }
        if (quoted && CALLOUT.test(rest)) {
            marks.claim(start + offset, start + offset + CALLOUT.exec(rest)[0].length, 'callout');
        }

        var rule = RULE.exec(rest);
        if (rule) {
            marks.claim(start + offset, start + offset + rest.replace(/[ \t]+$/, '').length, 'marker');
            return;
        }
        if (TABLE_RULE.test(rest) && rest.indexOf('|') !== -1 && rest.indexOf('-') !== -1) {
            marks.claim(start + offset, end, 'marker');
            return;
        }

        var heading = HEADING.exec(rest);
        if (heading) {
            marks.claim(start + offset + heading[1].length, start + offset + heading[1].length + heading[2].length, 'marker');
        } else {
            var bullet = BULLET.exec(rest);
            if (bullet) {
                marks.claim(start + offset + bullet[1].length, start + offset + bullet[1].length + bullet[2].length, 'list');
            }
        }

        inline(text, marks, start + offset, end);

        if (heading) {
            marks.fill(start + offset, end, 'heading');
        } else if (quoted) {
            marks.fill(start + offset, end, 'quote');
        }
    }

    /**
     * The whole document, as non-overlapping spans in document order
     *
     * Exported because it is the part with the judgement in it, and a pure function over a string
     * is the part worth testing - `markdown-highlight.test.js` never builds a DOM.
     */
    function tokenize(text) {
        text = text === null || text === undefined ? '' : String(text);
        var marks = new Marks(text.length);
        var fence = '';
        lines(text).forEach(function (row, index) {
            var raw = text.slice(row.start, row.end);
            var trimmed = raw.replace(/\s+$/, '');
            if (fence !== '') {
                var close = FENCE_CLOSE.exec(trimmed);
                if (close && close[1].charAt(0) === fence) {
                    marks.claim(row.start, row.end, 'fence');
                    fence = '';
                } else {
                    marks.fill(row.start, row.end, 'code');
                }
                return;
            }
            var open = FENCE.exec(raw);
            if (open) {
                fence = open[1].charAt(0);
                marks.claim(row.start, row.end, 'fence');
                return;
            }
            // A separator on line zero is opening front matter, not a break - `separatorLines()`
            if (index >= 1 && trimmed === SEPARATOR) {
                marks.claim(row.start, row.start + trimmed.length, 'separator');
                return;
            }
            block(text, marks, row.start, row.end);
        });
        return marks.spans();
    }

    function escapeHtml(text) {
        return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    /**
     * The document as markup for the backdrop
     *
     * A `<pre>` swallows a final newline, so a text ending in one gets a space after it or the
     * last line of the field sits a row above the caret.
     */
    function render(text) {
        text = text === null || text === undefined ? '' : String(text);
        var out;
        if (text.length > LIMIT) {
            out = escapeHtml(text);
        } else {
            out = '';
            var at = 0;
            tokenize(text).forEach(function (span) {
                if (span.start > at) {
                    out += escapeHtml(text.slice(at, span.start));
                }
                out += '<span class="md-' + span.type + '">' + escapeHtml(text.slice(span.start, span.end)) + '</span>';
                at = span.end;
            });
            out += escapeHtml(text.slice(at));
        }
        return /\n$/.test(text) ? out + ' ' : out;
    }

    // --- the backdrop ---

    /**
     * The properties the two layers have to agree on, copied rather than duplicated
     *
     * Writing them again in a stylesheet is the bug this feature is prone to: one number drifts
     * and the colours slide off the letters halfway down a long post, on that browser only.
     * Reading them off the textarea means `admin.css` stays the single place the field's metrics
     * are decided and the backdrop cannot disagree with it.
     */
    var METRICS = [
        'fontFamily', 'fontSize', 'fontWeight', 'fontStyle', 'lineHeight', 'letterSpacing',
        'wordSpacing', 'textIndent', 'textTransform', 'whiteSpace', 'wordBreak', 'overflowWrap',
        'tabSize', 'paddingTop', 'paddingBottom', 'paddingLeft',
        'borderTopWidth', 'borderRightWidth', 'borderBottomWidth', 'borderLeftWidth'
    ];

    /**
     * Puts a `<pre>` behind a markdown textarea and keeps it in step
     *
     * Answers the field's API, which is also left on the element as `dpressHighlight` - the same
     * place a list leaves itself, so anything that changed the value by hand can repaint.
     */
    function attach(textarea) {
        if (!textarea || textarea.dataset.markdownHighlighted || !global.getComputedStyle) {
            return textarea ? textarea.dpressHighlight || null : null;
        }
        textarea.dataset.markdownHighlighted = '1';

        var wrapper = document.createElement('div');
        wrapper.className = 'markdown-field';
        var pre = document.createElement('pre');
        pre.className = 'markdown-highlight';
        pre.setAttribute('aria-hidden', 'true');   // the textarea already reads out its own value

        textarea.parentNode.insertBefore(wrapper, textarea);
        wrapper.appendChild(pre);
        wrapper.appendChild(textarea);
        textarea.classList.add('is-highlighted');

        var frame = null;

        function syncScroll() {
            pre.scrollTop = textarea.scrollTop;
            pre.scrollLeft = textarea.scrollLeft;
        }

        function repaint() {
            frame = null;
            pre.innerHTML = render(textarea.value);
            syncScroll();
        }

        /** One paint per frame: a keystroke arrives faster than a browser draws */
        function schedule() {
            if (frame !== null) {
                return;
            }
            frame = global.requestAnimationFrame
                ? global.requestAnimationFrame(repaint)
                : global.setTimeout(repaint, 16);
        }

        function measure() {
            var style = global.getComputedStyle(textarea);
            METRICS.forEach(function (name) {
                pre.style[name] = style[name];
            });
            // A vertical scrollbar takes its width out of the textarea's lines and not out of
            // ours, so without this the two wrap at different columns the moment a post is long
            // enough to scroll - which is every post that matters.
            var gutter = textarea.offsetWidth - textarea.clientWidth
                - (parseFloat(style.borderLeftWidth) || 0) - (parseFloat(style.borderRightWidth) || 0);
            pre.style.paddingRight = ((parseFloat(style.paddingRight) || 0) + Math.max(0, gutter)) + 'px';
        }

        measure();
        repaint();

        textarea.addEventListener('input', schedule);
        textarea.addEventListener('scroll', syncScroll);
        if (global.ResizeObserver) {
            // The field is resizable by the handle, and a scrollbar appears without a resize
            new global.ResizeObserver(function () {
                measure();
                syncScroll();
            }).observe(textarea);
        } else if (global.addEventListener) {
            global.addEventListener('resize', measure);
        }

        var api = {repaint: repaint, measure: measure, element: pre};
        textarea.dpressHighlight = api;
        return api;
    }

    Dpress.markdown = {
        tokenize: tokenize,
        render: render,
        attach: attach,
        LIMIT: LIMIT
    };

})(typeof window !== 'undefined' ? window : this);
