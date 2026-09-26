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
            'recent' => $this->can(Permissions::CONTENT_HISTORY) ? $this->recent() : [],
            'edit_icon' => $this->icon('edit'),
            'view_icon' => $this->icon('eye'),
        ]);
    }

    /**
     * The latest changes, each with the way into its editor and onto its page where there is one
     *
     * The pen into the editor, and the eye to the page as the site serves it - what the editor's
     * View opens, one click from here. Only where the post is still there and not in the trash.
     * The pen for whoever may edit it; the eye for anybody where it is published, and for whoever
     * may edit it where it is a draft, since that is who the site shows a draft to.
     *
     * A post's address is its slug, which the query brought along. A page's is its ancestors'
     * too, so a page is looked up - once, however many of its revisions are listed.
     */
    protected function recent(): array {
        $rows = $this->history->recent(10);
        $pagePaths = [];
        foreach ($rows as $index => $row) {
            $type = (string)($row['live_type'] ?? '');
            $status = (string)($row['live_status'] ?? '');
            $there = in_array($type, Content::TYPES, true) && $status !== Content::STATUS_TRASH;
            $mayEdit = $there && $this->can(Permissions::forContent($type, 'update'));
            $id = (int)$row['id'];
            $rows[$index]['edit_url'] = $mayEdit ? $this->router->url('/admin/content/'.$type.'/edit/'.$id) : '';
            $rows[$index]['view_url'] = '';
            if ($there && ($status === Content::STATUS_PUBLISHED || $mayEdit)) {
                if ($type === Content::TYPE_PAGE) {
                    if (!array_key_exists($id, $pagePaths)) {
                        $page = $this->content->findById($id);
                        $pagePaths[$id] = $page !== null ? $this->content->publicPath($page) : null;
                    }
                    $path = $pagePaths[$id];
                } else {
                    $path = $this->content->postPath((string)($row['live_slug'] ?? ''));
                }
                $rows[$index]['view_url'] = $path !== null ? $this->router->url($path) : '';
            }
        }
        return $rows;
    }

    /**
     * The count cards, each with a `key` its colour is chosen by and the section's own icon -
     * the one it has in the navigation, so a card and the place it leads to look alike
     *
     * @return array [['key' => ..., 'label' => ..., 'total' => ..., 'url' => ..., 'icon' => <svg markup>]]
     */
    protected function counts(): array {
        $counts = [];
        if ($this->can(Permissions::POST_VIEW)) {
            $counts[] = [
                'key'   => 'posts', 'icon' => $this->icon('content'),
                'label' => 'Posts',
                'total' => $this->content->countAll(['type' => Content::TYPE_POST]),
                'url'   => $this->router->url('/admin/content/post'),
            ];
            $counts[] = [
                // the pencil, since a draft is a post somebody is still writing
                'key'   => 'drafts', 'icon' => $this->icon('edit'),
                'label' => 'Drafts',
                'total' => $this->content->countAll(['type' => Content::TYPE_POST, 'status' => Content::STATUS_DRAFT]),
                'url'   => $this->router->url('/admin/content/post', ['status' => Content::STATUS_DRAFT]),
            ];
        }
        if ($this->can(Permissions::PAGE_VIEW)) {
            $counts[] = [
                'key'   => 'pages', 'icon' => $this->icon('pages'),
                'label' => 'Pages',
                'total' => $this->content->countAll(['type' => Content::TYPE_PAGE]),
                'url'   => $this->router->url('/admin/content/page'),
            ];
        }
        if ($this->can(Permissions::MEDIA_VIEW)) {
            $counts[] = [
                'key'   => 'media', 'icon' => $this->icon('media'),
                'label' => 'Media',
                'total' => $this->media->countAll(),
                'url'   => $this->router->url('/admin/media'),
            ];
        }
        if ($this->can(Permissions::USER_VIEW)) {
            $counts[] = [
                'key'   => 'users', 'icon' => $this->icon('users'),
                'label' => 'Users',
                'total' => $this->users->countAll(),
                'url'   => $this->router->url('/admin/users'),
            ];
        }
        return $counts;
    }
}
