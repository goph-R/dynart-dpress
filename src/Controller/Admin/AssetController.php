<?php

namespace Dynart\Dpress\Controller\Admin;

use Dynart\Micro\Attribute\AllowAnonymous;
use Dynart\Micro\Attribute\Route;
use Dynart\Micro\Micro;
use Dynart\Micro\ResponseInterface;
use Dynart\Dpress\Controller\AbstractController;
use Dynart\Dpress\Plugin\PluginService;
use Dynart\Dpress\Theme\ThemeAssets;

/**
 * Serves the admin's own JavaScript and CSS out of the package
 *
 * The alternative is a publish step that copies files into the site's `public/`, which is one
 * more thing to forget after an update - and then the admin is running last version's list code
 * against this version's endpoints. Serving them from here means installing the package installs
 * the admin. It costs a PHP request per file, on admin screens only, and the URL carries the
 * package version so the answer can be cached forever.
 *
 * `logo.svg` is here for the same reason and is safe for the same reason the uploads are not:
 * it is a file this package ships, not one anybody sent us.
 */
class AssetController extends AbstractController {

    /** Only these, by name. Nothing here takes a path from the request. */
    const ASSETS = [
        'dynamic-list.js' => 'application/javascript; charset=utf-8',
        'admin.js'        => 'application/javascript; charset=utf-8',
        'admin.css'       => 'text/css; charset=utf-8',
        'logo.svg'        => 'image/svg+xml',
    ];

    /**
     * The URL of an asset, with the version as a cache buster
     */
    public static function url(string $name): string {
        $router = Micro::get(\Dynart\Micro\RouterInterface::class);
        return $router->url('/admin/assets/'.$name, ['v' => \Dynart\Dpress\Dpress::VERSION]);
    }

    /**
     * Anonymous on purpose: these are static files with no data in them, and requiring a token
     * would mean a login page that cannot style itself if the admin ever shares a stylesheet.
     */
    #[AllowAnonymous]
    #[Route('GET', '/admin/assets/?')]
    public function asset(string $name): string {
        if (!isset(self::ASSETS[$name])) {
            $this->app()->sendError(404);
        }
        $path = dirname(__DIR__, 3).'/assets/'.$name;
        if (!is_file($path)) {
            $this->app()->sendError(404);
        }
        $this->sendFile($path, self::ASSETS[$name]);
        return '';
    }

    /** What a plugin is allowed to serve, by extension */
    const PLUGIN_TYPES = [
        'js'  => 'application/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'svg' => 'image/svg+xml',
    ];

    /**
     * The URL of a file inside a plugin's `assets/` folder
     */
    public static function pluginUrl(string $plugin, string $file, string $version = ''): string {
        $router = Micro::get(\Dynart\Micro\RouterInterface::class);
        return $router->url('/admin/assets/plugin/'.$plugin.'/'.$file, ['v' => $version ?: \Dynart\Dpress\Dpress::VERSION]);
    }

    /**
     * A plugin's own stylesheet or script
     *
     * A field type usually comes with behaviour, and a plugin has nowhere to put it otherwise -
     * `ASSETS` above is a fixed list of files this package ships.
     *
     * Two things keep this from being a way to read the disk. The name is matched against
     * `[A-Za-z0-9._-]+` with no slashes and no dots in sequence, so it cannot climb out of the
     * folder; and the plugin has to be one the loader **actually loaded**, so a folder somebody
     * dropped in but never enabled serves nothing.
     */
    /**
     * The syntax highlighter, on the **front end** rather than under `/admin`
     *
     * The only asset a visitor ever loads, and only on a page that has a code block on it - see
     * `CodeAssets`. It lives on this controller because the serving is identical: a file with no
     * data in it, an allowlist, and the same immutable headers.
     *
     * The name is matched against the files that are actually there rather than against a
     * pattern. A `..` cannot survive `basename()`, but an allowlist does not need arguing about.
     */
    #[AllowAnonymous]
    #[Route('GET', '/assets/enlighter/?')]
    public function enlighterAsset(string $file): string {
        $path = dirname(__DIR__, 3).'/assets/enlighter/'.basename($file);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!isset(self::PLUGIN_TYPES[$extension]) || !is_file($path)) {
            $this->app()->sendError(404);
        }
        $this->sendFile($path, self::PLUGIN_TYPES[$extension]);
        return '';
    }

    /**
     * A file out of the active theme's `assets/` folder
     *
     * On the **front end**, like the highlighter above it and unlike a plugin's: this is the
     * stylesheet of the site itself, and it is the one thing after the highlighter that every
     * visitor loads. It is here because the serving is identical - a file with no data in it, an
     * allowlist, and headers that let it be cached forever.
     *
     * `ThemeAssets::file()` holds the whole rule about what may be served and from where, so the
     * answer to "can this URL read something it should not" is one testable method rather than a
     * controller.
     */
    #[AllowAnonymous]
    #[Route('GET', ThemeAssets::ROUTE.'?')]
    public function themeAsset(string $file): string {
        $asset = Micro::get(ThemeAssets::class)->file($file);
        if ($asset === null) {
            $this->app()->sendError(404);
        }
        $this->sendFile($asset['path'], $asset['type']);
        return '';
    }

    #[AllowAnonymous]
    #[Route('GET', '/admin/assets/plugin/?/?')]
    public function pluginAsset(string $plugin, string $file): string {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $plugin)
            || !preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9]+$/', $file)
            || !isset(self::PLUGIN_TYPES[$extension])) {
            $this->app()->sendError(404);
        }
        $loaded = null;
        foreach (Micro::get(PluginService::class)->loaded() as $candidate) {
            if ($candidate->name === $plugin) {
                $loaded = $candidate;
                break;
            }
        }
        if ($loaded === null) {
            $this->app()->sendError(404);
        }
        $path = $loaded->path.'/assets/'.$file;
        if (!is_file($path)) {
            $this->app()->sendError(404);
        }
        $this->sendFile($path, self::PLUGIN_TYPES[$extension]);
        return '';
    }

    protected function sendFile(string $path, string $contentType): void {
        $response = Micro::get(ResponseInterface::class);
        $response->setHeader('Content-Type', $contentType);
        $response->setHeader('Cache-Control', 'public, max-age=31536000, immutable');
        $response->send((string)file_get_contents($path));
        $this->app()->finish();
    }
}
