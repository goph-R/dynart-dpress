<?php

namespace Dynart\Dpress\Entity;

use Dynart\Micro\Entities\Attribute\Auditable;
use Dynart\Micro\Entities\Attribute\Column;
use Dynart\Micro\Entities\Entity;

#[Auditable]
class User extends Entity {

    protected static string $eventName = 'user';

    /** Can log in and do whatever their roles allow */
    const STATUS_ACTIVE = 'active';

    /** Registered but has not confirmed their email address yet */
    const STATUS_PENDING = 'pending';

    /** Kept for the sake of what they authored, but cannot log in */
    const STATUS_BLOCKED = 'blocked';

    const STATUSES = [self::STATUS_ACTIVE, self::STATUS_PENDING, self::STATUS_BLOCKED];

    #[Column(type: Column::TYPE_INT, primaryKey: true, autoIncrement: true, notNull: true)]
    public int $id = 0;

    /**
     * 190 rather than 255 so the unique index fits in a utf8mb4 key on older MariaDB
     */
    #[Column(type: Column::TYPE_STRING, size: 190, notNull: true, unique: true)]
    public string $email = '';

    #[Column(type: Column::TYPE_STRING, size: 255, notNull: true)]
    public string $password_hash = '';

    #[Column(type: Column::TYPE_STRING, size: 100, notNull: true)]
    public string $name = '';

    #[Column(type: Column::TYPE_STRING, size: 20, notNull: true, default: self::STATUS_ACTIVE, index: true)]
    public string $status = self::STATUS_ACTIVE;

    #[Column(type: Column::TYPE_DATETIME, notNull: true)]
    public ?string $created_at = null;

    #[Column(type: Column::TYPE_DATETIME)]
    public ?string $updated_at = null;

    public function isActive(): bool {
        return $this->status === self::STATUS_ACTIVE;
    }
}
