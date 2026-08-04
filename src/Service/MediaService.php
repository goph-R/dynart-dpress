<?php

namespace Dynart\Dpress\Service;

use Dynart\Micro\ConfigInterface;
use Dynart\Micro\EventServiceInterface;
use Dynart\Micro\UploadedFile;
use Dynart\Micro\Entities\Database;
use Dynart\Micro\Entities\EntityManager;
use Dynart\Micro\Entities\QueryExecutor;
use Dynart\Dpress\DpressException;
use Dynart\Dpress\Entity\ContentAttachment;
use Dynart\Dpress\Entity\Media;
use Dynart\Dpress\Media\ImageProcessor;
use Dynart\Dpress\Media\MediaStorage;
use Dynart\Dpress\Media\MediaTypes;
use Dynart\Dpress\Query\QueryFactory;

/**
 * The media library
 */
class MediaService {

    const EVENT_BEFORE_UPLOAD = 'media:before_upload';
    const EVENT_UPLOADED = 'media:uploaded';
    const EVENT_UPDATED = 'media:updated';
    const EVENT_DELETED = 'media:deleted';
    const EVENT_RESTORED = 'media:restored';
    const EVENT_PURGED = 'media:purged';
    const EVENT_ATTACHED = 'media:attached';
    const EVENT_DETACHED = 'media:detached';
    const EVENT_DERIVATIVE_CREATED = 'media:derivative_created';

    const CONFIG_MAX_SIZE = 'media.max_size';

    /** 16 MB */
    const DEFAULT_MAX_SIZE = 16777216;

    public function __construct(
        protected ConfigInterface $config,
        protected EntityManager $em,
        protected Database $db,
        protected QueryExecutor $queryExecutor,
        protected QueryFactory $queries,
        protected EventServiceInterface $events,
        protected MediaStorage $storage,
        protected MediaTypes $types,
        protected ImageProcessor $images,
    ) {}

    // --- Reading ---

    public function findById(int $id): ?Media {
        $media = $this->em->findById(Media::class, $id);
        return $media instanceof Media ? $media : null;
    }

    public function findByPath(string $path): ?Media {
        $id = $this->db->fetchOne(
            'select `id` from '.$this->em->safeTableName(Media::class).' where `path` = :path',
            [':path' => $path]
        );
        return $id === false || $id === null ? null : $this->findById((int)$id);
    }

    public function findAll(array $context = []): array {
        return $this->queryExecutor->findAll($this->queries->create('media_list', $context));
    }

    public function countAll(array $context = []): int {
        return (int)$this->queryExecutor->findAllCount($this->queries->create('media_list', $context));
    }

    /**
     * How many pieces of content use this item, so a delete can say what it affects
     */
    public function usageCount(int $mediaId): int {
        $attachments = (int)$this->db->fetchOne(
            'select count(1) from '.$this->em->safeTableName(ContentAttachment::class).' where `media_id` = :id',
            [':id' => $mediaId]
        );
        $featured = (int)$this->db->fetchOne(
            'select count(1) from '.$this->em->safeTableName(\Dynart\Dpress\Entity\Content::class)
                .' where `featured_media_id` = :id',
            [':id' => $mediaId]
        );
        return $attachments + $featured;
    }

    // --- Uploading ---

    /**
     * Takes an uploaded file into the library
     *
     * @throws DpressException if the type is not allowed, the file is too large, or it cannot be stored
     */
    public function upload(UploadedFile $file, int $userId, array $meta = []): Media {
        if ($file->error() !== UPLOAD_ERR_OK) {
            throw new DpressException($this->uploadErrorMessage($file->error()));
        }
        return $this->store($file->tempName(), $file->name(), $userId, $meta, true);
    }

    /**
     * Takes a file that is already on disk, for the CLI and for seeding
     */
    public function importFile(string $path, int $userId, array $meta = []): Media {
        if (!is_file($path)) {
            throw new DpressException("There is no file at '$path'.");
        }
        $temporary = tempnam(sys_get_temp_dir(), 'dpress');
        copy($path, $temporary);
        return $this->store($temporary, basename($path), $userId, $meta, false);
    }

    /**
     * The common part of both: sniff, check, name, move, record
     */
    protected function store(string $sourcePath, string $fileName, int $userId, array $meta, bool $isUpload): Media {
        $size = (int)@filesize($sourcePath);
        $limit = (int)$this->maxUploadSize();
        if ($size > $limit) {
            throw new DpressException('That file is larger than the '.$this->humanSize($limit).' limit.');
        }
        $mimeType = $this->types->detect($sourcePath);
        if (!$this->types->isAllowed($mimeType)) {
            throw new DpressException(
                $mimeType === ''
                    ? 'Could not work out what kind of file that is.'
                    : "Files of type '$mimeType' are not accepted."
            );
        }
        $this->events->emit(self::EVENT_BEFORE_UPLOAD, [$fileName, $mimeType, $size]);

        $relativePath = $this->storage->reservePath($fileName, $this->types->extensionOf($mimeType));
        $this->storage->protect();
        $this->storage->store($sourcePath, $relativePath, $isUpload);

        $media = new Media();
        $media->path = $relativePath;
        $media->file_name = $fileName;
        $media->mime_type = $mimeType;
        $media->category = $this->types->categoryOf($mimeType);
        $media->size = $size;
        $media->uploaded_by = $userId;
        $media->created_at = $this->now();
        $media->updated_at = $media->created_at;
        $media->alt = $meta['alt'] ?? null;
        $media->title = $meta['title'] ?? null;
        $media->caption = $meta['caption'] ?? null;

        $dimensions = $this->images->dimensions($this->storage->fullPath($relativePath));
        if ($dimensions !== null) {
            [$media->width, $media->height] = $dimensions;
        }

        $this->em->save($media);
        $this->events->emit(self::EVENT_UPLOADED, [$media]);
        return $media;
    }

    public function update(Media $media, array $meta): void {
        foreach (['alt', 'title', 'caption'] as $field) {
            if (array_key_exists($field, $meta)) {
                $media->$field = $meta[$field] !== '' ? $meta[$field] : null;
            }
        }
        $media->updated_at = $this->now();
        $this->em->save($media);
        $this->events->emit(self::EVENT_UPDATED, [$media]);
    }

    /**
     * Replaces the file behind an item
     *
     * Write once, so this creates a **new** item rather than overwriting: an old revision that
     * references the previous file keeps showing what it showed then. The old item is marked
     * deleted, and the caller decides what to re-point.
     */
    public function replace(Media $media, UploadedFile $file, int $userId): Media {
        $replacement = $this->upload($file, $userId, [
            'alt' => $media->alt, 'title' => $media->title, 'caption' => $media->caption,
        ]);
        $this->delete($media);
        return $replacement;
    }

    // --- Deleting ---

    /**
     * Marks an item deleted; the file stays on disk
     */
    public function delete(Media $media): void {
        if ($media->isDeleted()) {
            return;
        }
        $media->deleted_at = $this->now();
        $media->updated_at = $media->deleted_at;
        $this->em->save($media);
        $this->events->emit(self::EVENT_DELETED, [$media]);
    }

    public function restore(Media $media): void {
        if (!$media->isDeleted()) {
            return;
        }
        $media->deleted_at = null;
        $media->updated_at = $this->now();
        $this->em->save($media);
        $this->events->emit(self::EVENT_RESTORED, [$media]);
    }

    /**
     * Actually removes the row, the file and its derivatives
     *
     * **This is the operation that breaks history**: an old revision referencing this item will
     * point at a file that is no longer there. Nothing calls it by accident - the CLI asks for
     * confirmation first.
     */
    public function purge(Media $media): void {
        $path = $media->path;
        foreach (array_keys($this->images->presets()) as $preset) {
            $this->storage->delete($this->storage->derivativePath($path, $preset));
        }
        $this->storage->delete($path);
        $this->detachAll($media->id);
        $this->em->deleteById(Media::class, $media->id);
        $this->events->emit(self::EVENT_PURGED, [$media]);
    }

    // --- Derivatives ---

    /**
     * Returns the relative path of a derivative, generating it if it is not there yet
     *
     * Lazy on purpose: exactly one visitor per size pays for the work, and a size nobody ever
     * requests is never generated at all.
     *
     * @return string|null The original's path when the item cannot be resized
     */
    public function derivative(Media $media, string $preset): ?string {
        if (!$media->isResizable() || !$this->images->hasPreset($preset)) {
            return $media->path;
        }
        $relative = $this->storage->derivativePath($media->path, $preset);
        if ($this->storage->exists($relative)) {
            return $relative;
        }
        if (!$this->storage->exists($media->path)) {
            return null;
        }
        $this->images->resize(
            $this->storage->fullPath($media->path),
            $this->storage->fullPath($relative),
            $preset
        );
        $this->events->emit(self::EVENT_DERIVATIVE_CREATED, [$media, $preset, $relative]);
        return $relative;
    }

    /**
     * Deletes every generated derivative, so they are rebuilt on the next request
     *
     * @return int How many files were removed
     */
    public function clearDerivatives(?Media $media = null): int {
        $items = $media !== null ? [$media] : $this->allMedia();
        $count = 0;
        foreach ($items as $item) {
            foreach (array_keys($this->images->presets()) as $preset) {
                if ($this->storage->delete($this->storage->derivativePath($item->path, $preset))) {
                    $count++;
                }
            }
        }
        return $count;
    }

    // --- Attachments ---

    public function attach(int $contentId, int $mediaId, int $position = 0): void {
        if ($this->isAttached($contentId, $mediaId)) {
            return;
        }
        $attachment = new ContentAttachment();
        $attachment->content_id = $contentId;
        $attachment->media_id = $mediaId;
        $attachment->position = $position;
        $this->em->save($attachment);
        $this->events->emit(self::EVENT_ATTACHED, [$contentId, $mediaId]);
    }

    public function detach(int $contentId, int $mediaId): void {
        if (!$this->isAttached($contentId, $mediaId)) {
            return;
        }
        $attachment = new ContentAttachment();
        $attachment->content_id = $contentId;
        $attachment->media_id = $mediaId;
        $attachment->setNew(false);
        $this->events->emit(ContentAttachment::event(ContentAttachment::EVENT_BEFORE_DELETE), [$attachment]);
        $this->db->query(
            'delete from '.$this->em->safeTableName(ContentAttachment::class)
                .' where `content_id` = :contentId and `media_id` = :mediaId',
            [':contentId' => $contentId, ':mediaId' => $mediaId],
            true
        );
        $this->events->emit(ContentAttachment::event(ContentAttachment::EVENT_AFTER_DELETE), [$attachment]);
        $this->events->emit(self::EVENT_DETACHED, [$contentId, $mediaId]);
    }

    public function isAttached(int $contentId, int $mediaId): bool {
        return (int)$this->db->fetchOne(
            'select count(1) from '.$this->em->safeTableName(ContentAttachment::class)
                .' where `content_id` = :contentId and `media_id` = :mediaId',
            [':contentId' => $contentId, ':mediaId' => $mediaId]
        ) > 0;
    }

    /**
     * @return array The media rows attached to a piece of content
     */
    public function attachmentsOf(int $contentId): array {
        return $this->queryExecutor->findAll($this->queries->create('content_attachments', ['content_id' => $contentId]));
    }

    /**
     * Removes every attachment of a piece of content, through the entity manager
     *
     * Not a database cascade: a cascade fires no event and writes no audit row, so the removal
     * would leave no trace.
     */
    public function detachAllOfContent(int $contentId): void {
        $rows = $this->db->fetchColumn(
            'select `media_id` from '.$this->em->safeTableName(ContentAttachment::class).' where `content_id` = :id',
            [':id' => $contentId]
        );
        foreach ($rows as $mediaId) {
            $this->detach($contentId, (int)$mediaId);
        }
    }

    protected function detachAll(int $mediaId): void {
        $rows = $this->db->fetchColumn(
            'select `content_id` from '.$this->em->safeTableName(ContentAttachment::class).' where `media_id` = :id',
            [':id' => $mediaId]
        );
        foreach ($rows as $contentId) {
            $this->detach((int)$contentId, $mediaId);
        }
    }

    // --- Helpers ---

    /**
     * @return Media[]
     */
    protected function allMedia(): array {
        $ids = $this->db->fetchColumn('select `id` from '.$this->em->safeTableName(Media::class));
        $result = [];
        foreach ($ids as $id) {
            $media = $this->findById((int)$id);
            if ($media !== null) {
                $result[] = $media;
            }
        }
        return $result;
    }

    /**
     * The largest file the library accepts
     *
     * Capped by what PHP itself will accept, since a bigger number here would only fail later
     * with a less helpful message.
     */
    public function maxUploadSize(): int {
        $configured = (int)$this->config->get(self::CONFIG_MAX_SIZE, self::DEFAULT_MAX_SIZE);
        $phpLimit = $this->phpUploadLimit();
        return $phpLimit > 0 ? min($configured, $phpLimit) : $configured;
    }

    protected function phpUploadLimit(): int {
        $values = array_filter([
            $this->toBytes((string)ini_get('upload_max_filesize')),
            $this->toBytes((string)ini_get('post_max_size')),
        ]);
        return empty($values) ? 0 : (int)min($values);
    }

    protected function toBytes(string $value): int {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $unit = strtolower(substr($value, -1));
        $number = (int)$value;
        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    public function humanSize(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB'];
        $index = 0;
        $value = (float)$bytes;
        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }
        return ($index === 0 ? (int)$value : round($value, 1)).' '.$units[$index];
    }

    protected function uploadErrorMessage(int $error): string {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That file is too large.',
            UPLOAD_ERR_PARTIAL => 'The upload did not finish.',
            UPLOAD_ERR_NO_FILE => 'No file was chosen.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'The server could not save the file.',
            UPLOAD_ERR_EXTENSION => 'The upload was blocked by the server.',
            default => 'The upload failed.',
        };
    }

    protected function now(): string {
        return gmdate('Y-m-d H:i:s');
    }
}
