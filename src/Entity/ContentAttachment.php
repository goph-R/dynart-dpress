<?php

namespace Dynart\Dpress\Entity;

use Dynart\Micro\Entities\Attribute\Auditable;
use Dynart\Micro\Entities\Attribute\Column;
use Dynart\Micro\Entities\Attribute\Table;
use Dynart\Micro\Entities\Entity;

/**
 * A file attached to a piece of content
 *
 * A **link** to a library item, not a copy of one. Detaching removes the link; the media stays
 * in the library for anything else that uses it.
 *
 * Audited and non-cascading, for the reasons in `ContentCategory`.
 */
#[Auditable]
#[Table(name: 'content_attachment')]
class ContentAttachment extends Entity {

    protected static string $eventName = 'content_attachment';

    #[Column(type: Column::TYPE_INT, primaryKey: true, notNull: true, foreignKey: [Content::class, 'id'])]
    public int $content_id = 0;

    #[Column(type: Column::TYPE_INT, primaryKey: true, notNull: true, foreignKey: [Media::class, 'id'])]
    public int $media_id = 0;

    #[Column(type: Column::TYPE_INT, notNull: true, default: 0)]
    public int $position = 0;
}
