<?php
declare(strict_types=1);

namespace Sigil\Storage;

final class RenderRepository
{
    public function __construct(private Database $db)
    {
    }

    public function createQueued(
        string $id,
        string $briefId,
        ?string $batchId,
        int $variantIndex,
        ?int $apiKeyId,
        string $provider,
        string $prompt,
        string $format
    ): void {
        $this->db->execute(
            "INSERT INTO renders (id, brief_id, batch_id, variant_index, api_key_id, provider, prompt_sent, output_format, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'queued')",
            [$id, $briefId, $batchId, $variantIndex, $apiKeyId, $provider, $prompt, $format]
        );
    }

    /** All renders in one batch, in the order they were requested. */
    public function findBatch(string $batchId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM renders WHERE batch_id = ? ORDER BY variant_index ASC',
            [$batchId]
        );
    }

    public function markRendering(string $id): void
    {
        $this->db->execute("UPDATE renders SET status = 'rendering' WHERE id = ?", [$id]);
    }

    public function markSucceeded(string $id, array $result): void
    {
        $this->db->execute(
            "UPDATE renders
             SET status = 'succeeded', provider_job_id = ?, output_path = ?, output_url = ?,
                 width_px = ?, height_px = ?, cost_usd = ?, output_format = ?, completed_at = NOW()
             WHERE id = ?",
            [
                $result['provider_job_id'],
                $result['output_path'],
                $result['output_url'],
                $result['width_px'],
                $result['height_px'],
                $result['cost_usd'],
                $result['format'],
                $id,
            ]
        );
    }

    public function markFailed(string $id, string $error): void
    {
        $this->db->execute(
            "UPDATE renders SET status = 'failed', error_message = ?, completed_at = NOW() WHERE id = ?",
            [$error, $id]
        );
    }

    public function find(string $id): ?array
    {
        return $this->db->fetch('SELECT * FROM renders WHERE id = ?', [$id]);
    }

    public function list(int $limit, int $offset): array
    {
        return $this->db->fetchAll(
            'SELECT r.*, b.refined_brief AS brief_snippet
             FROM renders r
             LEFT JOIN briefs b ON b.id = r.brief_id
             ORDER BY r.created_at DESC
             LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset
        );
    }

    public function count(): int
    {
        $row = $this->db->fetch('SELECT COUNT(*) AS c FROM renders');
        return (int) ($row['c'] ?? 0);
    }

    /**
     * Latest succeeded render for any brief id in the given list (a chat chain).
     */
    public function latestSucceededForBriefIds(array $briefIds): ?array
    {
        if ($briefIds === []) {
            return null;
        }
        $placeholders = implode(',', array_fill(0, count($briefIds), '?'));
        return $this->db->fetch(
            "SELECT * FROM renders
             WHERE brief_id IN ($placeholders) AND status = 'succeeded'
             ORDER BY created_at DESC LIMIT 1",
            array_values($briefIds)
        );
    }

    public function countByKeySince(int $apiKeyId, string $interval): int
    {
        $row = $this->db->fetch(
            "SELECT COUNT(*) AS c FROM renders WHERE api_key_id = ? AND created_at > NOW() - INTERVAL $interval",
            [$apiKeyId]
        );
        return (int) ($row['c'] ?? 0);
    }

    public function sumCostSince(string $interval): float
    {
        $row = $this->db->fetch(
            "SELECT COALESCE(SUM(cost_usd), 0) AS s FROM renders
             WHERE status = 'succeeded' AND created_at > NOW() - INTERVAL $interval"
        );
        return (float) ($row['s'] ?? 0);
    }
}
