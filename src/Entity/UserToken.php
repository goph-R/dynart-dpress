<?php

namespace Dynart\Dpress\Entity;

use Dynart\Micro\Entities\Attribute\Column;
use Dynart\Micro\Entities\Entity;

/**
 * A single use token sent to a user by email
 *
 * Password resets and email verifications are the same shape - a hashed token, an expiry and a
 * used marker - so they share one table with a `type` column rather than two tables that would
 * have to be kept in step with each other.
 *
 * Not audited, for the same reason as `RefreshToken`.
 */
class UserToken extends Entity {

    protected static string $eventName = 'user_token';

    const TYPE_PASSWORD_RESET = 'password_reset';
    const TYPE_EMAIL_VERIFICATION = 'email_verification';

    #[Column(type: Column::TYPE_INT, primaryKey: true, autoIncrement: true, notNull: true)]
    public int $id = 0;

    #[Column(type: Column::TYPE_INT, notNull: true, index: true, foreignKey: [User::class, 'id'])]
    public int $user_id = 0;

    #[Column(type: Column::TYPE_STRING, size: 32, notNull: true, index: true)]
    public string $type = '';

    /** sha256 hex of the token that was mailed out */
    #[Column(type: Column::TYPE_STRING, size: 64, fixSize: true, notNull: true, unique: true)]
    public string $token_hash = '';

    #[Column(type: Column::TYPE_DATETIME, notNull: true)]
    public ?string $expires_at = null;

    #[Column(type: Column::TYPE_DATETIME, notNull: true)]
    public ?string $created_at = null;

    #[Column(type: Column::TYPE_DATETIME)]
    public ?string $used_at = null;
}
