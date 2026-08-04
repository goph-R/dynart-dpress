<?php

namespace Dynart\Dpress\Migration;

use Dynart\Micro\Entities\MigrationInterface;
use Dynart\Micro\Entities\QueryExecutor;
use Dynart\Dpress\Entity\Category;
use Dynart\Dpress\Entity\ContentAttachment;
use Dynart\Dpress\Entity\ContentCategory;
use Dynart\Dpress\Entity\ContentTag;
use Dynart\Dpress\Entity\Role;
use Dynart\Dpress\Entity\Tag;
use Dynart\Dpress\Security\Permissions;
use Dynart\Dpress\Service\RoleService;

/**
 * Creates the taxonomy tables
 *
 * After media and content, because the join tables reference both.
 */
class CreateTaxonomyTables implements MigrationInterface {

    /** Parents before children */
    const ENTITIES = [
        Category::class,
        Tag::class,
        ContentCategory::class,
        ContentTag::class,
        ContentAttachment::class,
    ];

    public function __construct(
        private QueryExecutor $queryExecutor,
        private RoleService $roles,
    ) {}

    public function version(): string {
        return '0005_create_taxonomy_tables';
    }

    public function up(): void {
        foreach (self::ENTITIES as $className) {
            $this->queryExecutor->createTableWithAudit($className, true);
        }
        $this->grantEditorPermissions();
    }

    protected function grantEditorPermissions(): void {
        $editor = $this->roles->findByName(Role::NAME_EDITOR);
        if ($editor === null) {
            return;
        }
        foreach ([
            Permissions::CATEGORY_VIEW, Permissions::CATEGORY_CREATE, Permissions::CATEGORY_UPDATE,
            Permissions::TAG_VIEW, Permissions::TAG_CREATE, Permissions::TAG_UPDATE, Permissions::TAG_DELETE,
            Permissions::MEDIA_VIEW, Permissions::MEDIA_CREATE, Permissions::MEDIA_UPDATE, Permissions::MEDIA_DELETE,
        ] as $permission) {
            $this->roles->grant($editor, $permission);
        }
    }
}
