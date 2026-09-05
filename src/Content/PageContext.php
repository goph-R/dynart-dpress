<?php

namespace Dynart\Dpress\Content;

use Dynart\Dpress\Entity\Content;

/**
 * What piece of content the page being rendered is *about*, if any
 *
 * A block renderer is handed `(Block $block, array $settings)` and nothing else, which is right -
 * a tag cloud has no business knowing where it is. But some blocks are entirely about where they
 * are: a comments block has to name the thread it belongs to, and "related posts" has to know what
 * it is related to. Without this they can be put in a place and cannot answer the one question they
 * exist to answer.
 *
 * **A service rather than an argument.** Passing the content down through `Places::render()` was
 * the alternative and it only solves half of it: a *shortcode* is not rendered through a place, and
 * `{{ comments }}` in the middle of a post wants exactly the same answer.
 *
 * **Empty is the normal case, not a failure.** The front page, an archive, a category listing and
 * every admin screen have no content of their own, and a block that needs one should render
 * *nothing* there rather than guess. `has()` is the question to ask; on the front page the honest
 * answer for a comments block is no comments.
 *
 * It lives exactly as long as the request does, like everything else in the container. Nothing
 * writes it but `AbstractController::renderContent()`, which is the one place a post or a page is
 * turned into HTML.
 */
class PageContext {

    private ?Content $content = null;

    public function set(?Content $content): void {
        $this->content = $content;
    }

    /**
     * The content being viewed, or null on a page that is not about one
     */
    public function content(): ?Content {
        return $this->content;
    }

    public function has(): bool {
        return $this->content !== null;
    }

    /**
     * The id of the content being viewed, or 0
     *
     * The common question, and worth answering directly: an id is what a plugin keys its own row
     * on, and `$context->content()?->id ?? 0` at every call site is the sort of line that gets
     * written once correctly and then copied wrongly.
     */
    public function id(): int {
        return $this->content === null ? 0 : (int)$this->content->id;
    }

    public function isPost(): bool {
        return $this->content !== null && $this->content->isPost();
    }
}
