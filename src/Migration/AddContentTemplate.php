<?php

namespace Dynart\Dpress\Migration;

use Dynart\Micro\Entities\Database;
use Dynart\Micro\Entities\EntityManager;
use Dynart\Micro\Entities\MigrationInterface;
use Dynart\Dpress\Entity\Content;

/**
 * `content.template`, the page template chosen in the editor (0.91.0) - and its audit mirror
 *
 * The first column added by a migration rather than by an `alter table` in the changelog: two
 * sites are live now, and `dpress upgrade` is a command where a paragraph of SQL is a thing to
 * get wrong on one of them. **On a fresh install it does nothing**, because `CreateSchema` builds
 * the table from the entity, which already has the column - so it asks before it alters.
 */
class AddContentTemplate implements MigrationInterface {

    public function __construct(
        private Database $db,
        private EntityManager $em,
    ) {}

    public function version(): string {
        return '0002_add_content_template';
    }

    public function up(): void {
        $table = $this->em->tableNameByClass(Content::class);
        foreach ([$table, $table.EntityManager::AUDIT_TABLE_SUFFIX] as $name) {
            if (!$this->hasColumn($name, 'template')) {
                $this->db->query('alter table '.$this->db->escapeName($name).' add column `template` varchar(64) null after `css`');
            }
        }
    }

    protected function hasColumn(string $table, string $column): bool {
        return (bool)$this->db->fetchOne(
            'select count(1) from information_schema.columns where table_schema = :db and table_name = :table and column_name = :column',
            [':db' => $this->db->configValue('name'), ':table' => $table, ':column' => $column]
        );
    }
}
