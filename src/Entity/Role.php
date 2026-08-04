<?php

namespace Dynart\Dpress\Entity;

use Dynart\Micro\Entities\Attribute\Auditable;
use Dynart\Micro\Entities\Attribute\Column;
use Dynart\Micro\Entities\Entity;

/**
 * A named set of permissions
 *
 * Audited for the same reason `RolePermission` is: creating or deleting a role changes who can
 * do what, and that question gets asked after the fact.
 */
#[Auditable]
class Role extends Entity {

    protected static string $eventName = 'role';

    /** Holds every permission implicitly and cannot be removed */
    const NAME_ADMIN = 'admin';

    const NAME_EDITOR = 'editor';
    const NAME_READER = 'reader';

    #[Column(type: Column::TYPE_INT, primaryKey: true, autoIncrement: true, notNull: true)]
    public int $id = 0;

    #[Column(type: Column::TYPE_STRING, size: 64, notNull: true, unique: true)]
    public string $name = '';

    #[Column(type: Column::TYPE_STRING, size: 100, notNull: true)]
    public string $label = '';

    /**
     * The admin role is unremovable, so the site can never be left without one
     */
    #[Column(type: Column::TYPE_BOOL, notNull: true, default: true)]
    public bool $removable = true;

    public function isAdmin(): bool {
        return $this->name === self::NAME_ADMIN;
    }
}
