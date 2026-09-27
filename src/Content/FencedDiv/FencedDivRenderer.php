<?php

namespace Dynart\Dpress\Content\FencedDiv;

use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;

/** A box as `<div id="..." class="...">`, with what it holds rendered inside */
class FencedDivRenderer implements NodeRendererInterface {

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable {
        FencedDiv::assertInstanceOf($node);
        /** @var FencedDiv $node */
        $attributes = [];
        if ($node->id() !== null) {
            $attributes['id'] = $node->id();
        }
        if ($node->classes() !== []) {
            $attributes['class'] = implode(' ', $node->classes());
        }
        $separator = $childRenderer->getBlockSeparator();
        return new HtmlElement(
            'div', $attributes,
            $separator.$childRenderer->renderNodes($node->children()).$separator
        );
    }
}
