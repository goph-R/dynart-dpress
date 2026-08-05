<?php

namespace Dynart\Dpress\Query;

use Dynart\Micro\Entities\EntityManager;
use Dynart\Micro\Entities\Query;
use Dynart\Dpress\Entity\Category;
use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Entity\ContentAttachment;
use Dynart\Dpress\Entity\ContentCategory;
use Dynart\Dpress\Entity\ContentTag;
use Dynart\Dpress\Entity\Media;
use Dynart\Dpress\Entity\Menu;
use Dynart\Dpress\Entity\MenuItem;
use Dynart\Dpress\Entity\Tag;
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

    public function __construct(private EntityManager $em) {}

    public static function register(QueryFactory $factory): void {
        $factory->add('user_by_email', [self::class, 'userByEmail']);
        $factory->add('user_list', [self::class, 'userList']);
        $factory->add('user_roles', [self::class, 'userRoles']);
        $factory->add('user_permissions', [self::class, 'userPermissions']);
        $factory->add('role_list', [self::class, 'roleList']);
        $factory->add('role_permissions', [self::class, 'rolePermissions']);
        $factory->add('content_list', [self::class, 'contentList']);
        $factory->add('content_by_slug', [self::class, 'contentBySlug']);
        $factory->add('content_children', [self::class, 'contentChildren']);
        $factory->add('content_archive', [self::class, 'contentArchive']);
        $factory->add('category_list', [self::class, 'categoryList']);
        $factory->add('tag_list', [self::class, 'tagList']);
        $factory->add('tag_cloud', [self::class, 'tagCloud']);
        $factory->add('content_tags', [self::class, 'contentTags']);
        $factory->add('content_categories', [self::class, 'contentCategories']);
        $factory->add('content_by_category', [self::class, 'contentByCategory']);
        $factory->add('content_by_tag', [self::class, 'contentByTag']);
        $factory->add('media_list', [self::class, 'mediaList']);
        $factory->add('content_attachments', [self::class, 'contentAttachments']);
        $factory->add('menu_list', [self::class, 'menuList']);
        $factory->add('menu_items', [self::class, 'menuItems']);
    }

    // --- Menus ---

    public function menuList(array $context): Query {
        $query = new Query(Menu::class);
        $this->applyListOptions($query, $context, ['name' => 'asc']);
        return $query;
    }

    public function menuItems(array $context): Query {
        $query = new Query(MenuItem::class);
        $query->addCondition('`menu_id` = :menuId', [':menuId' => $context['menu_id'] ?? 0]);
        $query->addOrderBy('position');
        $query->addOrderBy('label');
        return $query;
    }

    // --- Taxonomy ---

    public function categoryList(array $context): Query {
        $query = new Query(Category::class);
        if (array_key_exists('parent_id', $context)) {
            if ($context['parent_id'] === null) {
                $query->addCondition('`parent_id` is null');
            } else {
                $query->addCondition('`parent_id` = :parentId', [':parentId' => $context['parent_id']]);
            }
        }
        $this->applyListOptions($query, $context, ['position' => 'asc', 'name' => 'asc']);
        return $query;
    }

    public function tagList(array $context): Query {
        $query = new Query(Tag::class);
        if (!empty($context['search'])) {
            $query->addCondition('`name` like :search', [':search' => '%'.$context['search'].'%']);
        }
        $this->applyListOptions($query, $context, ['name' => 'asc']);
        return $query;
    }

    /**
     * Tags with how many published items carry them, for a tag cloud
     */
    public function tagCloud(array $context): Query {
        $query = new Query(Tag::class);
        // qualified, because the join brings in a `content` that also has an id and a slug
        $table = $this->table(Tag::class);
        $query->setFields([
            'id'    => $table.'.id',
            'name'  => $table.'.name',
            'slug'  => $table.'.slug',
            'total' => ['count(1)'],
        ]);
        $query->addInnerJoin([ContentTag::class, 'ct'], '`ct`.`tag_id` = '.$this->safeTable(Tag::class).'.`id`');
        $query->addInnerJoin([Content::class, 'c'], '`c`.`id` = `ct`.`content_id`');
        $query->addCondition('`c`.`status` = :publishedStatus', [':publishedStatus' => Content::STATUS_PUBLISHED]);
        $query->addGroupBy($this->safeTable(Tag::class).'.`id`');
        $query->addOrderBy('name');
        return $query;
    }

    public function contentTags(array $context): Query {
        $query = new Query(Tag::class);
        $query->setFields(['id' => 'id', 'name' => 'name', 'slug' => 'slug']);
        $query->addInnerJoin([ContentTag::class, 'ct'], '`ct`.`tag_id` = '.$this->safeTable(Tag::class).'.`id`');
        $query->addCondition('`ct`.`content_id` = :contentId', [':contentId' => $context['content_id'] ?? 0]);
        $query->addOrderBy('name');
        return $query;
    }

    public function contentCategories(array $context): Query {
        $query = new Query(Category::class);
        $query->setFields(['id' => 'id', 'name' => 'name', 'slug' => 'slug', 'parent_id' => 'parent_id']);
        $query->addInnerJoin([ContentCategory::class, 'cc'], '`cc`.`category_id` = '.$this->safeTable(Category::class).'.`id`');
        $query->addCondition('`cc`.`content_id` = :contentId', [':contentId' => $context['content_id'] ?? 0]);
        $query->addOrderBy('name');
        return $query;
    }

    public function contentByCategory(array $context): Query {
        $query = new Query(Content::class);
        $query->addInnerJoin([ContentCategory::class, 'cc'], '`cc`.`content_id` = '.$this->safeTable(Content::class).'.`id`');
        $query->addCondition('`cc`.`category_id` = :categoryId', [':categoryId' => $context['category_id'] ?? 0]);
        $this->onlyPublished($query);
        $query->addOrderBy('published_at', 'desc');
        return $query;
    }

    public function contentByTag(array $context): Query {
        $query = new Query(Content::class);
        $query->addInnerJoin([ContentTag::class, 'ct'], '`ct`.`content_id` = '.$this->safeTable(Content::class).'.`id`');
        $query->addCondition('`ct`.`tag_id` = :tagId', [':tagId' => $context['tag_id'] ?? 0]);
        $this->onlyPublished($query);
        $query->addOrderBy('published_at', 'desc');
        return $query;
    }

    // --- Media ---

    /**
     * Deleted items are hidden unless the caller asks for them
     */
    public function mediaList(array $context): Query {
        $query = new Query(Media::class);
        if (empty($context['with_deleted'])) {
            $query->addCondition('`deleted_at` is null');
        }
        if (!empty($context['category'])) {
            $query->addCondition('`category` = :category', [':category' => $context['category']]);
        }
        if (!empty($context['search'])) {
            $query->addCondition(
                '(`file_name` like :search or `title` like :search or `alt` like :search)',
                [':search' => '%'.$context['search'].'%']
            );
        }
        $this->applyListOptions($query, $context, ['created_at' => 'desc']);
        return $query;
    }

    /**
     * The attachments of a piece of content
     *
     * **Hidden ones are left out unless asked for.** An image inside the article body is
     * attached, but it is already on the page - listing it again under "Attachments" says the
     * same thing twice. Filtering here rather than in the templates means a theme gets it right
     * without knowing the rule exists.
     *
     * `with_hidden` is for the places that need the true picture rather than the published one:
     * the admin's "where is this used", and anything deciding whether a file is safe to remove.
     */
    public function contentAttachments(array $context): Query {
        $query = new Query(Media::class);
        $query->addInnerJoin([ContentAttachment::class, 'ca'], '`ca`.`media_id` = '.$this->safeTable(Media::class).'.`id`');
        $query->addCondition('`ca`.`content_id` = :contentId', [':contentId' => $context['content_id'] ?? 0]);
        $query->addCondition('`deleted_at` is null');
        if (empty($context['with_hidden'])) {
            $query->addCondition('`ca`.`hidden` = :notHidden', [':notHidden' => false]);
        }
        return $query;
    }

    /**
     * @param array $context type, status, search, author_id, parent_id, published_only
     */
    public function contentList(array $context): Query {
        $query = new Query(Content::class);
        $this->applyContentFilters($query, $context);
        $this->applyListOptions($query, $context, ['published_at' => 'desc', 'created_at' => 'desc']);
        return $query;
    }

    /**
     * `published_only` defaults to **true**: the filter has to be on unless the caller asks for
     * drafts, or a forgotten flag leaks unpublished work to visitors.
     */
    public function contentBySlug(array $context): Query {
        $query = new Query(Content::class);
        $query->addCondition('`slug` = :slug', [':slug' => $context['slug'] ?? '']);
        if ($context['published_only'] ?? true) {
            $this->onlyPublished($query);
        }
        return $query;
    }

    public function contentChildren(array $context): Query {
        $query = new Query(Content::class);
        $query->addCondition('`parent_id` = :parentId', [':parentId' => $context['parent_id'] ?? 0]);
        if ($context['published_only'] ?? false) {
            $this->onlyPublished($query);
        }
        $this->applyListOptions($query, $context, ['title' => 'asc']);
        return $query;
    }

    /**
     * Published posts grouped by month, for an archive listing
     */
    public function contentArchive(array $context): Query {
        $query = new Query(Content::class);
        $query->setFields([
            'period' => ["date_format(`published_at`, '%Y-%m')"],
            'total'  => ['count(1)'],
        ]);
        $query->addCondition('`type` = :type', [':type' => $context['type'] ?? Content::TYPE_POST]);
        $this->onlyPublished($query);
        $query->addGroupBy("date_format(`published_at`, '%Y-%m')");
        return $query;
    }

    protected function applyContentFilters(Query $query, array $context): void {
        if (!empty($context['type'])) {
            $query->addCondition('`type` = :type', [':type' => $context['type']]);
        }
        if (!empty($context['author_id'])) {
            $query->addCondition('`author_id` = :authorId', [':authorId' => $context['author_id']]);
        }
        if (array_key_exists('parent_id', $context)) {
            if ($context['parent_id'] === null) {
                $query->addCondition('`parent_id` is null');
            } else {
                $query->addCondition('`parent_id` = :parentId', [':parentId' => $context['parent_id']]);
            }
        }
        if (!empty($context['search'])) {
            $query->addCondition(
                '(`title` like :search or `markdown` like :search)',
                [':search' => '%'.$context['search'].'%']
            );
        }
        if (!empty($context['status'])) {
            $query->addCondition('`status` = :status', [':status' => $context['status']]);
        } else if ($context['published_only'] ?? false) {
            $this->onlyPublished($query);
        }
    }

    /**
     * Published, and not dated in the future
     */
    protected function onlyPublished(Query $query): void {
        $query->addCondition('`status` = :publishedStatus', [':publishedStatus' => Content::STATUS_PUBLISHED]);
        $query->addCondition('`published_at` <= :publishedNow', [':publishedNow' => gmdate('Y-m-d H:i:s')]);
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
        $this->applyListOptions($query, $context, ['name' => 'asc']);
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
        $this->applyListOptions($query, $context, ['name' => 'asc']);
        return $query;
    }

    public function rolePermissions(array $context): Query {
        $query = new Query(RolePermission::class);
        $query->setFields(['permission' => 'permission']);
        $query->addCondition('`role_id` = :roleId', [':roleId' => $context['role_id'] ?? 0]);
        return $query;
    }

    /**
     * Applies the ordering and the page a dynamic list asked for
     *
     * The name is checked against a pattern as well as against the whitelist `ListRequest`
     * already applied, because this is the point where it becomes SQL and a second caller may
     * build the context by hand. Without a requested order the builder's own default stands, so
     * a listing that nobody sorted still comes back in a sensible order.
     *
     * @param array $default In [field => direction] order
     */
    protected function applyListOptions(Query $query, array $context, array $default = []): void {
        $orderBy = (string)($context['order_by'] ?? '');
        if ($orderBy !== '' && preg_match('/^[a-z0-9_]+$/', $orderBy)) {
            $query->addOrderBy($orderBy, ($context['order_dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc');
        } else {
            foreach ($default as $field => $direction) {
                $query->addOrderBy($field, $direction);
            }
        }
        if (isset($context['max'])) {
            $query->setLimit((int)($context['offset'] ?? 0), (int)$context['max']);
        }
    }

    /**
     * The escaped table name of an entity, for a join condition
     *
     * Asked of the entity manager rather than written as a `#ClassName` token, so a
     * `#[Table(name: ...)]` override is honoured without relying on the substitution.
     */
    protected function safeTable(string $className): string {
        return $this->em->safeTableName($className);
    }

    /**
     * The unescaped table name, for a field that has to be qualified
     *
     * `Database::escapeName()` splits on the dot, so `dp_tag.id` becomes `` `dp_tag`.`id` ``.
     */
    protected function table(string $className): string {
        return $this->em->tableName($className);
    }
}
