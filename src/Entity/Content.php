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

    /**
     * A row that exists so the editor has something to write against, and nothing more
     *
     * Opening "New" inserts one, because an editor with no id cannot attach a file, and asking
     * somebody to save an empty post first so that they may attach something to it is a strange
     * thing to ask. The **first save promotes it to a draft**; until then it holds no title and
     * no text, since nothing but attaching writes before Save does.
     *
     * **Deliberately not in `STATUSES`.** That list is what the status select offers and what
     * `assertStatus()` accepts, so this cannot be chosen by hand or arrive through a form - it is
     * set once, by `startDraft()`, and left exactly once, by the first `update()`.
     */
    const STATUS_AUTO_DRAFT = 'auto_draft';

    const STATUSES = [self::STATUS_DRAFT, self::STATUS_PUBLISHED];

    #[Column(type: Column::TYPE_INT, notNull: true, autoIncrement: true, primaryKey: true)]
    public int $id = 0;

    #[Column(type: Column::TYPE_STRING, size: 20, notNull: true, default: self::TYPE_POST)]
    public string $type = self::TYPE_POST;

    /**
     * Only pages use the tree; a post never has a parent
     */
    #[Column(type: Column::TYPE_INT, index: true)]
    public ?int $parent_id = null;

    #[Column(type: Column::TYPE_INT, notNull: true, foreignKey: [User::class, 'id'], index: true)]
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

    /**
     * Where this one goes when a listing is ordered, above the date
     *
     * **A tiebreaker and not a sort order.** Every listing asks for `weight desc,
     * published_at desc`, so a site that never sets one is a site ordered by date, exactly as
     * before - which is what made it safe to put on every listing at once. The alternative,
     * a weight that *replaces* the date, means every new post arrives at 0 and lands at the
     * bottom until somebody remembers to number it.
     *
     * Signed, because "push this one down" is as real a wish as pushing one up, and a
     * negative number says it without renumbering everything else.
     */
    #[Column(type: Column::TYPE_INT, notNull: true, default: 0)]
    public int $weight = 0;

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
     * Has this ever been saved by the person writing it?
     */
    public function isAutoDraft(): bool {
        return $this->status === self::STATUS_AUTO_DRAFT;
    }

    /**
     * Is there a body, or is the whole document the lead?
     */
    public function hasBody(): bool {
        return $this->body_html !== null && $this->body_html !== '';
    }
}
