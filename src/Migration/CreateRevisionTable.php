<?php

namespace Dynart\Dpress\Migration;

use Dynart\Micro\Entities\EntityManager;
use Dynart\Micro\Entities\MigrationInterface;
use Dynart\Micro\Entities\QueryExecutor;
use Dynart\Micro\Entities\Revision;

/**
 * Creates the revision table the auditing depends on
 *
 * `Revision` comes from the entities library rather than from the CMS, so the application's
 * namespace scan does not reach it and it has to be registered explicitly.
 */
class CreateRevisionTable implements MigrationInterface {

    public function __construct(
        private EntityManager $em,
        private QueryExecutor $queryExecutor,
    ) {}

    public function version(): string {
        return '0001_create_revision_table';
    }

    public function up(): void {
        $this->em->registerEntity(Revision::class);
        $this->queryExecutor->createTable(Revision::class, true);
    }
}
