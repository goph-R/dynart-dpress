<?php

namespace Dynart\Dpress\Entity;

use Dynart\Micro\Entities\Attribute\Column;
use Dynart\Micro\Entities\Attribute\Table;
use Dynart\Micro\Entities\Entity;

/**
 * One try at something worth limiting
 *
 * A row per attempt rather than a counter per key, because a counter cannot answer "how many in
 * the last fifteen minutes" without also storing when it was last reset - which is the same
 * information, kept worse. Counting rows in a window is one indexed query, and expiring them is
 * a delete by date.
 *
 * **The key is a hash.** Attempts are counted per address and per account, and the account is
 * whatever somebody typed into the form - including addresses that have no account here, which
 * is exactly the set this site has no business writing down. Counting works the same on a
 * digest, because a count only ever asks about a key it already has.
 *
 * Not audited: this is a working set that expires, not a record of anything. The history table
 * would outlive the rows by design and turn a rate limiter into a permanent log of who typed
 * what into a login form.
 */
#[Table(name: 'auth_attempt', index: [['scope', 'key_hash', 'created_at']])]
class AuthAttempt extends Entity {

    protected static string $eventName = 'auth_attempt';

    #[Column(type: Column::TYPE_INT, primaryKey: true, autoIncrement: true, notNull: true)]
    public int $id = 0;

    /** What was being tried: `login`, `password_reset`, `password_reset_token` */
    #[Column(type: Column::TYPE_STRING, size: 32, notNull: true)]
    public string $scope = '';

    /** sha256 hex of the address or the account this attempt is counted against */
    #[Column(type: Column::TYPE_STRING, size: 64, fixSize: true, notNull: true)]
    public string $key_hash = '';

    #[Column(type: Column::TYPE_DATETIME, notNull: true, index: true)]
    public ?string $created_at = null;
}
