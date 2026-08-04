<?php

namespace Dynart\Dpress\Entity;

use Dynart\Micro\Entities\Attribute\Auditable;
use Dynart\Micro\Entities\Attribute\Column;
use Dynart\Micro\Entities\Attribute\Table;
use Dynart\Micro\Entities\Entity;

/**
 * Which tags a piece of content has
 *
 * Audited: re-tagging a post changes no row in `content`, so without a mirror of its own
 * the change would leave no trace. Every column is part of the key, so the history is add and
 * del only - "which tags did this have in March" is a replay, not a row lookup.
 *
 * **No `ON DELETE CASCADE`**, like every audited relation: a cascade happens inside the database
 * where no event fires and nothing is recorded. `TaxonomyService` removes these rows itself.
 */
#[Auditable]
#[Table(name: 'content_tag')]
class ContentTag extends Entity {

    protected static string $eventName = 'content_tag';

    #[Column(type: Column::TYPE_INT, primaryKey: true, notNull: true, foreignKey: [Content::class, 'id'])]
    public int $content_id = 0;

    #[Column(type: Column::TYPE_INT, primaryKey: true, notNull: true, foreignKey: [Tag::class, 'id'])]
    public int $tag_id = 0;
}
