<?php
declare(strict_types=1);

namespace Sigil\Storage;

final class BriefRepository
{
    public function __construct(private Database $db)
    {
    }

    public function createRoot(
        string $id,
        string $raw,
        ?string $logoDescription,
        string $entityType,
        ?string $brandName,
        string $refined,
        string $style,
        ?string $palette,
        string $model
    ): void {
        $this->db->execute(
            'INSERT INTO briefs (id, parent_brief_id, raw_description, logo_description, entity_type, brand_name, feedback, refined_brief, style, palette_hint, model_used)
             VALUES (?, NULL, ?, ?, ?, ?, NULL, ?, ?, ?, ?)',
            [$id, $raw, $logoDescription, $entityType, $brandName, $refined, $style, $palette, $model]
        );
    }

    public function createChild(
        string $id,
        string $parentId,
        string $feedback,
        string $refined,
        string $entityType,
        ?string $brandName,
        string $style,
        ?string $palette,
        string $model
    ): void {
        $this->db->execute(
            'INSERT INTO briefs (id, parent_brief_id, raw_description, logo_description, entity_type, brand_name, feedback, refined_brief, style, palette_hint, model_used)
             VALUES (?, ?, NULL, NULL, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $parentId, $entityType, $brandName, $feedback, $refined, $style, $palette, $model]
        );
    }

    /** The root of a chain carries the original description fields. */
    public function root(string $id): ?array
    {
        $chain = $this->chain($id);
        return $chain[0] ?? null;
    }

    public function find(string $id): ?array
    {
        return $this->db->fetch('SELECT * FROM briefs WHERE id = ?', [$id]);
    }

    /**
     * Walk the parent chain up to the root, then reverse it so the result is
     * ordered root -> newest (deterministic, independent of timestamps).
     * The root row carries the raw_description; every other row carries feedback.
     */
    public function chain(string $id): array
    {
        $current = $this->find($id);
        if ($current === null) {
            return [];
        }
        $walk = [];
        $guard = 0;
        while ($current !== null && $guard < 100) {
            $walk[] = $current;
            $parentId = $current['parent_brief_id'];
            $current = $parentId === null ? null : $this->find($parentId);
            $guard++;
        }
        return array_reverse($walk);
    }

    public function list(int $limit, int $offset): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM briefs ORDER BY created_at DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset
        );
    }
}
