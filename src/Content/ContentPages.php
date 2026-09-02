<?php

namespace Dynart\Dpress\Content;

/**
 * A body that was written in pages, served one page at a time
 *
 * `[< Previous page] [Next page >]` on a long post, from the same `---` that already separates the
 * lead from the body. The second separator and every one after it is a page break, which means the
 * feature needs no new syntax, no new column and no new field on the editor - somebody who knows
 * how to end a lead already knows how to end a page.
 *
 * **A page with no break costs one `str_contains`**, which is the same guard `Shortcodes::expand()`
 * and `CodeAssets::needed()` use, and for the same reason: a site that never wanted this must not
 * pay for it on every page view.
 */
class ContentPages {

    /**
     * The pages of a rendered body
     *
     * @return string[] one entry for a body that was never broken up, and never zero
     */
    public static function split(?string $html): array {
        $html = (string)$html;
        if (!str_contains($html, MarkdownRenderer::PAGE_MARKER)) {
            return [$html];
        }
        return explode(MarkdownRenderer::PAGE_MARKER, $html);
    }

    public static function count(?string $html): int {
        return count(self::split($html));
    }

    /**
     * One page of a body, counted from 1
     *
     * Out of range answers with `null` rather than with the first page, so a caller can tell the
     * difference between "page 7" of a three page post and page 1 - one of those is a 404 and the
     * other is a post.
     */
    public static function page(?string $html, int $number): ?string {
        $pages = self::split($html);
        return $pages[$number - 1] ?? null;
    }
}
