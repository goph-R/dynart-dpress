<?php

namespace Dynart\Dpress\Security;

use Dynart\Micro\ConfigInterface;
use Dynart\Micro\Entities\Database;
use Dynart\Micro\Entities\EntityManager;
use Dynart\Dpress\Entity\AuthAttempt;

/**
 * How many times something may be tried before it stops being answered
 *
 * Guessing a password is cheap and a password is short, so the only thing standing between an
 * eight character password and somebody who wants it is how many guesses a minute they get.
 *
 * **Two limits, always.** A per account limit stops one account being hammered; on its own it
 * hands anybody a way to lock a person out by failing on their behalf. A per address limit
 * stops one machine spraying one password across every account; on its own it does nothing
 * about a botnet with a thousand addresses and one target. Neither is optional and neither is
 * sufficient, which is why every scope below declares both.
 *
 * The count is over a window, and the window is what "try again later" means: once the oldest
 * attempt inside it expires there is room for another. That is a sliding window rather than a
 * lockout with a timer - the difference matters, because a fixed lockout is a state somebody
 * can keep an account in indefinitely, one failure at a time.
 *
 * Storage is a row per attempt in `auth_attempt`, keyed by a digest. Rows outlive their window
 * only until something prunes them, and `record()` does that now and then.
 */
class RateLimiter {

    const SCOPE_LOGIN = 'login';
    const SCOPE_PASSWORD_RESET = 'password_reset';
    const SCOPE_PASSWORD_RESET_TOKEN = 'password_reset_token';

    const CONFIG_ENABLED = 'dpress.rate_limit.enabled';

    /**
     * `[scope => [account attempts, address attempts, window in seconds]]`
     *
     * Each is overridable as `dpress.rate_limit.<scope>.account`, `.address` and `.window`.
     *
     * The numbers are meant to be invisible to a person and ruinous to a script. Five wrong
     * passwords in a quarter of an hour is more than anybody types by accident and fewer than a
     * thousandth of what a guesser needs; three reset mails an hour to one address is one more
     * than a confused person sends and few enough that the inbox is not a weapon.
     */
    const LIMITS = [
        self::SCOPE_LOGIN                => ['account' => 5,  'address' => 20, 'window' => 900],
        self::SCOPE_PASSWORD_RESET       => ['account' => 3,  'address' => 10, 'window' => 3600],
        self::SCOPE_PASSWORD_RESET_TOKEN => ['account' => 10, 'address' => 30, 'window' => 3600],
    ];

    /** Roughly one `record()` in this many also clears out what has expired */
    const PRUNE_EVERY = 50;

    public function __construct(
        protected ConfigInterface $config,
        protected EntityManager $em,
        protected Database $db,
    ) {}

    public function enabled(): bool {
        return (bool)$this->config->get(self::CONFIG_ENABLED, true);
    }

    /**
     * Has this key had its allowance for this scope?
     *
     * `$dimension` is `account` or `address`, and only says which of the two numbers applies -
     * the key itself is opaque here.
     */
    public function reached(string $scope, string $dimension, string $key): bool {
        if (!$this->enabled() || $key === '') {
            return false;
        }
        return $this->count($scope, $key) >= $this->limit($scope, $dimension);
    }

    /**
     * True when either the account or the address has had enough
     *
     * The pair is what a caller actually wants to ask, and asking it in one place is what keeps
     * a screen from remembering to check one and forgetting the other.
     */
    public function reachedEither(string $scope, string $account, string $address): bool {
        return $this->reached($scope, 'account', $account)
            || $this->reached($scope, 'address', $address);
    }

    /**
     * Writes down an attempt against both keys
     *
     * Called on failure, not on arrival: a limit that counts successes throttles the person who
     * knows their password along with the one who does not.
     */
    public function record(string $scope, string $account, string $address): void {
        if (!$this->enabled()) {
            return;
        }
        foreach ([$account, $address] as $key) {
            if ($key !== '') {
                $this->insert($scope, $key);
            }
        }
        if (random_int(1, self::PRUNE_EVERY) === 1) {
            $this->prune();
        }
    }

    /**
     * Forgets a key's attempts, after the thing it was guarding succeeded
     *
     * **The account only.** Clearing the address as well would mean anybody holding one valid
     * account could wipe their own address count between guesses at everybody else's, which is
     * the whole per address limit undone by one login.
     */
    public function clear(string $scope, string $key): void {
        if ($key === '') {
            return;
        }
        $this->db->query(
            'delete from '.$this->em->safeTableName(AuthAttempt::class)
                .' where `scope` = :scope and `key_hash` = :key',
            [':scope' => $scope, ':key' => $this->hash($key)], true
        );
    }

    /**
     * Seconds until the oldest attempt in the window expires, which is when there is room again
     *
     * Rounded up to the minute by the caller that shows it; here it is the honest number, and 0
     * when nothing is being held back.
     */
    public function retryAfter(string $scope, string $key): int {
        $oldest = $this->db->fetchOne(
            'select min(`created_at`) from '.$this->em->safeTableName(AuthAttempt::class)
                .' where `scope` = :scope and `key_hash` = :key and `created_at` > :since',
            [':scope' => $scope, ':key' => $this->hash($key), ':since' => $this->since($scope)]
        );
        if ($oldest === null || $oldest === false) {
            return 0;
        }
        return max(0, strtotime($oldest.' UTC') + $this->window($scope) - time());
    }

    /**
     * The longer of the two retry times, for a caller holding an account and an address
     */
    public function retryAfterEither(string $scope, string $account, string $address): int {
        return max($this->retryAfter($scope, $account), $this->retryAfter($scope, $address));
    }

    /**
     * "15 minutes", for a message somebody has to read
     */
    public function humanRetryAfter(int $seconds): string {
        $minutes = (int)ceil(max(1, $seconds) / 60);
        if ($minutes < 60) {
            return $minutes.' minute'.($minutes > 1 ? 's' : '');
        }
        $hours = (int)ceil($minutes / 60);
        return $hours.' hour'.($hours > 1 ? 's' : '');
    }

    /**
     * Deletes everything past the longest window there is
     *
     * One statement for every scope rather than one per scope, because the table is a working
     * set and the only question that matters is whether a row can still be counted by anybody.
     */
    public function prune(): void {
        $longest = max(array_column(self::LIMITS, 'window'));
        $this->db->query(
            'delete from '.$this->em->safeTableName(AuthAttempt::class).' where `created_at` <= :cutoff',
            [':cutoff' => gmdate('Y-m-d H:i:s', time() - $longest)], true
        );
    }

    // --- the parts a caller does not need to know ---

    protected function count(string $scope, string $key): int {
        return (int)$this->db->fetchOne(
            'select count(1) from '.$this->em->safeTableName(AuthAttempt::class)
                .' where `scope` = :scope and `key_hash` = :key and `created_at` > :since',
            [':scope' => $scope, ':key' => $this->hash($key), ':since' => $this->since($scope)]
        );
    }

    protected function insert(string $scope, string $key): void {
        $attempt = new AuthAttempt();
        $attempt->scope = $scope;
        $attempt->key_hash = $this->hash($key);
        $attempt->created_at = gmdate('Y-m-d H:i:s');
        $this->em->save($attempt);
    }

    /**
     * The start of the window, as the database stores time
     */
    protected function since(string $scope): string {
        return gmdate('Y-m-d H:i:s', time() - $this->window($scope));
    }

    public function window(string $scope): int {
        return max(1, (int)$this->config->get(
            'dpress.rate_limit.'.$scope.'.window', self::LIMITS[$scope]['window'] ?? 900
        ));
    }

    public function limit(string $scope, string $dimension): int {
        return max(1, (int)$this->config->get(
            'dpress.rate_limit.'.$scope.'.'.$dimension, self::LIMITS[$scope][$dimension] ?? 5
        ));
    }

    /**
     * Unsalted on purpose: a count has to find the same key again next time, and a per install
     * salt would only stop somebody who has the database from confirming an address they had
     * already guessed - which the user table answers anyway.
     */
    protected function hash(string $key): string {
        return hash('sha256', $key);
    }
}
