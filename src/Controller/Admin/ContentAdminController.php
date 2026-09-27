<?php

namespace Dynart\Dpress\Controller\Admin;

use Dynart\Micro\Attribute\Route;
use Dynart\Micro\ConfigInterface;
use Dynart\Micro\JwtAuthInterface;
use Dynart\Micro\RequestInterface;
use Dynart\Micro\RouterInterface;
use Dynart\Micro\SessionInterface;
use Dynart\Micro\ViewInterface;
use Dynart\Micro\Entities\AuditService;
use Dynart\Dpress\Content\Dates;
use Dynart\Dpress\Content\FencedDivs;
use Dynart\Dpress\Content\Slugger;
use Dynart\Dpress\Content\MarkdownRenderer;
use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Entity\Role;
use Dynart\Dpress\Entity\Setting;
use Dynart\Dpress\Form\AdminForms;
use Dynart\Dpress\Form\FormFactory;
use Dynart\Dpress\Media\MediaView;
use Dynart\Dpress\Query\ListRequest;
use Dynart\Dpress\Security\Permissions;
use Dynart\Dpress\Service\ContentHistoryService;
use Dynart\Dpress\Service\ContentService;
use Dynart\Dpress\Service\MediaService;
use Dynart\Dpress\Service\SettingService;
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
    /** Where a preview keeps the boxes it was handed, and what says which one to read */
    const PREVIEW_SESSION = 'dpress.preview';
    const PREVIEW_TOKEN = 'preview';

    /** How many previews a session remembers - enough for a few tabs, not a place to grow */
    const PREVIEW_KEEP = 3;

    const SORTABLE = ['id', 'title', 'status', 'weight', 'published_at', 'created_at', 'updated_at'];

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
        protected Dates $dates,
        protected Slugger $slugger,
        protected SessionInterface $session,
        protected MarkdownRenderer $markdown,
        protected SettingService $settings,
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
            // the way into the trash, for somebody who may put things in it and take them out
            'trash'   => $this->can(Permissions::forContent($type, 'delete')) ? [
                'url'   => $this->router->url('/admin/content/'.$type.'/trash'),
                'icon'  => $this->icon('delete'),
                'count' => $this->content->countAll(['type' => $type, 'trashed' => true]),
            ] : null,
        ]);
    }

    // --- the trash ---

    /**
     * What of this type is in the trash, and nothing that is not
     *
     * A screen of its own rather than a status in the list's filter: here every row is a deleted
     * one, so its two actions - Restore, Delete permanently - never have to ask which kind of row
     * they are on, and nothing on the ordinary list can be deleted for good by a slip.
     */
    #[Route('GET', '/admin/content/?/trash')]
    public function trash(string $type): string {
        $this->enter($type, 'delete');
        $config = $this->trashConfig($type);
        $context = $this->firstPageContext($config, self::SORTABLE, ['search']);
        $config['firstPage'] = $this->page(['type' => $type, 'trashed' => true] + $context);
        $isPage = $type === Content::TYPE_PAGE;
        return $this->admin('dpress_admin:trash', [
            'title'       => $isPage ? 'Pages' : 'Posts',
            'back_url'    => $this->router->url('/admin/content/'.$type),
            'empty_url'   => $this->router->url('/admin/content/'.$type.'/empty-trash'),
            'empty_confirm' => 'Delete every '.($isPage ? 'page' : 'post').' in the trash for good?'
                .' Their history is kept, but they cannot be restored from here any more.',
            'list_id'     => 'content-trash',
            'list_config' => $config,
            'filters'     => '<input type="search" name="search" placeholder="Search...">',
        ]);
    }

    #[Route('GET', '/admin/content/?/trash/list')]
    public function trashRows(string $type): array {
        $this->enter($type, 'delete');
        $context = $this->list->context(self::SORTABLE, ['search']);
        return $this->page(['type' => $type, 'trashed' => true] + $context);
    }

    /**
     * The list's columns without the ones that mean nothing in the trash, and the trash's actions
     */
    protected function trashConfig(string $type): array {
        $config = $this->listConfig($type);
        $config['endpoint'] = $this->router->url('/admin/content/'.$type.'/trash/list');
        // newest in the trash first: what somebody came to take back out is what they just put in
        $config['orderBy'] = 'updated_at';
        $config['orderDir'] = 'desc';
        unset($config['columns']['status'], $config['columns']['weight']);
        $config['columns']['updated_at']['label'] = 'Trashed';
        $config['rowActions'] = [
            ['type' => 'restore', 'title' => 'Restore', 'icon' => $this->icon('restore'),
             'post' => $this->router->url('/admin/content/'.$type.'/restore/')],
            ['type' => 'delete', 'title' => 'Delete permanently', 'icon' => $this->icon('delete'),
             'post' => $this->router->url('/admin/content/'.$type.'/destroy/'),
             'confirm' => 'Delete this for good? Its history is kept, but it cannot be restored from here any more.'],
        ];
        return $config;
    }

    #[Route('POST', '/admin/content/?/restore/?')]
    public function restore(string $type, string $id): string {
        $this->enter($type, 'delete');
        $this->requireAction();
        $content = $this->found($this->content->findById((int)$id));
        $this->assertType($content, $type);
        $this->content->restore($content);
        $this->done('/admin/content/'.$type.'/trash', 'Restored'.($content->isPublished() ? ', and published again.' : ' as a draft.'));
        return '';
    }

    /**
     * Delete permanently - the row, its links and its attachments; the history stays
     *
     * Only from the trash. Something still on the list is one slip from gone, and "cannot be
     * restored" should never be the first thing that happens to it.
     */
    #[Route('POST', '/admin/content/?/destroy/?')]
    public function destroy(string $type, string $id): string {
        $this->enter($type, 'delete');
        $this->requireAction();
        $content = $this->found($this->content->findById((int)$id));
        $this->assertType($content, $type);
        if (!$content->isTrashed()) {
            $this->done('/admin/content/'.$type, 'Move it to the trash first.');
            return '';
        }
        $this->content->delete($content);
        $this->done('/admin/content/'.$type.'/trash', 'Deleted for good. Its history is kept.');
        return '';
    }

    #[Route('POST', '/admin/content/?/empty-trash')]
    public function emptyTrash(string $type): string {
        $this->enter($type, 'delete');
        $this->requireAction();
        $count = $this->content->emptyTrash($type);
        $this->done('/admin/content/'.$type.'/trash',
            $count === 0 ? 'The trash was already empty.' : "Deleted $count for good. Their history is kept.");
        return '';
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
        // the pictures of the whole page in one query rather than one per row; a file in the
        // bin comes back as nothing, the same answer the site gives. A list without the column
        // asks for no pictures at all.
        $withPictures = $this->showsPictures((string)($context['type'] ?? ''));
        $pictures = $withPictures ? $this->media->findByIds(array_column($rows, 'featured_media_id')) : [];
        $rows = array_map(fn(array $row) => $this->row($row, $withPictures ? $pictures : null), $rows);
        return $this->rows($rows, $this->content->countAll($context));
    }

    /**
     * Whether this type's list carries the featured picture column
     *
     * Posts always. Pages when the Admin settings tab says so, off by default: a page is found by
     * its title and its place in the tree, and most have no picture to show.
     */
    protected function showsPictures(string $type): bool {
        return $type !== Content::TYPE_PAGE || $this->settings->getBool(Setting::ADMIN_PAGES_THUMBNAIL, false);
    }

    /**
     * One row, with only the columns the list shows
     *
     * Built by hand rather than handing the entity over: the row is a public API of the admin and
     * `markdown` / `body_html` have no business travelling to the browser on every list request.
     *
     * @param ?array $pictures [media id => Media] for a list with the picture column, null without
     */
    protected function row(array $content, ?array $pictures = null): array {
        $type = $content['type'];
        // nothing in the trash opens in the editor - it is restored first, from the row
        $editable = $this->can(Permissions::forContent($type, 'update'))
            && $content['status'] !== Content::STATUS_TRASH;
        $row = [
            'id'           => (int)$content['id'],
            'title'        => $content['title'],
            'status'       => $content['status'],
            'weight'       => (int)$content['weight'],
            'published_at' => $content['published_at'],
            'created_at'   => $content['created_at'],
            'updated_at'   => $content['updated_at'],
            // the way in, and the only one: the title cell is the link. Left out for somebody who
            // may not edit, and the column falls back to plain text - a link to a page that is
            // going to refuse them is worse than no link.
            'edit_url'     => $editable
                ? $this->router->url('/admin/content/'.$type.'/edit/'.$content['id']) : '',
        ];
        if ($pictures !== null) {
            $picture = $pictures[(int)($content['featured_media_id'] ?? 0)] ?? null;
            $row['thumbnail_html'] = $picture === null ? '' : $this->mediaView->tag($picture, 'thumb');
        }
        return $row;
    }

    /**
     * What the browser needs to render the list
     */
    protected function listConfig(string $type): array {
        $rowActions = [];
        if ($this->can(Permissions::forContent($type, 'update'))) {
            // in place, and then the list again: a move is one step, and a page reload for each
            // would lose the scroll every time somebody walks a post up five places
            foreach ([ContentService::MOVE_UP => 'Move up', ContentService::MOVE_DOWN => 'Move down'] as $direction => $title) {
                $rowActions[] = [
                    'type' => 'move-'.$direction, 'title' => $title, 'icon' => $this->icon($direction),
                    'ajax' => $this->router->url('/admin/content/'.$type.'/move'),
                    'idParam' => 'id', 'params' => ['direction' => $direction],
                ];
            }
        }
        if ($this->can(Permissions::CONTENT_HISTORY)) {
            $rowActions[] = [
                'type' => 'history', 'title' => 'History', 'icon' => $this->icon('history'),
                'link' => $this->router->url('/admin/content/'.$type.'/history/'),
            ];
        }
        // Into the trash, with no question asked: it comes back with one click, and a confirmation
        // in front of something that undoes is a dialog people learn to dismiss before it reaches
        // the one that does not - Delete permanently, in the trash.
        if ($this->can(Permissions::forContent($type, 'delete'))) {
            $rowActions[] = [
                'type' => 'delete', 'title' => 'Move to trash', 'icon' => $this->icon('delete'),
                'post' => $this->router->url('/admin/content/'.$type.'/delete/'),
            ];
        }
        $config = [
            'endpoint' => $this->router->url('/admin/content/'.$type.'/list'),
            // No column: the order the site uses - weight, then what up and down arranged, then
            // the date - so the list shows what a visitor sees and a move is one row on screen.
            // Any column header still sorts by that column alone.
            'orderBy'  => '',
            'orderDir' => 'asc',
            'columns'  => [
                // the id, because it is what a reference in somebody's markdown is made of:
                // `post#42` is written by hand as often as it is inserted by a button
                'id'     => ['label' => '#', 'align' => 'right', 'width' => '1%'],
                // the featured picture, which is how a post is recognised at a glance more often
                // than by its title - and a way into the editor like the title is. On the Pages
                // list only when the Admin settings tab turns it on; see `showsPictures()`.
                'thumbnail_html' => ['label' => '', 'view' => 'htmlLink', 'sortable' => false, 'width' => '54px',
                                     'options' => ['hrefProperty' => 'edit_url']],
                // No slug column. It is in the editor, where it is edited, and on a list it was a
                // second copy of the title in a different shape - taking the width the dates and
                // the weight now want.
                'title'  => ['label' => 'Title', 'view' => 'link', 'options' => ['hrefProperty' => 'edit_url']],
                'status' => ['label' => 'Status', 'view' => 'badge', 'options' => [
                    'labels' => [Content::STATUS_DRAFT => 'Draft', Content::STATUS_PUBLISHED => 'Published'],
                ]],
                // sortable like the rest, which is how "show me what I have ordered by hand"
                // is asked without a screen of its own
                'weight' => ['label' => 'Weight', 'align' => 'right', 'width' => '1%'],
                'published_at' => ['label' => 'Published', 'view' => 'dateTime'],
                'updated_at'   => ['label' => 'Changed', 'view' => 'dateTime'],
            ],
            'rowActions'   => $rowActions,
            'groupActions' => [],
        ];
        if (!$this->showsPictures($type)) {
            unset($config['columns']['thumbnail_html']);
        }
        return $config;
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
        // An old link, a bookmark, the back button: the editor would save it and leave it in the
        // trash with changes nobody can see, or publish it from there. Restoring comes first.
        if ($content->isTrashed()) {
            $this->done('/admin/content/'.$type.'/trash', 'That one is in the trash. Restore it to edit it.');
            return '';
        }
        $form = $this->forms->create(AdminForms::CONTENT, $this->editorContext($type, $content));
        // The date is checked before anything is written. Half a save - the text stored and the
        // date refused - is a worse answer than none, and `done()` redirects, so a message put
        // on the form after the save is a message nobody ever sees.
        if ($form->process() && $this->publishedAtIsReadable($form) && $this->boxesAreClosed($form)) {
            // read before the save, which is what turns an auto-draft into a draft
            $wasAutoDraft = $content->isAutoDraft();
            $form->handle(function ($form) use ($content, $type) {
                $values = $form->values();
                // one `update()` and so one revision: a second save for the author alone would
                // put two rows in the history for one press of the button
                $this->content->update(
                    $content,
                    $this->contentData($values) + $this->authorData($type, $values, $content)
                );
                $this->applyTaxonomy($content, $values);
                return $content;
            });
            $this->applyPublication($content, $form, $type);
            $this->done('/admin/content/'.$type, $wasAutoDraft ? 'Created.' : 'Saved.');
        }
        return $this->editor($type, $form, $content);
    }

    /**
     * The editor's boxes as the page they would make, saving none of it
     *
     * Posted from the editor form by a submit button with `formaction`, so the values arrive
     * exactly as Save would send them - no second copy of the fields and no JavaScript. What comes
     * back is the **theme's** page, rendered from a `Content` that lives for one request and is
     * never handed to the entity manager.
     *
     * Saving first and then looking would have been simpler, and it is wrong for the case that
     * matters: on a *published* post it would put the unsaved edits live, which is the opposite of
     * a preview. It would also write a revision every time somebody peeked.
     *
     * **No CSRF token, on purpose.** `Form::process()` mints a fresh one into the session every
     * time it runs, so checking it here would spend the token printed on the editor page that is
     * still open behind this new tab, and the next Save would be refused as a forgery. Leaving it
     * out is safe because there is nothing for a forged request to do: this writes nothing, and
     * the renderer strips HTML, so there is no state change and no script to reflect. The
     * permission is the guard.
     */
    #[Route('POST', '/admin/content/?/preview/?')]
    public function preview(string $type, string $id): string {
        $this->enter($type, 'update');
        $stored = $this->found($this->content->findById((int)$id));
        $this->assertType($stored, $type);
        // `csrf: false` so `process()` leaves the editor's token where it is - see above
        $form = $this->forms->create(AdminForms::CONTENT, $this->editorContext($type, $stored), false);
        $form->process(); // the result is ignored: previewing half a post is the whole point
        $token = $this->keepPreview($stored->id, $form->values());
        // Post, redirect, get. The boxes arrive by POST because that is the only way to send
        // them, and everything after that is a GET of a real address - which is what lets the
        // page links of a body written in `---` parts be **links**, the way they are on the
        // published page. Refreshing the tab stops re-posting too.
        $params = [self::PREVIEW_TOKEN => $token];
        $page = $this->previewPageOf($stored, $form->values());
        if ($page > 1) {
            $params[self::PAGE_PARAM] = $page;
        }
        $this->app()->redirect('/admin/content/'.$type.'/preview/'.$stored->id, $params, 303);
        return '';
    }

    /**
     * One page of a preview that was handed over a moment ago
     *
     * A GET, so the pager is ordinary links and a theme needs to know nothing about previews. The
     * token says which set of boxes to read; without a live one there is nothing to show, and
     * saying so is better than rendering the stored post under a bar that claims it is unsaved.
     */
    #[Route('GET', '/admin/content/?/preview/?')]
    public function previewPage(string $type, string $id): string {
        $this->enter($type, 'update');
        $stored = $this->found($this->content->findById((int)$id));
        $this->assertType($stored, $type);
        $token = (string)$this->request->get(self::PREVIEW_TOKEN, '');
        $values = $this->storedPreview($stored->id, $token);
        if ($values === null) {
            return $this->message('Preview', 'This preview is not here any more.'
                .' Press Preview in the editor again.',
                ['url' => $this->router->url('/admin/content/'.$type.'/edit/'.$stored->id),
                 'label' => 'Back to the editor']);
        }
        // the token travels with every page number, so a body written in `---` parts pages
        // through exactly as it will once it is saved
        return $this->renderPreview($stored, $values, '/admin/content/'.$type.'/preview/'.$stored->id,
            [self::PREVIEW_TOKEN => $token], true);
    }

    /**
     * A set of the editor's values, drawn as the page they would make
     *
     * What the editor's Preview and a revision's preview both are: the stored row with some of
     * its fields replaced, rendered through the theme under the preview bar - which says `true`'s
     * "nothing here is saved", or whatever `$bar` says instead.
     *
     * @param array  $routeParams what every page link of a `---` body has to carry along
     * @param string|bool $bar    the preview bar's message, or `true` for the editor's own
     */
    protected function renderPreview(Content $stored, array $values, string $route, array $routeParams, string|bool $bar): string {
        $content = $this->previewOf($stored, $values);
        $this->addContentStyle($content);
        $common = $this->pagedBody($content, $route, $routeParams) + [
            'preview'     => $bar,
            'title'       => $content->title,
            'content'     => $content,
            'author'      => $this->authorOf($content),
            // attaching is an immediate write, so what is stored is what the editor is showing
            'attachments' => $this->media->attachmentsOf($stored->id),
            'featured'    => $content->featured_media_id !== null
                ? $this->media->findById($content->featured_media_id) : null,
            'mediaView'   => $this->mediaView,
        ];
        if ($content->isPage()) {
            return $this->render('dpress:content/page', $common + [
                // from the stored row rather than from the posted parent: nothing has checked the
                // new one for a cycle, and `ancestors()` walking a loop would not come back
                'ancestors' => $this->content->ancestors($stored),
                'children'  => $this->content->findChildren($stored->id),
            ], 'page');
        }
        return $this->render('dpress:content/single', $common + [
            'tags'       => $this->previewTags($values),
            'categories' => $this->previewCategories($values),
        ], 'post');
    }

    /**
     * The page of the body the cursor was on, so a preview opens where the writing was
     *
     * Without the script there is no line and every preview opens at page one, which is where it
     * opened before this existed.
     */
    protected function previewPageOf(Content $stored, array $values): int {
        $line = (string)($values['cursor_line'] ?? '');
        if (!ctype_digit($line)) {
            return 1;
        }
        $markdown = array_key_exists('markdown', $values)
            ? (string)$values['markdown'] : $stored->markdown;
        return $this->markdown->pageOfLine($markdown, (int)$line);
    }

    /**
     * Puts the boxes somewhere the next few GETs can read them, and says where
     *
     * The session and **not the database**: the post itself is still never written, which is the
     * whole point of a preview. This is the tab's own copy, it belongs to one person, and it goes
     * when the session does.
     *
     * A few are kept rather than one, so previewing two posts in two tabs does not knock the
     * first one out - and a few rather than all of them, so a long session is not a place a
     * hundred drafts pile up.
     */
    protected function keepPreview(int $contentId, array $values): string {
        $token = bin2hex(random_bytes(16));
        $store = (array)$this->session->get(self::PREVIEW_SESSION, []);
        $store[$token] = ['content_id' => $contentId, 'values' => $values];
        $this->session->set(self::PREVIEW_SESSION,
            array_slice($store, -self::PREVIEW_KEEP, null, true));
        return $token;
    }

    /**
     * The boxes a token stands for, or null when there are none any more
     *
     * The content id is checked as well as the token: a preview is of one post, and a token that
     * has fallen off the end must not quietly render whatever else the address names.
     */
    protected function storedPreview(int $contentId, string $token): ?array {
        $store = (array)$this->session->get(self::PREVIEW_SESSION, []);
        $kept = $store[$token] ?? null;
        if (!is_array($kept) || ($kept['content_id'] ?? 0) !== $contentId) {
            return null;
        }
        return (array)($kept['values'] ?? []);
    }

    /**
     * The stored row with the posted boxes laid over it, rendered and not saved
     *
     * A clone rather than the row itself, so nothing later in the request can save the edited
     * copy by accident. The **id stays the stored one**, which is what lets the media, the
     * attachments and the author still resolve.
     *
     * `parent_id` is deliberately not copied: moving a page changes its ancestors, nothing here
     * has checked the new parent for a cycle, and walking a loop does not come back.
     */
    protected function previewOf(Content $stored, array $values): Content {
        $content = clone $stored;
        $data = $this->contentData($values);
        if (array_key_exists('title', $data)) {
            $content->title = trim($data['title']);
        }
        if (array_key_exists('markdown', $data)) {
            $content->markdown = (string)$data['markdown'];
        }
        if (array_key_exists('featured_media_id', $data)) {
            $content->featured_media_id = $data['featured_media_id'];
        }
        // the styles as typed, so trying a rule out is a preview and not a save
        if (array_key_exists('css', $data)) {
            $content->css = $data['css'];
        }
        $this->content->renderInto($content);
        return $content;
    }

    /**
     * The categories ticked right now, whether or not the tick has been saved
     */
    protected function previewCategories(array $values): array {
        $chosen = array_map('strval', (array)($values['categories'] ?? []));
        return array_values(array_filter(
            $this->taxonomy->categories(), fn($row) => in_array((string)$row['id'], $chosen, true)
        ));
    }

    /**
     * The tags typed into the box, matched against the ones that exist
     *
     * A name nobody has used yet has no row and so no slug - it gets one made the way
     * `findOrCreateTag()` would, so the chip reads right. Its link is a 404 until the post is
     * saved and the tag really made, which is exactly the truth about a tag that is not there.
     */
    protected function previewTags(array $values): array {
        $typed = array_filter(array_map('trim', explode(',', (string)($values['tags'] ?? ''))));
        if ($typed === []) {
            return [];
        }
        $existing = [];
        foreach ($this->taxonomy->tags() as $row) {
            $existing[mb_strtolower($row['name'])] = $row;
        }
        $tags = [];
        foreach ($typed as $name) {
            $tags[] = $existing[mb_strtolower($name)]
                ?? ['name' => $name, 'slug' => $this->slugger->slugify($name)];
        }
        return $tags;
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
                    'thumbnail_html' => ['label' => 'Icon', 'view' => 'html', 'sortable' => false, 'width' => '52px'],
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
            'back_url' => $this->router->url('/admin/content/'.$type),
            // A saved post, draft or published alike: the front end already serves an unpublished
            // one to anybody who may edit posts. An auto-draft holds nothing yet, so there is
            // nothing to look at - that is what Preview is for.
            'view_url' => $isNew ? '' : $this->router->url($this->content->publicPath($content)),
            'preview_url' => $this->router->url('/admin/content/'.$type.'/preview/'.$content->id),

            // one revision saying an empty row was made is not a history worth offering
            'history_url' => !$isNew && $this->can(Permissions::CONTENT_HISTORY)
                ? $this->router->url('/admin/content/'.$type.'/history/'.$content->id) : '',
        ]);
    }

    /**
     * Whether the Published box can be read, saying so on the field when it cannot
     *
     * A plain text box rather than a date picker, because writing `1999-01-02` is faster than
     * four clicks - which means a typo is possible, and a typo has to come back as a sentence
     * about that box rather than as a date somewhere near the one that was meant.
     */
    /**
     * Every `:::` box in the text opened and closed, before anything is written
     *
     * The renderer would close what is left open, so this is not about a broken page - it is
     * about the page somebody meant. A stray `:::` or a box that swallows the rest of the post is
     * a mistake that shows only once it is published, and the line number is here, now.
     */
    protected function boxesAreClosed($form): bool {
        $markdown = (string)($form->values()['markdown'] ?? '');
        $problems = FencedDivs::problems($markdown, $this->markdown->breaks($markdown));
        if ($problems === []) {
            return true;
        }
        $more = count($problems) - 1;
        $form->addFieldError('markdown', $problems[0].($more > 0 ? " (And $more more.)" : ''));
        return false;
    }

    protected function publishedAtIsReadable($form): bool {
        $typed = trim((string)($form->values()['published_at'] ?? ''));
        if ($typed === '' || $this->dates->parse($typed) !== null) {
            return true;
        }
        $form->addFieldError('published_at', 'Write it as 1999-01-02, or 1999-01-02 14:30 with a time.');
        return false;
    }

    /**
     * Publishes, unpublishes or re-dates, when the editor asked for it and may
     *
     * The third case is the one the select cannot express: a post that is already published
     * and whose date moved. That is what importing an old post is - published today, dated
     * years ago - and the archive, the ordering and the byline all read off `published_at`.
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
    protected function applyPublication(?Content $content, $form, string $type): void {
        $values = $form->values();
        if (!$content instanceof Content
            || !array_key_exists('status', $values)
            || !$this->can(Permissions::forContent($type, 'publish'))) {
            return;
        }
        $publishedAt = $this->dates->parse((string)($values['published_at'] ?? ''));
        $change = $this->statusChange($content->status, (string)$values['status']);
        if ($change === 'publish') {
            $this->content->publish($content, $publishedAt);
        } else if ($change === 'unpublish') {
            $this->content->unpublish($content);
        } else if ($publishedAt !== null) {
            // already published, and the date moved - the one case the status select
            // cannot express, and the whole reason a post can be imported with its own date
            $this->content->setPublishedAt($content, $publishedAt);
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
            // empty for anybody who may not reassign, and the form offers no box for it -
            // `AdminForms::content()` asks this and nothing else
            'authors' => $this->canAssignAuthor($type) ? $this->authorOptions($content) : [],
            // a select that cannot do anything is worse than no select: the page says "Saved."
            // and nothing moved, which is exactly the bug this whole method exists to fix
            'can_publish' => $this->can(Permissions::forContent($type, 'publish')),
            'can_attach'  => $this->can(Permissions::MEDIA_VIEW),
            // the thumbnail the field shows for what is already chosen. Rendered here because a
            // template has no business asking a service what a media id looks like.
            'featured_preview' => $this->featuredPreview($content),
            // in the site's timezone and in the shape the field accepts back, so what is shown
            // is what would be saved again if nobody touched it
            'published_input' => $this->dates->input($content?->published_at),

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
     * May this person put somebody else's name on this?
     *
     * Its own permission rather than `update`, because it is a different act: writing a post
     * and deciding who wrote it are not the same authority. The stock editor role does not
     * hold it and the admin role holds every permission implicitly, so out of the box it is
     * an administrator's.
     */
    protected function canAssignAuthor(string $type): bool {
        return $this->can(Permissions::forContent($type, 'assign_author'));
    }

    /** The roles whose holders can be named as a post's author: the people who write here */
    const AUTHOR_ROLES = [Role::NAME_ADMIN, Role::NAME_EDITOR];

    /**
     * Everybody who could be named as the author, by id
     *
     * **The admins and the editors**, not every account: a site with registration open has
     * readers with accounts, and a select full of them is a list to scroll past to find the four
     * people who write. Blocked ones stay: somebody who has left still wrote what they wrote.
     *
     * **And the post's own author, whoever that is.** Somebody who has since lost the role still
     * wrote it, and a name missing from the select would make the next save quietly hand the post
     * to whoever is at the top of the list.
     *
     * A select, like the parent page one, which is the same bet: a site with thousands of
     * writers wants a search box here instead, and this CMS does not have one anywhere yet.
     *
     * @return array [id => name]
     */
    protected function authorOptions(?Content $content): array {
        $options = [];
        $rows = $this->users->findAll(['role_names' => self::AUTHOR_ROLES, 'order_by' => 'name', 'order_dir' => 'asc']);
        foreach ($rows as $user) {
            $options[(int)$user['id']] = $user['name'];
        }
        $current = $content !== null ? (int)$content->author_id : 0;
        if ($current > 0 && !isset($options[$current]) && ($author = $this->users->findById($current)) !== null) {
            $options[$current] = $author->name;
            asort($options, SORT_NATURAL | SORT_FLAG_CASE);
        }
        return $options;
    }

    /**
     * The chosen author as an update, if this person may choose one and chose a real one
     *
     * Checked against what the select offered rather than trusted: `author_id` is a foreign key,
     * so an id that is not a user is an exception on save, and one that is a reader's account is
     * a post handed to somebody the select never showed.
     *
     * **Separate from `contentData()`, and merged in at the call site.** That method maps
     * boxes to columns and asks nothing about who is asking - which is what lets the preview
     * route reuse it on a controller with no request behind it. A permission check inside it
     * turned every preview into a fatal, and the preview is the one screen where a fatal is a
     * blank page in a new tab with the editor still open behind it.
     */
    protected function authorData(string $type, array $values, ?Content $content = null): array {
        if (!$this->canAssignAuthor($type) || !array_key_exists('author_id', $values)) {
            return [];
        }
        $id = (int)$values['author_id'];
        return $id > 0 && isset($this->authorOptions($content)[$id]) ? ['author_id' => $id] : [];
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
        // an empty box is 0 and not "leave it alone": the field is on the form, and somebody
        // who cleared it meant to say "back to normal"
        if (array_key_exists('weight', $values)) {
            $data['weight'] = (int)$values['weight'];
        }
        if (array_key_exists('css', $values)) {
            $data['css'] = (string)$values['css'];
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
     * Up or down one place, from the list
     *
     * Answers in JSON rather than redirecting: the list sends it with `fetch` and refreshes
     * itself. At the end of its group it simply does not move - the top of a weight is as far as
     * up goes, because past it is the weight's to decide and not this button's.
     */
    #[Route('POST', '/admin/content/?/move')]
    public function move(string $type): array {
        $this->enter($type, 'update');
        $this->requireAction();
        $content = $this->found($this->content->findById((int)$this->request->get('id', 0)));
        $this->assertType($content, $type);
        $direction = (string)$this->request->get('direction', '');
        if ($direction !== ContentService::MOVE_UP && $direction !== ContentService::MOVE_DOWN) {
            $this->app()->sendError(400);
        }
        return $this->answer(['moved' => $this->content->move($content, $direction)]);
    }

    #[Route('POST', '/admin/content/?/publish/?')]
    public function publish(string $type, string $id): string {
        $this->enter($type, 'publish');
        $this->requireAction();
        $content = $this->found($this->content->findById((int)$id));
        $this->assertType($content, $type);
        if ($content->isTrashed()) {
            $this->done('/admin/content/'.$type.'/trash', 'That one is in the trash. Restore it first.');
            return '';
        }
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

    /**
     * Delete, from the list - which is a move to the trash; for good is `destroy()`, in there
     *
     * The same address it always had, so a plugin's link or somebody's muscle memory still lands
     * on the gentle one.
     */
    #[Route('POST', '/admin/content/?/delete/?')]
    public function delete(string $type, string $id): string {
        $this->enter($type, 'delete');
        $this->requireAction();
        $content = $this->found($this->content->findById((int)$id));
        $this->assertType($content, $type);
        $this->content->trash($content);
        $this->done('/admin/content/'.$type, 'Moved to the trash.');
        return '';
    }

    // --- history ---

    #[Route('GET', '/admin/content/?/history/?')]
    public function history(string $type, string $id): string {
        $this->enter($type, 'view');
        $this->requirePermission(Permissions::CONTENT_HISTORY);
        $content = $this->found($this->content->findById((int)$id));
        $this->assertType($content, $type);
        $base = '/admin/content/'.$type.'/history/'.$content->id;
        return $this->admin('dpress_admin:content/history', [
            'title'     => 'History',
            'type'      => $type,
            'content'   => $content,
            'revisions' => $this->history->revisions($content->id),
            'back_url'  => $this->router->url('/admin/content/'.$type.'/edit/'.$content->id),
            // the two per-row buttons, as prefixes the revision's id is put after
            'preview_url' => $this->router->url($base.'/preview/'),
            // restoring writes, so it is for somebody who may edit - and not from the trash
            'restore_url' => $this->can(Permissions::forContent($type, 'update')) && !$content->isTrashed()
                ? $this->router->url($base.'/restore/') : '',
            'preview_icon' => $this->icon('eye'),
            'restore_icon' => $this->icon('restore'),
        ]);
    }

    /**
     * One revision, drawn as the page it made
     *
     * Through the editor's preview renderer, with the revision's writing in place of the stored
     * row's - so what is on the screen is what restoring it would put on the site, down to the
     * CSS. The tags and categories are today's: they are not part of a revision.
     */
    #[Route('GET', '/admin/content/?/history/?/preview/?')]
    public function revisionPreview(string $type, string $id, string $revisionId): string {
        [$content, $revision] = $this->revisionOf($type, $id, $revisionId);
        $values = ['tags' => implode(', ', array_column($this->taxonomy->tagsOf($content->id), 'name')),
                   'categories' => $this->taxonomy->categoryIdsOf($content->id)];
        foreach (ContentService::REVISION_FIELDS as $field) {
            $values[$field] = (string)($revision[$field] ?? '');
        }
        $when = substr((string)($revision['rev_at'] ?? ''), 0, 16);
        $who = trim((string)($revision['rev_user_name'] ?? ''));
        return $this->renderPreview($content, $values,
            '/admin/content/'.$type.'/history/'.$content->id.'/preview/'.$revisionId, [],
            'A revision from '.$when.($who !== '' ? ', by '.$who : '').' - how it looked then. Nothing here is saved.');
    }

    /**
     * Puts a revision's writing back - as a new revision, so nothing in between is lost
     *
     * Asked about first, in the browser: it replaces the text somebody is working on.
     */
    #[Route('POST', '/admin/content/?/history/?/restore/?')]
    public function revisionRestore(string $type, string $id, string $revisionId): string {
        $this->enter($type, 'update');
        $this->requireAction();
        [$content, $revision] = $this->revisionOf($type, $id, $revisionId);
        $history = '/admin/content/'.$type.'/history/'.$content->id;
        if ($content->isTrashed()) {
            $this->done($history, 'That one is in the trash. Restore it first.');
            return '';
        }
        $this->content->restoreRevision($content, $revision);
        $this->done($history, 'Restored the revision from '.substr((string)($revision['rev_at'] ?? ''), 0, 16)
            .'. What it said before is the revision above it.');
        return '';
    }

    /**
     * The content and one of its revisions, or a 404 - checked for the history permission, and
     * that the revision is a state of it and not its deletion
     *
     * @return array [Content, array]
     */
    protected function revisionOf(string $type, string $id, string $revisionId): array {
        $this->enter($type, 'view');
        $this->requirePermission(Permissions::CONTENT_HISTORY);
        $content = $this->found($this->content->findById((int)$id));
        $this->assertType($content, $type);
        $revision = $this->history->revision($content->id, (int)$revisionId);
        if ($revision === null || ($revision['rev_type'] ?? '') === AuditService::TYPE_DEL) {
            $this->app()->sendError(404);
        }
        return [$content, $revision];
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
