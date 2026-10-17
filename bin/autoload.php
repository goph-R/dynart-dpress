<?php

/**
 * Finds the Composer autoloader
 *
 * dpress can be checked out on its own, installed as a dependency of a site, or symlinked
 * through a path repository, and the autoloader sits somewhere different in each case.
 */

$candidates = [];

/**
 * What Composer's bin proxy says, and it is the only one that can answer for a symlink
 *
 * `vendor/bin/dpress` is a generated proxy that sets this to its own `vendor/autoload.php`
 * before including this file. It has to come first, because **`__DIR__` resolves symlinks**:
 * installed through a path repository with `symlink: true` - which is how this package is
 * developed, see CLAUDE.md - `__DIR__` is the *checkout's* `bin/`, somewhere outside the site
 * altogether, and no number of `..` from there arrives at the site's vendor folder. The third
 * candidate below was written for exactly that case and could never match.
 */
if (!empty($GLOBALS['_composer_autoload_path'])) {
    $candidates[] = $GLOBALS['_composer_autoload_path'];
}

// and the layouts that can be found by walking up, for `php bin/dpress` run directly, where
// there is no proxy and so no global
array_push($candidates,
    __DIR__ . '/../vendor/autoload.php',        // dpress checked out on its own
    __DIR__ . '/../../../autoload.php',         // installed into a site's vendor/
    __DIR__ . '/../../../../vendor/autoload.php', // a non-symlinked path repository
);

foreach ($candidates as $candidate) {
    if (is_file($candidate)) {
        require_once $candidate;
        return;
    }
}

fwrite(STDERR, "Could not find the Composer autoloader. Run `composer install` first.\n");
exit(1);
