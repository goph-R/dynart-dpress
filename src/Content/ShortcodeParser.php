<?php

namespace Dynart\Dpress\Content;

use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;

/**
 * Reads `{{ name(arguments) }}` out of a document
 *
 * **An inline parser rather than a regular expression over the markdown**, which is what makes a
 * shortcode documentable. CommonMark claims a code span and a fenced block before any inline
 * parser is offered the text, so `` `{{ video('media#13') }}` `` stays four words and a pair of
 * braces on the screen. A regex over the source cannot tell those apart from the real thing, and
 * a CMS whose own documentation cannot be written in it is a CMS with a bad idea in it.
 *
 * `\{\{` escapes, and always did: `{` is ASCII punctuation, so CommonMark's backslash escapes
 * cover it without anything here.
 */
class ShortcodeParser implements InlineParserInterface {

    /**
     * The whole call, in one match
     *
     * Deliberately ungreedy up to the first `}}`: a shortcode does not nest, so the first closer
     * is the right one, and a runaway `{{` cannot swallow the rest of a paragraph.
     */
    const REGEX = '\{\{\s*([a-z_][a-z0-9_]*)\s*(\((?:[^{}]*)\))?\s*\}\}';

    public function __construct(protected Shortcodes $shortcodes) {}

    public function getMatchDefinition(): InlineParserMatch {
        return InlineParserMatch::regex(self::REGEX);
    }

    public function parse(InlineParserContext $inlineContext): bool {
        $matches = $inlineContext->getSubMatches();
        $name = $matches[0] ?? '';
        if ($name === '') {
            return false;
        }
        // Left as text when nothing registered the name. Not an error and not silence: a document
        // is written before the plugin providing a shortcode is installed as often as after, and
        // the marker is what would otherwise be baked into the page permanently.
        if (!$this->shortcodes->has($name)) {
            return false;
        }
        $arguments = ShortcodeArguments::parse($matches[1] ?? '');
        if ($arguments === null) {
            return false; // malformed: leave the author their own text back rather than eat it
        }
        $inlineContext->getCursor()->advanceBy(strlen($inlineContext->getFullMatch()));
        $inlineContext->getContainer()->appendChild(new ShortcodeNode($name, $arguments));
        return true;
    }
}
