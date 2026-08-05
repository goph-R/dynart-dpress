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

    /**
     * Attached, but not to be listed
     *
     * An image inside the article body is attached so that deleting it is noticed and so that
     * the file follows the content - but it is already on the page, and listing it again under
     * "Attachments" says the same thing twice.
     *
     * **On the link rather than on the media**, because the same library image can be an inline
     * illustration in one post and a listed download in another. Hidden is a fact about *this
     * use* of it, and on `Media` the two uses would fight over one flag.
     */
    #[Column(type: Column::TYPE_BOOL, notNull: true, default: false)]
    public bool $hidden = false;
}
