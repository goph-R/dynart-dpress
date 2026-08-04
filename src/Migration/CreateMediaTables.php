<?php

namespace Dynart\Dpress\Migration;

use Dynart\Micro\Entities\MigrationInterface;
use Dynart\Micro\Entities\QueryExecutor;
use Dynart\Dpress\Entity\Media;
use Dynart\Dpress\Media\MediaStorage;

/**
 * Creates the media library
 *
 * Before the content table, because `Content.featured_media_id` references it and a
 * `CREATE TABLE` can only point at a table that already exists.
 */
class CreateMediaTables implements MigrationInterface {

    public function __construct(
        private QueryExecutor $queryExecutor,
        private MediaStorage $storage,
    ) {}

    public function version(): string {
        return '0003_create_media_tables';
    }

    public function up(): void {
        $this->queryExecutor->createTableWithAudit(Media::class, true);
        // creates the uploads folder and the .htaccess that stops it executing anything
        $this->storage->protect();
    }
}
