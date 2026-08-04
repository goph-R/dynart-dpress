<?php

namespace Dynart\Dpress\Security;

/**
 * Hashes and verifies passwords
 *
 * A thin wrapper, but it keeps `password_hash()` out of the services so they stay testable, and
 * gives one place to change the algorithm.
 */
class PasswordHasher {

    /** Below this a password is refused outright */
    const MIN_LENGTH = 8;

    public function hash(string $password): string {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public function verify(string $password, string $hash): bool {
        if ($hash === '') {
            return false;
        }
        return password_verify($password, $hash);
    }

    /**
     * Should this hash be replaced, because the algorithm or its cost changed?
     *
     * Checked on a successful login, where the plain password is available to rehash with.
     */
    public function needsRehash(string $hash): bool {
        return password_needs_rehash($hash, PASSWORD_DEFAULT);
    }

    /**
     * Generates a token to be mailed out, and its hash for storing
     *
     * @return array [the token for the URL, the sha256 hex to store]
     */
    public function createToken(int $bytes = 32): array {
        $token = bin2hex(random_bytes($bytes));
        return [$token, $this->hashToken($token)];
    }

    /**
     * Tokens are hashed with a plain sha256 rather than `password_hash()`
     *
     * They are long random values, so there is nothing to brute force and nothing to salt, and a
     * lookup by hash has to be a single indexed query rather than a scan.
     */
    public function hashToken(string $token): string {
        return hash('sha256', $token);
    }
}
