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
 * Pages at their own paths
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
            // the page is real but this is not where it lives - one page, one URL. The page
            // number travels with the redirect: somebody following an old link to part three of
            // an article should land on part three, not at the beginning of it.
            $number = (int)$this->request->get(self::PAGE_PARAM, 1);
            $this->app()->redirect(
                $this->content->path($content),
                $number > 1 ? [self::PAGE_PARAM => $number] : [],
                301
            );
        }
        return $this->render('dpress:content/page', $this->pagedBody($content, $this->content->path($content)) + [
            'title'       => $content->title,
            'content'     => $content,
            'ancestors'   => $this->content->ancestors($content),
            'children'    => $this->content->findChildren($content->id),
            'attachments' => $this->media->attachmentsOf($content->id),
            'featured'    => $content->featured_media_id !== null
                ? $this->media->findById($content->featured_media_id) : null,
            'mediaView'   => $this->mediaView,
        ], 'page');
    }

    protected function maySeeDrafts(): bool {
        $user = $this->currentUser();
        return $user !== null && $user->hasPermission(Permissions::forContent(Content::TYPE_PAGE, 'update'));
    }
}
