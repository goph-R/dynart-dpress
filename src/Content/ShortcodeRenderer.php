<?php

namespace Dynart\Dpress\Content;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;

/**
 * Wires shortcodes into the markdown renderer, and writes their markers
 *
 * Subscribed to `MarkdownRenderer::EVENT_ENVIRONMENT`, the way `InternalLinks` is - the renderer
 * still knows nothing about what a shortcode is.
 *
 * What this writes is the marker, never the output: see `Shortcodes` for why the handler runs on
 * the page rather than here.
 */
class ShortcodeRenderer implements NodeRendererInterface {

    public function __construct(protected Shortcodes $shortcodes) {}

    /**
     * Subscribed to `MarkdownRenderer::EVENT_ENVIRONMENT`
     */
    public function onEnvironment(EnvironmentBuilderInterface $environment): void {
        $environment->addInlineParser(new ShortcodeParser($this->shortcodes));
        $environment->addRenderer(ShortcodeNode::class, $this);
        $environment->addEventListener(DocumentParsedEvent::class, [$this, 'onDocumentParsed']);
    }

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string {
        if (!$node instanceof ShortcodeNode) {
            return '';
        }
        // deliberately not escaped and deliberately not an `HtmlElement`: this is a comment that
        // `Shortcodes::expand()` finds again, and its payload is already base64
        return $this->shortcodes->marker($node->getName(), $node->getArguments());
    }

    /**
     * Takes a block shortcode out of the paragraph CommonMark wrapped it in
     *
     * Everything inline is parsed inside a block, so a `{{ video(…) }}` alone on a line arrives
     * as the only child of a `Paragraph`. Left there, the marker expands inside a `<p>` - which
     * is merely untidy around a `<video>` and **invalid** around a `<figure>` or a `<div>`, and
     * a browser does not leave invalid nesting alone, it rearranges it.
     *
     * Only when it is the whole paragraph. A block shortcode in the middle of a sentence is an
     * author asking for something that cannot be done, and quietly tearing their paragraph in
     * half is a worse answer than rendering it where they put it.
     */
    public function onDocumentParsed(DocumentParsedEvent $event): void {
        $lift = [];
        foreach ($event->getDocument()->iterator() as $node) {
            if (!$node instanceof Paragraph) {
                continue;
            }
            $first = $node->firstChild();
            if ($first instanceof ShortcodeNode
                && $first->next() === null
                && $this->shortcodes->kind($first->getName()) === Shortcodes::BLOCK) {
                $lift[] = [$node, $first];
            }
        }
        // collected first, moved after: detaching while iterating rearranges the tree under the
        // walk, which is the bug `InternalLinks` leaves a comment about for the same reason
        foreach ($lift as [$paragraph, $shortcode]) {
            $paragraph->insertBefore($shortcode);
            $paragraph->detach();
        }
    }
}
