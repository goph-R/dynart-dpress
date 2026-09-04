<?php

namespace Dynart\Dpress\Theme;

use Dynart\Micro\Micro;
use Dynart\Micro\RouterInterface;

/**
 * A theme's own stylesheet, fonts and pictures, served out of the theme folder
 *
 * A theme that is only templates has nowhere to put a design. The built-in layout answers that
 * with one inline `<style>`, which is honest for a layout with forty rules in it and untenable
 * for a real theme with a typeface and a header image - and the alternative, `public/static/`,
 * splits a theme across two folders so that copying one is copying two, and uninstalling one
 * leaves the other behind.
 *
 * So a theme keeps its files in its own `assets/`, exactly as a plugin does, and this serves
 * them. **A theme stays one folder**, which is the whole promise a theme folder makes.
 *
 * ### Flat, on purpose
 *
 * One folder, no subfolders. Two things fall out of that and both are worth more than `fonts/`
 * would be: a name matched against `[A-Za-z0-9_-]+\.[A-Za-z0-9]+` cannot climb anywhere, so
 * there is no traversal to reason about at all; and a `url(hero.png)` written inside
 * `style.css` resolves against `/assets/theme/` and simply works, which it would not if the
 * stylesheet lived a folder deeper than the picture.
 *
 * ### The active theme, and no other
 *
 * The name is not in the URL. There is one theme rendering, its files are the only ones anybody
 * has asked for, and a name in the URL would be a way to read out of any folder under `themes/`
 * whether the site is using it or not. The same rule as a plugin's assets, which are served only
 * for a plugin the loader actually loaded.
 */
class ThemeAssets {

    /** Where a theme keeps them */
    const FOLDER = 'assets';

    const ROUTE = '/assets/theme/';

    /**
     * What a theme may serve, by extension
     *
     * Wider than a plugin's three, because a plugin ships behaviour and a theme ships a design:
     * a typeface and a background are not extras. Everything here is inert - there is no `html`
     * and there is no extension the browser will execute in this site's origin beyond the `js`
     * a theme's own layout asks for.
     */
    const TYPES = [
        'css'   => 'text/css; charset=utf-8',
        'js'    => 'application/javascript; charset=utf-8',
        'svg'   => 'image/svg+xml',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'webp'  => 'image/webp',
        'avif'  => 'image/avif',
        'ico'   => 'image/x-icon',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'otf'   => 'font/otf',
    ];

    public function __construct(protected ThemeService $themes) {}

    /**
     * The URL of a file in the active theme's `assets/`, or '' when no theme is active
     *
     * **Cache-busted by the theme's own version**, not by the CMS's. A theme is released on its
     * own schedule and is the only thing that can change these files, so a version bump in
     * `theme.ini` is what should reach a visitor's cache - and upgrading dpress should not expire
     * a font nothing touched.
     *
     * A theme's template is only ever rendered while that theme is active, so the empty string is
     * for a caller that is not a theme.
     *
     * @param bool $versioned false for a file **named** after its own contents - a font, above
     *   all. A `url()` inside the stylesheet carries no version, because a stylesheet cannot know
     *   one; so a `<link rel=preload>` built with a version is a *different URL* from the one the
     *   `@font-face` then asks for, and the browser downloads the font twice while the preload
     *   helps nothing. The two have to agree, and the one that cannot change is the CSS.
     */
    public function url(string $file, bool $versioned = true): string {
        $theme = $this->themes->get($this->themes->active());
        if ($theme === null) {
            return '';
        }
        $params = [];
        if ($versioned) {
            $params['v'] = $theme['version'] !== '' ? $theme['version'] : '0';
        }
        return Micro::get(RouterInterface::class)->url(self::ROUTE.$file, $params);
    }

    /**
     * The file on disk and what to send it as, or `null` for anything that is not both
     *
     * One method rather than a controller doing it, so the rule about what a theme may serve is
     * in one place and testable without a request.
     *
     * @return array|null ['path' => string, 'type' => string]
     */
    public function file(string $name): ?array {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9]+$/', $name) !== 1
            || !isset(self::TYPES[$extension])) {
            return null;
        }
        $active = $this->themes->active();
        if ($active === ThemeService::FALLBACK) {
            return null;
        }
        $path = $this->themes->path().'/'.$active.'/'.self::FOLDER.'/'.$name;
        return is_file($path) ? ['path' => $path, 'type' => self::TYPES[$extension]] : null;
    }

    /**
     * Whether the active theme has this asset
     *
     * For a template that wants to link something **optional** - a theme that works with or
     * without an icon set, say, where the alternative is a `<link>` to a 404 on every page of
     * every site that never installed one. The same rule `file()` serves by, so a template
     * cannot be told yes about a file the route would then refuse.
     */
    public function exists(string $file): bool {
        return $this->file($file) !== null;
    }
}
