<?php

namespace Dynart\Dpress\Media;

use DOMAttr;
use DOMDocument;
use DOMElement;
use Rhukster\DomSanitizer\DOMSanitizer;
use Dynart\Dpress\DpressException;

/**
 * The shipped SVG sanitiser, over `rhukster/dom-sanitizer`
 *
 * MIT, which is why it is this one: `enshrined/svg-sanitize` is the better known package and is
 * GPL-2.0-or-later, so it cannot ship inside an MIT library. A site is free to bind that one to
 * `SvgSanitizerInterface` itself, where the licence is the site's own decision.
 *
 * The library parses the document and keeps only an allowlist of elements and attributes, which
 * is the part that matters: a blocklist of dangerous things is a promise to have thought of all
 * of them, and SVG is a large enough specification that nobody can keep it. `<script>`, `<use>`,
 * `<foreignObject>`, `<animate>` and the rest are dropped, every `on*` handler goes with them
 * because no handler is in the attribute allowlist, and the parser runs with `LIBXML_NONET` and
 * no doctype, so an external entity has nothing to fetch and nothing to expand.
 *
 * Namespaces are **kept**. Stripping `xmlns` would make the file no longer an SVG as far as a
 * browser is concerned - safe, and blank.
 */
class SvgSanitizer implements SvgSanitizerInterface {

    /**
     * Elements this refuses on top of the library's list
     *
     * Kept as our own constant so the reasoning is here rather than in a dependency's private
     * property, and so a change upstream cannot quietly drop one.
     */
    const ALSO_DISALLOWED = ['script', 'foreignobject', 'use', 'handler', 'listener'];

    /**
     * Attributes that may hold an absolute URL, because they are not one
     *
     * A namespace declaration is a URL and has to stay - `xmlns="http://www.w3.org/2000/svg"` is
     * what makes the file an SVG.
     */
    const NAMESPACE_ATTRIBUTES = ['xmlns'];

    /** The only `data:` payloads worth keeping: a raster image cannot execute */
    const DATA_URL = '#^data:image/(png|jpe?g|gif|webp);base64,#i';

    /** An absolute reference to somewhere else - another host, or any scheme-relative URL */
    const EXTERNAL_URL = '#^\s*(?:[a-z][a-z0-9+.-]*:)?//#i';

    /** A CSS `url()` pointing off this site, which the library only catches inside `url('...')` */
    const EXTERNAL_CSS_URL = '#url\(\s*[\'"]?\s*(?:[a-z][a-z0-9+.-]*:)?//#i';

    public function sanitize(string $svg): string {
        if (trim($svg) === '') {
            throw new DpressException('That SVG file is empty.');
        }
        $sanitizer = new DOMSanitizer(DOMSanitizer::SVG);
        $sanitizer->addDisallowedTags(self::ALSO_DISALLOWED);

        $result = $sanitizer->sanitize($svg, [
            // an SVG without its namespace is not an SVG to a browser
            'remove-namespaces' => false,
            'remove-php-tags'   => true,
            'remove-html-tags'  => true,
            'remove-xml-tags'   => true,
            'compress-output'   => false,
        ]);
        $result = $this->removeExternalReferences($result);

        if (trim($result) === '' || stripos($result, '<svg') === false) {
            // either it was never an SVG, or there was nothing left of it once the executable
            // parts were gone - both are a file this site should not be storing
            throw new DpressException('That file could not be read as an SVG.');
        }
        return $result;
    }

    /**
     * Would sanitising remove anything?
     *
     * Counted rather than compared: the output is reserialised, so an untouched file still comes
     * back with different whitespace and a different attribute order, and a byte comparison would
     * report every file in the library as dirty.
     *
     * A doctype is dirty by definition - it is how an entity attack is delivered, and it is
     * something no drawing needs.
     */
    public function isClean(string $svg): bool {
        if (trim($svg) === '') {
            return false;
        }
        if (stripos($svg, '<!DOCTYPE') !== false || stripos($svg, '<?php') !== false) {
            return false;
        }
        $before = $this->countNodes($svg);
        if ($before === null) {
            return false; // it does not parse, so it is not something we would leave alone
        }
        try {
            $after = $this->countNodes($this->sanitize($svg));
        } catch (DpressException $e) {
            return false;
        }
        return $after !== null && $after === $before;
    }

    /**
     * @return array|null [elements, attributes], or null when it does not parse
     */
    protected function countNodes(string $svg): ?array {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return null;
        }
        $elements = 0;
        $attributes = 0;
        foreach ($document->getElementsByTagName('*') as $element) {
            $elements++;
            $attributes += $element->attributes->length;
        }
        return [$elements, $attributes];
    }

    /**
     * Strips every reference that points off this site
     *
     * The library only rejects an external URL inside a CSS `url()`, so a plain
     * `<image href="http://elsewhere/pixel.png">` survives it. Through `<img src>` a browser
     * would not fetch that, but the file is also reachable at its own address, and there it is a
     * tracking pixel firing from this origin.
     *
     * The rule is the blunt one: **no absolute references at all.** A stored illustration should
     * be self-contained; if it genuinely needs a second file, that file belongs in the library
     * too. Losing an outbound link from inside an SVG is a cheap price.
     */
    protected function removeExternalReferences(string $svg): string {
        if (trim($svg) === '') {
            return $svg; // nothing survived the first pass; the caller's own check reports it
        }
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return $svg; // the caller's own check rejects whatever this was
        }
        foreach ($document->getElementsByTagName('*') as $element) {
            /** @var DOMElement $element */
            $this->stripExternalAttributes($element);
        }
        return (string)$document->saveXML();
    }

    protected function stripExternalAttributes(DOMElement $element): void {
        $remove = [];
        foreach (iterator_to_array($element->attributes) as $attribute) {
            /** @var DOMAttr $attribute */
            if ($this->isNamespaceDeclaration($attribute) || !$this->isExternal($attribute->value)) {
                continue;
            }
            $remove[] = $attribute;
        }
        foreach ($remove as $attribute) {
            $element->removeAttributeNode($attribute);
        }
    }

    protected function isNamespaceDeclaration(DOMAttr $attribute): bool {
        return $attribute->prefix === 'xmlns'
            || in_array(strtolower($attribute->name), self::NAMESPACE_ATTRIBUTES, true);
    }

    protected function isExternal(string $value): bool {
        if (preg_match(self::EXTERNAL_URL, $value) || preg_match(self::EXTERNAL_CSS_URL, $value)) {
            return true;
        }
        // a `data:` that is not an inert raster image - `data:text/html` is a document
        return stripos(ltrim($value), 'data:') === 0 && !preg_match(self::DATA_URL, ltrim($value));
    }
}
