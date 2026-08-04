<?php

namespace Dynart\Dpress\Entity;

use Dynart\Micro\Entities\Attribute\Auditable;
use Dynart\Micro\Entities\Attribute\Column;
use Dynart\Micro\Entities\Attribute\Table;
use Dynart\Micro\Entities\Entity;

/**
 * A hierarchical taxonomy term
 *
 * Categories form a tree; tags do not. That is the whole difference between the two, and it is
 * why they are separate entities rather than one with a `type` column: the tree is a real
 * structural difference, unlike the post/page split.
 */
#[Auditable]
#[Table(name: 'category')]
class Category extends Entity {

    protected static string $eventName = 'category';

    #[Column(type: Column::TYPE_INT, primaryKey: true, autoIncrement: true, notNull: true)]
    public int $id = 0;

    #[Column(type: Column::TYPE_INT, index: true)]
    public ?int $parent_id = null;

    #[Column(type: Column::TYPE_INT)]
    public ?int $thumbnail_media_id = null;

    #[Column(type: Column::TYPE_STRING, size: 100, notNull: true)]
    public string $name = '';

    #[Column(type: Column::TYPE_STRING, size: 200, notNull: true, unique: true)]
    public string $slug = '';

    #[Column(type: Column::TYPE_STRING)]
    public ?string $description = null;

    /** Editors order categories by hand; alphabetical is rarely what a menu wants */
    #[Column(type: Column::TYPE_INT, notNull: true, default: 0)]
    public int $position = 0;
}
