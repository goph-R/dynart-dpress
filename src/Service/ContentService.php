<?php

namespace Dynart\Dpress\Service;

use Dynart\Micro\EventServiceInterface;
use Dynart\Micro\Entities\Database;
use Dynart\Micro\Entities\EntityManager;
use Dynart\Micro\Entities\QueryExecutor;
use Dynart\Dpress\Content\MarkdownRenderer;
use Dynart\Dpress\Content\Slugger;
use Dynart\Dpress\DpressException;
use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Query\QueryFactory;

/**
 * Everything that reads or changes content
 *
 * Every state change emits a generic `content:*` event **and** a type specific one
 * (`post:created`, `page:created`), so a plugin can subscribe narrowly without inspecting the
 * type itself.
 */
class ContentService {

    const EVENT_BEFORE_CREATE = 'content:before_create';
    const EVENT_CREATED = 'content:created';
    const EVENT_BEFORE_UPDATE = 'content:before_update';
    const EVENT_UPDATED = 'content:updated';
    const EVENT_BEFORE_DELETE = 'content:before_delete';
    const EVENT_DELETED = 'content:deleted';
    const EVENT_PUBLISHED = 'content:published';
    const EVENT_UNPUBLISHED = 'content:unpublished';

    public function __construct(
        protected EntityManager $em,
        protected Database $db,
        protected QueryExecutor $queryExecutor,
        protected QueryFactory $queries,
        protected EventServiceInterface $events,
        protected MarkdownRenderer $markdown,
        protected Slugger $slugger,
        protected TaxonomyService $taxonomy,
        protected MediaService $media,
    ) {}

    // --- Reading ---

    public function findById(int $id): ?Content {
        $content = $this->em->findById(Content::class, $id);
        return $content instanceof Content ? $content : null;
    }

    public function findBySlug(string $slug, bool $publishedOnly = true): ?Content {
        $rows = $this->queryExecutor->findAll($this->queries->create('content_by_slug', [
            'slug' => $slug,
            'published_only' => $publishedOnly,
        ]));
        return empty($rows) ? null : $this->findById((int)$rows[0]['id']);
    }

    public function slugExists(string $slug): bool {
        $count = $this->db->fetchOne(
            'select count(1) from '.$this->em->safeTableName(Content::class).' where `slug` = :slug',
            [':slug' => $slug]
        );
        return (int)$count > 0;
    }

    /**
     * @return array The raw rows, for a listing
     */
    public function findAll(array $context = []): array {
        return $this->queryExecutor->findAll($this->queries->create('content_list', $context));
    }

    public function countAll(array $context = []): int {
        return (int)$this->queryExecutor->findAllCount($this->queries->create('content_list', $context));
    }

    public function findByTag(int $tagId): array {
        return $this->queryExecutor->findAll($this->queries->create('content_by_tag', ['tag_id' => $tagId]));
    }

    public function findByCategory(int $categoryId): array {
        return $this->queryExecutor->findAll($this->queries->create('content_by_category', ['category_id' => $categoryId]));
    }

    /**
     * The children of a page, for the tree
     */
    public function findChildren(int $parentId): array {
        return $this->queryExecutor->findAll($this->queries->create('content_children', ['parent_id' => $parentId]));
    }

    /**
     * Walks up the `parent_id` chain
     *
     * @return Content[] From the root down to the given page, the page itself last
     */
    public function ancestors(Content $content): array {
        $result = [];
        $seen = [$content->id => true];
        $current = $content;
        while ($current->parent_id !== null) {
            $parent = $this->findById($current->parent_id);
            // a cycle would be a corrupt tree, but it must not hang the request
            if ($parent === null || isset($seen[$parent->id])) {
                break;
            }
            $seen[$parent->id] = true;
            array_unshift($result, $parent);
            $current = $parent;
        }
        return $result;
    }

    /**
     * The canonical path of a page, built from its ancestor chain
     */
    public function path(Content $content): string {
        $parts = [];
        foreach ($this->ancestors($content) as $ancestor) {
            $parts[] = $ancestor->slug;
        }
        $parts[] = $content->slug;
        return '/'.join('/', $parts);
    }

    /**
     * Where this piece of content lives on the site
     *
     * Posts are chronological and live under `/post/`; pages are hierarchical and live at their
     * own path. That difference in *routing* is the whole reason the two share one table - it
     * belongs here rather than in a second entity.
     */
    public function publicPath(Content $content): string {
        return $content->isPage() ? $this->path($content) : '/post/'.$content->slug;
    }

    /**
     * Finds a page by its full path, and says whether that path was the canonical one
     *
     * The slug is globally unique, so the last segment identifies the page on its own and the
     * ancestors do not have to be walked to find it. They still have to be *checked*: without
     * that, `/anything/you/like/contact` would serve the contact page too, and the same content
     * answering at unlimited URLs is what search engines penalise.
     *
     * @return array [Content|null, bool] the page, and whether the given path was canonical
     */
    public function findByPath(string $path, bool $publishedOnly = true): array {
        $segments = array_values(array_filter(explode('/', trim($path, '/')), fn($s) => $s !== ''));
        if (empty($segments)) {
            return [null, true];
        }
        $content = $this->findBySlug(end($segments), $publishedOnly);
        if ($content === null || !$content->isPage()) {
            return [null, true];
        }
        return [$content, $this->path($content) === '/'.join('/', $segments)];
    }

    // --- Writing ---

    /**
     * @param array $data title, markdown, and optionally type, slug, status, parent_id,
     *                    featured_media_id, published_at
     */
    public function create(array $data, int $authorId): Content {
        $content = new Content();
        $content->type = $this->assertType($data['type'] ?? Content::TYPE_POST);
        $content->author_id = $authorId;
        $content->title = trim($data['title'] ?? '');
        $content->markdown = (string)($data['markdown'] ?? '');
        $content->parent_id = $this->nullableId($data['parent_id'] ?? null);
        $content->featured_media_id = $this->nullableId($data['featured_media_id'] ?? null);
        $content->slug = $this->resolveSlug($data['slug'] ?? '', $content->title);
        $content->created_at = $this->now();
        $content->updated_at = $content->created_at;
        $this->renderInto($content);
        $status = $this->assertStatus($data['status'] ?? Content::STATUS_DRAFT);
        $content->status = $status;
        if ($status === Content::STATUS_PUBLISHED) {
            $content->published_at = $data['published_at'] ?? $this->now();
        }
        $this->emitBoth($content, self::EVENT_BEFORE_CREATE, 'before_create');
        $this->em->save($content);
        $this->emitBoth($content, self::EVENT_CREATED, 'created');
        return $content;
    }

    /**
     * Applies changed fields and re-renders when the markdown moved
     */
    public function update(Content $content, array $data): Content {
        if (array_key_exists('title', $data)) {
            $content->title = trim($data['title']);
        }
        if (array_key_exists('slug', $data) && trim($data['slug']) !== '') {
            $slug = $this->slugger->slugify($data['slug']);
            if ($slug !== $content->slug) {
                $content->slug = $this->uniqueSlug($slug, $content->id);
            }
        }
        if (array_key_exists('markdown', $data)) {
            $content->markdown = (string)$data['markdown'];
            $this->renderInto($content);
        }
        foreach (['parent_id', 'featured_media_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $content->$field = $this->nullableId($data[$field]);
            }
        }
        if (array_key_exists('parent_id', $data)) {
            $this->assertNoCycle($content);
        }
        $content->updated_at = $this->now();
        $this->emitBoth($content, self::EVENT_BEFORE_UPDATE, 'before_update');
        $this->em->save($content);
        $this->emitBoth($content, self::EVENT_UPDATED, 'updated');
        return $content;
    }

    public function publish(Content $content, ?string $publishedAt = null): void {
        if ($content->isPublished()) {
            return;
        }
        $content->status = Content::STATUS_PUBLISHED;
        $content->published_at = $publishedAt ?? $this->now();
        $content->updated_at = $this->now();
        $this->em->save($content);
        $this->emitBoth($content, self::EVENT_PUBLISHED, 'published');
    }

    public function unpublish(Content $content): void {
        if (!$content->isPublished()) {
            return;
        }
        $content->status = Content::STATUS_DRAFT;
        $content->updated_at = $this->now();
        $this->em->save($content);
        $this->emitBoth($content, self::EVENT_UNPUBLISHED, 'unpublished');
    }

    /**
     * Deletes content, orphaning its children rather than taking them with it
     *
     * A cascade would delete a whole page subtree because somebody removed one page in the
     * middle, and it would happen inside the database where no event fires and nothing is
     * audited. The children are re-parented to this one's parent instead.
     *
     * The category, tag and attachment links go first, through their own services. They *have*
     * to go through something: the relation tables carry a foreign key and no `ON DELETE
     * CASCADE`, so the row cannot be removed while a link to it exists - and a cascade is exactly
     * what was not wanted, because it happens inside the database where no event fires and
     * "which categories did this post have when it was deleted" is lost.
     */
    public function delete(Content $content): void {
        $this->emitBoth($content, self::EVENT_BEFORE_DELETE, 'before_delete');
        foreach ($this->findChildren($content->id) as $row) {
            $child = $this->findById((int)$row['id']);
            if ($child !== null) {
                $child->parent_id = $content->parent_id;
                $child->updated_at = $this->now();
                $this->em->save($child);
            }
        }
        $this->taxonomy->clearAssignments($content->id);
        $this->media->detachAllOfContent($content->id);
        $this->em->deleteById(Content::class, $content->id);
        $this->emitBoth($content, self::EVENT_DELETED, 'deleted');
    }

    // --- Helpers ---

    /**
     * A nullable foreign key, from whatever the caller had
     *
     * Both of these columns are `?int`, and both are filled from a `<select>` or a hidden input
     * whose "nothing chosen" value is the empty string. Assigning that to a typed property is a
     * fatal, so it is coerced here rather than in each caller: this is the one place that knows
     * the column is an id, and a service that fatals on ordinary form input is a trap for the
     * next caller as much as it was for this one.
     *
     * `0` means nothing too - it is what `(int)''` gives, and there is no row with that id.
     */
    protected function nullableId(mixed $value): ?int {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        $id = (int)$value;
        return $id > 0 ? $id : null;
    }

    /**
     * Renders the markdown into the cached HTML columns
     *
     * The HTML is a cache of the markdown, so anything that changes rendering has to come back
     * through here rather than assign the columns itself.
     */
    public function renderInto(Content $content): void {
        $rendered = $this->markdown->renderSplit($content->markdown);
        $content->lead_html = $rendered['lead'];
        $content->body_html = $rendered['body'];
    }

    /**
     * Re-renders everything, for after a rendering change
     *
     * @return int How many were re-rendered
     */
    public function rerenderAll(): int {
        $ids = $this->db->fetchColumn('select `id` from '.$this->em->safeTableName(Content::class));
        $count = 0;
        foreach ($ids as $id) {
            $content = $this->findById((int)$id);
            if ($content === null) {
                continue;
            }
            $this->renderInto($content);
            $this->em->save($content);
            $count++;
        }
        return $count;
    }

    /**
     * Emits the generic event and the type specific alias
     *
     * `post:created` next to `content:created`, so a plugin that only cares about posts does not
     * have to inspect the type on every content event of the site.
     */
    protected function emitBoth(Content $content, string $genericEvent, string $suffix): void {
        $this->events->emit($genericEvent, [$content]);
        $this->events->emit($content->type.':'.$suffix, [$content]);
    }

    protected function resolveSlug(string $given, string $title): string {
        $wanted = trim($given) !== '' ? $given : $title;
        return $this->slugger->unique($wanted, fn(string $candidate) => $this->slugExists($candidate));
    }

    /**
     * Like `Slugger::unique()`, but a piece of content may keep its own slug
     */
    protected function uniqueSlug(string $slug, int $exceptId): string {
        return $this->slugger->unique($slug, function(string $candidate) use ($exceptId) {
            $count = $this->db->fetchOne(
                'select count(1) from '.$this->em->safeTableName(Content::class)
                    .' where `slug` = :slug and `id` <> :id',
                [':slug' => $candidate, ':id' => $exceptId]
            );
            return (int)$count > 0;
        });
    }

    protected function assertType(string $type): string {
        if (!in_array($type, Content::TYPES)) {
            throw new DpressException("Unknown content type '$type'.");
        }
        return $type;
    }

    protected function assertStatus(string $status): string {
        if (!in_array($status, Content::STATUSES)) {
            throw new DpressException("Unknown content status '$status'.");
        }
        return $status;
    }

    /**
     * A page cannot be its own ancestor, or rendering its path would never finish
     */
    protected function assertNoCycle(Content $content): void {
        $parentId = $content->parent_id;
        $seen = [$content->id => true];
        while ($parentId !== null) {
            if (isset($seen[$parentId])) {
                throw new DpressException('That would make the page its own ancestor.');
            }
            $seen[$parentId] = true;
            $parent = $this->findById($parentId);
            if ($parent === null) {
                return;
            }
            $parentId = $parent->parent_id;
        }
    }

    protected function now(): string {
        return gmdate('Y-m-d H:i:s');
    }
}
