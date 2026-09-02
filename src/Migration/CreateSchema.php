<?php

namespace Dynart\Dpress\Migration;

use Dynart\Micro\Entities\EntityManager;
use Dynart\Micro\Entities\MigrationInterface;
use Dynart\Micro\Entities\QueryExecutor;
use Dynart\Micro\Entities\Revision;
use Dynart\Dpress\Entity\AuthAttempt;
use Dynart\Dpress\Entity\Block;
use Dynart\Dpress\Entity\Category;
use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Entity\ContentAttachment;
use Dynart\Dpress\Entity\ContentCategory;
use Dynart\Dpress\Entity\ContentTag;
use Dynart\Dpress\Entity\Media;
use Dynart\Dpress\Entity\Menu;
use Dynart\Dpress\Entity\MenuItem;
use Dynart\Dpress\Entity\RefreshToken;
use Dynart\Dpress\Entity\Role;
use Dynart\Dpress\Entity\RolePermission;
use Dynart\Dpress\Entity\Setting;
use Dynart\Dpress\Entity\Tag;
use Dynart\Dpress\Entity\User;
use Dynart\Dpress\Entity\UserRole;
use Dynart\Dpress\Entity\UserToken;
use Dynart\Dpress\Security\Permissions;
use Dynart\Dpress\Service\RoleService;

/**
 * The whole schema, and the roles a new site starts with
 *
 * This was eight migrations until 0.22.0, one per group of tables plus an `alter` for a column
 * added later. They were squashed while dpress is still before 1.0 and no installation holds
 * anything anybody minds losing: eight files describing a schema nobody had ever applied
 * incrementally is eight files to read to find out what a table looks like.
 *
 * **After 1.0 this stops.** From there migrations are append-only, because somebody's data is on
 * the other end of them.
 */
class CreateSchema implements MigrationInterface {

    /**
     * Every table, parents before children
     *
     * The order is the foreign keys': `Revision` first because every `_aud` mirror points at it,
     * then `User` and `Role` before `UserRole` joins them, `Content` and `Media` before
     * `ContentAttachment` links the two.
     *
     * One list and one call, because **whether a table gets an audit mirror is the entity's own
     * answer**: `createTableWithAudit()` builds one only where the class is `#[Auditable]`, and
     * five of these are not - the two tokens, the two menu tables and the failed-login window.
     * A second list here would be that same fact written down twice, and the copy would be the
     * one that goes stale.
     */
    const TABLES = [
        Revision::class,
        User::class,
        Role::class,
        UserRole::class,
        RolePermission::class,
        RefreshToken::class,
        UserToken::class,
        Media::class,
        Content::class,
        Category::class,
        Tag::class,
        ContentCategory::class,
        ContentTag::class,
        ContentAttachment::class,
        Setting::class,
        Menu::class,
        MenuItem::class,
        Block::class,
        AuthAttempt::class,
    ];

    /**
     * What an editor may do
     *
     * The **admin** role is seeded with none of these and holds them all implicitly, which is why
     * a permission invented later - by a plugin, say - never needs granting to it retroactively.
     */
    const EDITOR_PERMISSIONS = [
        Permissions::USER_VIEW,
        Permissions::POST_VIEW, Permissions::POST_CREATE, Permissions::POST_UPDATE,
        Permissions::POST_DELETE, Permissions::POST_PUBLISH,
        Permissions::PAGE_VIEW, Permissions::PAGE_UPDATE,
        Permissions::CONTENT_HISTORY,
        Permissions::CATEGORY_VIEW, Permissions::CATEGORY_CREATE, Permissions::CATEGORY_UPDATE,
        Permissions::TAG_VIEW, Permissions::TAG_CREATE, Permissions::TAG_UPDATE, Permissions::TAG_DELETE,
        Permissions::MEDIA_VIEW, Permissions::MEDIA_CREATE, Permissions::MEDIA_UPDATE, Permissions::MEDIA_DELETE,
        Permissions::MENU_VIEW, Permissions::MENU_UPDATE,
        Permissions::BLOCK_VIEW, Permissions::BLOCK_UPDATE,
        Permissions::SETTING_VIEW,
    ];

    public function __construct(
        private EntityManager $em,
        private QueryExecutor $queryExecutor,
        private RoleService $roles,
    ) {}

    public function version(): string {
        return '0001_create_schema';
    }

    public function up(): void {
        // `Revision` comes from the library rather than from the CMS, so the namespace scan never
        // reaches it and it has to be introduced by hand before its table can be built
        $this->em->registerEntity(Revision::class);
        foreach (self::TABLES as $className) {
            $this->queryExecutor->createTableWithAudit($className, true);
        }
        $this->seedRoles();
    }

    /**
     * The three roles a site starts with
     *
     * Each is created only when it is missing, so running this against a database that already
     * holds them changes nothing.
     */
    protected function seedRoles(): void {
        if ($this->roles->findByName(Role::NAME_ADMIN) === null) {
            $this->roles->create(Role::NAME_ADMIN, 'Administrator', [], false);
        }
        if ($this->roles->findByName(Role::NAME_READER) === null) {
            $this->roles->create(Role::NAME_READER, 'Reader', []);
        }
        if ($this->roles->findByName(Role::NAME_EDITOR) === null) {
            $this->roles->create(Role::NAME_EDITOR, 'Editor', []);
        }
        $editor = $this->roles->findByName(Role::NAME_EDITOR);
        if ($editor === null) {
            return;
        }
        foreach (self::EDITOR_PERMISSIONS as $permission) {
            $this->roles->grant($editor, $permission);
        }
    }
}
