<?php

namespace Dynart\Dpress\Entity;

use Dynart\Micro\Entities\Attribute\Column;
use Dynart\Micro\Entities\Attribute\Table;
use Dynart\Micro\Entities\Entity;

/**
 * A long lived token that can mint new access tokens
 *
 * Deliberately **not** audited: it is short lived by design, and auditing it would copy a
 * credential into a table that is never deleted from.
 *
 * Only the hash is stored. A leaked database then cannot be used to log in as anybody, the same
 * reason passwords are hashed.
 */
#[Table(name: 'refresh_token')]
class RefreshToken extends Entity {

    protected static string $eventName = 'refresh_token';

    #[Column(type: Column::TYPE_INT, primaryKey: true, autoIncrement: true, notNull: true)]
    public int $id = 0;

    #[Column(type: Column::TYPE_INT, notNull: true, index: true, foreignKey: [User::class, 'id'])]
    public int $user_id = 0;

    /** sha256 hex of the token that was handed out */
    #[Column(type: Column::TYPE_STRING, size: 64, fixSize: true, notNull: true, unique: true)]
    public string $token_hash = '';

    #[Column(type: Column::TYPE_DATETIME, notNull: true, index: true)]
    public ?string $expires_at = null;

    #[Column(type: Column::TYPE_DATETIME, notNull: true)]
    public ?string $created_at = null;

    /** Set when the token was used or logged out, so it can never be replayed */
    #[Column(type: Column::TYPE_DATETIME)]
    public ?string $revoked_at = null;
}
