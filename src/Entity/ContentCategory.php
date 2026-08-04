<?php

namespace Dynart\Dpress\Entity;

use Dynart\Micro\Entities\Attribute\Auditable;
use Dynart\Micro\Entities\Attribute\Column;
use Dynart\Micro\Entities\Attribute\Table;
use Dynart\Micro\Entities\Entity;

/**
 * Which categories a piece of content is in
 *
 * Audited: re-categorising a post changes no row in `content`, so without a mirror of its own
 * the change would leave no trace. Every column is part of the key, so the history is add and
 * del only - "which categories did this have in March" is a replay, not a row lookup.
 *
 * **No `ON DELETE CASCADE`**, like every audited relation: a cascade happens inside the database
 * where no event fires and nothing is recorded. `TaxonomyService` removes these rows itself.
 */
#[Auditable]
#[Table(name: 'content_category')]
class ContentCategory extends Entity {

    protected static string $eventName = 'content_category';

    #[Column(type: Column::TYPE_INT, primaryKey: true, notNull: true, foreignKey: [Content::class, 'id'])]
    public int $content_id = 0;

    #[Column(type: Column::TYPE_INT, primaryKey: true, notNull: true, foreignKey: [Category::class, 'id'])]
    public int $category_id = 0;
}
