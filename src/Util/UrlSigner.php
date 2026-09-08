<?php
declare(strict_types=1);

namespace Sigil\Util;

/**
 * Signs expiring URLs so the web UI can load render assets via <img>
 * without exposing an API key. HMAC-SHA256 over payload|expiry.
 */
final class UrlSigner
{
    public function __construct(private string $secret)
    {
    }

    /**
     * @return array{sig: string, exp: string}
     */
    public function sign(string $payload, int $ttlSeconds): array
    {
        $exp = time() + $ttlSeconds;
        return ['sig' => hash_hmac('sha256', $payload . '|' . $exp, $this->secret), 'exp' => (string) $exp];
    }

    public function verify(string $payload, string $sig, int $exp): bool
    {
        if ($exp < time()) {
            return false;
        }
        $expected = hash_hmac('sha256', $payload . '|' . $exp, $this->secret);
        return hash_equals($expected, $sig);
    }
}
