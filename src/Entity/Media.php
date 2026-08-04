<?php

namespace Dynart\Dpress\Entity;

use Dynart\Micro\Entities\Attribute\Auditable;
use Dynart\Micro\Entities\Attribute\Column;
use Dynart\Micro\Entities\Attribute\Table;
use Dynart\Micro\Entities\Entity;

/**
 * One item of the central media library
 *
 * Content **references** media; an attachment is a link, never a copy. The same image can be a
 * featured image on one post and an attachment on another without being stored twice.
 *
 * Audited, for attribution: "who removed this image, and when" is a question that gets asked
 * after the fact. The generated derivatives are not audited - they are a cache of the original,
 * the way `lead_html` is a cache of the markdown.
 */
#[Auditable]
#[Table(name: 'media')]
class Media extends Entity {

    protected static string $eventName = 'media';

    const CATEGORY_IMAGE = 'image';
    const CATEGORY_VIDEO = 'video';
    const CATEGORY_AUDIO = 'audio';
    const CATEGORY_DOCUMENT = 'document';
    const CATEGORY_SHEET = 'sheet';
    const CATEGORY_ARCHIVE = 'archive';
    const CATEGORY_OTHER = 'other';

    const CATEGORIES = [
        self::CATEGORY_IMAGE, self::CATEGORY_VIDEO, self::CATEGORY_AUDIO,
        self::CATEGORY_DOCUMENT, self::CATEGORY_SHEET, self::CATEGORY_ARCHIVE,
        self::CATEGORY_OTHER,
    ];

    #[Column(type: Column::TYPE_INT, primaryKey: true, autoIncrement: true, notNull: true)]
    public int $id = 0;

    /**
     * Relative to the uploads folder: `2026/08/my-photo-a1b2c3.jpg`
     *
     * Write once. Replacing a file produces a new path and a new row, so an old revision that
     * references this one keeps showing what it showed then.
     */
    #[Column(type: Column::TYPE_STRING, size: 255, notNull: true, unique: true)]
    public string $path = '';

    /** What the file was called when it was uploaded, for the download name */
    #[Column(type: Column::TYPE_STRING, size: 255, notNull: true)]
    public string $file_name = '';

    /** Sniffed from the bytes, never taken from the client */
    #[Column(type: Column::TYPE_STRING, size: 100, notNull: true)]
    public string $mime_type = '';

    /** Which icon represents it when it is not an image */
    #[Column(type: Column::TYPE_STRING, size: 20, notNull: true, index: true, default: self::CATEGORY_OTHER)]
    public string $category = self::CATEGORY_OTHER;

    #[Column(type: Column::TYPE_INT, notNull: true, default: 0)]
    public int $size = 0;

    #[Column(type: Column::TYPE_INT)]
    public ?int $width = null;

    #[Column(type: Column::TYPE_INT)]
    public ?int $height = null;

    #[Column(type: Column::TYPE_STRING, size: 255)]
    public ?string $alt = null;

    #[Column(type: Column::TYPE_STRING, size: 255)]
    public ?string $title = null;

    #[Column(type: Column::TYPE_STRING)]
    public ?string $caption = null;

    #[Column(type: Column::TYPE_INT, notNull: true, index: true, foreignKey: [User::class, 'id'])]
    public int $uploaded_by = 0;

    #[Column(type: Column::TYPE_DATETIME, notNull: true)]
    public ?string $created_at = null;

    #[Column(type: Column::TYPE_DATETIME)]
    public ?string $updated_at = null;

    /**
     * Marked rather than removed
     *
     * Revisions are kept forever, so an old post revision can still reference this file. A
     * later purge - with a loud warning - is what actually deletes bytes, because that is the
     * operation that breaks history.
     */
    #[Column(type: Column::TYPE_DATETIME, index: true)]
    public ?string $deleted_at = null;

    public function isImage(): bool {
        return $this->category === self::CATEGORY_IMAGE;
    }

    public function isDeleted(): bool {
        return $this->deleted_at !== null;
    }

    /**
     * Can a thumbnail be generated for it?
     *
     * An SVG is an image but has no raster to resize, so it is shown at its own size instead.
     */
    public function isResizable(): bool {
        return $this->isImage() && $this->mime_type !== 'image/svg+xml';
    }

    public function label(): string {
        return $this->title !== null && $this->title !== '' ? $this->title : $this->file_name;
    }
}
