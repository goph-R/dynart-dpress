<?php

namespace Dynart\Dpress\Block;

use Dynart\Micro\ViewInterface;
use Dynart\Dpress\Entity\Block;

/**
 * A Ko-fi button, in a place
 *
 * The one thing a blog wants from Ko-fi is a link to its own page with a cup on it, and their
 * widget does that with an iframe and a script. This is the link: no iframe, no script, nothing
 * loaded from anywhere on a page that does not have the block. The cup is an `<img>` from their
 * CDN, which is the one third-party request left - an image, with no cookie and nothing to run.
 *
 * The colour is the reason this is a block and not something a theme hardcodes: it belongs to
 * whoever set the page up, and a hex value in a settings box is where they already keep it.
 */
class KofiBlock {

    const DEFAULT_TEXT = 'Buy me a coffee';

    /** Ko-fi's own blue, so a block that says nothing about colour still looks like Ko-fi */
    const DEFAULT_COLOR = '#29abe0';

    /** Their cup, at the address they publish it at */
    const ICON = 'https://storage.ko-fi.com/cdn/cup-border.png';

    /**
     * Above this the button gets black text rather than white
     *
     * Relative luminance, weighted the way an eye is - green carries most of it. A site whose
     * brand colour happens to be a pale yellow would otherwise get white text on it, which is a
     * button nobody can read, and the site owner has no way of knowing that is what happened.
     */
    const LIGHT_ABOVE = 0.6;

    public function __construct(protected ViewInterface $view) {}

    public function render(Block $block, array $settings): string {
        $page = $this->page((string)($settings['page'] ?? ''));
        if ($page === '') {
            // nothing to point at is nothing to show, rather than a button that goes to ko-fi.com
            // and asks the reader which of several million pages was meant
            return '';
        }
        $color = $this->color((string)($settings['color'] ?? ''));
        return $this->view->fetch('dpress:block/kofi', [
            'url'         => 'https://ko-fi.com/'.$page,
            'text'        => trim((string)($settings['text'] ?? '')) ?: self::DEFAULT_TEXT,
            'description' => trim((string)($settings['description'] ?? '')),
            'color'       => $color,
            'ink'         => $this->isLight($color) ? '#1b1b1b' : '#ffffff',
            'icon'        => self::ICON,
        ]);
    }

    /**
     * The page name out of whatever was typed
     *
     * The field asks for the bit after the slash, and somebody will paste the whole address
     * anyway - it is the thing in front of them. Both are the same answer, so both are accepted,
     * and anything that is not a page name at all is none: this ends up in an `href`.
     */
    public function page(string $value): string {
        $value = trim($value);
        if (preg_match('~^(?:https?://)?(?:www\.)?ko-?fi\.com/~i', $value) === 1) {
            $value = preg_replace('~^(?:https?://)?(?:www\.)?ko-?fi\.com/~i', '', $value);
        }
        $value = trim((string)$value, '/');
        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value) === 1 ? $value : '';
    }

    /**
     * A hex colour, or Ko-fi's own when it is not one
     *
     * Validated and not escaped, because this goes into a `style` attribute: escaping would stop
     * it breaking out of the quotes, and what has to be impossible is a settings box naming a
     * *declaration*. Three digits are expanded to six so the template has one shape to deal with.
     */
    public function color(string $value): string {
        $value = ltrim(trim($value), '#');
        if (preg_match('/^[0-9A-Fa-f]{3}$/', $value) === 1) {
            $value = $value[0].$value[0].$value[1].$value[1].$value[2].$value[2];
        }
        return preg_match('/^[0-9A-Fa-f]{6}$/', $value) === 1 ? '#'.strtolower($value) : self::DEFAULT_COLOR;
    }

    /**
     * Whether text on this colour should be black
     *
     * @param string $color `#rrggbb`, as `color()` returns
     */
    public function isLight(string $color): bool {
        $hex = ltrim($color, '#');
        $red = hexdec(substr($hex, 0, 2)) / 255;
        $green = hexdec(substr($hex, 2, 2)) / 255;
        $blue = hexdec(substr($hex, 4, 2)) / 255;
        return (0.2126 * $red + 0.7152 * $green + 0.0722 * $blue) > self::LIGHT_ABOVE;
    }
}
