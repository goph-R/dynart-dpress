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
    const SORTABLE = ['id', 'title', 'slug', 'status', 'published_at', 'created_at', 'updated_at'];

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
        $config = $this->listConfig($type);
        $context = $this->firstPageContext($config, self::SORTABLE, ['search', 'status']);
        $context['type'] = $type;
        $config['firstPage'] = $this->page($context);
        return $this->admin('dpress_admin:content/list', [
            'title'  => $isPage ? 'Pages' : 'Posts',
            'type'   => $type,
            'new_url' => $this->router->url('/admin/content/'.$type.'/new'),
            'can_create' => $this->can(Permissions::forContent($type, 'create')),
            'status'  => (string)$this->request->get('status', ''),
            'list_id' => 'content-list',
            'list_config' => $config,
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
        return $this->page($context);
    }

    /**
     * One page of rows
     *
     * Its own method because two callers want it: this endpoint, and the screen above, which
     * renders the first page into the list rather than making the browser come back for it.
     */
    protected function page(array $context): array {
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
            // the way in, and the only one: the title cell is the link. Left out for somebody who
            // may not edit, and the column falls back to plain text - a link to a page that is
            // going to refuse them is worse than no link.
            'edit_url'     => $this->can(Permissions::forContent($type, 'update'))
                ? $this->router->url('/admin/content/'.$type.'/edit/'.$content['id']) : '',
        ];
    }

    /**
     * What the browser needs to render the list
     */
    protected function listConfig(string $type): array {
        $rowActions = [];
        if ($this->can(Permissions::CONTENT_HISTORY)) {
            $rowActions[] = [
                'type' => 'history', 'title' => 'History', 'icon' => $this->icon('history'),
                'link' => $this->router->url('/admin/content/'.$type.'/history/'),
            ];
        }
        $groupActions = [];
        if ($this->can(Permissions::forContent($type, 'delete'))) {
            $groupActions[] = [
                'type' => 'delete', 'label' => 'Delete selected',
                'post' => $this->router->url('/admin/content/'.$type.'/delete-selected'),
                'confirm' => 'Delete the selected items permanently?',
            ];
        }
        return [
            'endpoint' => $this->router->url('/admin/content/'.$type.'/list'),
            'orderBy'  => $type === Content::TYPE_PAGE ? 'title' : 'published_at',
            'orderDir' => $type === Content::TYPE_PAGE ? 'asc' : 'desc',
            'columns'  => [
                // the id, because it is what a reference in somebody's markdown is made of:
                // `post#42` is written by hand as often as it is inserted by a button
                'id'     => ['label' => '#', 'align' => 'right', 'width' => '1%'],
                'title'  => ['label' => 'Title', 'view' => 'link', 'options' => ['hrefProperty' => 'edit_url']],
                'slug'   => ['label' => 'Slug'],
                'status' => ['label' => 'Status', 'view' => 'badge', 'options' => [
                    'labels' => [Content::STATUS_DRAFT => 'Draft', Content::STATUS_PUBLISHED => 'Published'],
                ]],
                'published_at' => ['label' => 'Published', 'view' => 'dateTime'],
                'updated_at'   => ['label' => 'Changed', 'view' => 'dateTime'],
            ],
            'rowActions'   => $rowActions,
            'groupActions' => $groupActions,
        ];
    }

    // --- the editor ---

    /**
     * "New" - which writes a row and sends you to the editor for it
     *
     * A POST, not a link, for the reason every other write in the admin is one: a link that
     * changes something can be followed by a prefetcher or a crawler, and this one inserts. It
     * hands back the author's existing auto-draft when there is one, so clicking it twice does
     * not make two.
     *
     * There is no `create()` any more. `edit()` is the only editor, which is the point of the
     * whole thing: no screen has to answer "and what does this do before the post exists?".
     */
    #[Route('POST', '/admin/content/?/new')]
    public function create(string $type): string {
        $this->enter($type, 'create');
        $this->requireAction();
        $content = $this->content->startDraft($type, (int)$this->currentUser()->id());
        $this->done('/admin/content/'.$type.'/edit/'.$content->id);
        return '';
    }

    #[Route('GET', '/admin/content/?/edit/?')]
    #[Route('POST', '/admin/content/?/edit/?')]
    public function edit(string $type, string $id): string {
        $this->enter($type, 'update');
        $content = $this->found($this->content->findById((int)$id));
        $this->assertType($content, $type);
        $form = $this->forms->create(AdminForms::CONTENT, $this->editorContext($type, $content));
        if ($form->process()) {
            // read before the save, which is what turns an auto-draft into a draft
            $wasAutoDraft = $content->isAutoDraft();
            $form->handle(function ($form) use ($content) {
                $values = $form->values();
                $this->content->update($content, $this->contentData($values));
                $this->applyTaxonomy($content, $values);
                return $content;
            });
            $this->applyStatus($content, $form->values(), $type);
            $this->done('/admin/content/'.$type, $wasAutoDraft ? 'Created.' : 'Saved.');
        }
        return $this->editor($type, $form, $content);
    }

    /**
     * What the attachments panel under the editor needs
     *
     * Attaching is an immediate write, the same as every other row action in the admin - keeping
     * it in the form until save would be a second way of writing, and an abandoned form would
     * leave files attached to nothing. That needs an id, which is what `startDraft()` is for:
     * there is always one, so this panel has no empty case any more.
     */
    protected function attachmentPanel(string $type, Content $content): array {
        $base = $this->router->url('/admin/content/'.$type);
        $id = '/'.$content->id;
        return [
            'list_id'    => 'attachment-list',
            'attach_url' => $base.'/attach'.$id,
            'config'     => [
                'endpoint'  => $base.'/attachments'.$id,
                'pageSize'  => 50,
                'allOrderDisabled' => true,
                'texts'     => ['noResults' => 'No attachments yet.'],
                'columns'   => [
                    'thumbnail_html' => ['label' => '', 'view' => 'html', 'sortable' => false, 'width' => '52px'],
                    'file_name'      => ['label' => 'File'],
                    'alt'            => ['label' => 'Alt text'],
                ],
                'rowActions' => [
                    [
                        'type' => 'insert', 'title' => 'Insert into the text', 'insert' => true,
                        'icon' => $this->icon('insert'),
                    ],
                    [
                        'type' => 'delete', 'title' => 'Detach', 'icon' => $this->icon('delete'),
                        'ajax' => $base.'/detach'.$id,
                        'confirm' => 'Detach this file? The text is left alone - remove it from there yourself.',
                    ],
                ],
            ],
        ];
    }

    protected function editor(string $type, $form, Content $content): string {
        $isPage = $type === Content::TYPE_PAGE;
        // It says "New" while it has never been saved, which is the only thing an auto-draft
        // changes about this screen - it is a real row underneath either way
        $isNew = $content->isAutoDraft();
        return $this->admin('dpress_admin:content/edit', [
            'attachments' => $this->attachmentPanel($type, $content),
            'can_attach'  => $this->can(Permissions::MEDIA_VIEW),
            'title'   => ($isNew ? 'New ' : 'Edit ').($isPage ? 'page' : 'post'),
            'type'    => $type,
            'form'    => $form,
            'content' => $content,
            'narrow'  => false,
            'back_url' => $this->router->url('/admin/content/'.$type),
            'view_url' => $content->isPublished() ? $this->router->url($this->content->publicPath($content)) : '',
            // one revision saying an empty row was made is not a history worth offering
            'history_url' => !$isNew && $this->can(Permissions::CONTENT_HISTORY)
                ? $this->router->url('/admin/content/'.$type.'/history/'.$content->id) : '',
        ]);
    }

    /**
     * Publishes or unpublishes, when the editor asked for it and may
     *
     * **The status is not an update field.** `ContentService::update()` deliberately ignores it,
     * because becoming visible is not the same kind of change as a corrected typo: it sets
     * `published_at` and it is what a plugin, a feed or a cache listens for. So the editor's
     * select goes through the same two methods the row actions use, and the transition is
     * announced exactly once however it was asked for.
     *
     * Silently, when the permission is missing, rather than as an error - the field is not
     * offered to somebody who cannot publish, so anything arriving here without it was not
     * typed into a form this admin rendered.
     */
    protected function applyStatus(?Content $content, array $values, string $type): void {
        if (!$content instanceof Content
            || !array_key_exists('status', $values)
            || !$this->can(Permissions::forContent($type, 'publish'))) {
            return;
        }
        $change = $this->statusChange($content->status, (string)$values['status']);
        if ($change === 'publish') {
            $this->content->publish($content);
        } else if ($change === 'unpublish') {
            $this->content->unpublish($content);
        }
    }

    /**
     * What has to happen to get from one status to another, or nothing
     *
     * A status the form does not offer is not a third state to move to - it is somebody sending
     * whatever they like, and the answer is to leave the content where it is.
     *
     * @return string|null `publish`, `unpublish`, or null when there is nothing to do
     */
    protected function statusChange(string $current, string $wanted): ?string {
        if ($wanted === Content::STATUS_PUBLISHED && $current !== Content::STATUS_PUBLISHED) {
            return 'publish';
        }
        if ($wanted === Content::STATUS_DRAFT && $current !== Content::STATUS_DRAFT) {
            return 'unpublish';
        }
        return null;
    }

    /**
     * What the form builder needs to offer the right fields
     */
    protected function editorContext(string $type, ?Content $content): array {
        $context = [
            'is_page' => $type === Content::TYPE_PAGE,
            'content' => $content,
            // a select that cannot do anything is worse than no select: the page says "Saved."
            // and nothing moved, which is exactly the bug this whole method exists to fix
            'can_publish' => $this->can(Permissions::forContent($type, 'publish')),
            'can_attach'  => $this->can(Permissions::MEDIA_VIEW),
            // the thumbnail the field shows for what is already chosen. Rendered here because a
            // template has no business asking a service what a media id looks like.
            'featured_preview' => $this->featuredPreview($content),
        ];
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
     * The thumbnail of the currently chosen featured image, or nothing
     *
     * The field carries its own preview rather than reading a view variable, because a form may
     * hold more than one media field and one variable cannot be the preview of both.
     *
     * A media id that no longer resolves - the file was purged - shows no preview rather than
     * failing: the field still holds the id, and the editor can see it is set and change it.
     */
    protected function featuredPreview(?Content $content): string {
        if ($content === null || $content->featured_media_id === null) {
            return '';
        }
        $media = $this->media->findById($content->featured_media_id);
        return $media === null ? '' : $this->mediaView->tag($media, 'thumb');
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
     *
     * **`status` is not here.** It went through `create()`, which honours it, and through
     * `update()`, which ignores it - so the same select published a new post and did nothing at
     * all to an existing one. Worse, the create path took it without asking whether this person
     * may publish, and the stock `editor` role holds `post.publish` but not `page.publish`.
     * Everything now starts as a draft and `applyStatus()` decides, once, in one place.
     */
    protected function contentData(array $values): array {
        $data = [];
        foreach (['title', 'markdown', 'slug'] as $field) {
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

    // --- attachments ---

    /**
     * The files attached to one piece of content
     *
     * The permission is the *content's*: somebody who may edit this post may say what hangs off
     * it. `media.view` is not enough and not required - the library and this list are different
     * questions.
     */
    #[Route('GET', '/admin/content/?/attachments/?')]
    public function attachmentRows(string $type, string $id): array {
        $this->enter($type, 'update');
        $content = $this->found($this->content->findById((int)$id));
        $this->assertType($content, $type);
        $rows = [];
        foreach ($this->media->attachmentsOf($content->id) as $media) {
            $rows[] = [
                'id'             => (int)$media['id'],
                'file_name'      => $media['file_name'],
                'title'          => (string)($media['title'] ?? ''),
                'alt'            => (string)($media['alt'] ?? ''),
                'category'       => $media['category'],
                'url'            => $this->mediaView->rowUrl($media),
                'thumbnail_html' => $this->mediaView->rowTag($media),
            ];
        }
        return $this->rows($rows, count($rows));
    }

    /**
     * Attaches a library item to this content
     *
     * Only ever from the "Add attachment" button. Putting a picture in the text does not come
     * through here and attaches nothing: the body carries a `media#<id>` reference, and the
     * attachment list is the list of files, not an index of what the article shows.
     */
    #[Route('POST', '/admin/content/?/attach/?')]
    public function attach(string $type, string $id): array {
        $content = $this->attachable($type, $id);
        $media = $this->found($this->media->findById((int)$this->request->get('media_id', 0)));
        $this->media->attach($content->id, $media->id);
        return $this->answer();
    }

    #[Route('POST', '/admin/content/?/detach/?')]
    public function detach(string $type, string $id): array {
        $content = $this->attachable($type, $id);
        $this->media->detach($content->id, (int)$this->request->get('media_id', 0));
        return $this->answer();
    }

    /**
     * The content an attachment action is allowed to touch
     *
     * Attaching and detaching are both a POST that changes something, so they go through the
     * same check: the update permission for this type, a valid action token, and a row that exists
     * and really is of the type the URL claims.
     */
    protected function attachable(string $type, string $id): Content {
        $this->enter($type, 'update');
        $this->requireAction();
        $content = $this->found($this->content->findById((int)$id));
        $this->assertType($content, $type);
        return $content;
    }

    // --- the list actions ---

    /**
     * The list's own way of removing things
     *
     * A separate path rather than the same one with no id: `/delete/?` and `/delete` are two
     * routes, and a bulk delete arriving at the single one with an empty segment would be a 404
     * at best. The single route stays - it is what a plugin or a script would use.
     */
    #[Route('POST', '/admin/content/?/delete-selected')]
    public function deleteMany(string $type): string {
        $this->enter($type, 'delete');
        $this->requireAction();
        $notice = $this->deleteSelected(function (int $id) use ($type) {
            $content = $this->content->findById($id);
            // the type check is not pedantry: the ids arrive in a request, and this route holds
            // the `post` permission, not the `page` one
            if ($content === null || $content->type !== $type) {
                return false;
            }
            $this->content->delete($content);
            return true;
        });
        $this->done('/admin/content/'.$type, $notice);
        return '';
    }

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
        return $this->admin('dpress_admin:content/history', [
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
