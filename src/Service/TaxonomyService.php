<?php

namespace Dynart\Dpress\Service;

use Dynart\Micro\EventServiceInterface;
use Dynart\Micro\Entities\Database;
use Dynart\Micro\Entities\EntityManager;
use Dynart\Micro\Entities\QueryExecutor;
use Dynart\Dpress\Content\Slugger;
use Dynart\Dpress\Content\TreeOrder;
use Dynart\Dpress\DpressException;
use Dynart\Dpress\Entity\Category;
use Dynart\Dpress\Entity\ContentCategory;
use Dynart\Dpress\Entity\ContentTag;
use Dynart\Dpress\Entity\Tag;
use Dynart\Dpress\Query\QueryFactory;

/**
 * Categories and tags, and what content belongs to them
 *
 * The two join tables are audited, so every assignment and removal goes through here rather
 * than through a database cascade - a cascade fires no event and records nothing.
 */
class TaxonomyService {

    const EVENT_CATEGORY_CREATED = 'category:created';
    const EVENT_CATEGORY_UPDATED = 'category:updated';
    const EVENT_CATEGORY_DELETED = 'category:deleted';
    const EVENT_TAG_CREATED = 'tag:created';
    const EVENT_TAG_UPDATED = 'tag:updated';
    const EVENT_TAG_DELETED = 'tag:deleted';
    const EVENT_CONTENT_CATEGORISED = 'content:categorised';
    const EVENT_CONTENT_UNCATEGORISED = 'content:uncategorised';
    const EVENT_CONTENT_TAGGED = 'content:tagged';
    const EVENT_CONTENT_UNTAGGED = 'content:untagged';

    public function __construct(
        protected EntityManager $em,
        protected Database $db,
        protected QueryExecutor $queryExecutor,
        protected QueryFactory $queries,
        protected EventServiceInterface $events,
        protected Slugger $slugger,
        protected TreeOrder $tree,
    ) {}

    // --- Categories ---

    public function findCategory(int $id): ?Category {
        $category = $this->em->findById(Category::class, $id);
        return $category instanceof Category ? $category : null;
    }

    public function findCategoryBySlug(string $slug): ?Category {
        return $this->findBySlug(Category::class, $slug, fn(int $id) => $this->findCategory($id));
    }

    public function categories(array $context = []): array {
        return $this->queryExecutor->findAll($this->queries->create('category_list', $context));
    }

    /**
     * How many there are before the page is applied, which is what a pager needs
     */
    public function countCategories(array $context = []): int {
        return (int)$this->queryExecutor->findAllCount($this->queries->create('category_list', $context));
    }

    public function createCategory(string $name, array $data = []): Category {
        $category = new Category();
        $category->name = trim($name);
        $category->slug = $this->uniqueSlug(Category::class, $data['slug'] ?? $name, 0);
        $category->parent_id = $data['parent_id'] ?? null;
        $category->description = $data['description'] ?? null;
        $category->thumbnail_media_id = $data['thumbnail_media_id'] ?? null;
        $category->position = (int)($data['position'] ?? 0);
        $this->assertNoCategoryCycle($category);
        $this->em->save($category);
        $this->events->emit(self::EVENT_CATEGORY_CREATED, [$category]);
        return $category;
    }

    public function updateCategory(Category $category, array $data): void {
        if (array_key_exists('name', $data)) {
            $category->name = trim($data['name']);
        }
        if (array_key_exists('slug', $data) && trim($data['slug']) !== '') {
            $category->slug = $this->uniqueSlug(Category::class, $data['slug'], $category->id);
        }
        foreach (['parent_id', 'description', 'thumbnail_media_id', 'position'] as $field) {
            if (array_key_exists($field, $data)) {
                $category->$field = $data[$field];
            }
        }
        $this->assertNoCategoryCycle($category);
        $this->em->save($category);
        $this->events->emit(self::EVENT_CATEGORY_UPDATED, [$category]);
    }

    /**
     * Moves a category under a parent, at a position among that parent's children
     *
     * What a drag on the categories screen ends up calling. Unlike a menu there is no scope: the
     * categories of a site are one tree.
     *
     * @throws DpressException if the parent is inside the category itself
     */
    public function moveCategory(Category $category, ?int $parentId, int $position): void {
        $this->tree->move(Category::class, $category->id, $parentId, $position);
        $this->events->emit(self::EVENT_CATEGORY_UPDATED, [$category]);
    }

    /**
     * Deletes a category, orphaning its children and removing its assignments
     *
     * Children are re-parented rather than cascaded away, for the same reason a page's children
     * are: nobody deleting one category means to delete the subtree under it.
     */
    public function deleteCategory(Category $category): void {
        foreach ($this->childCategoryIds($category->id) as $childId) {
            $child = $this->findCategory($childId);
            if ($child !== null) {
                $child->parent_id = $category->parent_id;
                $this->em->save($child);
            }
        }
        foreach ($this->contentIdsInCategory($category->id) as $contentId) {
            $this->removeFromCategory($contentId, $category->id);
        }
        $this->em->deleteById(Category::class, $category->id);
        $this->events->emit(self::EVENT_CATEGORY_DELETED, [$category]);
    }

    /**
     * The public path of a category, for `Router::url()` to finish
     *
     * A path rather than a URL, like `ContentService::publicPath()`, because only the router
     * knows how this installation writes them - with rewriting off it is a query parameter, and
     * anything joining the pieces itself would produce a link that goes nowhere.
     */
    public function categoryPath(Category $category): string {
        return '/category/'.$category->slug;
    }

    // --- Tags ---

    public function findTag(int $id): ?Tag {
        $tag = $this->em->findById(Tag::class, $id);
        return $tag instanceof Tag ? $tag : null;
    }

    public function findTagBySlug(string $slug): ?Tag {
        return $this->findBySlug(Tag::class, $slug, fn(int $id) => $this->findTag($id));
    }

    public function tags(array $context = []): array {
        return $this->queryExecutor->findAll($this->queries->create('tag_list', $context));
    }

    public function countTags(array $context = []): int {
        return (int)$this->queryExecutor->findAllCount($this->queries->create('tag_list', $context));
    }

    /**
     * Tags with a count of how many published items use them
     */
    public function tagCloud(): array {
        return $this->queryExecutor->findAll($this->queries->create('tag_cloud'));
    }

    public function createTag(string $name, string $slug = ''): Tag {
        $tag = new Tag();
        $tag->name = trim($name);
        $tag->slug = $this->uniqueSlug(Tag::class, $slug !== '' ? $slug : $name, 0);
        $this->em->save($tag);
        $this->events->emit(self::EVENT_TAG_CREATED, [$tag]);
        return $tag;
    }

    /**
     * Finds a tag by name, or makes it
     *
     * What a tag input needs: an editor types words, not identifiers.
     */
    public function findOrCreateTag(string $name): Tag {
        $slug = $this->slugger->slugify($name);
        $existing = $this->findTagBySlug($slug);
        return $existing ?? $this->createTag($name, $slug);
    }

    public function updateTag(Tag $tag, array $data): void {
        if (array_key_exists('name', $data)) {
            $tag->name = trim($data['name']);
        }
        if (array_key_exists('slug', $data) && trim($data['slug']) !== '') {
            $tag->slug = $this->uniqueSlug(Tag::class, $data['slug'], $tag->id);
        }
        $this->em->save($tag);
        $this->events->emit(self::EVENT_TAG_UPDATED, [$tag]);
    }

    public function deleteTag(Tag $tag): void {
        foreach ($this->contentIdsWithTag($tag->id) as $contentId) {
            $this->untag($contentId, $tag->id);
        }
        $this->em->deleteById(Tag::class, $tag->id);
        $this->events->emit(self::EVENT_TAG_DELETED, [$tag]);
    }

    /** @see categoryPath() */
    public function tagPath(Tag $tag): string {
        return '/tag/'.$tag->slug;
    }

    // --- Assignments ---

    public function addToCategory(int $contentId, int $categoryId): void {
        if ($this->isInCategory($contentId, $categoryId)) {
            return;
        }
        $link = new ContentCategory();
        $link->content_id = $contentId;
        $link->category_id = $categoryId;
        $this->em->save($link);
        $this->events->emit(self::EVENT_CONTENT_CATEGORISED, [$contentId, $categoryId]);
    }

    public function removeFromCategory(int $contentId, int $categoryId): void {
        if (!$this->isInCategory($contentId, $categoryId)) {
            return;
        }
        $link = new ContentCategory();
        $link->content_id = $contentId;
        $link->category_id = $categoryId;
        $link->setNew(false);
        $this->deleteLink(ContentCategory::class, $link, [
            'content_id' => $contentId, 'category_id' => $categoryId,
        ]);
        $this->events->emit(self::EVENT_CONTENT_UNCATEGORISED, [$contentId, $categoryId]);
    }

    public function tag(int $contentId, int $tagId): void {
        if ($this->hasTag($contentId, $tagId)) {
            return;
        }
        $link = new ContentTag();
        $link->content_id = $contentId;
        $link->tag_id = $tagId;
        $this->em->save($link);
        $this->events->emit(self::EVENT_CONTENT_TAGGED, [$contentId, $tagId]);
    }

    public function untag(int $contentId, int $tagId): void {
        if (!$this->hasTag($contentId, $tagId)) {
            return;
        }
        $link = new ContentTag();
        $link->content_id = $contentId;
        $link->tag_id = $tagId;
        $link->setNew(false);
        $this->deleteLink(ContentTag::class, $link, ['content_id' => $contentId, 'tag_id' => $tagId]);
        $this->events->emit(self::EVENT_CONTENT_UNTAGGED, [$contentId, $tagId]);
    }

    /**
     * Replaces the whole tag set of a piece of content, one event per actual change
     *
     * @param string[] $names Tag names as typed; unknown ones are created
     */
    public function setTags(int $contentId, array $names): void {
        $wanted = [];
        foreach ($names as $name) {
            $name = trim($name);
            if ($name !== '') {
                $wanted[] = $this->findOrCreateTag($name)->id;
            }
        }
        $current = $this->tagIdsOf($contentId);
        foreach (array_diff($wanted, $current) as $tagId) {
            $this->tag($contentId, $tagId);
        }
        foreach (array_diff($current, $wanted) as $tagId) {
            $this->untag($contentId, $tagId);
        }
    }

    /**
     * @param int[] $categoryIds
     */
    public function setCategories(int $contentId, array $categoryIds): void {
        $categoryIds = array_map('intval', $categoryIds);
        $current = $this->categoryIdsOf($contentId);
        foreach (array_diff($categoryIds, $current) as $categoryId) {
            $this->addToCategory($contentId, $categoryId);
        }
        foreach (array_diff($current, $categoryIds) as $categoryId) {
            $this->removeFromCategory($contentId, $categoryId);
        }
    }

    /**
     * Removes every assignment of a piece of content, for when it is deleted
     */
    public function clearAssignments(int $contentId): void {
        foreach ($this->tagIdsOf($contentId) as $tagId) {
            $this->untag($contentId, $tagId);
        }
        foreach ($this->categoryIdsOf($contentId) as $categoryId) {
            $this->removeFromCategory($contentId, $categoryId);
        }
    }

    // --- Reading assignments ---

    public function tagsOf(int $contentId): array {
        return $this->queryExecutor->findAll($this->queries->create('content_tags', ['content_id' => $contentId]));
    }

    public function categoriesOf(int $contentId): array {
        return $this->queryExecutor->findAll($this->queries->create('content_categories', ['content_id' => $contentId]));
    }

    /**
     * @return int[]
     */
    public function tagIdsOf(int $contentId): array {
        return array_map('intval', $this->db->fetchColumn(
            'select `tag_id` from '.$this->em->safeTableName(ContentTag::class).' where `content_id` = :id',
            [':id' => $contentId]
        ));
    }

    /**
     * @return int[]
     */
    public function categoryIdsOf(int $contentId): array {
        return array_map('intval', $this->db->fetchColumn(
            'select `category_id` from '.$this->em->safeTableName(ContentCategory::class).' where `content_id` = :id',
            [':id' => $contentId]
        ));
    }

    public function isInCategory(int $contentId, int $categoryId): bool {
        return (int)$this->db->fetchOne(
            'select count(1) from '.$this->em->safeTableName(ContentCategory::class)
                .' where `content_id` = :contentId and `category_id` = :categoryId',
            [':contentId' => $contentId, ':categoryId' => $categoryId]
        ) > 0;
    }

    public function hasTag(int $contentId, int $tagId): bool {
        return (int)$this->db->fetchOne(
            'select count(1) from '.$this->em->safeTableName(ContentTag::class)
                .' where `content_id` = :contentId and `tag_id` = :tagId',
            [':contentId' => $contentId, ':tagId' => $tagId]
        ) > 0;
    }

    // --- Helpers ---

    protected function deleteLink(string $className, object $link, array $keys): void {
        $this->events->emit($className::event($className::EVENT_BEFORE_DELETE), [$link]);
        $conditions = [];
        $params = [];
        foreach ($keys as $column => $value) {
            $conditions[] = '`'.$column.'` = :'.$column;
            $params[':'.$column] = $value;
        }
        $this->db->query(
            'delete from '.$this->em->safeTableName($className).' where '.join(' and ', $conditions),
            $params,
            true
        );
        $this->events->emit($className::event($className::EVENT_AFTER_DELETE), [$link]);
    }

    protected function findBySlug(string $className, string $slug, callable $loader): ?object {
        $id = $this->db->fetchOne(
            'select `id` from '.$this->em->safeTableName($className).' where `slug` = :slug',
            [':slug' => $slug]
        );
        return $id === false || $id === null ? null : $loader((int)$id);
    }

    protected function uniqueSlug(string $className, string $text, int $exceptId): string {
        return $this->slugger->unique($text, function(string $candidate) use ($className, $exceptId) {
            return (int)$this->db->fetchOne(
                'select count(1) from '.$this->em->safeTableName($className)
                    .' where `slug` = :slug and `id` <> :id',
                [':slug' => $candidate, ':id' => $exceptId]
            ) > 0;
        });
    }

    /**
     * @return int[]
     */
    protected function childCategoryIds(int $parentId): array {
        return array_map('intval', $this->db->fetchColumn(
            'select `id` from '.$this->em->safeTableName(Category::class).' where `parent_id` = :id',
            [':id' => $parentId]
        ));
    }

    /**
     * @return int[]
     */
    protected function contentIdsInCategory(int $categoryId): array {
        return array_map('intval', $this->db->fetchColumn(
            'select `content_id` from '.$this->em->safeTableName(ContentCategory::class).' where `category_id` = :id',
            [':id' => $categoryId]
        ));
    }

    /**
     * @return int[]
     */
    protected function contentIdsWithTag(int $tagId): array {
        return array_map('intval', $this->db->fetchColumn(
            'select `content_id` from '.$this->em->safeTableName(ContentTag::class).' where `tag_id` = :id',
            [':id' => $tagId]
        ));
    }

    /**
     * A category cannot be its own ancestor, or walking the tree would never finish
     */
    protected function assertNoCategoryCycle(Category $category): void {
        $parentId = $category->parent_id;
        $seen = $category->id > 0 ? [$category->id => true] : [];
        while ($parentId !== null) {
            if (isset($seen[$parentId])) {
                throw new DpressException('That would make the category its own ancestor.');
            }
            $seen[$parentId] = true;
            $parent = $this->findCategory($parentId);
            if ($parent === null) {
                return;
            }
            $parentId = $parent->parent_id;
        }
    }
}
