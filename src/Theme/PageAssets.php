<?php

namespace Dynart\Dpress\Theme;

use Dynart\Micro\Micro;

/**
 * What goes into the `<head>` of a page a visitor asked for
 *
 * The front end's half of `FormWidgets` and `Blocks`: a registry, so adding a stylesheet to every
 * page of the site is a registration rather than an edit to somebody's theme. Before this, a
 * plugin could add a field type, a shortcode, a block and a route, and could not put one line into
 * a page - which meant the two things most people would write a plugin for, a set of icons and a
 * button, both ended with *"now open your theme's `head.phtml`"*.
 *
 * **Nothing here is loaded on a page that does not need it.** Each entry carries a `needle`, a
 * plain substring, and the entry is skipped unless the finished HTML contains it - so Font
 * Awesome's stylesheet is on the pages with an icon on them and nowhere else. That is the same
 * test `Shortcodes::expand()` and the highlighter already use, and it is a `str_contains` over a
 * string that is in memory anyway. An empty needle means every page.
 *
 * **It runs over the finished page**, in `AbstractController::render()`, for the reason the
 * shortcodes do: content HTML reaches a template from five places and a theme may render any of
 * them, so what is *on* the page is a fact about the output and not about the controller. A page
 * whose layout has no `</head>` gets nothing, and gets it silently - that is somebody rendering a
 * fragment on purpose.
 */
class PageAssets {

    /** @var array<string, array{tags: string|array, needle: string}> */
    private array $assets = [];

    /**
     * Registers markup for the head
     *
     * @param string $name        What this is, so it can be replaced rather than added twice.
     *                            `plugin:<plugin>:<file>` for a plugin's own, `code` for the
     *                            highlighter.
     * @param string|array $tags  The markup, or a Micro callable `[Class::class, 'method']` taking
     *                            the page HTML and answering with markup. A callable is resolved
     *                            through the container **only when its needle matches**, so a
     *                            registration costs nothing on a page that does not want it.
     * @param string $needle      A substring the finished page must contain. `''` for every page.
     */
    public function add(string $name, string|array|callable $tags, string $needle = ''): void {
        $this->assets[$name] = ['tags' => $tags, 'needle' => $needle];
    }

    /**
     * The two tags anybody actually writes, as markup
     *
     * Static, because they are also what a **closure** registered above needs - a plugin's
     * stylesheet cannot have its URL built at load time, since the loader runs in the CLI too
     * and there is no router to ask there. Registered as a closure, the address is worked out
     * when a page wants the file and never in a `dpress upgrade`.
     */
    public static function styleTag(string $url): string {
        return '<link rel="stylesheet" href="'.htmlspecialchars($url, ENT_QUOTES).'">';
    }

    public static function scriptTag(string $url, bool $defer = true): string {
        return '<script src="'.htmlspecialchars($url, ENT_QUOTES).'"'
            .($defer ? ' defer' : '').'></script>';
    }

    /**
     * A stylesheet, which is the case this exists for
     */
    public function addStyle(string $name, string $url, string $needle = ''): void {
        $this->add($name, self::styleTag($url), $needle);
    }

    /**
     * A script, deferred unless told otherwise
     *
     * `defer` by default because nothing on a dpress page waits for a script - the front end
     * renders without any - and a blocking one in the head is the most reliable way to make a
     * fast site feel slow.
     */
    public function addScript(string $name, string $url, string $needle = '', bool $defer = true): void {
        $this->add($name, self::scriptTag($url, $defer), $needle);
    }

    public function has(string $name): bool {
        return isset($this->assets[$name]);
    }

    /** @return string[] */
    public function names(): array {
        return array_keys($this->assets);
    }

    public function remove(string $name): void {
        unset($this->assets[$name]);
    }

    /**
     * The markup for one page, in registration order
     *
     * @param string $html the finished page, which is what the needles are matched against
     */
    public function tags(string $html): string {
        $found = [];
        foreach ($this->assets as $asset) {
            if ($asset['needle'] !== '' && !str_contains($html, $asset['needle'])) {
                continue;
            }
            $tags = is_string($asset['tags'])
                ? $asset['tags']
                : (string)call_user_func(Micro::getCallable($asset['tags']), $html);
            if ($tags !== '') {
                $found[] = $tags;
            }
        }
        return implode("\n", $found);
    }
}
