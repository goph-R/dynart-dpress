<?php

namespace Dynart\Dpress\Content;

use League\CommonMark\Node\Inline\AbstractInline;

/**
 * One `{{ name(args) }}` in a parsed document
 *
 * It holds the *call*, never the output. What gets written into `body_html` is a marker, and the
 * handler runs when a page is rendered - see `Shortcodes` for why that is the whole point.
 *
 * An inline node even for a block shortcode: CommonMark parses inline content inside a paragraph,
 * so there is no other kind of node to be at this stage. `Shortcodes::liftBlocks()` moves the
 * block ones out of their paragraph afterwards.
 */
class ShortcodeNode extends AbstractInline {

    public function __construct(
        private string $name,
        private array $arguments,
    ) {
        parent::__construct();
    }

    public function getName(): string {
        return $this->name;
    }

    public function getArguments(): array {
        return $this->arguments;
    }
}
