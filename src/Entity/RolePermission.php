<?php

namespace Dynart\Dpress\Entity;

use Dynart\Micro\Entities\Attribute\Auditable;
use Dynart\Micro\Entities\Attribute\Column;
use Dynart\Micro\Entities\Entity;

/**
 * Which permissions a role grants
 *
 * The permission is a plain string, because the strings *are* the registry - a plugin declares
 * `myplugin.do_thing` and the role editor picks it up without a migration.
 *
 * Like `UserRole`, no `ON DELETE CASCADE`: see the note there.
 */
#[Auditable]
class RolePermission extends Entity {

    protected static string $eventName = 'role_permission';

    #[Column(type: Column::TYPE_INT, primaryKey: true, notNull: true, foreignKey: [Role::class, 'id'])]
    public int $role_id = 0;

    #[Column(type: Column::TYPE_STRING, size: 100, primaryKey: true, notNull: true)]
    public string $permission = '';
}
