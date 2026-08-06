<?php

namespace Dynart\Dpress\Content;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Inline\AbstractWebResource;
use League\CommonMark\Node\Node;

/**
 * Turns `media#12` and `post#42` in a document into the URLs they mean
 *
 * A stored document names *what* it points at, never *where* that is today. `app.base_url` is in
 * the ini and the slug is in a row, so a site that moves host - test to production - or a post
 * that is renamed does not need a single character of anybody's markdown changed. The URL is
 * worked out when the markdown is rendered, which is at save time, so a page view pays nothing
 * for any of this.
 *
 * Only a link or an image destination is looked at. Writing "see issue #42" in a sentence has to
 * stay a sentence, and a code block showing somebody how to write a reference has to keep
 * showing it.
 */
class InternalLinks {

    /**
     * `<kind>#<id>`, optionally carrying a fragment or a query
     *
     * `post`, `page` and `content` are the same lookup. Content ids are unique across both types
     * and the entity decides the shape of its own URL, so `post#5` naming a page still resolves
     * rather than failing on a technicality nobody writing prose should have to know.
     */
    const PATTERN = '/^(media|post|page|content|category|tag)#(\d+)([#?].*)?$/';

    public function __construct(protected LinkTargetResolverInterface $targets) {}

    /**
     * Subscribed to `MarkdownRenderer::EVENT_ENVIRONMENT`
     */
    public function onEnvironment(EnvironmentBuilderInterface $environment): void {
        $environment->addEventListener(DocumentParsedEvent::class, [$this, 'onDocumentParsed']);
    }

    /**
     * On the parsed document rather than on the rendered HTML
     *
     * A URL is a field of a node here, and an unresolved one is a node to remove - both are exact.
     * The same two operations on a string of HTML would be a pair of regular expressions over
     * markup, and the second of them would have to match a whole `<a>` element with whatever is
     * nested inside it.
     */
    public function onDocumentParsed(DocumentParsedEvent $event): void {
        $found = [];
        foreach ($event->getDocument()->iterator() as $node) {
            if (!$node instanceof AbstractWebResource) {
                continue;
            }
            if (preg_match(self::PATTERN, $node->getUrl(), $matches)) {
                $found[] = [$node, $matches];
            }
        }
        // collected first, changed after: unwrapping detaches nodes, and a walk that is having
        // the tree rearranged underneath it is a bug waiting for the document that triggers it
        $resolved = [];
        foreach ($found as [$node, $matches]) {
            // one picture used twice is one lookup. Only within this document, though - the
            // answers go stale the moment anything is renamed, and re-rendering after a rename
            // is exactly when that happens
            $key = $matches[1].'#'.$matches[2];
            if (!array_key_exists($key, $resolved)) {
                $resolved[$key] = $this->targets->resolve($matches[1], (int)$matches[2]);
            }
            $url = $resolved[$key];
            if ($url === null) {
                $this->unwrap($node);
                continue;
            }
            $node->setUrl($url.($matches[3] ?? ''));
        }
    }

    /**
     * Replaces a node with whatever was inside it
     *
     * What a reference to something that is gone should leave behind: `[the old post](post#42)`
     * becomes the words "the old post", and `![Screenshot](media#12)` becomes "Screenshot",
     * because an image's alt text *is* its children. One operation covers both.
     *
     * The alternative is leaving `media#12` in a `src`, which is a broken image on a published
     * page - the visitor pays for the editor's deleted file.
     */
    protected function unwrap(Node $node): void {
        $child = $node->firstChild();
        while ($child !== null) {
            $next = $child->next(); // insertBefore() detaches, taking this with it
            $node->insertBefore($child);
            $child = $next;
        }
        $node->detach();
    }
}
