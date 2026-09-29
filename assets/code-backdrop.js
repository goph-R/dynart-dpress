/**
 * The backdrop of a code field: its colours and its line numbers, painted behind the textarea
 *
 * One painter for every language. A field is coloured by a *grammar* - a tokenizer, and the
 * prefix its token classes carry - so the markdown field (`code-backdrop.js`) and the CSS
 * field (`css-highlight.js`) are two grammars handed to the same `attach()`, and a third
 * language is a tokenizer, not a second painter.
 *
 * The textarea stays the element: it keeps the value, the selection, the undo stack and the
 * form. What this adds is a `<pre>` sitting exactly underneath it holding the same characters in
 * colour, with the textarea's own text turned transparent over the top. Nothing here can write
 * into the field; with the script off, the field is what it was.
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

    function escapeHtml(text) {
        return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    /**
     * The document as markup for the backdrop, a `<span class="md-line">` to each of its lines
     *
     * **The line spans are what numbers the lines** (`'numbers' => true` on the field): made
     * blocks, each is as tall as its line wrapped, and a CSS counter in front of it puts the
     * number on the line's first row - so a wrapped paragraph or a long table row is one number,
     * however many rows it takes. Without that they are plain inline spans and change nothing.
     * Each keeps its `\n`, so the text is the document character for character either way.
     *
     * A token that ran across lines would have to be closed at the end of one and opened again at
     * the start of the next; none of this grammar's do, but the split below does it anyway.
     *
     * A `<pre>` swallows a final newline, so an empty last line gets a space, or the last line of
     * the field sits a row above the caret.
     */
    function render(text, grammar) {
        text = text === null || text === undefined ? '' : String(text);
        if (text === '') {
            return '';
        }
        var lineClass = grammar.prefix + 'line';
        var out = '';
        var line = '';

        function emit(piece, type) {
            piece.split('\n').forEach(function (part, index) {
                if (index > 0) {
                    out += '<span class="' + lineClass + '">' + line + '\n</span>';
                    line = '';
                }
                if (part === '') {
                    return;
                }
                var inner = escapeHtml(part);
                if (type === 'ref') {
                    // the `#` of `post#12` on its own, so a theme can set it apart - only the
                    // first: `content#5#top` is a reference and then an ordinary fragment
                    inner = inner.replace('#', '<span class="' + grammar.prefix + 'ref-hash">#</span>');
                }
                line += type ? '<span class="' + grammar.prefix + type + '">' + inner + '</span>' : inner;
            });
        }

        if (text.length > LIMIT) {
            emit(text, null);
        } else {
            var at = 0;
            grammar.tokenize(text).forEach(function (span) {
                if (span.start > at) {
                    emit(text.slice(at, span.start), null);
                }
                emit(text.slice(span.start, span.end), span.type);
                at = span.end;
            });
            emit(text.slice(at), null);
        }
        return out + '<span class="' + lineClass + '">' + (line === '' ? ' ' : line) + '</span>';
    }

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
     * Puts a `<pre>` behind a code field and keeps it in step
     *
     * Answers the field's API, which is also left on the element as `dpressHighlight` - the same
     * place a list leaves itself, so anything that changed the value by hand can repaint.
     */
    function attach(textarea, grammar) {
        if (!textarea || textarea.dataset.backdropped || !global.getComputedStyle) {
            return textarea ? textarea.dpressHighlight || null : null;
        }
        textarea.dataset.backdropped = '1';

        var wrapper = document.createElement('div');
        wrapper.className = 'code-field';
        var pre = document.createElement('pre');
        pre.className = 'code-backdrop';
        // numbered lines are the backdrop's to draw - the textarea only makes room for them
        if (textarea.classList.contains('numbered')) {
            pre.classList.add('numbered');
        }
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
            pre.innerHTML = render(textarea.value, grammar);
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
            // The same at the bottom, for a field that scrolls sideways (`'wrap' => false`): its
            // horizontal scrollbar takes height from the textarea, and without this the colours
            // end a scrollbar's height away from the letters once it is scrolled to the end
            var floor = textarea.offsetHeight - textarea.clientHeight
                - (parseFloat(style.borderTopWidth) || 0) - (parseFloat(style.borderBottomWidth) || 0);
            pre.style.paddingBottom = ((parseFloat(style.paddingBottom) || 0) + Math.max(0, floor)) + 'px';
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

    Dpress.backdrop = {
        render: render,
        attach: attach,
        LIMIT: LIMIT
    };

})(typeof window !== 'undefined' ? window : this);
