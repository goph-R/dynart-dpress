<?php

namespace Dynart\Dpress\Content\FencedDiv;

use Dynart\Dpress\Content\FencedDivs;
use League\CommonMark\Parser\Block\BlockStart;
use League\CommonMark\Parser\Block\BlockStartParserInterface;
use League\CommonMark\Parser\Cursor;
use League\CommonMark\Parser\MarkdownParserStateInterface;

/**
 * Opens a box at a line of three or more colons that names at least one id or class
 *
 * A bare `:::` opens nothing: it is the closing fence, and a box with nothing to tell it apart
 * would be a `<div>` that does nothing. That is also Pandoc's rule.
 */
class FencedDivStartParser implements BlockStartParserInterface {

    public function __construct(private FencedDivs $divs) {}

    public function tryStart(Cursor $cursor, MarkdownParserStateInterface $parserState): ?BlockStart {
        if ($cursor->isIndented() || $cursor->getNextNonSpaceCharacter() !== ':') {
            return BlockStart::none();
        }
        $attributes = FencedDivs::opening(ltrim($cursor->getRemainder(), " \t"));
        if ($attributes === null) {
            return BlockStart::none();
        }
        $parser = new FencedDivParser(new FencedDiv($attributes['id'], $attributes['classes']), $this->divs);
        $this->divs->opened($parser);
        $cursor->advanceToEnd();
        return BlockStart::of($parser)->at($cursor);
    }
}
