<?php

namespace Dynart\Dpress\Controller\Admin;

use Dynart\Micro\Attribute\Route;
use Dynart\Micro\ConfigInterface;
use Dynart\Micro\JwtAuthInterface;
use Dynart\Micro\RequestInterface;
use Dynart\Micro\RouterInterface;
use Dynart\Micro\ViewInterface;
use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Form\AdminForms;
use Dynart\Dpress\Form\FormFactory;
use Dynart\Dpress\Media\MediaView;
use Dynart\Dpress\Query\ListRequest;
use Dynart\Dpress\Security\Permissions;
use Dynart\Dpress\Service\ContentHistoryService;
use Dynart\Dpress\Service\ContentService;
use Dynart\Dpress\Service\MediaService;
use Dynart\Dpress\Service\TaxonomyService;
use Dynart\Dpress\Service\UserService;

/**
 * Posts and pages
 *
 * One controller for both, because they are one table - the type is a path segment and every
 * permission is resolved from it through `Permissions::forContent()`, so "may write posts" and
 * "may restructure the site" stay separate answers without a second controller saying the same
 * things twice.
 */
class ContentAdminController extends AbstractAdminController {

    /** What a list may be ordered by. Anything else is dropped rather than put into the SQL. */
    const SORTABLE = ['title', 'slug', 'status', 'published_at', 'created_at', 'updated_at'];

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
        protected TaxonomyService $taxonomy,
        protected MediaService $media,
        protected MediaView $mediaView,
        protected UserService $users,
    ) {
        parent::__construct($view, $router, $request, $config, $jwtAuth, $forms, $list);
    }

    protected function section(): string {
        return $this->type() === Content::TYPE_PAGE ? 'pages' : 'content';
    }

    /** @var string The type of the request being handled, taken from the path */
    private string $currentType = Content::TYPE_POST;

    protected function type(): string {
        return $this->currentType;
    }

    /**
     * Reads the type out of the path and checks the permission it implies
     *
     * A type that is not one of ours is a 404, not a default: `/admin/content/posts` (with the s)
     * silently listing posts would hide a broken link forever.
     */
    protected function enter(string $type, string $action): void {
        if (!in_array($type, Content::TYPES, true)) {
            $this->app()->sendError(404);
        }
        $this->currentType = $type;
        $this->requirePermission(Permissions::forContent($type, $action));
    }

    // --- the list ---

    #[Route('GET', '/admin/content/?')]
    public function index(string $type): string {
        $this->enter($type, 'view');
        $isPage = $type === Content::TYPE_PAGE;
        return $this->admin('dpress:admin/content/list', [
            'title'  => $isPage ? 'Pages' : 'Posts',
            'type'   => $type,
            'new_url' => $this->router->url('/admin/content/'.$type.'/new'),
            'can_create' => $this->can(Permissions::forContent($type, 'create')),
            'status'  => (string)$this->request->get('status', ''),
            'list_id' => 'content-list',
            'list_config' => $this->listConfig($type),
        ]);
    }

    /**
     * The rows behind the list
     *
     * Note there is no `published_only`: this is the admin, and a draft is exactly what an editor
     * came here to find. The permission check above is what keeps it out of a visitor's hands.
     */
    #[Route('GET', '/admin/content/?/list')]
    public function rowsJson(string $type): array {
        $this->enter($type, 'view');
        $context = $this->list->context(self::SORTABLE, ['search', 'status']);
        $context['type'] = $type;
        $rows = $this->content->findAll($context);
        return $this->rows(array_map([$this, 'row'], $rows), $this->content->countAll($context));
    }

    /**
     * One row, with only the columns the list shows
     *
     * Built by hand rather than handing the entity over: the row is a public API of the admin and
     * `markdown` / `body_html` have no business travelling to the browser on every list request.
     */
    protected function row(array $content): array {
        $type = $content['type'];
        return [
            'id'           => (int)$content['id'],
            'title'        => $content['title'],
            'slug'         => $content['slug'],
            'status'       => $content['status'],
            'published_at' => $content['published_at'],
            'created_at'   => $content['created_at'],
            'updated_at'   => $content['updated_at'],
            'edit_url'     => $this->router->url('/admin/content/'.$type.'/edit/'.$content['id']),
        ];
    }

    /**
     * What the browser needs to render the list
     */
    protected function listConfig(string $type): array {
        $rowActions = [];
        if ($this->can(Permissions::forContent($type, 'update'))) {
            $rowActions[] = [
                'type' => 'edit', 'title' => 'Edit', 'icon' => $this->icon('edit'),
                'link' => $this->router->url('/admin/content/'.$type.'/edit/'),
            ];
        }
        if ($this->can(Permissions::forContent($type, 'publish'))) {
            $rowActions[] = [
                'type' => 'publish', 'title' => 'Publish', 'icon' => $this->icon('publish'),
                'post' => $this->router->url('/admin/content/'.$type.'/publish/'),
                'visibleWhen' => ['status' => Content::STATUS_DRAFT],
            ];
            $rowActions[] = [
                'type' => 'unpublish', 'title' => 'Move back to draft', 'icon' => $this->icon('unpublish'),
                'post' => $this->router->url('/admin/content/'.$type.'/unpublish/'),
                'visibleWhen' => ['status' => Content::STATUS_PUBLISHED],
            ];
        }
        if ($this->can(Permissions::CONTENT_HISTORY)) {
            $rowActions[] = [
                'type' => 'history', 'title' => 'History', 'icon' => $this->icon('history'),
                'link' => $this->router->url('/admin/content/'.$type.'/history/'),
            ];
        }
        if ($this->can(Permissions::forContent($type, 'delete'))) {
            $rowActions[] = [
                'type' => 'delete', 'title' => 'Delete', 'icon' => $this->icon('delete'),
                'post' => $this->router->url('/admin/content/'.$type.'/delete/'),
                'confirm' => 'Delete this permanently?',
            ];
        }
        return [
            'endpoint' => $this->router->url('/admin/content/'.$type.'/list'),
            'orderBy'  => $type === Content::TYPE_PAGE ? 'title' : 'published_at',
            'orderDir' => $type === Content::TYPE_PAGE ? 'asc' : 'desc',
            'columns'  => [
                'title'  => ['label' => 'Title', 'view' => 'link', 'options' => ['hrefProperty' => 'edit_url']],
                'slug'   => ['label' => 'Slug'],
                'status' => ['label' => 'Status', 'view' => 'badge', 'options' => [
                    'labels' => [Content::STATUS_DRAFT => 'Draft', Content::STATUS_PUBLISHED => 'Published'],
                ]],
                'published_at' => ['label' => 'Published', 'view' => 'dateTime'],
                'updated_at'   => ['label' => 'Changed', 'view' => 'dateTime'],
            ],
            'rowActions' => $rowActions,
        ];
    }

    // --- the editor ---

    #[Route('GET', '/admin/content/?/new')]
    #[Route('POST', '/admin/content/?/new')]
    public function create(string $type): string {
        $this->enter($type, 'create');
        $form = $this->forms->create(AdminForms::CONTENT, $this->editorContext($type, null));
        if ($form->process()) {
            $content = $form->handle(function ($form) use ($type) {
                $values = $form->values();
                $data = $this->contentData($values);
                $data['type'] = $type;
                $created = $this->content->create($data, (int)$this->currentUser()->id());
                $this->applyTaxonomy($created, $values);
                return $created;
            });
            $this->done('/admin/content/'.$type, 'Created.');
        }
        return $this->editor($type, $form, null);
    }

    #[Route('GET', '/admin/content/?/edit/?')]
    #[Route('POST', '/admin/content/?/edit/?')]
    public function edit(string $type, string $id): string {
        $this->enter($type, 'update');
        $content = $this->found($this->content->findById((int)$id));
        $this->assertType($content, $type);
        $form = $this->forms->create(AdminForms::CONTENT, $this->editorContext($type, $content));
        if ($form->process()) {
            $form->handle(function ($form) use ($content) {
                $values = $form->values();
                $this->content->update($content, $this->contentData($values));
                $this->applyTaxonomy($content, $values);
                return $content;
            });
            $this->done('/admin/content/'.$type, 'Saved.');
        }
        return $this->editor($type, $form, $content);
    }

    protected function editor(string $type, $form, ?Content $content): string {
        $isPage = $type === Content::TYPE_PAGE;
        return $this->admin('dpress:admin/content/edit', [
            'title'   => ($content === null ? 'New ' : 'Edit ').($isPage ? 'page' : 'post'),
            'type'    => $type,
            'form'    => $form,
            'content' => $content,
            'narrow'  => false,
            'back_url' => $this->router->url('/admin/content/'.$type),
            'view_url' => $content !== null && $content->isPublished()
                ? $this->router->url($this->content->publicPath($content)) : '',
            'history_url' => $content !== null && $this->can(Permissions::CONTENT_HISTORY)
                ? $this->router->url('/admin/content/'.$type.'/history/'.$content->id) : '',
        ]);
    }

    /**
     * What the form builder needs to offer the right fields
     */
    protected function editorContext(string $type, ?Content $content): array {
        $context = ['is_page' => $type === Content::TYPE_PAGE, 'content' => $content];
        if ($type === Content::TYPE_PAGE) {
            $context['pages'] = $this->pageOptions($content);
        } else {
            $context['categories'] = $this->categoryOptions();
            if ($content !== null) {
                $context['selected_categories'] = $this->taxonomy->categoryIdsOf($content->id);
                $context['tags'] = implode(', ', array_column($this->taxonomy->tagsOf($content->id), 'name'));
            }
        }
        return $context;
    }

    /**
     * Every page except this one and, as far as this goes, its own subtree
     *
     * `ContentService::update()` refuses a cycle anyway; leaving the page itself out of its own
     * parent list is so the obvious mistake is not offered in the first place.
     */
    protected function pageOptions(?Content $content): array {
        $options = ['' => '(top level)'];
        foreach ($this->content->findAll(['type' => Content::TYPE_PAGE, 'max' => 500]) as $page) {
            if ($content !== null && (int)$page['id'] === $content->id) {
                continue;
            }
            $options[$page['id']] = $page['title'];
        }
        return $options;
    }

    protected function categoryOptions(): array {
        $options = [];
        foreach ($this->taxonomy->categories() as $category) {
            $options[$category['id']] = $category['name'];
        }
        return $options;
    }

    /**
     * The content columns out of what the form collected
     *
     * Named rather than passed through wholesale: the form also carries `_csrf`, `tags` and
     * `categories`, none of which are columns, and a field a plugin adds should not reach the
     * entity by accident. The two ids arrive as strings because that is what a `<select>` posts.
     *
     * A key that is not in the form is left out entirely, because `update()` treats "absent" as
     * "leave it alone" - a page editor has no `categories` field and must not clear them.
     */
    protected function contentData(array $values): array {
        $data = [];
        foreach (['title', 'markdown', 'slug', 'status'] as $field) {
            if (array_key_exists($field, $values)) {
                $data[$field] = (string)$values[$field];
            }
        }
        foreach (['parent_id', 'featured_media_id'] as $field) {
            if (array_key_exists($field, $values)) {
                $data[$field] = $values[$field] === '' ? null : (int)$values[$field];
            }
        }
        return $data;
    }

    /**
     * Writes the categories and tags the form collected
     *
     * Through the services rather than the entity manager, so the assignment events fire and a
     * plugin watching "this post entered that category" sees it.
     */
    protected function applyTaxonomy(Content $content, array $values): void {
        if ($content->isPage()) {
            return;
        }
        if (array_key_exists('categories', $values)) {
            $this->taxonomy->setCategories($content->id, array_map('intval', (array)$values['categories']));
        }
        if (array_key_exists('tags', $values)) {
            $names = array_filter(array_map('trim', explode(',', (string)$values['tags'])));
            $this->taxonomy->setTags($content->id, $names);
        }
    }

    // --- the row actions ---

    #[Route('POST', '/admin/content/?/publish/?')]
    public function publish(string $type, string $id): string {
        $this->enter($type, 'publish');
        $this->requireAction();
        $content = $this->found($this->content->findById((int)$id));
        $this->assertType($content, $type);
        $this->content->publish($content);
        $this->done('/admin/content/'.$type, 'Published.');
        return '';
    }

    #[Route('POST', '/admin/content/?/unpublish/?')]
    public function unpublish(string $type, string $id): string {
        $this->enter($type, 'publish');
        $this->requireAction();
        $content = $this->found($this->content->findById((int)$id));
        $this->assertType($content, $type);
        $this->content->unpublish($content);
        $this->done('/admin/content/'.$type, 'Moved back to draft.');
        return '';
    }

    #[Route('POST', '/admin/content/?/delete/?')]
    public function delete(string $type, string $id): string {
        $this->enter($type, 'delete');
        $this->requireAction();
        $content = $this->found($this->content->findById((int)$id));
        $this->assertType($content, $type);
        $this->content->delete($content);
        $this->done('/admin/content/'.$type, 'Deleted.');
        return '';
    }

    // --- history ---

    #[Route('GET', '/admin/content/?/history/?')]
    public function history(string $type, string $id): string {
        $this->enter($type, 'view');
        $this->requirePermission(Permissions::CONTENT_HISTORY);
        $content = $this->found($this->content->findById((int)$id));
        $this->assertType($content, $type);
        return $this->admin('dpress:admin/content/history', [
            'title'     => 'History',
            'type'      => $type,
            'content'   => $content,
            'revisions' => $this->history->revisions($content->id),
            'back_url'  => $this->router->url('/admin/content/'.$type.'/edit/'.$content->id),
        ]);
    }

    /**
     * A page reached through the posts URL is a 404
     *
     * The row exists, but not at this address - and the permission that was checked was the one
     * for the *path's* type, which would be the wrong one to have let through.
     */
    protected function assertType(Content $content, string $type): void {
        if ($content->type !== $type) {
            $this->app()->sendError(404);
        }
    }
}
