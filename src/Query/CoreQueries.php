<?php

namespace Dynart\Dpress\Query;

use Dynart\Micro\Entities\Query;
use Dynart\Dpress\Entity\Role;
use Dynart\Dpress\Entity\RolePermission;
use Dynart\Dpress\Entity\User;
use Dynart\Dpress\Entity\UserRole;

/**
 * The query builders the CMS itself registers
 *
 * Every one of these goes through `QueryFactory`, so a plugin can attach conditions to any of
 * them. Each builder adds its own filters rather than trusting the caller to - a subscriber can
 * only narrow what a builder produced, never widen it.
 */
class CoreQueries {

    public static function register(QueryFactory $factory): void {
        $factory->add('user_by_email', [self::class, 'userByEmail']);
        $factory->add('user_list', [self::class, 'userList']);
        $factory->add('user_roles', [self::class, 'userRoles']);
        $factory->add('user_permissions', [self::class, 'userPermissions']);
        $factory->add('role_list', [self::class, 'roleList']);
        $factory->add('role_permissions', [self::class, 'rolePermissions']);
    }

    public function userByEmail(array $context): Query {
        $query = new Query(User::class);
        $query->addCondition('`email` = :email', [':email' => $context['email'] ?? '']);
        return $query;
    }

    /**
     * @param array $context `status` to filter by one, `search` for a name/email match
     */
    public function userList(array $context): Query {
        $query = new Query(User::class);
        if (!empty($context['status'])) {
            $query->addCondition('`status` = :status', [':status' => $context['status']]);
        }
        if (!empty($context['search'])) {
            $query->addCondition(
                '(`name` like :search or `email` like :search)',
                [':search' => '%'.$context['search'].'%']
            );
        }
        $query->addOrderBy('name');
        return $query;
    }

    /**
     * The role names of one user
     */
    public function userRoles(array $context): Query {
        $query = new Query(Role::class);
        $query->setFields(['id' => 'id', 'name' => 'name', 'label' => 'label']);
        $query->addInnerJoin(
            [UserRole::class, 'ur'],
            '`ur`.`role_id` = '.$this->safeTable(Role::class).'.`id`'
        );
        $query->addCondition('`ur`.`user_id` = :userId', [':userId' => $context['user_id'] ?? 0]);
        $query->addOrderBy('name');
        return $query;
    }

    /**
     * The permission strings of one user, through every role they hold
     */
    public function userPermissions(array $context): Query {
        $query = new Query(RolePermission::class);
        $query->setFields(['permission' => 'permission']);
        $query->addInnerJoin(
            [UserRole::class, 'ur'],
            '`ur`.`role_id` = '.$this->safeTable(RolePermission::class).'.`role_id`'
        );
        $query->addCondition('`ur`.`user_id` = :userId', [':userId' => $context['user_id'] ?? 0]);
        $query->addGroupBy('`permission`');
        return $query;
    }

    public function roleList(array $context): Query {
        $query = new Query(Role::class);
        $query->addOrderBy('name');
        return $query;
    }

    public function rolePermissions(array $context): Query {
        $query = new Query(RolePermission::class);
        $query->setFields(['permission' => 'permission']);
        $query->addCondition('`role_id` = :roleId', [':roleId' => $context['role_id'] ?? 0]);
        return $query;
    }

    /**
     * The `#ClassName` token the database layer replaces with the prefixed table name
     *
     * Used in join conditions, which are raw SQL, so the prefix does not have to be hardcoded.
     */
    protected function safeTable(string $className): string {
        $parts = explode('\\', $className);
        return '#'.end($parts);
    }
}
