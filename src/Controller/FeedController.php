<?php

namespace Dynart\Dpress\Controller;

use Dynart\Micro\Attribute\Route;
use Dynart\Micro\ConfigInterface;
use Dynart\Micro\JwtAuthInterface;
use Dynart\Micro\RequestInterface;
use Dynart\Micro\ResponseInterface;
use Dynart\Micro\RouterInterface;
use Dynart\Micro\ViewInterface;
use Dynart\Dpress\Content\Feed;

/**
 * `/feed` - the posts, as RSS
 *
 * Thin on purpose: the document is `Feed`'s, because a feed is a rendering of the content and not
 * a page, and the one thing a controller adds is the headers. It sends bytes and finishes rather
 * than returning a string, for the same reason `MediaController` does - what comes back here is
 * not HTML and must not reach the layout, the shortcodes or `PageAssets`.
 *
 * A trailing slash is somebody else's job: WordPress wrote `/feed/`, and the front controller's
 * `.htaccess` redirects it here before PHP starts. That is one rule for every URL on the site
 * instead of an alias route for this one.
 */
class FeedController extends AbstractController {

    public function __construct(
        ViewInterface $view,
        RouterInterface $router,
        RequestInterface $request,
        ConfigInterface $config,
        JwtAuthInterface $jwtAuth,
        protected ResponseInterface $response,
        protected Feed $feed,
    ) {
        parent::__construct($view, $router, $request, $config, $jwtAuth);
    }

    #[Route('GET', Feed::ROUTE)]
    public function index(): string {
        $items = $this->feed->items();
        $xml = $this->feed->xml();
        $this->response->setHeader('Content-Type', Feed::CONTENT_TYPE);
        $this->response->setHeader('Content-Length', (string)strlen($xml));
        // A reader fetches this every half hour for years. `Last-Modified` is one header and it
        // is the only thing here that lets one of them decide it has already seen this.
        $built = $this->feed->builtAt($items);
        if ($built !== '') {
            $this->response->setHeader('Last-Modified', $built);
        }
        $this->response->send($xml);
        $this->app()->finish();
        return '';
    }
}
