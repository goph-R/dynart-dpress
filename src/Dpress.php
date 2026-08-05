<?php

namespace Dynart\Dpress;

/**
 * Constants of the CMS itself
 */
class Dpress {

    const VERSION = '0.14.1';

    /** The file that marks the root of a dpress installation */
    const CONFIG_FILE_NAME = 'dpress.ini';

    /** The translation namespace of the CMS */
    const TRANSLATION_NAMESPACE = 'dpress';

    /** The view namespace of the CMS */
    const VIEW_NAMESPACE = 'dpress';

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

    public static function translationsPath(): string {
        return self::path('translations');
    }
}
