<?php

namespace Dynart\Dpress\Controller\Admin;

use Dynart\Micro\Attribute\AllowAnonymous;
use Dynart\Micro\Attribute\Route;
use Dynart\Micro\Micro;
use Dynart\Micro\ResponseInterface;
use Dynart\Dpress\Controller\AbstractController;

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
        $response = Micro::get(ResponseInterface::class);
        $response->setHeader('Content-Type', self::ASSETS[$name]);
        $response->setHeader('Cache-Control', 'public, max-age=31536000, immutable');
        $response->send((string)file_get_contents($path));
        $this->app()->finish();
        return '';
    }
}
