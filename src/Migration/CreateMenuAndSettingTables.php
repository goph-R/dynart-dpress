<?php

namespace Dynart\Dpress\Migration;

use Dynart\Micro\Entities\MigrationInterface;
use Dynart\Micro\Entities\QueryExecutor;
use Dynart\Dpress\Entity\Menu;
use Dynart\Dpress\Entity\MenuItem;
use Dynart\Dpress\Entity\Role;
use Dynart\Dpress\Entity\Setting;
use Dynart\Dpress\Security\Permissions;
use Dynart\Dpress\Service\RoleService;

/**
 * Creates the settings and the menus
 */
class CreateMenuAndSettingTables implements MigrationInterface {

    const ENTITIES = [
        Setting::class,
        Menu::class,
        MenuItem::class,
    ];

    public function __construct(
        private QueryExecutor $queryExecutor,
        private RoleService $roles,
    ) {}

    public function version(): string {
        return '0006_create_menu_and_setting_tables';
    }

    public function up(): void {
        foreach (self::ENTITIES as $className) {
            $this->queryExecutor->createTableWithAudit($className, true);
        }
        $editor = $this->roles->findByName(Role::NAME_EDITOR);
        if ($editor === null) {
            return;
        }
        foreach ([Permissions::MENU_VIEW, Permissions::MENU_UPDATE, Permissions::SETTING_VIEW] as $permission) {
            $this->roles->grant($editor, $permission);
        }
    }
}
