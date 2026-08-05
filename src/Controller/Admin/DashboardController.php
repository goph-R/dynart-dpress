<?php

namespace Dynart\Dpress\Controller\Admin;

use Dynart\Micro\Attribute\Route;
use Dynart\Micro\ConfigInterface;
use Dynart\Micro\JwtAuthInterface;
use Dynart\Micro\RequestInterface;
use Dynart\Micro\RouterInterface;
use Dynart\Micro\ViewInterface;
use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Form\FormFactory;
use Dynart\Dpress\Query\ListRequest;
use Dynart\Dpress\Security\Permissions;
use Dynart\Dpress\Service\ContentHistoryService;
use Dynart\Dpress\Service\ContentService;
use Dynart\Dpress\Service\MediaService;
use Dynart\Dpress\Service\UserService;

/**
 * The first screen after logging in
 *
 * Counts and the last few changes. Every panel is behind the permission for the thing it counts,
 * so an editor's dashboard is not an author's.
 */
class DashboardController extends AbstractAdminController {

    public function __construct(
        ViewInterface $view,
        RouterInterface $router,
        RequestInterface $request,
        ConfigInterface $config,
        JwtAuthInterface $jwtAuth,
        FormFactory $forms,
        ListRequest $list,
        protected ContentService $content,
        protected ContentHistoryService $history,
        protected MediaService $media,
        protected UserService $users,
    ) {
        parent::__construct($view, $router, $request, $config, $jwtAuth, $forms, $list);
    }

    protected function section(): string {
        return 'dashboard';
    }

    #[Route('GET', '/admin')]
    public function index(): string {
        return $this->admin('dpress_admin:dashboard', [
            'title' => 'Dashboard',
            'counts' => $this->counts(),
            'recent' => $this->can(Permissions::CONTENT_HISTORY) ? $this->history->recent(10) : [],
        ]);
    }

    /**
     * @return array [['label' => ..., 'total' => ..., 'url' => ...]]
     */
    protected function counts(): array {
        $counts = [];
        if ($this->can(Permissions::POST_VIEW)) {
            $counts[] = [
                'label' => 'Posts',
                'total' => $this->content->countAll(['type' => Content::TYPE_POST]),
                'url'   => $this->router->url('/admin/content/post'),
            ];
            $counts[] = [
                'label' => 'Drafts',
                'total' => $this->content->countAll(['type' => Content::TYPE_POST, 'status' => Content::STATUS_DRAFT]),
                'url'   => $this->router->url('/admin/content/post', ['status' => Content::STATUS_DRAFT]),
            ];
        }
        if ($this->can(Permissions::PAGE_VIEW)) {
            $counts[] = [
                'label' => 'Pages',
                'total' => $this->content->countAll(['type' => Content::TYPE_PAGE]),
                'url'   => $this->router->url('/admin/content/page'),
            ];
        }
        if ($this->can(Permissions::MEDIA_VIEW)) {
            $counts[] = [
                'label' => 'Media',
                'total' => $this->media->countAll(),
                'url'   => $this->router->url('/admin/media'),
            ];
        }
        if ($this->can(Permissions::USER_VIEW)) {
            $counts[] = [
                'label' => 'Users',
                'total' => $this->users->countAll(),
                'url'   => $this->router->url('/admin/users'),
            ];
        }
        return $counts;
    }
}
