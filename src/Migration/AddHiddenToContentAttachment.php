<?php

namespace Dynart\Dpress\Migration;

use Dynart\Micro\Entities\MigrationInterface;
use Dynart\Micro\Entities\QueryExecutor;
use Dynart\Dpress\Entity\ContentAttachment;

/**
 * Marks an attachment as one the public page should not list
 *
 * `addColumnWithAudit()` rather than two calls: `ContentAttachment` is audited, every audited
 * write copies the whole row into the mirror, and a mirror one column short fails the *next
 * save of any attachment at all* - not just of a hidden one. Both tables or neither.
 *
 * Existing attachments default to visible, which is what they were: everything attached before
 * this was attached deliberately, through the attachments UI, to be listed.
 */
class AddHiddenToContentAttachment implements MigrationInterface {

    public function __construct(
        private QueryExecutor $queryExecutor,
    ) {}

    public function version(): string {
        return '0008_add_hidden_to_content_attachment';
    }

    public function up(): void {
        $this->queryExecutor->addColumnWithAudit(ContentAttachment::class, 'hidden');
    }
}
