<?php

namespace Dynart\Dpress;

/**
 * Constants of the CMS itself
 */
class Dpress {

    const VERSION = '0.78.2';

    /** The file that marks the root of a dpress installation */
    const CONFIG_FILE_NAME = 'dpress.ini';

    /** The translation namespace of the CMS */
    const TRANSLATION_NAMESPACE = 'dpress';

    /** The view namespace of the CMS */
    const VIEW_NAMESPACE = 'dpress';

    /**
     * The admin's own view namespace, which a theme cannot reach
     *
     * Separate from `dpress:` for one reason: themeability is decided per namespace, and these
     * are the templates a theme must not be able to replace. A theme swapping the front end's
     * `single.phtml` is the whole point of themes; a theme swapping the admin's layout is
     * somebody locked out of their own site.
     */
    const ADMIN_VIEW_NAMESPACE = 'dpress_admin';

    /**
     * The active theme's own folder, for the templates that are the theme's own
     *
     * `dpress:` is for **overrides** - a theme puts `dpress/content/single.phtml` in its folder
     * and replaces the CMS's. This is for everything else a theme writes: the header two layouts
     * share, a card partial, a footer. Registered by `ThemeService::apply()` and only while a
     * theme is active, which is the only time anything can ask for it.
     */
    const THEME_VIEW_NAMESPACE = 'theme';

    /**
     * An absolute path inside the package
     *
     * The CMS ships its own views and translations, and they live wherever Composer put the
     * package rather than under the site's root path - so they cannot use the `~` alias.
     */
    public static function path(string $relative = ''): string {
        $root = dirname(__DIR__);
        return $relative === '' ? $root : $root.'/'.ltrim($relative, '/');
    }

    public static function viewsPath(): string {
        return self::path('views');
    }

    /**
     * The admin's icons: plain SVG files, not templates
     *
     * Outside `views/` because they hold no PHP and are never rendered as a template, and
     * outside `assets/` because nothing serves them over HTTP - they are inlined into the page
     * so the drawing takes the colour of whatever it sits in.
     */
    public static function iconsPath(): string {
        return self::path('icons');
    }

    /** @var array<string, string> one read of each icon per request */
    private static array $icons = [];

    /**
     * An admin icon, as the inline SVG markup it is drawn with
     *
     * Static so a template can ask for one where it draws a button - `Dpress::icon('save')` -
     * without every controller handing each button's drawing over. An icon this package does not
     * have falls back to a generic mark rather than a gap. **The result is markup**: ours, from
     * the package's own folder, so there is nothing to sanitise - but nothing may build the name
     * out of a request.
     */
    public static function icon(string $name): string {
        $path = self::iconsPath().'/'.$name.'.svg';
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $name) || !is_file($path)) {
            $path = self::iconsPath().'/section.svg';
        }
        return self::iconFile($path);
    }

    /**
     * An icon from a file named by its full path - a plugin's own, for its admin section
     *
     * The caller decides the path may be read; `PluginService` only hands over one inside the
     * plugin's folder. A file that is not there, or holds no `<svg`, is the generic mark.
     */
    public static function iconFile(string $path): string {
        if (!isset(self::$icons[$path])) {
            // From the `<svg` on: `section.svg` carries a PHP comment saying what it is for, and a
            // file read as text rather than rendered would put that comment into the page.
            $markup = is_file($path) ? (string)@file_get_contents($path) : '';
            $start = stripos($markup, '<svg');
            self::$icons[$path] = $start === false
                ? ($path === self::iconsPath().'/section.svg' ? '' : self::icon('section'))
                : trim(substr($markup, $start));
        }
        return self::$icons[$path];
    }

    public static function translationsPath(): string {
        return self::path('translations');
    }
}
