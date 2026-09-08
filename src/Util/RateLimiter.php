<?php
declare(strict_types=1);

namespace Sigil\Util;

use Sigil\Storage\Database;

/**
 * Per-key per-minute rate limiting using minute buckets.
 */
final class RateLimiter
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Record one hit for a key. Throws ApiException::rateLimited when the
     * limit is exceeded. Prunes stale buckets opportunistically.
     */
    public function hit(string $keyHash, int $limitPerMinute): void
    {
        $bucket = (int) floor(time() / 60);

        $this->db->execute('DELETE FROM rate_limits WHERE bucket < ?', [$bucket - 1]);

        $this->db->execute(
            'INSERT INTO rate_limits (key_hash, bucket, count) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE count = count + 1',
            [$keyHash, $bucket]
        );

        $row = $this->db->fetch(
            'SELECT count FROM rate_limits WHERE key_hash = ? AND bucket = ?',
            [$keyHash, $bucket]
        );
        $count = (int) ($row['count'] ?? 0);

        if ($count > $limitPerMinute) {
            $retryAfter = 60 - (time() % 60);
            throw ApiException::rateLimited('Rate limit exceeded (' . $limitPerMinute . '/min)', $retryAfter);
        }
    }
}
