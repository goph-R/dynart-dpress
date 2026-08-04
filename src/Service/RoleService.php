<?php

namespace Dynart\Dpress\Service;

use Dynart\Micro\EventServiceInterface;
use Dynart\Micro\Entities\Database;
use Dynart\Micro\Entities\EntityManager;
use Dynart\Micro\Entities\QueryExecutor;
use Dynart\Dpress\DpressException;
use Dynart\Dpress\Entity\Role;
use Dynart\Dpress\Entity\RolePermission;
use Dynart\Dpress\Entity\UserRole;
use Dynart\Dpress\Query\QueryFactory;
use Dynart\Dpress\Security\Permissions;

/**
 * Roles and what they are allowed to do
 */
class RoleService {

    const EVENT_BEFORE_CREATE = 'role:before_create';
    const EVENT_CREATED = 'role:created';
    const EVENT_UPDATED = 'role:updated';
    const EVENT_BEFORE_DELETE = 'role:before_delete';
    const EVENT_DELETED = 'role:deleted';
    const EVENT_PERMISSION_GRANTED = 'permission:granted';
    const EVENT_PERMISSION_REVOKED = 'permission:revoked';

    /** What a self registered user gets */
    const CONFIG_DEFAULT_ROLES = 'dpress.default_roles';

    public function __construct(
        protected EntityManager $em,
        protected Database $db,
        protected QueryExecutor $queryExecutor,
        protected QueryFactory $queries,
        protected EventServiceInterface $events,
        protected Permissions $permissions,
        protected \Dynart\Micro\ConfigInterface $config,
    ) {}

    // --- Reading ---

    public function findById(int $id): ?Role {
        $role = $this->em->findById(Role::class, $id);
        return $role instanceof Role ? $role : null;
    }

    public function findByName(string $name): ?Role {
        $id = $this->db->fetchOne(
            'select `id` from '.$this->em->safeTableName(Role::class).' where `name` = :name',
            [':name' => $name]
        );
        return $id === false || $id === null ? null : $this->findById((int)$id);
    }

    public function findAll(): array {
        return $this->queryExecutor->findAll($this->queries->create('role_list'));
    }

    /**
     * @return string[] The permissions a role grants
     */
    public function permissionsOf(int $roleId): array {
        return $this->queryExecutor->findAllColumn(
            $this->queries->create('role_permissions', ['role_id' => $roleId]),
            'permission'
        );
    }

    /**
     * @return string[] The role names a self registered user gets
     */
    public function defaultRoleNames(): array {
        $configured = $this->config->getCommaSeparatedValues(self::CONFIG_DEFAULT_ROLES);
        return empty($configured) ? [Role::NAME_READER] : $configured;
    }

    // --- Writing ---

    public function create(string $name, string $label = '', array $permissions = [], bool $removable = true): Role {
        if ($this->findByName($name) !== null) {
            throw new DpressException("There is already a role named '$name'.");
        }
        $role = new Role();
        $role->name = $name;
        $role->label = $label !== '' ? $label : $name;
        $role->removable = $removable;
        $this->events->emit(self::EVENT_BEFORE_CREATE, [$role]);
        $this->em->save($role);
        foreach ($permissions as $permission) {
            $this->grant($role, $permission);
        }
        $this->events->emit(self::EVENT_CREATED, [$role]);
        return $role;
    }

    public function update(Role $role): void {
        $this->em->save($role);
        $this->events->emit(self::EVENT_UPDATED, [$role]);
    }

    /**
     * Deletes a role, along with its permissions and its grants
     *
     * Both of those are removed through the entity manager rather than by a database cascade, so
     * the events fire and the audit records who lost what. A cascade would take the rows out
     * from under the ORM with no trace.
     *
     * @throws DpressException if the role is unremovable
     */
    public function delete(Role $role): void {
        if (!$role->removable) {
            throw new DpressException("The role '{$role->name}' can not be removed.");
        }
        $this->events->emit(self::EVENT_BEFORE_DELETE, [$role]);
        foreach ($this->permissionsOf($role->id) as $permission) {
            $this->revoke($role, $permission);
        }
        $this->deleteGrantsOf($role);
        $this->em->deleteById(Role::class, $role->id);
        $this->events->emit(self::EVENT_DELETED, [$role]);
    }

    public function grant(Role $role, string $permission): void {
        if ($this->hasPermission($role->id, $permission)) {
            return;
        }
        $rolePermission = new RolePermission();
        $rolePermission->role_id = $role->id;
        $rolePermission->permission = $permission;
        $this->em->save($rolePermission);
        $this->events->emit(self::EVENT_PERMISSION_GRANTED, [$role, $permission]);
    }

    public function revoke(Role $role, string $permission): void {
        if (!$this->hasPermission($role->id, $permission)) {
            return;
        }
        $rolePermission = new RolePermission();
        $rolePermission->role_id = $role->id;
        $rolePermission->permission = $permission;
        $rolePermission->setNew(false);
        $this->events->emit(RolePermission::event(RolePermission::EVENT_BEFORE_DELETE), [$rolePermission]);
        $this->db->query(
            'delete from '.$this->em->safeTableName(RolePermission::class).' where `role_id` = :roleId and `permission` = :permission',
            [':roleId' => $role->id, ':permission' => $permission],
            true
        );
        $this->events->emit(RolePermission::event(RolePermission::EVENT_AFTER_DELETE), [$rolePermission]);
        $this->events->emit(self::EVENT_PERMISSION_REVOKED, [$role, $permission]);
    }

    /**
     * Replaces the whole permission set of a role, emitting one event per actual change
     */
    public function setPermissions(Role $role, array $permissions): void {
        $current = $this->permissionsOf($role->id);
        foreach (array_diff($permissions, $current) as $permission) {
            $this->grant($role, $permission);
        }
        foreach (array_diff($current, $permissions) as $permission) {
            $this->revoke($role, $permission);
        }
    }

    public function hasPermission(int $roleId, string $permission): bool {
        $count = $this->db->fetchOne(
            'select count(1) from '.$this->em->safeTableName(RolePermission::class).' where `role_id` = :roleId and `permission` = :permission',
            [':roleId' => $roleId, ':permission' => $permission]
        );
        return (int)$count > 0;
    }

    protected function deleteGrantsOf(Role $role): void {
        $userIds = $this->db->fetchColumn(
            'select `user_id` from '.$this->em->safeTableName(UserRole::class).' where `role_id` = :roleId',
            [':roleId' => $role->id]
        );
        foreach ($userIds as $userId) {
            $userRole = new UserRole();
            $userRole->user_id = (int)$userId;
            $userRole->role_id = $role->id;
            $userRole->setNew(false);
            $this->events->emit(UserRole::event(UserRole::EVENT_BEFORE_DELETE), [$userRole]);
            $this->db->query(
                'delete from '.$this->em->safeTableName(UserRole::class).' where `user_id` = :userId and `role_id` = :roleId',
                [':userId' => (int)$userId, ':roleId' => $role->id],
                true
            );
            $this->events->emit(UserRole::event(UserRole::EVENT_AFTER_DELETE), [$userRole]);
        }
    }
}
