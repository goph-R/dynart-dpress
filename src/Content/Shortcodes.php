<?php

namespace Dynart\Dpress\Content;

use Dynart\Micro\LoggerInterface;
use Dynart\Micro\Micro;

/**
 * `{{ name(args) }}` in a document, and what it turns into
 *
 * **A shortcode runs when a page is rendered, not when the post is saved**, and that is the one
 * place this CMS pays for something on every view. It is deliberate: a gallery's contents change
 * without the posts that embed it being touched, and working out which posts to re-render is the
 * referrer-chasing problem that `ContentService::rerenderReferrers()` already shows the shape of.
 *
 * What that costs is bounded, because the *markdown* is still rendered once, at save. Parsing a
 * shortcode is a save-time job; what it leaves behind in `body_html` is a marker:
 *
 *     <!--dpress-sc eyJuIjoidmlkZW8i…-->
 *
 * and `expand()` swaps markers for output on the way to the page. A page with no shortcode in it
 * pays for one `str_contains` over a string that was already in memory.
 *
 * **The marker cannot be forged.** `html_input => 'strip'` means raw HTML never survives from a
 * document into `body_html`, so nothing an author types can become one - and inside a code span
 * it renders escaped, which is what makes a shortcode documentable in the CMS that has them.
 *
 * The payload is base64 rather than plain JSON for one blunt reason: an argument containing
 * `-->` would end the comment early and spill the rest of the post onto the page.
 */
class Shortcodes {

    /** Rendered on its own, out of any paragraph: a video, a gallery, a figure */
    const BLOCK = 'block';

    /** Rendered where it stands, inside the sentence: an icon, a value */
    const INLINE = 'inline';

    const MARKER_PREFIX = '<!--dpress-sc ';
    const MARKER_SUFFIX = '-->';

    /** What `expand()` looks for, and what `render()` writes */
    const MARKER_PATTERN = '/<!--dpress-sc ([A-Za-z0-9+\/=]+)-->/';

    /** @var array name => ['handler' => callable, 'kind' => string] */
    private array $shortcodes = [];

    public function __construct(protected LoggerInterface $logger) {}

    /**
     * @param callable $handler fn(array $arguments): string, returning HTML
     * @param string $kind BLOCK or INLINE - see `liftBlocks()` for why it matters
     */
    public function add(string $name, callable|array $handler, string $kind = self::INLINE): void {
        $this->shortcodes[$name] = ['handler' => $handler, 'kind' => $kind];
    }

    public function has(string $name): bool {
        return isset($this->shortcodes[$name]);
    }

    public function kind(string $name): string {
        return $this->shortcodes[$name]['kind'] ?? self::INLINE;
    }

    /** @return string[] */
    public function names(): array {
        return array_keys($this->shortcodes);
    }

    /**
     * The marker a parsed shortcode leaves in the cached HTML
     */
    public function marker(string $name, array $arguments): string {
        $payload = base64_encode((string)json_encode(['n' => $name, 'a' => $arguments]));
        return self::MARKER_PREFIX.$payload.self::MARKER_SUFFIX;
    }

    /**
     * Swaps every marker in a piece of stored HTML for what its shortcode says today
     *
     * The `str_contains` first is not a micro-optimisation, it is the whole performance story: a
     * site that uses no shortcodes must not pay a regular expression over every page.
     */
    public function expand(?string $html): string {
        $html = (string)$html;
        if ($html === '' || !str_contains($html, self::MARKER_PREFIX)) {
            return $html;
        }
        return (string)preg_replace_callback(self::MARKER_PATTERN, function (array $match): string {
            $decoded = json_decode((string)base64_decode($match[1], true), true);
            if (!is_array($decoded) || !isset($decoded['n'])) {
                return ''; // a marker this version cannot read is not something to print
            }
            return $this->render((string)$decoded['n'], (array)($decoded['a'] ?? []));
        }, $html);
    }

    /**
     * Runs one shortcode
     *
     * An unknown name leaves a comment and a log line rather than an exception or an empty space,
     * which is `FormWidgets` doing the same thing for an unregistered field type. The plugin that
     * provided it may simply be switched off this morning, and a post is not broken content
     * because of that - it is a post with one thing missing, on a page that still renders.
     */
    public function render(string $name, array $arguments): string {
        if (!isset($this->shortcodes[$name])) {
            $this->logger->warning(
                "dpress: no shortcode called '$name'. Registered: ".(join(', ', $this->names()) ?: 'none')
            );
            return '<!-- no shortcode called '.htmlspecialchars($name).' -->';
        }
        // through the container, so a plugin's handler is built when something renders one and
        // never on a page that has none of them
        $handler = Micro::getCallable($this->shortcodes[$name]['handler']);
        return (string)$handler($arguments);
    }
}
