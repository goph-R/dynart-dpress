<?php

namespace Dynart\Dpress\Content\FencedDiv;

use Dynart\Dpress\Content\FencedDivs;
use League\CommonMark\Extension\CommonMark\Parser\Block\FencedCodeParser;
use League\CommonMark\Extension\CommonMark\Parser\Block\HtmlBlockParser;
use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Parser\Block\AbstractBlockContinueParser;
use League\CommonMark\Parser\Block\BlockContinue;
use League\CommonMark\Parser\Block\BlockContinueParserInterface;
use League\CommonMark\Parser\Cursor;

/**
 * An open box, until a line of `:::` closes it
 *
 * **A closing fence closes the innermost box.** CommonMark asks every open container about a line
 * from the outside in, so the outer box sees the inner box's `:::` first - and would take it as
 * its own. Each box asks `FencedDivs` whether it is the innermost one open, and only that one
 * closes; the others let the line through to it.
 *
 * **A `:::` inside a code block is code.** The open block the line would go to is handed in as
 * `$activeBlockParser`, and while that is a fenced code block (or raw HTML, stripped but still a
 * block) the box does not look at the line at all.
 */
class FencedDivParser extends AbstractBlockContinueParser {

    public function __construct(
        private FencedDiv $block,
        private FencedDivs $divs,
    ) {}

    public function getBlock(): AbstractBlock {
        return $this->block;
    }

    public function isContainer(): bool {
        return true;
    }

    public function canContain(AbstractBlock $childBlock): bool {
        return true;
    }

    public function tryContinue(Cursor $cursor, BlockContinueParserInterface $activeBlockParser): ?BlockContinue {
        if ($activeBlockParser instanceof FencedCodeParser || $activeBlockParser instanceof HtmlBlockParser) {
            return BlockContinue::at($cursor);
        }
        if (!$cursor->isIndented()
            && $this->divs->innermost() === $this
            && preg_match(FencedDivs::CLOSE, rtrim(ltrim($cursor->getRemainder(), " \t"))) === 1
        ) {
            return BlockContinue::finished();
        }
        return BlockContinue::at($cursor);
    }

    public function closeBlock(): void {
        $this->divs->closed($this);
    }
}
