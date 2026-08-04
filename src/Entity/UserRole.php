<?php

namespace Dynart\Dpress\Entity;

use Dynart\Micro\Entities\Attribute\Auditable;
use Dynart\Micro\Entities\Attribute\Column;
use Dynart\Micro\Entities\Attribute\Table;
use Dynart\Micro\Entities\Entity;

/**
 * Which roles a user holds
 *
 * Audited: granting somebody the editor role changes no row in `user`, so without a mirror of
 * its own the change would leave no trace anywhere.
 *
 * **No `ON DELETE CASCADE` on purpose.** A cascade happens inside the database, so no entity
 * event fires and no audit row is written - the history would show a user existing and then the
 * grant simply gone. `UserService` and `RoleService` delete these rows through the entity
 * manager before removing the parent, so every removal is recorded.
 */
#[Auditable]
#[Table(name: 'user_role')]
class UserRole extends Entity {

    protected static string $eventName = 'user_role';

    #[Column(type: Column::TYPE_INT, primaryKey: true, notNull: true, foreignKey: [User::class, 'id'])]
    public int $user_id = 0;

    #[Column(type: Column::TYPE_INT, primaryKey: true, notNull: true, foreignKey: [Role::class, 'id'])]
    public int $role_id = 0;
}
