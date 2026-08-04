<?php

namespace Dynart\Dpress\Controller\Admin;

use Dynart\Micro\Attribute\Route;
use Dynart\Micro\ConfigInterface;
use Dynart\Micro\JwtAuthInterface;
use Dynart\Micro\RequestInterface;
use Dynart\Micro\RouterInterface;
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

    const SORTABLE = ['file_name', 'title', 'category', 'size', 'created_at'];

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
        return $this->admin('dpress:admin/media/list', [
            'title'       => 'Media',
            'can_upload'  => $this->can(Permissions::MEDIA_CREATE),
            'upload_url'  => $this->router->url('/admin/media/upload'),
            'categories'  => Media::CATEGORIES,
            'list_id'     => 'media-list',
            'list_config' => $this->listConfig(),
        ]);
    }

    #[Route('GET', '/admin/media/list')]
    public function rowsJson(): array {
        $this->requirePermission(Permissions::MEDIA_VIEW);
        $context = $this->list->context(self::SORTABLE, ['search', 'category']);
        if ($this->can(Permissions::MEDIA_DELETE) && $this->request->get('with_deleted')) {
            $context['with_deleted'] = true;
        }
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
            'edit_url'       => $this->router->url('/admin/media/edit/'.$media['id']),
        ];
    }

    protected function listConfig(): array {
        $rowActions = [];
        if ($this->can(Permissions::MEDIA_UPDATE)) {
            $rowActions[] = ['type' => 'edit', 'title' => 'Edit', 'icon' => $this->icon('edit'),
                             'link' => $this->router->url('/admin/media/edit/')];
        }
        if ($this->can(Permissions::MEDIA_DELETE)) {
            $rowActions[] = ['type' => 'delete', 'title' => 'Delete', 'icon' => $this->icon('delete'),
                             'post' => $this->router->url('/admin/media/delete/'),
                             'confirm' => 'Delete this item? The file stays on disk until it is purged.',
                             'visibleWhen' => ['deleted' => false]];
            $rowActions[] = ['type' => 'restore', 'title' => 'Restore', 'icon' => $this->icon('restore'),
                             'post' => $this->router->url('/admin/media/restore/'),
                             'visibleWhen' => ['deleted' => true]];
        }
        return [
            'endpoint' => $this->router->url('/admin/media/list'),
            'orderBy'  => 'created_at',
            'orderDir' => 'desc',
            'columns'  => [
                'thumbnail_html' => ['label' => '', 'view' => 'html', 'sortable' => false, 'width' => '54px'],
                'file_name'  => ['label' => 'File', 'view' => 'link', 'options' => ['hrefProperty' => 'url']],
                'title'      => ['label' => 'Title'],
                'category'   => ['label' => 'Kind'],
                'size'       => ['label' => 'Size', 'view' => 'bytes', 'align' => 'right'],
                'created_at' => ['label' => 'Uploaded', 'view' => 'dateTime'],
            ],
            'rowActions' => $rowActions,
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
        return $this->admin('dpress:admin/media/upload', [
            'title'  => 'Upload',
            'form'   => $form,
            'narrow' => true,
            'max_size' => $this->media->humanSize($this->media->maxUploadSize()),
            'allowed'  => array_values(array_unique(array_values(MediaTypes::EXTENSIONS))),
            'back_url' => $this->router->url('/admin/media'),
        ]);
    }

    // --- editing ---

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
        return $this->admin('dpress:admin/media/edit', [
            'title'  => 'Edit media',
            'form'   => $form,
            'media'  => $media,
            'narrow' => true,
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
        $this->done('/admin/media', 'Deleted.');
        return '';
    }

    #[Route('POST', '/admin/media/restore/?')]
    public function restore(string $id): string {
        $this->requirePermission(Permissions::MEDIA_DELETE);
        $this->requireAction();
        $this->media->restore($this->found($this->media->findById((int)$id)));
        $this->done('/admin/media', 'Restored.');
        return '';
    }
}
