<?php

namespace Dynart\Dpress\Controller;

use Dynart\Micro\Attribute\Route;
use Dynart\Micro\ConfigInterface;
use Dynart\Micro\JwtAuthInterface;
use Dynart\Micro\RequestInterface;
use Dynart\Micro\RouterInterface;
use Dynart\Micro\ViewInterface;
use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Media\MediaView;
use Dynart\Dpress\Security\Permissions;
use Dynart\Dpress\Service\ContentService;
use Dynart\Dpress\Service\MediaService;

/**
 * Whatever lives at the root: every page, and posts when they live there too
 *
 * The catch-all is matched **after** every exact and segment route, so this only sees a path
 * nothing else claimed. That ordering is in the router itself rather than in registration
 * order, so adding a controller later cannot accidentally end up behind this one.
 */
class PageController extends AbstractController {

    public function __construct(
        ViewInterface $view,
        RouterInterface $router,
        RequestInterface $request,
        ConfigInterface $config,
        JwtAuthInterface $jwtAuth,
        protected ContentService $content,
        protected MediaService $media,
        protected MediaView $mediaView,
    ) {
        parent::__construct($view, $router, $request, $config, $jwtAuth);
    }

    #[Route('GET', '/*')]
    public function page(string $path): string {
        [$content, $isCanonical] = $this->content->findByPath($path, !$this->maySeeDrafts());
        if ($content === null) {
            $this->app()->sendError(404);
        }
        if (!$isCanonical) {
            // it is real but this is not where it lives - one piece of content, one URL. The
            // page number travels with the redirect: somebody following an old link to part
            // three of an article should land on part three, not at the beginning of it.
            $number = (int)$this->request->get(self::PAGE_PARAM, 1);
            $this->app()->redirect(
                $this->content->publicPath($content),
                $number > 1 ? [self::PAGE_PARAM => $number] : [],
                301
            );
        }
        if ($this->content->isFrontPage($content)) {
            // the page `/` renders has one address and it is `/`. Whatever the link carried
            // goes along - `?track=`, `?page=3` - and it is a 302 for the reason the setting's
            // other redirect is: the setting can change back, and a 301 would be remembered
            $this->app()->redirect('/', self::carriedQuery((string)$this->request->server('QUERY_STRING', '')), 302);
        }
        return $this->renderContent($content);
    }

    /**
     * The query a redirect takes with it: what the visitor's link said, and nothing else
     *
     * Without `route`, which is the front controller's rewrite talking to itself and not part of
     * anybody's link, and without anything that is not a plain value - a redirect is not the
     * place to round-trip `a[b][c]`.
     *
     * @return array<string, string>
     */
    public static function carriedQuery(string $queryString): array {
        parse_str($queryString, $query);
        unset($query['route']);
        return array_map('strval', array_filter($query, 'is_scalar'));
    }

    protected function maySeeDrafts(): bool {
        $user = $this->currentUser();
        return $user !== null && $user->hasPermission(Permissions::forContent(Content::TYPE_PAGE, 'update'));
    }
}
