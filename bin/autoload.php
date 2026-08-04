<?php

/**
 * Finds the Composer autoloader
 *
 * dpress can be checked out on its own, installed as a dependency of a site, or symlinked
 * through a path repository, and the autoloader sits somewhere different in each case.
 */

$candidates = [
    __DIR__ . '/../vendor/autoload.php',        // dpress checked out on its own
    __DIR__ . '/../../../autoload.php',         // installed into a site's vendor/
    __DIR__ . '/../../../../vendor/autoload.php', // symlinked through a path repository
];

foreach ($candidates as $candidate) {
    if (is_file($candidate)) {
        require_once $candidate;
        return;
    }
}

fwrite(STDERR, "Could not find the Composer autoloader. Run `composer install` first.\n");
exit(1);
