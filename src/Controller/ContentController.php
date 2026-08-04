<?php

namespace Dynart\Dpress\Controller;

use Dynart\Micro\Attribute\Route;
use Dynart\Micro\ConfigInterface;
use Dynart\Micro\JwtAuthInterface;
use Dynart\Micro\RequestInterface;
use Dynart\Micro\RouterInterface;
use Dynart\Micro\ViewInterface;
use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Security\Permissions;
use Dynart\Dpress\Service\ContentService;

/**
 * Reading content on the front end
 *
 * A single post lives at `/post/<slug>`, one segment, which the router handles today. The
 * hierarchical page paths belong to the presentation phase, along with the catch-all route the
 * framework still needs for them.
 */
class ContentController extends AbstractController {

    public function __construct(
        ViewInterface $view,
        RouterInterface $router,
        RequestInterface $request,
        ConfigInterface $config,
        JwtAuthInterface $jwtAuth,
        protected ContentService $content,
    ) {
        parent::__construct($view, $router, $request, $config, $jwtAuth);
    }

    #[Route('GET', '/post/?')]
    public function post(string $slug): string {
        $content = $this->content->findBySlug($slug, !$this->maySeeDrafts());
        if ($content === null || !$content->isPost()) {
            $this->app()->sendError(404);
        }
        return $this->render('dpress:content/single', [
            'title'   => $content->title,
            'content' => $content,
        ]);
    }

    /**
     * Somebody who may edit posts can follow a link to a draft; a visitor gets a 404
     */
    protected function maySeeDrafts(): bool {
        $user = $this->currentUser();
        return $user !== null && $user->hasPermission(Permissions::forContent(Content::TYPE_POST, 'update'));
    }
}
