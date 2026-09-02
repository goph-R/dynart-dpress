<?php

namespace Dynart\Dpress\Content;

use Dynart\Micro\Entities\Database;
use Dynart\Micro\Entities\Entity;
use Dynart\Micro\Entities\EntityManager;
use Dynart\Dpress\DpressException;

/**
 * Moving a node around a `parent_id` + `position` tree
 *
 * Menu items and categories are the same shape - a nullable parent and an integer position among
 * siblings - and dragging one is the same operation on both, so it is written once here rather
 * than twice in two services that would drift.
 *
 * **Positions are renumbered, not nudged.** A drag hands over "put this under that, third", and
 * what comes back out is `0, 1, 2, …` with no gaps and no duplicates, on both the row of siblings
 * the node left and the one it joined. Anything else accumulates: a list whose positions are
 * `0, 3, 3, 7` sorts by insertion order for the ties and nobody can see why.
 *
 * Everything goes through the entity manager rather than one `update` statement, so a move is
 * audited on the entities that are audited - `Category` is, `MenuItem` is not. A drag writes a
 * handful of rows in one revision, which is the honest record of what it did.
 */
class TreeOrder {

    public function __construct(
        protected EntityManager $em,
        protected Database $db,
    ) {}

    /**
     * Puts a node under a parent, at an index among that parent's children
     *
     * @param string $className the entity
     * @param int $id the node being moved
     * @param ?int $parentId its new parent, or null for the top level
     * @param int $position where among its new siblings, clamped into range
     * @param array $scope extra `column => value` the tree is confined to, e.g. `menu_id`
     * @throws DpressException if the node is missing, or the move would put it inside itself
     */
    public function move(string $className, int $id, ?int $parentId, int $position, array $scope = []): void {
        $node = $this->em->findById($className, $id);
        if (!$node instanceof Entity) {
            throw new DpressException('There is nothing here to move.');
        }
        if ($parentId !== null) {
            $this->assertNotItsOwnDescendant($className, $id, $parentId, $scope);
        }
        $oldParentId = $node->parent_id;

        // the new siblings, in their current order, without the node itself
        $siblings = array_values(array_filter($this->childIds($className, $parentId, $scope), fn($x) => $x !== $id));
        $position = max(0, min($position, count($siblings)));
        array_splice($siblings, $position, 0, [$id]);

        // The moved node is written here rather than left to `renumber()`, which loads its own
        // copies by id and would know nothing about the new parent set on this one. Its position
        // is already final, so the pass below skips it rather than saving it twice.
        $node->parent_id = $parentId;
        $node->position = $position;
        $this->em->save($node);

        $this->renumber($className, $siblings);

        // and the row it left, which now has a gap in it
        if ($oldParentId !== $parentId) {
            $this->renumber($className, $this->childIds($className, $oldParentId, $scope));
        }
    }

    /**
     * Writes `0, 1, 2, …` over a list of ids, saving only what actually moved
     *
     * @param int[] $ids in the order they should end up
     */
    protected function renumber(string $className, array $ids): void {
        foreach (array_values($ids) as $index => $nodeId) {
            $node = $this->em->findById($className, $nodeId);
            if (!$node instanceof Entity) {
                continue;
            }
            // a save per sibling is a revision row per sibling on an audited entity, so the ones
            // that did not move are left alone
            if ((int)$node->position === $index) {
                continue;
            }
            $node->position = $index;
            $this->em->save($node);
        }
    }

    /**
     * A node cannot be put inside itself, or inside anything already inside it
     *
     * Without this the branch simply disappears: it is still in the table, with a parent chain
     * that loops, so nothing that walks down from the top ever reaches it again.
     *
     * @throws DpressException
     */
    protected function assertNotItsOwnDescendant(string $className, int $id, int $parentId, array $scope): void {
        if ($parentId === $id) {
            throw new DpressException('An item cannot be put inside itself.');
        }
        $seen = [];
        $walk = $parentId;
        while ($walk !== null) {
            if ($walk === $id) {
                throw new DpressException('An item cannot be put inside something it already contains.');
            }
            if (isset($seen[$walk])) {
                return; // a loop that was already there; not this move's to fix
            }
            $seen[$walk] = true;
            $node = $this->em->findById($className, $walk);
            $walk = $node instanceof Entity ? $node->parent_id : null;
        }
    }

    /**
     * The ids directly under a parent, in position order
     *
     * @return int[]
     */
    protected function childIds(string $className, ?int $parentId, array $scope): array {
        $where = [$parentId === null ? '`parent_id` is null' : '`parent_id` = :parentId'];
        $params = $parentId === null ? [] : [':parentId' => $parentId];
        foreach (array_keys($scope) as $index => $column) {
            $name = ':scope'.$index;
            $where[] = $this->db->escapeName($column).' = '.$name;
            $params[$name] = $scope[$column];
        }
        $rows = $this->db->fetchColumn(
            'select `id` from '.$this->em->safeTableName($className)
                .' where '.join(' and ', $where).' order by `position`, `id`',
            $params
        );
        return array_map('intval', $rows);
    }
}
