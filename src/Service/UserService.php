<?php

namespace Dynart\Dpress\Service;

use Dynart\Micro\EventServiceInterface;
use Dynart\Micro\Entities\Database;
use Dynart\Micro\Entities\EntityManager;
use Dynart\Micro\Entities\QueryExecutor;
use Dynart\Dpress\DpressException;
use Dynart\Dpress\Entity\Role;
use Dynart\Dpress\Entity\RefreshToken;
use Dynart\Dpress\Entity\User;
use Dynart\Dpress\Entity\UserRole;
use Dynart\Dpress\Entity\UserToken;
use Dynart\Dpress\Query\QueryFactory;
use Dynart\Dpress\Security\DpressUser;
use Dynart\Dpress\Security\PasswordHasher;

/**
 * Everything that changes a user
 *
 * Every state change goes through here and emits before/after events, so a plugin can see it.
 * A controller writing a `User` directly would be invisible to plugins forever.
 */
class UserService {

    const EVENT_BEFORE_CREATE = 'user:before_create';
    const EVENT_CREATED = 'user:created';
    const EVENT_BEFORE_UPDATE = 'user:before_update';
    const EVENT_UPDATED = 'user:updated';
    const EVENT_BEFORE_DELETE = 'user:before_delete';
    const EVENT_DELETED = 'user:deleted';
    const EVENT_REGISTERED = 'user:registered';
    const EVENT_PASSWORD_CHANGED = 'user:password_changed';
    const EVENT_ROLE_GRANTED = 'user:role_granted';
    const EVENT_ROLE_REVOKED = 'user:role_revoked';

    public function __construct(
        protected EntityManager $em,
        protected Database $db,
        protected QueryExecutor $queryExecutor,
        protected QueryFactory $queries,
        protected EventServiceInterface $events,
        protected PasswordHasher $hasher,
        protected RoleService $roles,
    ) {}

    // --- Reading ---

    public function findById(int $id): ?User {
        $user = $this->em->findById(User::class, $id);
        return $user instanceof User ? $user : null;
    }

    public function findByEmail(string $email): ?User {
        $rows = $this->queryExecutor->findAll($this->queries->create('user_by_email', ['email' => $email]));
        if (empty($rows)) {
            return null;
        }
        return $this->findById((int)$rows[0]['id']);
    }

    public function emailExists(string $email): bool {
        return $this->findByEmail($email) !== null;
    }

    /**
     * @return array The raw rows, for a listing screen
     */
    public function findAll(array $context = []): array {
        return $this->queryExecutor->findAll($this->queries->create('user_list', $context));
    }

    public function countAll(array $context = []): int {
        return (int)$this->queryExecutor->findAllCount($this->queries->create('user_list', $context));
    }

    /**
     * @return string[] The role names of a user
     */
    public function roleNames(int $userId): array {
        $rows = $this->queryExecutor->findAll($this->queries->create('user_roles', ['user_id' => $userId]));
        return array_column($rows, 'name');
    }

    /**
     * @return string[] The permissions a user holds through their roles
     */
    public function permissions(int $userId): array {
        return $this->queryExecutor->findAllColumn(
            $this->queries->create('user_permissions', ['user_id' => $userId]),
            'permission'
        );
    }

    /**
     * Builds the object the framework's authorization works with
     */
    public function toDpressUser(User $user): DpressUser {
        return new DpressUser(
            (string)$user->id,
            $this->roleNames($user->id),
            $this->permissions($user->id),
            $user
        );
    }

    // --- Writing ---

    /**
     * @param string[] $roleNames The roles to grant, by name
     * @throws DpressException if the email is taken or the password is too short
     */
    public function create(string $email, string $password, string $name, array $roleNames = [], string $status = User::STATUS_ACTIVE): User {
        $email = $this->normalizeEmail($email);
        $this->assertEmailIsFree($email);
        $this->assertPasswordIsUsable($password);
        $user = new User();
        $user->email = $email;
        $user->name = $name !== '' ? $name : $email;
        $user->password_hash = $this->hasher->hash($password);
        $user->status = $status;
        $user->created_at = $this->now();
        $user->updated_at = $user->created_at;
        $this->events->emit(self::EVENT_BEFORE_CREATE, [$user]);
        $this->em->save($user);
        foreach ($roleNames as $roleName) {
            $this->grantRole($user, $roleName);
        }
        $this->events->emit(self::EVENT_CREATED, [$user]);
        return $user;
    }

    /**
     * A self service sign up
     *
     * The same as `create()` plus the registered event, which is what a welcome mail or a
     * moderation queue would hang off. Kept separate so a plugin can tell an administrator
     * adding an account apart from somebody signing themselves up.
     */
    public function register(string $email, string $password, string $name, string $status = User::STATUS_PENDING): User {
        $user = $this->create($email, $password, $name, $this->roles->defaultRoleNames(), $status);
        $this->events->emit(self::EVENT_REGISTERED, [$user]);
        return $user;
    }

    public function update(User $user): void {
        $user->updated_at = $this->now();
        $this->events->emit(self::EVENT_BEFORE_UPDATE, [$user]);
        $this->em->save($user);
        $this->events->emit(self::EVENT_UPDATED, [$user]);
    }

    public function changeEmail(User $user, string $email): void {
        $email = $this->normalizeEmail($email);
        if ($email !== $user->email) {
            $this->assertEmailIsFree($email);
            $user->email = $email;
        }
        $this->update($user);
    }

    public function setPassword(User $user, string $password): void {
        $this->assertPasswordIsUsable($password);
        $user->password_hash = $this->hasher->hash($password);
        $user->updated_at = $this->now();
        $this->em->save($user);
        $this->events->emit(self::EVENT_PASSWORD_CHANGED, [$user]);
    }

    public function setStatus(User $user, string $status): void {
        if (!in_array($status, User::STATUSES)) {
            throw new DpressException("Unknown user status '$status'.");
        }
        if ($status !== User::STATUS_ACTIVE) {
            // pending locks somebody out exactly as thoroughly as blocked does
            $this->guardLastActiveAdmin($user, "the account cannot be set to '$status'");
        }
        $user->status = $status;
        $this->update($user);
    }

    /**
     * Deletes a user and everything that hangs off them
     *
     * The role grants and the tokens are removed through the entity manager rather than by a
     * database cascade: a cascade happens inside the database, so no event fires and no audit row
     * is written - the history would show the grant simply gone.
     */
    public function delete(User $user): void {
        $this->guardLastActiveAdmin($user, 'the account cannot be deleted');
        $this->events->emit(self::EVENT_BEFORE_DELETE, [$user]);
        foreach ($this->roleNames($user->id) as $roleName) {
            $this->revokeRole($user, $roleName);
        }
        $this->deleteTokensOf($user->id);
        $this->em->deleteById(User::class, $user->id);
        $this->events->emit(self::EVENT_DELETED, [$user]);
    }

    // --- Roles ---

    public function grantRole(User $user, string $roleName): void {
        $role = $this->roles->findByName($roleName);
        if ($role === null) {
            throw new DpressException("There is no role named '$roleName'.");
        }
        if ($this->hasRole($user->id, $role->id)) {
            return;
        }
        $userRole = new UserRole();
        $userRole->user_id = $user->id;
        $userRole->role_id = $role->id;
        $this->em->save($userRole);
        $this->events->emit(self::EVENT_ROLE_GRANTED, [$user, $role]);
    }

    public function revokeRole(User $user, string $roleName): void {
        $role = $this->roles->findByName($roleName);
        if ($role === null || !$this->hasRole($user->id, $role->id)) {
            return;
        }
        if ($roleName === Role::NAME_ADMIN) {
            $this->guardLastActiveAdmin($user, 'the role cannot be taken away');
        }
        $userRole = new UserRole();
        $userRole->user_id = $user->id;
        $userRole->role_id = $role->id;
        $userRole->setNew(false);
        $this->events->emit(UserRole::event(UserRole::EVENT_BEFORE_DELETE), [$userRole]);
        $this->db->query(
            'delete from '.$this->em->safeTableName(UserRole::class).' where `user_id` = :userId and `role_id` = :roleId',
            [':userId' => $user->id, ':roleId' => $role->id],
            true
        );
        $this->events->emit(UserRole::event(UserRole::EVENT_AFTER_DELETE), [$userRole]);
        $this->events->emit(self::EVENT_ROLE_REVOKED, [$user, $role]);
    }

    public function hasRole(int $userId, int $roleId): bool {
        $count = $this->db->fetchOne(
            'select count(1) from '.$this->em->safeTableName(UserRole::class).' where `user_id` = :userId and `role_id` = :roleId',
            [':userId' => $userId, ':roleId' => $roleId]
        );
        return (int)$count > 0;
    }

    /**
     * How many people could actually sign in and administer the site
     *
     * **Active**, not merely holding the role. Counting every administrator regardless of status
     * gets the arithmetic wrong in the one case that matters: with two of them and one already
     * blocked, blocking the other leaves nobody who can log in while a naive count still says
     * two. `AuthService::login()` refuses anybody who is not active, so this is the number that
     * decides whether the site still has a way in.
     */
    public function countActiveAdmins(): int {
        return (int)$this->db->fetchOne(
            'select count(1) from '.$this->em->safeTableName(UserRole::class).' ur'
                .' inner join '.$this->em->safeTableName(Role::class).' r on `r`.`id` = `ur`.`role_id`'
                .' inner join '.$this->em->safeTableName(User::class).' u on `u`.`id` = `ur`.`user_id`'
                .' where `r`.`name` = :role and `u`.`status` = :status',
            [':role' => Role::NAME_ADMIN, ':status' => User::STATUS_ACTIVE]
        );
    }

    /**
     * Is this the one account still able to administer the site?
     *
     * Somebody who is already blocked is not it, however many roles they hold - taking the role
     * from an account that cannot log in anyway costs the site nothing.
     */
    public function isLastActiveAdmin(User $user): bool {
        return $user->isActive()
            && in_array(Role::NAME_ADMIN, $this->roleNames($user->id), true)
            && $this->countActiveAdmins() <= 1;
    }

    /**
     * Refuses to leave the site with no way in
     *
     * **Here rather than in the controller or the CLI.** The check used to live in
     * `UserCommands`, which meant it guarded `dpress user:role -revoke` and nothing else: the
     * admin UI revokes through `applyRoles()`, blocks through `setStatus()` and deletes through
     * `delete()`, and all three walked straight past it. A rule about the state the *site* may
     * end up in belongs where every path has to go through it.
     *
     * Recovering from this without a guard needs shell access - `dpress user:status` - which is
     * exactly what the person locked out of their own admin does not necessarily have.
     */
    protected function guardLastActiveAdmin(User $user, string $consequence): void {
        if ($this->isLastActiveAdmin($user)) {
            throw new DpressException(
                'This is the last administrator who can sign in, so '.$consequence.'.'
            );
        }
    }

    /**
     * How many users hold a role, so the last administrator cannot be demoted away
     */
    public function countByRole(string $roleName): int {
        $role = $this->roles->findByName($roleName);
        if ($role === null) {
            return 0;
        }
        return (int)$this->db->fetchOne(
            'select count(1) from '.$this->em->safeTableName(UserRole::class).' where `role_id` = :roleId',
            [':roleId' => $role->id]
        );
    }

    // --- Helpers ---

    public function deleteTokensOf(int $userId): void {
        foreach ([RefreshToken::class, UserToken::class] as $className) {
            $this->db->query(
                'delete from '.$this->em->safeTableName($className).' where `user_id` = :userId',
                [':userId' => $userId],
                true
            );
        }
    }

    public function normalizeEmail(string $email): string {
        return mb_strtolower(trim($email));
    }

    protected function assertEmailIsFree(string $email): void {
        if ($this->emailExists($email)) {
            throw new DpressException("The email address '$email' is already taken.");
        }
    }

    protected function assertPasswordIsUsable(string $password): void {
        if (mb_strlen($password) < PasswordHasher::MIN_LENGTH) {
            throw new DpressException('The password has to be at least '.PasswordHasher::MIN_LENGTH.' characters long.');
        }
    }

    protected function now(): string {
        return gmdate('Y-m-d H:i:s');
    }
}
