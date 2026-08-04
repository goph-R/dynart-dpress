<?php

namespace Dynart\Dpress\Service;

use Dynart\Micro\ConfigInterface;
use Dynart\Micro\EventServiceInterface;
use Dynart\Micro\JwtAuthInterface;
use Dynart\Micro\JwtUserInterface;
use Dynart\Micro\Entities\Database;
use Dynart\Micro\Entities\EntityManager;
use Dynart\Dpress\DpressException;
use Dynart\Dpress\Entity\RefreshToken;
use Dynart\Dpress\Entity\User;
use Dynart\Dpress\Entity\UserToken;
use Dynart\Dpress\Security\DpressUser;
use Dynart\Dpress\Security\PasswordHasher;
use Firebase\JWT\JWT;

/**
 * Logging in, out, and back in again
 *
 * Issues a short lived access token that carries the user's roles and permissions, plus a long
 * lived refresh token stored hashed in the database. The access token is what `JwtValidator`
 * decodes; the refresh token is the only thing that can mint a new one.
 *
 * Permissions travel inside the access token, so a role change does not take effect until the
 * next refresh. Keeping the access token short is what makes that acceptable.
 */
class AuthService {

    const EVENT_LOGGED_IN = 'user:logged_in';
    const EVENT_LOGGED_OUT = 'user:logged_out';
    const EVENT_LOGIN_FAILED = 'user:login_failed';
    const EVENT_TOKEN_REFRESHED = 'user:token_refreshed';
    const EVENT_PASSWORD_RESET = 'user:password_reset';

    const CONFIG_SECRET = 'jwt.secret';
    const CONFIG_ALGORITHM = 'jwt.algorithm';
    const CONFIG_ACCESS_TTL = 'jwt.access_ttl';
    const CONFIG_REFRESH_TTL = 'jwt.refresh_ttl';
    const CONFIG_RESET_TTL = 'dpress.password_reset_ttl';

    const DEFAULT_ALGORITHM = 'HS256';

    /** 15 minutes - short, because a role change only lands on the next refresh */
    const DEFAULT_ACCESS_TTL = 900;

    /** 30 days */
    const DEFAULT_REFRESH_TTL = 2592000;

    /** 1 hour */
    const DEFAULT_RESET_TTL = 3600;

    public function __construct(
        protected ConfigInterface $config,
        protected EntityManager $em,
        protected Database $db,
        protected EventServiceInterface $events,
        protected PasswordHasher $hasher,
        protected UserService $users,
        protected JwtAuthInterface $jwtAuth,
    ) {}

    public function postConstruct(): void {
        $this->jwtAuth->setUserResolver([$this, 'resolveUser']);
    }

    // --- Login ---

    /**
     * Checks the credentials and issues a token pair
     *
     * A blocked or pending user is refused with the same failure as a wrong password, so the
     * response never says which of the two it was.
     *
     * @return array ['access' => string, 'refresh' => string, 'user' => User]
     * @throws DpressException if the credentials are wrong or the account cannot log in
     */
    public function login(string $email, string $password): array {
        $user = $this->users->findByEmail($this->users->normalizeEmail($email));
        if ($user === null || !$this->hasher->verify($password, $user->password_hash) || !$user->isActive()) {
            $this->events->emit(self::EVENT_LOGIN_FAILED, [$email]);
            throw new DpressException('Invalid email address or password.');
        }
        if ($this->hasher->needsRehash($user->password_hash)) {
            $user->password_hash = $this->hasher->hash($password);
            $this->em->save($user);
        }
        $result = $this->issueTokens($user);
        $this->events->emit(self::EVENT_LOGGED_IN, [$user]);
        return $result;
    }

    /**
     * Revokes a refresh token
     *
     * Revoking rather than deleting, so a token that turns up again after a logout is recognised
     * as replayed instead of merely unknown.
     */
    public function logout(string $refreshToken): void {
        $stored = $this->findRefreshToken($refreshToken);
        if ($stored === null) {
            return;
        }
        $user = $this->users->findById($stored->user_id);
        $stored->revoked_at = $this->now();
        $this->em->save($stored);
        $this->events->emit(self::EVENT_LOGGED_OUT, [$user]);
    }

    /**
     * Trades a refresh token for a new pair
     *
     * The old refresh token is revoked and a new one issued, so a stolen token is usable at most
     * once before the real user's next refresh invalidates it.
     *
     * @return array ['access' => string, 'refresh' => string, 'user' => User]
     */
    public function refresh(string $refreshToken): array {
        $stored = $this->findRefreshToken($refreshToken);
        if ($stored === null || $stored->revoked_at !== null || $stored->expires_at < $this->now()) {
            throw new DpressException('The session has expired, please log in again.');
        }
        $user = $this->users->findById($stored->user_id);
        if ($user === null || !$user->isActive()) {
            throw new DpressException('The session has expired, please log in again.');
        }
        $stored->revoked_at = $this->now();
        $this->em->save($stored);
        $result = $this->issueTokens($user);
        $this->events->emit(self::EVENT_TOKEN_REFRESHED, [$user]);
        return $result;
    }

    /**
     * @return array ['access' => string, 'refresh' => string, 'user' => User]
     */
    public function issueTokens(User $user): array {
        return [
            'access'  => $this->createAccessToken($user),
            'refresh' => $this->createRefreshToken($user),
            'user'    => $user,
        ];
    }

    public function createAccessToken(User $user): string {
        $issuedAt = time();
        $payload = [
            'sub'   => (string)$user->id,
            'iat'   => $issuedAt,
            'exp'   => $issuedAt + $this->accessTtl(),
            'name'  => $user->name,
            'roles' => $this->users->roleNames($user->id),
            'perms' => $this->users->permissions($user->id),
        ];
        return JWT::encode($payload, $this->secret(), $this->algorithm());
    }

    public function createRefreshToken(User $user): string {
        [$token, $hash] = $this->hasher->createToken();
        $entity = new RefreshToken();
        $entity->user_id = $user->id;
        $entity->token_hash = $hash;
        $entity->created_at = $this->now();
        $entity->expires_at = gmdate('Y-m-d H:i:s', time() + $this->refreshTtl());
        $this->em->save($entity);
        return $token;
    }

    /**
     * Rebuilds the user from a decoded token, for `JwtAuth`
     *
     * Everything comes from the payload, so an authorized request costs no database query at
     * all. The price is that the roles are as fresh as the token.
     */
    public function resolveUser(string $sub, object $payload): JwtUserInterface {
        return new DpressUser(
            $sub,
            isset($payload->roles) ? (array)$payload->roles : [],
            isset($payload->perms) ? (array)$payload->perms : [],
            null,
            isset($payload->name) ? (string)$payload->name : ''
        );
    }

    /**
     * The logged in user, loaded from the database
     *
     * Use it when the `User` record itself is needed. `JwtAuth::user()` is enough for
     * permission checks and does not hit the database.
     */
    public function currentUser(): ?User {
        $jwtUser = $this->jwtAuth->user();
        return $jwtUser === null ? null : $this->users->findById((int)$jwtUser->sub());
    }

    // --- Password reset ---

    /**
     * Creates a single use reset token
     *
     * Returns null for an unknown address rather than throwing, so the caller can answer the
     * same way either way and not turn the form into a way of finding out who has an account.
     *
     * @return string|null The token to put in the emailed URL
     */
    public function createPasswordResetToken(string $email): ?string {
        $user = $this->users->findByEmail($this->users->normalizeEmail($email));
        if ($user === null) {
            return null;
        }
        [$token, $hash] = $this->hasher->createToken();
        $entity = new UserToken();
        $entity->user_id = $user->id;
        $entity->type = UserToken::TYPE_PASSWORD_RESET;
        $entity->token_hash = $hash;
        $entity->created_at = $this->now();
        $entity->expires_at = gmdate('Y-m-d H:i:s', time() + $this->resetTtl());
        $this->em->save($entity);
        return $token;
    }

    public function findValidUserToken(string $token, string $type): ?UserToken {
        $id = $this->db->fetchOne(
            'select `id` from '.$this->em->safeTableName(UserToken::class)
                .' where `token_hash` = :hash and `type` = :type and `used_at` is null and `expires_at` > :now',
            [':hash' => $this->hasher->hashToken($token), ':type' => $type, ':now' => $this->now()]
        );
        if ($id === false || $id === null) {
            return null;
        }
        $entity = $this->em->findById(UserToken::class, (int)$id);
        return $entity instanceof UserToken ? $entity : null;
    }

    /**
     * Consumes a reset token and sets the new password
     *
     * Every refresh token of the user is revoked as well: whoever forced the reset should not
     * keep a session that was opened before it.
     */
    public function resetPassword(string $token, string $password): User {
        $stored = $this->findValidUserToken($token, UserToken::TYPE_PASSWORD_RESET);
        if ($stored === null) {
            throw new DpressException('This password reset link is invalid or has expired.');
        }
        $user = $this->users->findById($stored->user_id);
        if ($user === null) {
            throw new DpressException('This password reset link is invalid or has expired.');
        }
        $this->users->setPassword($user, $password);
        $stored->used_at = $this->now();
        $this->em->save($stored);
        $this->revokeAllRefreshTokens($user->id);
        $this->events->emit(self::EVENT_PASSWORD_RESET, [$user]);
        return $user;
    }

    public function revokeAllRefreshTokens(int $userId): void {
        $this->db->update(
            $this->em->tableName(RefreshToken::class),
            ['revoked_at' => $this->now()],
            '`user_id` = :userId and `revoked_at` is null',
            [':userId' => $userId]
        );
    }

    // --- Config ---

    public function accessTtl(): int {
        return (int)$this->config->get(self::CONFIG_ACCESS_TTL, self::DEFAULT_ACCESS_TTL);
    }

    public function refreshTtl(): int {
        return (int)$this->config->get(self::CONFIG_REFRESH_TTL, self::DEFAULT_REFRESH_TTL);
    }

    public function resetTtl(): int {
        return (int)$this->config->get(self::CONFIG_RESET_TTL, self::DEFAULT_RESET_TTL);
    }

    public function algorithm(): string {
        return (string)$this->config->get(self::CONFIG_ALGORITHM, self::DEFAULT_ALGORITHM);
    }

    /**
     * @throws DpressException if there is no usable secret - HS256 needs at least 32 bytes
     */
    public function secret(): string {
        $secret = (string)$this->config->get(self::CONFIG_SECRET, '');
        if (strlen($secret) < 32) {
            throw new DpressException(
                'jwt.secret is missing or too short. It has to be at least 32 characters.'
            );
        }
        return $secret;
    }

    protected function findRefreshToken(string $token): ?RefreshToken {
        $id = $this->db->fetchOne(
            'select `id` from '.$this->em->safeTableName(RefreshToken::class).' where `token_hash` = :hash',
            [':hash' => $this->hasher->hashToken($token)]
        );
        if ($id === false || $id === null) {
            return null;
        }
        $entity = $this->em->findById(RefreshToken::class, (int)$id);
        return $entity instanceof RefreshToken ? $entity : null;
    }

    protected function now(): string {
        return gmdate('Y-m-d H:i:s');
    }
}
