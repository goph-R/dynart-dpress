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
use Dynart\Dpress\Entity\Setting;
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

    /**
     * The date moved on something already published, which is not a fresh publication
     *
     * A separate event because `content:published` means "this just went out" - a listener that
     * mails, pings a feed or warms a cache would do it all again for a corrected date, and
     * correcting the date is what importing an old post is.
     */
    const EVENT_RESCHEDULED = 'content:rescheduled';


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
        protected SettingService $settings,
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
     * @param array $options `max` and `offset`, and `order_by` / `order_dir`, like any listing
     */
    public function findByTag(int $tagId, array $options = []): array {
        return $this->queryExecutor->findAll(
            $this->queries->create('content_by_tag', $options + ['tag_id' => $tagId])
        );
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
     * Pages are hierarchical and live at their own path. Posts are chronological, and where
     * they live is the `post_path` setting: under `/post/`, or at the root beside the pages.
     * That difference in *routing* is the whole reason the two share one table - it belongs
     * here rather than in a second entity.
     */
    public function publicPath(Content $content): string {
        if ($content->isPage()) {
            return $this->path($content);
        }
        return $this->postPath($content->slug);
    }

    /**
     * Where a post lives, from its slug alone
     *
     * A listing has rows and not entities, and a post needs nothing but its slug to be found -
     * unlike a page, whose path is its ancestors as well.
     */
    public function postPath(string $slug): string {
        return $this->postsAtRoot() ? '/'.$slug : '/post/'.$slug;
    }

    /**
     * Whether a post lives at `/<slug>` rather than under `/post/`
     *
     * Anything the setting does not name is the prefixed shape, which is the one that was
     * always there: a typo in a setting must not put every post on the site at an address
     * nothing answers.
     */
    public function postsAtRoot(): bool {
        return $this->settings->get(Setting::POST_PATH, Setting::POST_PATH_PREFIXED)
            === Setting::POST_PATH_ROOT;
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
        // A post answers here only when it lives at the root. The slug is unique across both
        // types, so there is nothing to disambiguate - the question is only whether this is
        // where the post is supposed to be.
        if ($content === null || (!$content->isPage() && !$this->postsAtRoot())) {
            return [null, true];
        }
        return [$content, $this->publicPath($content) === '/'.join('/', $segments)];
    }

    // --- Writing ---

    /**
     * @param array $data title, markdown, and optionally type, slug, status, parent_id,
     *                    featured_media_id, published_at
     */
    /**
     * The row the editor is opened against, made if it is not there already
     *
     * **An editor with no id cannot do anything immediate**, and attaching a file is immediate -
     * the same as every other row action in the admin. The old answer was to ask for a save
     * first and come back, which is a strange thing to ask of somebody who has not written the
     * post yet. So "New" writes a row, and from then on there is no such thing as an unsaved
     * post: one editor path instead of two, and the next feature that wants an id has one.
     *
     * **The author's existing auto-draft is reused.** Clicking New five times is one row, not
     * five, and anything attached before wandering off is still attached on the way back. That
     * also bounds the table at one row per author per type, which is what makes pruning a tidy-up
     * rather than a necessity.
     *
     * The two-tab case is the honest cost: open New twice, save the first, and the second tab is
     * now editing the post the first one made. Nothing is lost - every save is a revision - but
     * it is a surprise, and the same surprise as two tabs on one post, which this CMS has never
     * guarded against either.
     */
    public function startDraft(string $type, int $authorId): Content {
        $this->assertType($type);
        $rows = $this->queryExecutor->findAll(
            $this->queries->create('content_auto_draft', ['type' => $type, 'author_id' => $authorId])
        );
        if (!empty($rows) && ($existing = $this->findById((int)$rows[0]['id'])) !== null) {
            return $existing;
        }
        $content = new Content();
        $content->type = $type;
        $content->author_id = $authorId;
        $content->status = Content::STATUS_AUTO_DRAFT;
        // Unique and not null, and there is no title to make one from yet. Random rather than
        // `auto-draft-1`, so it can never be what somebody's real slug wanted to be. The first
        // save replaces it from the title.
        $content->slug = 'auto-draft-'.bin2hex(random_bytes(8));
        $content->created_at = $this->now();
        $content->updated_at = $content->created_at;
        $this->emitBoth($content, self::EVENT_BEFORE_CREATE, 'before_create');
        $this->em->save($content);
        $this->emitBoth($content, self::EVENT_CREATED, 'created');
        return $content;
    }

    /**
     * Throws away the auto-drafts nobody came back to
     *
     * A tidy-up rather than a necessity, because reuse already caps these at one per author per
     * type. Goes through `delete()` so an abandoned draft takes its attachments with it and the
     * removal is audited like any other.
     *
     * @param string $before Anything created before this timestamp
     * @return int How many went
     */
    public function pruneAutoDrafts(string $before): int {
        $ids = $this->db->fetchColumn(
            'select `id` from '.$this->em->safeTableName(Content::class)
                .' where `status` = :status and `created_at` < :before',
            [':status' => Content::STATUS_AUTO_DRAFT, ':before' => $before]
        );
        $count = 0;
        foreach ($ids as $id) {
            $content = $this->findById((int)$id);
            if ($content !== null) {
                $this->delete($content);
                $count++;
            }
        }
        return $count;
    }

    public function create(array $data, int $authorId): Content {
        $content = new Content();
        $content->type = $this->assertType($data['type'] ?? Content::TYPE_POST);
        $content->author_id = $authorId;
        $content->title = trim($data['title'] ?? '');
        $content->markdown = (string)($data['markdown'] ?? '');
        $content->parent_id = $this->nullableId($data['parent_id'] ?? null);
        $content->featured_media_id = $this->nullableId($data['featured_media_id'] ?? null);
        $content->weight = (int)($data['weight'] ?? 0);
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
        // what this content's own URL is made of, before anything touches it
        $wasAt = [$content->slug, $content->parent_id];
        // The first save of a row `startDraft()` made. It stops being scaffolding and becomes a
        // draft here rather than in `applyStatus()`, because this is not a publishing decision -
        // nothing about it is the author's to choose, and `assertStatus()` would refuse the value
        // anyway. Read before the title is written, since the slug below needs to know.
        $wasAutoDraft = $content->isAutoDraft();
        if ($wasAutoDraft) {
            $content->status = Content::STATUS_DRAFT;
        }
        if (array_key_exists('title', $data)) {
            $content->title = trim($data['title']);
        }
        if (array_key_exists('slug', $data) && trim($data['slug']) !== '') {
            $slug = $this->slugger->slugify($data['slug']);
            if ($slug !== $content->slug) {
                $content->slug = $this->uniqueSlug($slug, $content->id);
            }
        } else if ($wasAutoDraft) {
            // "left empty it is made from the title", which on every other save means "leave the
            // one it has" - but the one it has is `auto-draft-3f9c...`, which is not a name
            // anybody chose. So the first save resolves it the way `create()` would.
            $content->slug = $this->uniqueSlug($content->title, $content->id);
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
        if (array_key_exists('weight', $data)) {
            $content->weight = (int)$data['weight'];
        }
        if (array_key_exists('parent_id', $data)) {
            $this->assertNoCycle($content);
        }
        $content->updated_at = $this->now();
        $this->emitBoth($content, self::EVENT_BEFORE_UPDATE, 'before_update');
        $this->em->save($content);
        if ($wasAt !== [$content->slug, $content->parent_id]) {
            $this->rerenderReferrers($content);
        }
        $this->emitBoth($content, self::EVENT_UPDATED, 'updated');
        return $content;
    }

    /**
     * Re-renders whatever links here, after this moved
     *
     * An internal reference is resolved when the markdown is rendered, so the URL sits in
     * somebody else's `body_html` from the last time *they* were saved. Renaming a post leaves
     * every link to it pointing at the old address until something renders them again, and
     * "everything silently broke and no message said so" is not a thing to leave lying around.
     *
     * A page moves more than itself: its slug and its parent are both parts of the path, and
     * every page beneath it wears that path as a prefix.
     */
    public function rerenderReferrers(Content $content): void {
        $moved = [$content->id];
        if ($content->isPage()) {
            $moved = array_merge($moved, $this->descendantIds($content->id));
        }
        foreach ($this->referrerIds($moved) as $id) {
            if ($id === $content->id) {
                continue; // it was rendered a moment ago, with its new slug already in place
            }
            $referrer = $this->findById($id);
            if ($referrer === null) {
                continue;
            }
            $this->renderInto($referrer);
            $this->em->save($referrer);
        }
    }

    /**
     * Everything whose markdown might mention one of these ids
     *
     * A **candidate** list, deliberately: `like '%post#42%'` also matches `post#421`, and no
     * amount of SQL is going to parse markdown. Re-rendering something that did not need it
     * costs a render and produces the same bytes, so the loose end is the cheap one to leave.
     *
     * @return int[]
     */
    protected function referrerIds(array $ids): array {
        $conditions = [];
        $params = [];
        foreach ($ids as $index => $id) {
            foreach (['content', 'post', 'page'] as $kind) {
                $name = ':ref'.$index.$kind;
                $conditions[] = '`markdown` like '.$name;
                $params[$name] = '%'.$kind.'#'.$id.'%';
            }
        }
        if (!$conditions) {
            return [];
        }
        $found = $this->db->fetchColumn(
            'select `id` from '.$this->em->safeTableName(Content::class).' where '.join(' or ', $conditions),
            $params
        );
        return array_map('intval', $found);
    }

    /**
     * The ids of everything under a page, however deep
     *
     * By level rather than by recursion into a query per row: the pages of a site are a small
     * set, and a tree three deep should not be three hundred queries.
     *
     * @return int[]
     */
    protected function descendantIds(int $parentId): array {
        $found = [];
        $level = [$parentId];
        while ($level) {
            $children = [];
            foreach ($level as $id) {
                foreach ($this->findChildren($id) as $child) {
                    $childId = (int)$child['id'];
                    if (in_array($childId, $found, true)) {
                        continue; // a cycle cannot be saved, but it must not hang this either
                    }
                    $found[] = $childId;
                    $children[] = $childId;
                }
            }
            $level = $children;
        }
        return $found;
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

    /**
     * Moves the moment a published post says it went out
     *
     * What a migration needs: a post written in 2014 and brought over today is published now and
     * dated then, and the archive, the ordering and the byline all read off `published_at`.
     *
     * A publishing decision rather than an edit, so it sits here beside `publish()` rather than
     * being another field `update()` writes - **the date is what decides whether a published post
     * is visible at all**, since the public queries ask for `published_at <= now`. Dating one
     * forward hides it until then, which is scheduling, and is the same act as unpublishing it.
     *
     * @param string $publishedAt a stored UTC timestamp, as `Dates::parse()` returns
     */
    public function setPublishedAt(Content $content, string $publishedAt): void {
        if (!$content->isPublished() || $content->published_at === $publishedAt) {
            return;
        }
        $content->published_at = $publishedAt;
        $content->updated_at = $this->now();
        $this->em->save($content);
        $this->emitBoth($content, self::EVENT_RESCHEDULED, 'rescheduled');
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
