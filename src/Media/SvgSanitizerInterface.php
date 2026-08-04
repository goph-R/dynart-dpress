<?php

namespace Dynart\Dpress\Media;

use Dynart\Dpress\DpressException;

/**
 * Makes an SVG safe to serve
 *
 * An SVG is a document, not a picture. It can carry `<script>`, event handlers, `<foreignObject>`
 * with arbitrary HTML in it, external references, and XML entities that expand until the parser
 * runs out of memory. Used through `<img src>` a browser will not run any of it, but the file is
 * also reachable by its own URL, and that is a page on this origin.
 *
 * An interface rather than a class, so a site can put its own implementation in the container -
 * a stricter allowlist, a different library, or a service call. Rebinding it to something that
 * returns its input unchanged is how you turn sanitising off, and it should look exactly that
 * deliberate; there is no config flag for it.
 */
interface SvgSanitizerInterface {

    /**
     * @param string $svg The file's own bytes
     * @return string The bytes to store, with anything executable removed
     * @throws DpressException if the content cannot be parsed as SVG at all
     */
    public function sanitize(string $svg): string;

    /**
     * Would sanitising this remove anything?
     *
     * Not "are the bytes identical" - sanitising reserialises the document, so an untouched file
     * still comes back with different whitespace and attribute order. A report built on that
     * would flag every SVG ever stored, and a report that flags everything is one nobody reads.
     * This answers the question actually being asked: is there an element or an attribute here
     * that would not survive?
     */
    public function isClean(string $svg): bool;
}
