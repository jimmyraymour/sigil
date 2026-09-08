<?php
declare(strict_types=1);

namespace Sigil\Api;

use Sigil\Storage\ApiKeyRepository;
use Sigil\Util\ApiException;
use Sigil\Util\RateLimiter;

final class Auth
{
    public function __construct(
        private ApiKeyRepository $keys,
        private RateLimiter $limiter,
        private array $config
    ) {
    }

    /**
     * Authenticate from the Authorization: Bearer header. Rate-limits and
     * touches the key. Throws ApiException on failure.
     *
     * @return array the api_keys row
     */
    public function requireKey(?string $presented = null): array
    {
        $key = $presented ?? $this->presentedKey();
        if ($key === null || $key === '') {
            throw ApiException::unauthorized('Missing API key');
        }
        $row = $this->keys->findByHash(hash('sha256', $key));
        if ($row === null) {
            throw ApiException::unauthorized('Invalid API key');
        }
        $this->limiter->hit($row['key_hash'], (int) $this->config['rate_limit_per_minute']);
        $this->keys->touch((int) $row['id']);
        return $row;
    }

    /**
     * Validate a key without rate limiting (used by render.php asset serving,
     * where browsers fire many parallel image requests).
     */
    public function isValidKey(string $key): bool
    {
        if ($key === '') {
            return false;
        }
        return $this->keys->findByHash(hash('sha256', $key)) !== null;
    }

    private function presentedKey(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (is_string($header) && preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return trim($m[1]);
        }
        return null;
    }
}
