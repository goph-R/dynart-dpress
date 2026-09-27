<?php

namespace Dynart\Dpress\Content\FencedDiv;

use League\CommonMark\Node\Block\AbstractBlock;

/**
 * A box: `::: {#an-id .a-class}` ... `:::`, rendered as a `<div>` around what it holds
 *
 * See `FencedDivs` for the syntax and the rules.
 */
class FencedDiv extends AbstractBlock {

    /**
     * @param string[] $classes
     */
    public function __construct(
        private ?string $id = null,
        private array $classes = [],
    ) {
        parent::__construct();
    }

    public function id(): ?string {
        return $this->id;
    }

    /** @return string[] */
    public function classes(): array {
        return $this->classes;
    }
}
