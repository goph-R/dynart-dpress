<?php

namespace Dynart\Dpress;

/**
 * Constants of the CMS itself
 */
class Dpress {

    const VERSION = '0.27.4';

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

    public static function translationsPath(): string {
        return self::path('translations');
    }
}
