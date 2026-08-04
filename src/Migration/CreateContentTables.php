<?php

namespace Dynart\Dpress\Migration;

use Dynart\Micro\Entities\MigrationInterface;
use Dynart\Micro\Entities\QueryExecutor;
use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Entity\Role;
use Dynart\Dpress\Security\Permissions;
use Dynart\Dpress\Service\RoleService;

/**
 * Creates the content table and grants the editor role its permissions
 */
class CreateContentTables implements MigrationInterface {

    public function __construct(
        private QueryExecutor $queryExecutor,
        private RoleService $roles,
    ) {}

    public function version(): string {
        return '0003_create_content_tables';
    }

    public function up(): void {
        $this->queryExecutor->createTableWithAudit(Content::class, true);
        $this->grantEditorPermissions();
    }

    /**
     * An editor writes and publishes posts, and edits pages without restructuring them
     *
     * The admin role is untouched: it holds everything implicitly, which is why a new permission
     * never needs granting to it retroactively.
     */
    protected function grantEditorPermissions(): void {
        $editor = $this->roles->findByName(Role::NAME_EDITOR);
        if ($editor === null) {
            return;
        }
        foreach ([
            Permissions::POST_VIEW, Permissions::POST_CREATE, Permissions::POST_UPDATE,
            Permissions::POST_DELETE, Permissions::POST_PUBLISH,
            Permissions::PAGE_VIEW, Permissions::PAGE_UPDATE,
            Permissions::CONTENT_HISTORY,
        ] as $permission) {
            $this->roles->grant($editor, $permission);
        }
    }
}
