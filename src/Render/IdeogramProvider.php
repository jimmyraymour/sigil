<?php
declare(strict_types=1);

namespace Sigil\Render;

use Sigil\Util\Env;
use Sigil\Util\HttpClient;
use Sigil\Util\Logger;

/**
 * Ideogram 3.0 — https://api.ideogram.ai/v1/ideogram-v3/generate
 * Output URLs are ephemeral, so the image is downloaded immediately.
 */
final class IdeogramProvider implements RenderProviderInterface
{
    public function __construct(private array $cfg, private Logger $logger)
    {
    }

    public function name(): string
    {
        return 'ideogram';
    }

    public function render(string $prompt, string $format, ?int $seed): array
    {
        $keyEnv = (string) ($this->cfg['api_key_env'] ?? 'IDEOGRAM_API_KEY');
        $key = (string) Env::get($keyEnv, '');
        if ($key === '') {
            throw new \RuntimeException($keyEnv . ' is not set — add your Ideogram API key to .env');
        }

        $base = rtrim((string) Env::get($this->cfg['api_url_env'] ?? 'IDEOGRAM_API_URL', $this->cfg['api_url'] ?? 'https://api.ideogram.ai'), '/');
        $url = $base . ($this->cfg['path'] ?? '/v1/ideogram-v3/generate');

        $body = [
            'prompt' => $prompt,
            'aspect_ratio' => (string) ($this->cfg['aspect_ratio'] ?? '1x1'),
            'rendering_speed' => (string) ($this->cfg['rendering_speed'] ?? 'TURBO'),
            'style_type' => (string) ($this->cfg['style_type'] ?? 'AUTO'),
        ];
        if ($seed !== null) {
            $body['seed'] = $seed;
        }

        $raw = $this->postWithRetry($url, $body, ['Api-Key' => $key]);
        $data = json_decode($raw, true);
        $item = is_array($data) ? ($data['data'][0] ?? null) : null;
        $url = is_array($item) ? ($item['url'] ?? null) : null;
        if (!is_string($url) || $url === '') {
            throw new \RuntimeException('Ideogram returned no image URL: ' . substr($raw, 0, 300));
        }

        $bytes = HttpClient::get($url);
        if ($format === 'png' && !$this->looksLikePng($bytes)) {
            throw new \RuntimeException('Ideogram returned content that is not a valid PNG');
        }

        return [
            'bytes' => $bytes,
            'format' => $format,
            'provider_job_id' => is_array($item) && isset($item['id']) ? (string) $item['id'] : null,
            'output_url' => $url,
            'cost_usd' => (float) ($this->cfg['cost_estimate'] ?? 0.10),
        ];
    }

    private function looksLikePng(string $bytes): bool
    {
        return str_starts_with($bytes, "\x89PNG\r\n\x1a\n");
    }

    /**
     * POST with retry: 5xx/network retried up to 3 times (1s,2s,4s backoff);
     * 429 waits 10s and retries once; other 4xx fail immediately.
     */
    private function postWithRetry(string $url, array $body, array $headers): string
    {
        $delays = [1, 2, 4];
        $attempt = 0;
        while (true) {
            $res = HttpClient::postJson($url, $body, $headers, 120);
            $status = $res['status'];

            if ($status >= 200 && $status < 300) {
                return $res['body'];
            }

            if ($status === 429) {
                $this->logger->warn('ideogram', 'Rate limited (429), waiting 10s', ['attempt' => $attempt]);
                if ($attempt >= 1) {
                    throw new \RuntimeException('Ideogram rate limited after retry: ' . substr($res['body'], 0, 300));
                }
                sleep(10);
                $attempt++;
                continue;
            }

            if ($status >= 500 || $res['error'] !== null) {
                if ($attempt < count($delays)) {
                    $this->logger->warn('ideogram', 'Transient failure, retrying', ['status' => $status, 'attempt' => $attempt]);
                    sleep($delays[$attempt]);
                    $attempt++;
                    continue;
                }
                throw new \RuntimeException('Ideogram failed after retries (HTTP ' . $status . '): ' . substr($res['body'], 0, 300));
            }

            throw new \RuntimeException('Ideogram API error (HTTP ' . $status . '): ' . substr($res['body'], 0, 300));
        }
    }
}
