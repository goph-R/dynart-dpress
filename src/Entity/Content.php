<?php

namespace Dynart\Dpress\Entity;

use Dynart\Micro\Entities\Attribute\Auditable;
use Dynart\Micro\Entities\Attribute\Column;
use Dynart\Micro\Entities\Attribute\Table;
use Dynart\Micro\Entities\Entity;

/**
 * A post or a page
 *
 * One table with a `type` column rather than two: the field sets are identical and they share
 * versioning, permissions, attachments, taxonomy, menu targeting and event handling. The
 * difference is routing and listing semantics, which belongs in the service layer.
 */
#[Auditable]
#[Table(name: 'content', index: [['type', 'status', 'published_at']])]
class Content extends Entity {

    protected static string $eventName = 'content';

    /** Chronological, taxonomy driven */
    const TYPE_POST = 'post';

    /** Hierarchical, menu driven */
    const TYPE_PAGE = 'page';

    const TYPES = [self::TYPE_POST, self::TYPE_PAGE];

    const STATUS_DRAFT = 'draft';
    const STATUS_PUBLISHED = 'published';

    const STATUSES = [self::STATUS_DRAFT, self::STATUS_PUBLISHED];

    #[Column(type: Column::TYPE_INT, primaryKey: true, autoIncrement: true, notNull: true)]
    public int $id = 0;

    #[Column(type: Column::TYPE_STRING, size: 20, notNull: true, default: self::TYPE_POST)]
    public string $type = self::TYPE_POST;

    /**
     * Only pages use the tree; a post never has a parent
     */
    #[Column(type: Column::TYPE_INT, index: true)]
    public ?int $parent_id = null;

    #[Column(type: Column::TYPE_INT, notNull: true, index: true, foreignKey: [User::class, 'id'])]
    public int $author_id = 0;

    /**
     * The foreign key is added by a later migration with an ALTER, because `Media` did not exist
     * when this table was created
     */
    #[Column(type: Column::TYPE_INT, foreignKey: [Media::class, 'id'])]
    public ?int $featured_media_id = null;

    #[Column(type: Column::TYPE_STRING, size: 200, notNull: true)]
    public string $title = '';

    /**
     * Globally unique across posts and pages - one flat namespace, see the plan
     */
    #[Column(type: Column::TYPE_STRING, size: 200, notNull: true, unique: true)]
    public string $slug = '';

    /** The source, as the editor wrote it */
    #[Column(type: Column::TYPE_STRING, notNull: true)]
    public string $markdown = '';

    /** Rendered from the part before the first lead separator */
    #[Column(type: Column::TYPE_STRING)]
    public ?string $lead_html = null;

    /** Rendered from the part after it */
    #[Column(type: Column::TYPE_STRING)]
    public ?string $body_html = null;

    #[Column(type: Column::TYPE_STRING, size: 20, notNull: true, default: self::STATUS_DRAFT)]
    public string $status = self::STATUS_DRAFT;

    #[Column(type: Column::TYPE_DATETIME, index: true)]
    public ?string $published_at = null;

    #[Column(type: Column::TYPE_DATETIME, notNull: true)]
    public ?string $created_at = null;

    #[Column(type: Column::TYPE_DATETIME)]
    public ?string $updated_at = null;

    public function isPost(): bool {
        return $this->type === self::TYPE_POST;
    }

    public function isPage(): bool {
        return $this->type === self::TYPE_PAGE;
    }

    public function isPublished(): bool {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /**
     * Is there a body, or is the whole document the lead?
     */
    public function hasBody(): bool {
        return $this->body_html !== null && $this->body_html !== '';
    }
}
