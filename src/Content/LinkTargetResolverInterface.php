<?php

namespace Dynart\Dpress\Content;

/**
 * What a `media#12` in somebody's markdown actually points at
 *
 * Separate from the rewriting so the two can be tested apart: `InternalLinks` is a walk over a
 * parsed document and has no business knowing what a category is, and this is a handful of
 * lookups with no business knowing what an AST is.
 */
interface LinkTargetResolverInterface {

    /**
     * The full URL of an internal thing, or null when there is no such thing
     *
     * Null is the answer for a purged file and for a deleted post alike - the caller decides
     * what a document does about it, and this only reports that the target is gone.
     *
     * @param string $kind One of the prefixes: `media`, `post`, `page`, `content`, `category`, `tag`
     */
    public function resolve(string $kind, int $id): ?string;
}
