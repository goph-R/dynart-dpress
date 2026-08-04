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
        $content->parent_id = $data['parent_id'] ?? null;
        $content->featured_media_id = $data['featured_media_id'] ?? null;
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
                $content->$field = $data[$field];
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
        $this->em->deleteById(Content::class, $content->id);
        $this->emitBoth($content, self::EVENT_DELETED, 'deleted');
    }

    // --- Helpers ---

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
