<?php

namespace Dynart\Dpress\Service;

use Dynart\Micro\EventServiceInterface;
use Dynart\Micro\Entities\Database;
use Dynart\Micro\Entities\EntityManager;
use Dynart\Micro\Entities\QueryExecutor;
use Dynart\Dpress\Block\Blocks;
use Dynart\Dpress\Content\TreeOrder;
use Dynart\Dpress\Entity\Block;
use Dynart\Dpress\Query\QueryFactory;

/**
 * The blocks a site has, and what order they are in
 *
 * A place holds many blocks in an order, where a place holds one menu - which is the only way the
 * two differ. Everything else is deliberately the same: the same `places[]` a theme declares, the
 * same "in a place the theme does not render, it is invisible rather than broken", and the same
 * drag handle to reorder, through `TreeOrder` on a tree that happens to be flat.
 *
 * **A block's settings go through its type on the way in.** `Blocks::prepare()` is where the
 * markdown block turns what somebody typed into stored HTML, so a page view never parses markdown
 * - the content rule, one level down. Nothing else in here knows that markdown exists.
 */
class BlockService {

    const EVENT_SAVED = 'block:saved';
    const EVENT_DELETED = 'block:deleted';
    const EVENT_BEFORE_RENDER = 'block:before_render';

    public function __construct(
        protected EntityManager $em,
        protected Database $db,
        protected QueryExecutor $queryExecutor,
        protected QueryFactory $queries,
        protected EventServiceInterface $events,
        protected Blocks $blocks,
        protected TreeOrder $tree,
    ) {}

    public function find(int $id): ?Block {
        $block = $this->em->findById(Block::class, $id);
        return $block instanceof Block ? $block : null;
    }

    /**
     * Every block there is, in place and position order
     *
     * @return array[] rows, for the admin screen
     */
    public function all(): array {
        return $this->queryExecutor->findAll($this->queries->create('block_list'));
    }

    /**
     * What renders in one place, in order, without the disabled ones
     *
     * **Two queries whatever is in the place**, not one per block: the ids in order, then the rows
     * in one `in (…)`. A sidebar is on every page of the site, so the cost of drawing it is a
     * number worth keeping small on purpose.
     *
     * The order comes from the id query rather than from the second one, which answers in whatever
     * order the database felt like.
     *
     * @return Block[]
     */
    public function inPlace(string $place): array {
        if ($place === '') {
            return [];
        }
        $ids = array_map('intval', $this->queryExecutor->findAllColumn(
            $this->queries->create('block_list', ['place' => $place, 'enabled' => true]), 'id'
        ));
        $byId = [];
        foreach ($this->em->findByIds(Block::class, $ids) as $block) {
            $byId[(int)$block->id] = $block;
        }
        $blocks = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $blocks[] = $byId[$id];
            }
        }
        return $blocks;
    }

    public function create(string $type, array $data): Block {
        $block = new Block();
        $block->type = $type;
        // at the end of whatever place it was put in, which is where somebody who just made a
        // thing expects to find it
        $block->position = count($this->inPlaceIds((string)($data['place'] ?? '')));
        $this->apply($block, $data);
        return $block;
    }

    public function update(Block $block, array $data): void {
        $movedFrom = array_key_exists('place', $data) && (string)$data['place'] !== $block->place
            ? $block->place : null;
        if ($movedFrom !== null) {
            $block->position = count($this->inPlaceIds((string)$data['place']));
        }
        $this->apply($block, $data);
        if ($movedFrom !== null) {
            // the place it left now has a gap in its numbering
            $this->tree->renumberFlat(Block::class, $this->inPlaceIds($movedFrom));
        }
    }

    /**
     * Writes the common fields, and the type's own settings through the type
     *
     * `settings` arrives as whatever the editor collected for the fields this type declared;
     * anything else is dropped, so a field a plugin removed does not live on in the row forever.
     */
    protected function apply(Block $block, array $data): void {
        if (array_key_exists('title', $data)) {
            $block->title = trim((string)$data['title']);
        }
        if (array_key_exists('place', $data)) {
            $block->place = (string)$data['place'];
        }
        if (array_key_exists('enabled', $data)) {
            $block->enabled = (bool)$data['enabled'];
        }
        if (array_key_exists('settings', $data)) {
            $block->setSettings($this->blocks->prepare($block->type, (array)$data['settings']));
        }
        $this->em->save($block);
        $this->events->emit(self::EVENT_SAVED, [$block]);
    }

    public function delete(Block $block): void {
        $place = $block->place;
        $this->em->deleteById(Block::class, $block->id);
        $this->events->emit(self::EVENT_DELETED, [$block]);
        $this->tree->renumberFlat(Block::class, $this->inPlaceIds($place));
    }

    /**
     * Drops a block at an index among the others in its place
     *
     * Flat rather than nested: a sidebar is a list. It still goes through `TreeOrder` because the
     * renumbering rule - `0, 1, 2, …` written over the whole row, never a nudge - is the part that
     * matters, and having it twice is having two of them drift.
     */
    public function move(Block $block, int $position): void {
        $this->tree->moveFlat(Block::class, $block->id, $position, ['place' => $block->place]);
    }

    /**
     * Re-renders every block whose type caches something at save time
     *
     * Called by `dpress content:rerender`, because a block holding `media#14` has exactly the
     * problem a post holding `media#14` has when the site moves: the stored HTML points at where
     * that file used to be.
     *
     * @return int how many were rewritten
     */
    public function rerenderAll(): int {
        $count = 0;
        foreach ($this->all() as $row) {
            $block = $this->find((int)$row['id']);
            if ($block === null) {
                continue;
            }
            $settings = $this->blocks->prepare($block->type, $block->settings());
            if ($settings === $block->settings()) {
                continue;
            }
            $block->setSettings($settings);
            $this->em->save($block);
            $count++;
        }
        return $count;
    }

    /**
     * The ids in a place, in order
     *
     * @return int[]
     */
    protected function inPlaceIds(string $place): array {
        if ($place === '') {
            return [];
        }
        $rows = $this->db->fetchColumn(
            'select `id` from '.$this->em->safeTableName(Block::class)
                .' where `place` = :place order by `position`, `id`',
            [':place' => $place]
        );
        return array_map('intval', $rows);
    }
}
