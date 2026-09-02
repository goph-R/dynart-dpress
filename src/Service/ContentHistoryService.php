<?php

namespace Dynart\Dpress\Service;

use Dynart\Micro\Entities\AuditService;
use Dynart\Micro\Entities\Database;
use Dynart\Micro\Entities\EntityManager;
use Dynart\Micro\Entities\Revision;
use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Entity\User;

/**
 * Reads the audit mirror back
 *
 * The audit tables are written by `AuditService` and never read by it, so this is the only place
 * that knows how to query them. Keeping the joins here means no screen has to learn the mirror's
 * shape.
 */
class ContentHistoryService {

    public function __construct(
        protected EntityManager $em,
        protected Database $db,
    ) {}

    /**
     * Every revision of one piece of content, newest first
     *
     * @return array Rows of the mirror plus `rev_at` and `rev_user_name`
     */
    public function revisions(int $contentId, int $limit = 100): array {
        $sql = 'select a.*, r.`created_at` as `rev_at`, u.`name` as `rev_user_name`'
            .' from '.$this->auditTable().' a'
            .' join '.$this->em->safeTableName(Revision::class).' r on r.`id` = a.`rev_id`'
            .' left join '.$this->em->safeTableName(User::class).' u on u.`id` = r.`user_id`'
            .' where a.`id` = :id'
            .' order by a.`rev_id` desc'
            .' limit '.max(1, $limit);
        return $this->db->fetchAll($sql, [':id' => $contentId]);
    }

    /**
     * One revision of one piece of content
     */
    public function revision(int $contentId, int $revisionId): ?array {
        $sql = 'select a.*, r.`created_at` as `rev_at`, u.`name` as `rev_user_name`'
            .' from '.$this->auditTable().' a'
            .' join '.$this->em->safeTableName(Revision::class).' r on r.`id` = a.`rev_id`'
            .' left join '.$this->em->safeTableName(User::class).' u on u.`id` = r.`user_id`'
            .' where a.`id` = :id and a.`rev_id` = :revId';
        $row = $this->db->fetch($sql, [':id' => $contentId, ':revId' => $revisionId]);
        return is_array($row) ? $row : null;
    }

    /**
     * What a piece of content looked like at a moment in time
     *
     * The nearest revision at or before the given time. Returns null when it did not exist yet,
     * or when that revision deleted it.
     */
    public function asOf(int $contentId, string $when): ?array {
        $sql = 'select a.*, r.`created_at` as `rev_at`'
            .' from '.$this->auditTable().' a'
            .' join '.$this->em->safeTableName(Revision::class).' r on r.`id` = a.`rev_id`'
            .' where a.`id` = :id and r.`created_at` <= :when'
            .' order by r.`created_at` desc, a.`rev_id` desc'
            .' limit 1';
        $row = $this->db->fetch($sql, [':id' => $contentId, ':when' => $when]);
        if (!is_array($row) || $row[EntityManager::AUDIT_TYPE_COLUMN] === AuditService::TYPE_DEL) {
            return null;
        }
        return $row;
    }

    /**
     * Which fields differ between two revisions
     *
     * @return array [field => ['from' => mixed, 'to' => mixed]]
     */
    public function diff(array $older, array $newer): array {
        $ignored = [
            EntityManager::AUDIT_REVISION_COLUMN,
            EntityManager::AUDIT_TYPE_COLUMN,
            'rev_at', 'rev_user_name', 'updated_at',
        ];
        $result = [];
        foreach ($newer as $field => $value) {
            if (in_array($field, $ignored)) {
                continue;
            }
            $before = $older[$field] ?? null;
            if ((string)$before !== (string)$value) {
                $result[$field] = ['from' => $before, 'to' => $value];
            }
        }
        return $result;
    }

    /**
     * How many revisions one piece of content has
     */
    public function countRevisions(int $contentId): int {
        return (int)$this->db->fetchOne(
            'select count(1) from '.$this->auditTable().' where `id` = :id',
            [':id' => $contentId]
        );
    }

    /**
     * The most recent changes across all content, for an activity list
     */
    public function recent(int $limit = 20): array {
        $sql = 'select a.`id`, a.`title`, a.`rev_id`, a.`rev_type`,'
            .' r.`created_at` as `rev_at`, u.`name` as `rev_user_name`'
            .' from '.$this->auditTable().' a'
            .' join '.$this->em->safeTableName(Revision::class).' r on r.`id` = a.`rev_id`'
            .' left join '.$this->em->safeTableName(User::class).' u on u.`id` = r.`user_id`'
            // An auto-draft is a row the editor made for itself, with no title and nothing in it.
            // Opening "New" is not a change worth reporting, and ten of them in a row turned the
            // dashboard into a stack of empty lines - which is how this was found.
            .' where a.`status` <> :notAutoDraft'
            .' order by a.`rev_id` desc'
            .' limit '.max(1, $limit);
        return $this->db->fetchAll($sql, [':notAutoDraft' => Content::STATUS_AUTO_DRAFT]);
    }

    protected function auditTable(): string {
        return $this->em->safeAuditTableName(Content::class);
    }
}
