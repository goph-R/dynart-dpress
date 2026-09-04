<?php

namespace Dynart\Dpress\Content\Shortcode;

/**
 * `{{ br }}` - a line break where markdown has no way to write one
 *
 * Markdown has two already: two trailing spaces, and a trailing backslash. Both need the line to
 * *end*, which is exactly what a table cell cannot do - the whole row is one line, so a cell is
 * inline content with no way to break it. GFM's own answer is `<br>` in the cell, and raw HTML is
 * stripped here (`html_input => 'strip'`) so that a compromised account cannot put a script on
 * every page.
 *
 * So: the one piece of markup markdown cannot express in the one place it is needed, spelled as a
 * shortcode rather than by opening HTML back up. It writes `<br />` and nothing else - the form
 * CommonMark itself emits for a hard break, so a document does not end up with two spellings of
 * one thing.
 *
 * **Outside a table it is the wrong tool.** Two trailing spaces are markdown, read as a break by
 * every editor and every other renderer, and a paragraph is what separates paragraphs. This is for
 * the case that has no alternative.
 */
class BreakShortcode {

    /**
     * @param array $arguments none are read - a line break has nothing to configure
     */
    public function render(array $arguments): string {
        return '<br />';
    }
}
