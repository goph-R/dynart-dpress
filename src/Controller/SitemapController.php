<?php

namespace Dynart\Dpress\Controller;

use Dynart\Micro\Attribute\Route;
use Dynart\Micro\ConfigInterface;
use Dynart\Micro\JwtAuthInterface;
use Dynart\Micro\RequestInterface;
use Dynart\Micro\ResponseInterface;
use Dynart\Micro\RouterInterface;
use Dynart\Micro\ViewInterface;
use Dynart\Dpress\Content\Sitemap;

/**
 * `/sitemap.xml`, and the `robots.txt` that points at it
 *
 * Thin, like `FeedController` and for the same reason: the document belongs to `Sitemap`, and what
 * a controller adds is the headers. Both send bytes and finish rather than returning a string -
 * neither is HTML, and neither must reach the layout, the shortcodes or `PageAssets`.
 *
 * **`robots.txt` is here rather than in `public/`** so that a site gets one without a deploy step
 * and the sitemap address is right on every installation. Dropping a real `public/robots.txt` in
 * overrides it completely: the front controller only sees the request because the file is missing.
 */
class SitemapController extends AbstractController {

    public function __construct(
        ViewInterface $view,
        RouterInterface $router,
        RequestInterface $request,
        ConfigInterface $config,
        JwtAuthInterface $jwtAuth,
        protected ResponseInterface $response,
        protected Sitemap $sitemap,
    ) {
        parent::__construct($view, $router, $request, $config, $jwtAuth);
    }

    #[Route('GET', Sitemap::ROUTE)]
    public function index(): string {
        return $this->send($this->sitemap->xml(), Sitemap::CONTENT_TYPE);
    }

    #[Route('GET', Sitemap::ROBOTS_ROUTE)]
    public function robots(): string {
        return $this->send($this->sitemap->robotsTxt(), Sitemap::ROBOTS_CONTENT_TYPE);
    }

    protected function send(string $body, string $contentType): string {
        $this->response->setHeader('Content-Type', $contentType);
        $this->response->setHeader('Content-Length', (string)strlen($body));
        $this->response->send($body);
        $this->app()->finish();
        return '';
    }
}
