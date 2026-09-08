<?php
declare(strict_types=1);

namespace Sigil\Storage;

final class ApiKeyRepository
{
    public function __construct(private Database $db)
    {
    }

    public function create(string $name, string $keyHash, string $keyPrefix): int
    {
        $this->db->execute(
            'INSERT INTO api_keys (name, key_hash, key_prefix) VALUES (?, ?, ?)',
            [$name, $keyHash, $keyPrefix]
        );
        return (int) $this->db->lastInsertId();
    }

    public function findByHash(string $keyHash): ?array
    {
        return $this->db->fetch('SELECT * FROM api_keys WHERE key_hash = ? AND revoked_at IS NULL', [$keyHash]);
    }

    public function findById(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM api_keys WHERE id = ?', [$id]);
    }

    public function all(): array
    {
        return $this->db->fetchAll(
            'SELECT id, name, key_prefix, created_at, last_used_at, revoked_at
             FROM api_keys ORDER BY created_at DESC'
        );
    }

    public function revoke(int $id): bool
    {
        return $this->db->execute(
            'UPDATE api_keys SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL',
            [$id]
        ) > 0;
    }

    public function touch(int $id): void
    {
        $this->db->execute('UPDATE api_keys SET last_used_at = NOW() WHERE id = ?', [$id]);
    }
}
