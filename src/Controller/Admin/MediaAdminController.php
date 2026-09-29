<?php

namespace Dynart\Dpress\Controller\Admin;

use Dynart\Micro\Attribute\Route;
use Dynart\Micro\ConfigInterface;
use Dynart\Micro\JwtAuthInterface;
use Dynart\Micro\RequestInterface;
use Dynart\Micro\RouterInterface;
use Dynart\Micro\UploadedFile;
use Dynart\Micro\ViewInterface;
use Dynart\Dpress\DpressException;
use Dynart\Dpress\Entity\Media;
use Dynart\Dpress\Form\AdminForms;
use Dynart\Dpress\Form\FormFactory;
use Dynart\Dpress\Media\MediaTypes;
use Dynart\Dpress\Media\MediaView;
use Dynart\Dpress\Query\ListRequest;
use Dynart\Dpress\Security\Permissions;
use Dynart\Dpress\Service\MediaService;

/**
 * The media library
 *
 * The same list the media picker opens in a dialog: one endpoint, one set of columns, one place
 * where "which items may this person see" is decided.
 */
class MediaAdminController extends AbstractAdminController {

    const SORTABLE = ['id', 'file_name', 'title', 'category', 'size', 'created_at'];

    public function __construct(
        ViewInterface $view,
        RouterInterface $router,
        RequestInterface $request,
        ConfigInterface $config,
        JwtAuthInterface $jwtAuth,
        FormFactory $forms,
        ListRequest $list,
        protected MediaService $media,
        protected MediaView $mediaView,
        protected MediaTypes $types,
    ) {
        parent::__construct($view, $router, $request, $config, $jwtAuth, $forms, $list);
    }

    protected function section(): string {
        return 'media';
    }

    #[Route('GET', '/admin/media')]
    public function index(): string {
        $this->requirePermission(Permissions::MEDIA_VIEW);
        $config = $this->listConfig();
        $config['firstPage'] = $this->page($this->firstPageContext($config, self::SORTABLE, ['search', 'category']));
        return $this->admin('dpress_admin:media/list', [
            'title'       => 'Media',
            'can_upload'  => $this->can(Permissions::MEDIA_CREATE),
            'upload_url'  => $this->router->url('/admin/media/upload'),
            'categories'  => Media::CATEGORIES,
            'list_id'     => 'media-list',
            'list_config' => $config,
            // the way into the trash, for somebody who may put things in it and take them out
            'trash'       => $this->can(Permissions::MEDIA_DELETE) ? [
                'url'   => $this->router->url('/admin/media/trash'),
                'icon'  => $this->icon('delete'),
                'count' => $this->media->countAll(['trashed' => true]),
            ] : null,
        ]);
    }

    #[Route('GET', '/admin/media/list')]
    public function rowsJson(): array {
        $this->requirePermission(Permissions::MEDIA_VIEW);
        return $this->page($this->list->context(self::SORTABLE, ['search', 'category']));
    }

    // --- the trash ---

    /**
     * What is in the trash, and nothing that is not
     *
     * A screen of its own in place of the "Show deleted" box the list had: here a row is always
     * a deleted one, so its two actions - Restore, Delete permanently - never have to ask which
     * kind of row they are on.
     */
    #[Route('GET', '/admin/media/trash')]
    public function trash(): string {
        $this->requirePermission(Permissions::MEDIA_DELETE);
        $config = $this->trashConfig();
        $config['firstPage'] = $this->page(
            ['trashed' => true] + $this->firstPageContext($config, self::SORTABLE, ['search', 'category'])
        );
        return $this->admin('dpress_admin:trash', [
            'title'       => 'Media',
            'back_url'    => $this->router->url('/admin/media'),
            'empty_url'   => $this->router->url('/admin/media/empty-trash'),
            'empty_confirm' => 'Delete every file in the trash for good? The files are removed from disk,'
                .' and every revision of a post that shows one of them will break.',
            'list_id'     => 'media-trash',
            'list_config' => $config,
            'filters'     => $this->view->fetch('dpress_admin:media/filters', ['categories' => Media::CATEGORIES]),
        ]);
    }

    #[Route('GET', '/admin/media/trash/list')]
    public function trashRows(): array {
        $this->requirePermission(Permissions::MEDIA_DELETE);
        return $this->page(['trashed' => true] + $this->list->context(self::SORTABLE, ['search', 'category']));
    }

    /**
     * The list's own columns, with the trash's endpoint and the trash's two actions
     */
    protected function trashConfig(): array {
        $config = $this->listConfig();
        $config['endpoint'] = $this->router->url('/admin/media/trash/list');
        $config['rowActions'] = [
            ['type' => 'restore', 'title' => 'Restore', 'icon' => $this->icon('restore'),
             'post' => $this->router->url('/admin/media/restore/')],
            ['type' => 'delete', 'title' => 'Delete permanently', 'icon' => $this->icon('delete'),
             'post' => $this->router->url('/admin/media/purge/'),
             'confirm' => 'Delete this file for good? It is removed from disk, and every revision of a post that shows it will break.'],
        ];
        return $config;
    }

    /**
     * One page of rows, for this endpoint and for the screen that seeds its first page
     */
    protected function page(array $context): array {
        $rows = $this->media->findAll($context);
        return $this->rows(array_map([$this, 'row'], $rows), $this->media->countAll($context));
    }

    protected function row(array $media): array {
        return [
            'id'             => (int)$media['id'],
            'file_name'      => $media['file_name'],
            'title'          => (string)($media['title'] ?? ''),
            'alt'            => (string)($media['alt'] ?? ''),
            'category'       => $media['category'],
            'mime_type'      => $media['mime_type'],
            'size'           => (int)$media['size'],
            'created_at'     => $media['created_at'],
            'deleted'        => $media['deleted_at'] !== null,
            'url'            => $this->mediaView->rowUrl($media),
            'thumbnail_url'  => $this->mediaView->rowUrl($media, 'thumb'),
            'thumbnail_html' => $this->mediaView->rowTag($media),
            'edit_url'       => $this->can(Permissions::MEDIA_UPDATE)
                ? $this->router->url('/admin/media/edit/'.$media['id']) : '',
        ];
    }

    protected function listConfig(): array {
        // Into the trash, with no question asked: it comes back out with one click, and a
        // confirmation in front of something that undoes is a dialog people learn to dismiss
        // before it reaches the one that does not.
        $rowActions = $this->can(Permissions::MEDIA_DELETE) ? [[
            'type' => 'delete', 'title' => 'Move to trash', 'icon' => $this->icon('delete'),
            'post' => $this->router->url('/admin/media/delete/'),
        ]] : [];
        return [
            'endpoint' => $this->router->url('/admin/media/list'),
            'orderBy'  => 'created_at',
            'orderDir' => 'desc',
            'columns'  => [
                'id' => ['label' => '#', 'align' => 'right', 'width' => '1%'],
                // the name opens the item's own page, as it does in every other list; the
                // picture opens the file, which is the thing this list is otherwise the only
                // one-click way to reach
                'thumbnail_html' => ['label' => 'Icon', 'view' => 'htmlLink', 'sortable' => false, 'width' => '54px',
                                     'options' => ['hrefProperty' => 'url']],
                'file_name'  => ['label' => 'File', 'view' => 'link', 'options' => ['hrefProperty' => 'edit_url']],
                'category'   => ['label' => 'Kind'],
                'size'       => ['label' => 'Size', 'view' => 'bytes', 'align' => 'right'],
                'created_at' => ['label' => 'Uploaded', 'view' => 'dateTime'],
            ],
            'rowActions'   => $rowActions,
            'groupActions' => [],
        ];
    }

    // --- uploading ---

    #[Route('GET', '/admin/media/upload')]
    #[Route('POST', '/admin/media/upload')]
    public function upload(): string {
        $this->requirePermission(Permissions::MEDIA_CREATE);
        $form = $this->forms->create(AdminForms::UPLOAD);
        if ($form->process()) {
            $file = $form->uploadedFile('file');
            try {
                $form->handle(fn() => $this->media->upload($file, (int)$this->currentUser()->id()));
                $this->done('/admin/media', 'Uploaded.');
            } catch (DpressException $e) {
                $form->addError($e->getMessage());
            }
        }
        return $this->admin('dpress_admin:media/upload', [
            'title'  => 'Upload',
            'form'   => $form,
            'max_size' => $this->media->humanSize($this->media->maxUploadSize()),
            'allowed'  => array_values(array_unique(array_values(MediaTypes::EXTENSIONS))),
            'back_url' => $this->router->url('/admin/media'),
        ]);
    }

    // --- editing ---

    /**
     * The same upload, answering with data instead of a redirect
     *
     * The dialog cannot follow a redirect: the whole point of it is that the screen behind it -
     * a half-written post, most likely - is still there afterwards. So this is a separate action
     * rather than a branch inside `upload()`, which stays exactly what it was: the route into the
     * library for somebody with no JavaScript, and not a place to grow two behaviours.
     *
     * **A rejected file is a 200 with an `error`.** Too large, or a type this site does not
     * accept, are ordinary answers to an ordinary request - `MediaService::upload()` already
     * throws them with a sentence meant for a person. A 500 would say the server broke, and the
     * dialog would have nothing useful to show.
     */
    #[Route('POST', '/admin/media/upload/json')]
    public function uploadJson(): array {
        $this->requirePermission(Permissions::MEDIA_CREATE);
        $this->requireAction();
        $file = $this->request->uploadedFile('file');
        if (!$file instanceof UploadedFile) {
            return $this->answer(['error' => 'No file arrived. It may be larger than the server accepts.']);
        }
        try {
            $media = $this->media->upload($file, (int)$this->currentUser()->id());
        } catch (DpressException $e) {
            return $this->answer(['error' => $e->getMessage()]);
        }
        return $this->answer(['item' => $this->row($this->mediaRow($media))]);
    }

    /**
     * A freshly saved entity as the row shape the list speaks
     *
     * `get_object_vars()` rather than a hand-written list: an `Entity`'s state is private on the
     * base class, so its public properties *are* its columns. A column added to `Media` then
     * reaches this without anybody remembering to add it here, which a literal list would not.
     */
    protected function mediaRow(Media $media): array {
        return get_object_vars($media);
    }

    /**
     * What the editor's *Preview media* shows for a `media#<id>` (`?id=`)
     *
     * An image at the `large` preset - a preview does not need the original's bytes, and a
     * 6000px photo would arrive long after the dialog did - and anything else as it is stored.
     * A row in the trash is answered too, with `deleted`: a post can still say `media#12` after
     * somebody binned it, and "in the trash" is the useful thing to tell its author.
     */
    #[Route('GET', '/admin/media/preview')]
    public function preview(): array {
        $this->requirePermission(Permissions::MEDIA_VIEW);
        $media = $this->found($this->media->findById((int)$this->request->get('id', 0)));
        return [
            'id'        => $media->id,
            'category'  => $media->category,
            'mime_type' => $media->mime_type,
            'url'       => $this->mediaView->url($media, $media->isResizable() ? 'large' : ''),
            'file_name' => $media->file_name,
            'alt'       => (string)$media->alt,
            'width'     => $media->width,
            'height'    => $media->height,
            'deleted'   => $media->isDeleted(),
        ];
    }

    #[Route('GET', '/admin/media/edit/?')]
    #[Route('POST', '/admin/media/edit/?')]
    public function edit(string $id): string {
        $this->requirePermission(Permissions::MEDIA_UPDATE);
        $media = $this->found($this->media->findById((int)$id));
        $form = $this->forms->create(AdminForms::MEDIA, ['media' => $media]);
        if ($form->process()) {
            $form->handle(fn($form) => $this->media->update($media, $form->values()));
            $this->done('/admin/media', 'Saved.');
        }
        return $this->admin('dpress_admin:media/edit', [
            'title'  => 'Edit media',
            'form'   => $form,
            'media'  => $media,
            'preview' => $this->mediaView->tag($media, 'medium'),
            'url'     => $this->mediaView->url($media),
            'usage'   => $this->media->usageCount($media->id),
            'back_url' => $this->router->url('/admin/media'),
        ]);
    }

    #[Route('POST', '/admin/media/delete/?')]
    public function delete(string $id): string {
        $this->requirePermission(Permissions::MEDIA_DELETE);
        $this->requireAction();
        $this->media->delete($this->found($this->media->findById((int)$id)));
        $this->done('/admin/media', 'Moved to the trash.');
        return '';
    }

    #[Route('POST', '/admin/media/restore/?')]
    public function restore(string $id): string {
        $this->requirePermission(Permissions::MEDIA_DELETE);
        $this->requireAction();
        $this->media->restore($this->found($this->media->findById((int)$id)));
        $this->done('/admin/media/trash', 'Restored.');
        return '';
    }

    /**
     * Delete permanently - the file, its derivatives and the row
     *
     * Only for something already in the trash: a file still in the library is one click from a
     * post somebody is reading, and "gone from disk" should never be the first thing that happens
     * to it.
     */
    #[Route('POST', '/admin/media/purge/?')]
    public function purge(string $id): string {
        $this->requirePermission(Permissions::MEDIA_DELETE);
        $this->requireAction();
        $media = $this->found($this->media->findById((int)$id));
        if (!$media->isDeleted()) {
            $this->done('/admin/media', 'Move it to the trash first.');
            return '';
        }
        $cleared = $this->media->purge($media);
        $this->done('/admin/media/trash', 'Deleted for good.'
            .($cleared > 0 ? " $cleared post(s) lost their featured image." : ''));
        return '';
    }

    #[Route('POST', '/admin/media/empty-trash')]
    public function emptyTrash(): string {
        $this->requirePermission(Permissions::MEDIA_DELETE);
        $this->requireAction();
        [$purged, $cleared] = $this->media->emptyTrash();
        $this->done('/admin/media/trash', $purged === 0 ? 'The trash was already empty.'
            : "Deleted $purged file(s) for good.".($cleared > 0 ? " $cleared post(s) lost their featured image." : ''));
        return '';
    }
}
