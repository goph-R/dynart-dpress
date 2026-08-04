<?php

namespace Dynart\Dpress\Security;

/**
 * The registry of permission strings
 *
 * Permissions are plain strings, so a plugin declaring `myplugin.do_thing` shows up in the role
 * editor without a migration or a lookup table. This class is the list of the ones the CMS
 * itself defines, plus wherever plugins add theirs.
 *
 * The **admin** role holds every permission implicitly, so a new permission never has to be
 * granted to it retroactively - which is the whole reason that role is unremovable.
 */
class Permissions {

    const USER_VIEW = 'user.view';
    const USER_CREATE = 'user.create';
    const USER_UPDATE = 'user.update';
    const USER_DELETE = 'user.delete';

    const ROLE_VIEW = 'role.view';
    const ROLE_CREATE = 'role.create';
    const ROLE_UPDATE = 'role.update';
    const ROLE_DELETE = 'role.delete';
    const ROLE_ASSIGN = 'role.assign';

    const SETTING_VIEW = 'setting.view';
    const SETTING_UPDATE = 'setting.update';

    /**
     * Per type, because "may write posts" and "may restructure the site's pages" are different
     * jobs even though both are `Content` rows
     */
    const POST_VIEW = 'post.view';
    const POST_CREATE = 'post.create';
    const POST_UPDATE = 'post.update';
    const POST_DELETE = 'post.delete';
    const POST_PUBLISH = 'post.publish';

    const PAGE_VIEW = 'page.view';
    const PAGE_CREATE = 'page.create';
    const PAGE_UPDATE = 'page.update';
    const PAGE_DELETE = 'page.delete';
    const PAGE_PUBLISH = 'page.publish';

    /** Reading the revision history of a piece of content */
    const CONTENT_HISTORY = 'content.history';

    /** The permissions the CMS defines, in [permission => group] format */
    const CORE = [
        self::USER_VIEW      => 'user',
        self::USER_CREATE    => 'user',
        self::USER_UPDATE    => 'user',
        self::USER_DELETE    => 'user',
        self::ROLE_VIEW      => 'role',
        self::ROLE_CREATE    => 'role',
        self::ROLE_UPDATE    => 'role',
        self::ROLE_DELETE    => 'role',
        self::ROLE_ASSIGN    => 'role',
        self::SETTING_VIEW   => 'setting',
        self::SETTING_UPDATE => 'setting',
        self::POST_VIEW      => 'post',
        self::POST_CREATE    => 'post',
        self::POST_UPDATE    => 'post',
        self::POST_DELETE    => 'post',
        self::POST_PUBLISH   => 'post',
        self::PAGE_VIEW      => 'page',
        self::PAGE_CREATE    => 'page',
        self::PAGE_UPDATE    => 'page',
        self::PAGE_DELETE    => 'page',
        self::PAGE_PUBLISH   => 'page',
        self::CONTENT_HISTORY => 'content',
    ];

    /**
     * The permission for an action on a content type, e.g. `post.create` / `page.create`
     *
     * The service resolves it from the row's `type`, so one code path covers both.
     */
    public static function forContent(string $type, string $action): string {
        return $type.'.'.$action;
    }

    /** @var array<string, string> Extra permissions in [permission => group] format */
    protected array $registered = [];

    /**
     * Registers a permission so the role editor offers it
     *
     * @param string $permission Dot separated, `<subject>.<verb>`
     * @param string $group What to list it under in the role editor
     */
    public function add(string $permission, string $group = 'other'): void {
        $this->registered[$permission] = $group;
    }

    public function has(string $permission): bool {
        return isset(self::CORE[$permission]) || isset($this->registered[$permission]);
    }

    /**
     * @return array<string, string> Every known permission in [permission => group] format
     */
    public function all(): array {
        return array_merge(self::CORE, $this->registered);
    }

    /**
     * @return array<string, string[]> Every known permission grouped, for the role editor
     */
    public function grouped(): array {
        $result = [];
        foreach ($this->all() as $permission => $group) {
            $result[$group][] = $permission;
        }
        return $result;
    }

    /**
     * @return string[] Just the permission names
     */
    public function names(): array {
        return array_keys($this->all());
    }
}
