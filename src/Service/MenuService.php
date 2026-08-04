<?php

namespace Dynart\Dpress\Service;

use Dynart\Micro\EventServiceInterface;
use Dynart\Micro\RouterInterface;
use Dynart\Micro\Entities\Database;
use Dynart\Micro\Entities\EntityManager;
use Dynart\Micro\Entities\QueryExecutor;
use Dynart\Dpress\DpressException;
use Dynart\Dpress\Entity\Category;
use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Entity\Menu;
use Dynart\Dpress\Entity\MenuItem;
use Dynart\Dpress\Entity\Tag;
use Dynart\Dpress\Query\QueryFactory;

/**
 * Menus, their items, and turning those items into links
 *
 * An item stores *what* it points at, not a URL, so renaming a page moves its menu entry with
 * it. Resolving to a URL therefore happens at render time, which is also when a target that has
 * been deleted is noticed and dropped.
 */
class MenuService {

    const EVENT_BEFORE_RENDER = 'menu:before_render';
    const EVENT_RENDERED = 'menu:rendered';
    const EVENT_MENU_SAVED = 'menu:saved';
    const EVENT_MENU_DELETED = 'menu:deleted';
    const EVENT_ITEM_SAVED = 'menu:item_saved';
    const EVENT_ITEM_DELETED = 'menu:item_deleted';

    public function __construct(
        protected EntityManager $em,
        protected Database $db,
        protected QueryExecutor $queryExecutor,
        protected QueryFactory $queries,
        protected EventServiceInterface $events,
        protected RouterInterface $router,
        protected ContentService $content,
        protected TaxonomyService $taxonomy,
    ) {}

    // --- Menus ---

    public function findMenu(int $id): ?Menu {
        $menu = $this->em->findById(Menu::class, $id);
        return $menu instanceof Menu ? $menu : null;
    }

    public function findMenuByPlace(string $place): ?Menu {
        $id = $this->db->fetchOne(
            'select `id` from '.$this->em->safeTableName(Menu::class).' where `place` = :place limit 1',
            [':place' => $place]
        );
        return $id === false || $id === null ? null : $this->findMenu((int)$id);
    }

    public function menus(): array {
        return $this->queryExecutor->findAll($this->queries->create('menu_list'));
    }

    public function createMenu(string $name, string $place = ''): Menu {
        $menu = new Menu();
        $menu->name = trim($name);
        $menu->place = $place;
        $this->em->save($menu);
        $this->events->emit(self::EVENT_MENU_SAVED, [$menu]);
        return $menu;
    }

    /**
     * Assigns a menu to a place, taking it away from whatever was there
     *
     * One menu per place: two menus rendering into the same slot is never what anybody wanted,
     * and silently rendering only the first is worse than moving the other one out.
     */
    public function setPlace(Menu $menu, string $place): void {
        if ($place !== '') {
            foreach ($this->menus() as $row) {
                if ((int)$row['id'] !== $menu->id && $row['place'] === $place) {
                    $other = $this->findMenu((int)$row['id']);
                    if ($other !== null) {
                        $other->place = '';
                        $this->em->save($other);
                    }
                }
            }
        }
        $menu->place = $place;
        $this->em->save($menu);
        $this->events->emit(self::EVENT_MENU_SAVED, [$menu]);
    }

    public function deleteMenu(Menu $menu): void {
        foreach ($this->itemRows($menu->id) as $row) {
            $item = $this->findItem((int)$row['id']);
            if ($item !== null) {
                $this->deleteItem($item);
            }
        }
        $this->em->deleteById(Menu::class, $menu->id);
        $this->events->emit(self::EVENT_MENU_DELETED, [$menu]);
    }

    // --- Items ---

    public function findItem(int $id): ?MenuItem {
        $item = $this->em->findById(MenuItem::class, $id);
        return $item instanceof MenuItem ? $item : null;
    }

    /**
     * @param array $data label, target_type, target_id, url, parent_id, position
     */
    public function addItem(Menu $menu, array $data): MenuItem {
        $item = new MenuItem();
        $item->menu_id = $menu->id;
        $this->fill($item, $data);
        $this->em->save($item);
        $this->events->emit(self::EVENT_ITEM_SAVED, [$item]);
        return $item;
    }

    public function updateItem(MenuItem $item, array $data): void {
        $this->fill($item, $data);
        $this->em->save($item);
        $this->events->emit(self::EVENT_ITEM_SAVED, [$item]);
    }

    /**
     * Deletes an item, lifting its children to its own parent
     */
    public function deleteItem(MenuItem $item): void {
        foreach ($this->childRows($item->id) as $row) {
            $child = $this->findItem((int)$row['id']);
            if ($child !== null) {
                $child->parent_id = $item->parent_id;
                $this->em->save($child);
            }
        }
        $this->em->deleteById(MenuItem::class, $item->id);
        $this->events->emit(self::EVENT_ITEM_DELETED, [$item]);
    }

    // --- Rendering ---

    /**
     * The tree of a menu, resolved to labels and URLs
     *
     * An item whose target has been deleted is **left out** rather than rendered as a dead link.
     * A menu is navigation: an entry that goes nowhere is worse than no entry.
     *
     * @return array Nested rows of ['label', 'url', 'children']
     */
    public function tree(string $place): array {
        $menu = $this->findMenuByPlace($place);
        if ($menu === null) {
            return [];
        }
        $this->events->emit(self::EVENT_BEFORE_RENDER, [$place, $menu]);
        $tree = $this->buildTree($this->itemRows($menu->id), null);
        $this->events->emit(self::EVENT_RENDERED, [$place, $tree]);
        return $tree;
    }

    protected function buildTree(array $rows, ?int $parentId): array {
        $result = [];
        foreach ($rows as $row) {
            $rowParent = $row['parent_id'] === null ? null : (int)$row['parent_id'];
            if ($rowParent !== $parentId) {
                continue;
            }
            $url = $this->resolveUrl($row);
            if ($url === null) {
                continue; // the target is gone
            }
            $result[] = [
                'id'       => (int)$row['id'],
                'label'    => (string)$row['label'],
                'url'      => $url,
                'external' => $row['target_type'] === MenuItem::TARGET_URL,
                'children' => $this->buildTree($rows, (int)$row['id']),
            ];
        }
        return $result;
    }

    /**
     * Works out where an item points, now
     *
     * @return string|null null when the target no longer exists
     */
    public function resolveUrl(array $row): ?string {
        $targetId = $row['target_id'] === null ? null : (int)$row['target_id'];
        switch ($row['target_type']) {
            case MenuItem::TARGET_URL:
                $url = trim((string)($row['url'] ?? ''));
                return $url !== '' ? $url : null;

            case MenuItem::TARGET_HOME:
                return $this->router->url('/');

            case MenuItem::TARGET_CONTENT:
                $content = $targetId === null ? null : $this->content->findById($targetId);
                if ($content === null || !$content->isPublished()) {
                    return null;
                }
                return $this->router->url($this->content->publicPath($content));

            case MenuItem::TARGET_CATEGORY:
                $category = $targetId === null ? null : $this->taxonomy->findCategory($targetId);
                return $category === null ? null : $this->router->url('/category/'.$category->slug);

            case MenuItem::TARGET_TAG:
                $tag = $targetId === null ? null : $this->taxonomy->findTag($targetId);
                return $tag === null ? null : $this->router->url('/tag/'.$tag->slug);
        }
        return null;
    }

    // --- Helpers ---

    protected function fill(MenuItem $item, array $data): void {
        if (array_key_exists('label', $data)) {
            $item->label = trim((string)$data['label']);
        }
        if (array_key_exists('target_type', $data)) {
            $type = (string)$data['target_type'];
            if (!in_array($type, MenuItem::TARGETS)) {
                throw new DpressException("Unknown menu target type '$type'.");
            }
            $item->target_type = $type;
        }
        foreach (['target_id', 'parent_id', 'url'] as $field) {
            if (array_key_exists($field, $data)) {
                $item->$field = $data[$field];
            }
        }
        if (array_key_exists('position', $data)) {
            $item->position = (int)$data['position'];
        }
    }

    protected function itemRows(int $menuId): array {
        return $this->queryExecutor->findAll($this->queries->create('menu_items', ['menu_id' => $menuId]));
    }

    protected function childRows(int $itemId): array {
        return $this->db->fetchAll(
            'select `id` from '.$this->em->safeTableName(MenuItem::class).' where `parent_id` = :id',
            [':id' => $itemId]
        );
    }
}
