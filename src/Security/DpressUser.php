<?php

namespace Dynart\Dpress\Security;

use Dynart\Micro\JwtUserInterface;
use Dynart\Dpress\Entity\Role;
use Dynart\Dpress\Entity\User;

/**
 * The logged in user, as the framework's authorization sees it
 *
 * Built from a `User` plus the roles and permissions resolved for them, and handed to
 * `JwtAuth::setUser()`. `#[Authorize('post.create')]` ends up calling `hasPermission()` here.
 *
 * The permissions are baked in at login and travel in the token, so a role change does not take
 * effect until the access token is refreshed. That is what keeps the access token short lived.
 */
class DpressUser implements JwtUserInterface {

    /**
     * @param string[] $roles Role names
     * @param string[] $permissions Permission strings
     */
    public function __construct(
        private string $sub,
        private array $roles = [],
        private array $permissions = [],
        private ?User $user = null,
    ) {}

    public function sub(): string {
        return $this->sub;
    }

    public function id(): int {
        return (int)$this->sub;
    }

    public function permissions(): array {
        return $this->permissions;
    }

    public function roles(): array {
        return $this->roles;
    }

    /**
     * The user record, when it was loaded - it is not, for a user resolved straight from a token
     */
    public function user(): ?User {
        return $this->user;
    }

    public function name(): string {
        return $this->user !== null ? $this->user->name : '';
    }

    public function isAdmin(): bool {
        return in_array(Role::NAME_ADMIN, $this->roles);
    }

    /**
     * An admin holds every permission implicitly, including ones added after the role was created
     */
    public function hasPermission(string $permission): bool {
        return $this->isAdmin() || in_array($permission, $this->permissions);
    }

    public function hasRole(string $role): bool {
        return in_array($role, $this->roles);
    }
}
