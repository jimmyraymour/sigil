<?php
declare(strict_types=1);

namespace Sigil\Render;

use Sigil\Util\Env;
use Sigil\Util\HttpClient;
use Sigil\Util\Logger;

/**
 * Recraft — https://external.api.recraft.ai/v1
 * SVG output uses the dedicated vector model (recraftv4_1_vector).
 */
final class RecraftProvider implements RenderProviderInterface
{
    public function __construct(private array $cfg, private Logger $logger)
    {
    }

    public function name(): string
    {
        return 'recraft';
    }

    public function render(string $prompt, string $format, ?int $seed): array
    {
        $keyEnv = (string) ($this->cfg['api_key_env'] ?? 'RECRAFT_API_KEY');
        $key = (string) Env::get($keyEnv, '');
        if ($key === '') {
            throw new \RuntimeException($keyEnv . ' is not set — add your Recraft API key to .env');
        }

        $base = rtrim((string) Env::get($this->cfg['api_url_env'] ?? 'RECRAFT_API_URL', $this->cfg['api_url'] ?? 'https://external.api.recraft.ai/v1'), '/');
        $model = $format === 'svg'
            ? (string) ($this->cfg['model_svg'] ?? 'recraftv4_1_vector')
            : (string) ($this->cfg['model_png'] ?? 'recraftv4_1');

        $body = ['prompt' => $prompt, 'model' => $model];
        if (!empty($this->cfg['style'])) {
            $body['style'] = $this->cfg['style'];
        }
        if (!empty($this->cfg['size'])) {
            $body['size'] = $this->cfg['size'];
        }
        if ($seed !== null) {
            $body['seed'] = $seed;
        }

        $raw = $this->postWithRetry($base . '/images/generations', $body, ['Authorization' => 'Bearer ' . $key]);
        $data = json_decode($raw, true);
        $item = is_array($data) ? ($data['data'][0] ?? null) : null;
        $url = is_array($item) ? ($item['url'] ?? null) : null;
        if (!is_string($url) || $url === '') {
            throw new \RuntimeException('Recraft returned no image URL: ' . substr($raw, 0, 300));
        }

        $bytes = HttpClient::get($url);
        if ($format === 'svg' && !$this->looksLikeSvg($bytes)) {
            throw new \RuntimeException('Recraft returned content that is not valid SVG');
        }

        return [
            'bytes' => $bytes,
            'format' => $format,
            'provider_job_id' => is_array($item) && isset($item['id']) ? (string) $item['id'] : null,
            'output_url' => $url,
            'cost_usd' => (float) ($this->cfg['cost_estimate'] ?? 0.04),
        ];
    }

    private function looksLikeSvg(string $bytes): bool
    {
        $head = substr(ltrim($bytes), 0, 200);
        return str_starts_with($head, '<?xml') || str_starts_with($head, '<svg');
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
                $this->logger->warn('recraft', 'Rate limited (429), waiting 10s', ['attempt' => $attempt]);
                if ($attempt >= 1) {
                    throw new \RuntimeException('Recraft rate limited after retry: ' . substr($res['body'], 0, 300));
                }
                sleep(10);
                $attempt++;
                continue;
            }

            if ($status >= 500 || $res['error'] !== null) {
                if ($attempt < count($delays)) {
                    $this->logger->warn('recraft', 'Transient failure, retrying', ['status' => $status, 'attempt' => $attempt]);
                    sleep($delays[$attempt]);
                    $attempt++;
                    continue;
                }
                throw new \RuntimeException('Recraft failed after retries (HTTP ' . $status . '): ' . substr($res['body'], 0, 300));
            }

            throw new \RuntimeException('Recraft API error (HTTP ' . $status . '): ' . substr($res['body'], 0, 300));
        }
    }
}
