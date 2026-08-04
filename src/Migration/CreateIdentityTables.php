<?php

namespace Dynart\Dpress\Migration;

use Dynart\Micro\Entities\MigrationInterface;
use Dynart\Micro\Entities\QueryExecutor;
use Dynart\Dpress\Entity\RefreshToken;
use Dynart\Dpress\Entity\Role;
use Dynart\Dpress\Entity\RolePermission;
use Dynart\Dpress\Entity\User;
use Dynart\Dpress\Entity\UserRole;
use Dynart\Dpress\Entity\UserToken;
use Dynart\Dpress\Security\Permissions;
use Dynart\Dpress\Service\RoleService;

/**
 * Creates the identity tables and seeds the default roles
 *
 * The order matters: `UserRole` and `RolePermission` have foreign keys into `User` and `Role`,
 * so those have to exist first.
 */
class CreateIdentityTables implements MigrationInterface {

    /** The tables to create, parents before children */
    const ENTITIES = [
        User::class,
        Role::class,
        UserRole::class,
        RolePermission::class,
        RefreshToken::class,
        UserToken::class,
    ];

    public function __construct(
        private QueryExecutor $queryExecutor,
        private RoleService $roles,
    ) {}

    public function version(): string {
        return '0002_create_identity_tables';
    }

    public function up(): void {
        foreach (self::ENTITIES as $className) {
            $this->queryExecutor->createTableWithAudit($className, true);
        }
        $this->seedRoles();
    }

    /**
     * The three default roles
     *
     * **admin** is unremovable and holds every permission implicitly, so it is seeded with none
     * of them - a permission added later by a plugin is covered without a migration.
     */
    protected function seedRoles(): void {
        if ($this->roles->findByName(Role::NAME_ADMIN) === null) {
            $this->roles->create(Role::NAME_ADMIN, 'Administrator', [], false);
        }
        if ($this->roles->findByName(Role::NAME_EDITOR) === null) {
            $this->roles->create(Role::NAME_EDITOR, 'Editor', [
                Permissions::USER_VIEW,
            ]);
        }
        if ($this->roles->findByName(Role::NAME_READER) === null) {
            $this->roles->create(Role::NAME_READER, 'Reader', []);
        }
    }
}
