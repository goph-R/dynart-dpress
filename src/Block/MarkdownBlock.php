<?php

namespace Dynart\Dpress\Block;

use Dynart\Dpress\Content\MarkdownRenderer;
use Dynart\Dpress\Entity\Block;

/**
 * Whatever somebody wants to write, beside the content
 *
 * The Ko-fi button is the case this was asked for, and it is a picture inside a link:
 *
 *     [![Buy me a coffee](media#14)](https://ko-fi.com/gopher)
 *
 * which is worth pointing at, because it is three of this CMS's rules meeting. The image is
 * `media#14`, so no path to a file is stored and moving the site cannot break it. It is markdown,
 * so it is the same thing an author writes everywhere else, with the same toolbar and the same
 * media picker. And a `{{ video('media#10') }}` in here works with nothing added, because
 * shortcodes are expanded over the finished page rather than over a piece of content.
 *
 * **Rendered at save**, like `lead_html` and `body_html`: `prepare()` turns the markdown into HTML
 * once and the page view prints it. Which also means `dpress content:rerender` has to come through
 * here - moving the site re-resolves `media#14`, and a block that kept the old URL would be the
 * one thing on the page still pointing at the old address.
 */
class MarkdownBlock {

    public function __construct(protected MarkdownRenderer $markdown) {}

    /**
     * Both halves are kept because both were typed
     *
     * `markdown` is the truth and `html` is a cache of it - the same pair, and the same rule, as a
     * piece of content. A `---` in here is not a lead separator: a block is one thing, not a
     * document with a summary, so the whole of it renders.
     */
    public function prepare(array $settings): array {
        $settings['html'] = $this->markdown->render((string)($settings['markdown'] ?? ''));
        return $settings;
    }

    public function render(Block $block, array $settings): string {
        return (string)($settings['html'] ?? '');
    }
}
