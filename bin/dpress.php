<?php

/**
 * The real entry point of the `dpress` command
 *
 * The bash and the batch launcher next to this file both delegate here, so there is exactly one
 * implementation to maintain.
 */

use Dynart\Micro\Micro;
use Dynart\Dpress\Dpress;
use Dynart\Dpress\DpressCliApp;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "dpress must be run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/autoload.php';

$configPath = dpress_resolve_config($argv);
if ($configPath === null && DpressCliApp::commandNeedsConfig($argv[1] ?? null)) {
    fwrite(STDERR, "Could not find a " . Dpress::CONFIG_FILE_NAME . " in the current directory or above it.\n");
    fwrite(STDERR, "Run the command from inside your site, or pass -config <path>.\n");
    exit(1);
}

Micro::run(new DpressCliApp($configPath === null ? [] : [$configPath]));

/**
 * Finds the config file
 *
 * An explicit `-config <path>` wins. Otherwise the directory tree is walked upwards from the
 * working directory looking for a `dpress.ini`, the same way git and composer find their root,
 * so the command works from anywhere inside a site.
 */
function dpress_resolve_config(array $argv): ?string {
    foreach ($argv as $i => $argument) {
        if ($argument === '-config' && isset($argv[$i + 1])) {
            $path = $argv[$i + 1];
            if (!is_file($path)) {
                fwrite(STDERR, "Config file not found: $path\n");
                exit(1);
            }
            return realpath($path);
        }
    }
    $dir = getcwd();
    while (is_string($dir) && $dir !== '') {
        $candidate = $dir . DIRECTORY_SEPARATOR . Dpress::CONFIG_FILE_NAME;
        if (is_file($candidate)) {
            return $candidate;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }
    return null;
}
