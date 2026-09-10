<?php
declare(strict_types=1);

namespace Sigil\Render;

use Sigil\Util\Env;
use Sigil\Util\HttpClient;
use Sigil\Util\Logger;

/**
 * Google Gemini image generation — the model marketed as "Nano Banana".
 *
 * https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent
 *
 * Unlike Recraft/Ideogram the image is not a URL to download: it comes back
 * inline, base64-encoded, in the candidate parts.
 */
final class GeminiProvider implements RenderProviderInterface
{
    public function __construct(private array $cfg, private Logger $logger)
    {
    }

    public function name(): string
    {
        return 'gemini';
    }

    public function render(string $prompt, string $format, ?int $seed): array
    {
        // Phase 5: central key with the local env var as the fallback.
        $keyEnv = (string) ($this->cfg['key_env'] ?? 'GEMINI_API_KEY');
        $key = ProviderRegistry::centralKey($keyEnv);
        if ($key === '') {
            throw new \RuntimeException($keyEnv . ' is not set — add your Google AI Studio key to .env');
        }

        $base = rtrim((string) ($this->cfg['api_url'] ?? 'https://generativelanguage.googleapis.com/v1beta'), '/');
        $model = (string) ($this->cfg['model'] ?? 'gemini-2.5-flash-image');
        $url = $base . '/models/' . rawurlencode($model) . ':generateContent';

        // The seed only varies the wording, since the API exposes no seed
        // parameter for image output — enough to keep variants distinct.
        $text = $prompt;
        if ($seed !== null) {
            $text .= "\n\nGenerate a distinct interpretation (variation #" . $seed . ').';
        }

        $body = [
            'contents' => [['parts' => [['text' => $text]]]],
            'generationConfig' => ['responseModalities' => ['IMAGE']],
        ];

        $raw = $this->postWithRetry($url, $body, ['x-goog-api-key' => $key]);
        $data = json_decode($raw, true);

        $inline = $this->findInlineImage(is_array($data) ? $data : []);
        if ($inline === null) {
            throw new \RuntimeException('Gemini returned no image data: ' . substr($raw, 0, 300));
        }

        $bytes = base64_decode($inline['data'], true);
        if ($bytes === false || $bytes === '') {
            throw new \RuntimeException('Gemini returned image data that is not valid base64');
        }

        return [
            'bytes' => $bytes,
            'format' => str_contains($inline['mime'], 'jpeg') ? 'jpg' : 'png',
            'provider_job_id' => is_array($data) ? ($data['responseId'] ?? null) : null,
            'output_url' => null,
            'cost_usd' => (float) ($this->cfg['cost_estimate'] ?? 0.04),
        ];
    }

    /**
     * Walks the candidate parts for the first inline image. Accepts both the
     * camelCase (REST) and snake_case (proto) spellings.
     *
     * @return array{data: string, mime: string}|null
     */
    private function findInlineImage(array $data): ?array
    {
        $parts = $data['candidates'][0]['content']['parts'] ?? [];
        if (!is_array($parts)) {
            return null;
        }
        foreach ($parts as $part) {
            if (!is_array($part)) {
                continue;
            }
            $blob = $part['inlineData'] ?? $part['inline_data'] ?? null;
            if (!is_array($blob)) {
                continue;
            }
            $b64 = (string) ($blob['data'] ?? '');
            if ($b64 === '') {
                continue;
            }
            $mime = (string) ($blob['mimeType'] ?? $blob['mime_type'] ?? 'image/png');
            return ['data' => $b64, 'mime' => $mime];
        }
        return null;
    }

    /**
     * POST with retry: 5xx/network retried up to 3 times (1s,2s,4s backoff);
     * 429 waits 10s and retries once; other 4xx fail immediately.
     */
    private function postWithRetry(string $url, array $body, array $headers): string
    {
        $timeout = (int) ($this->cfg['timeout'] ?? 120);
        $delays = [1, 2, 4];
        $attempt = 0;
        while (true) {
            $res = HttpClient::postJson($url, $body, $headers, $timeout);
            $status = $res['status'];

            if ($status >= 200 && $status < 300) {
                return $res['body'];
            }

            if ($status === 429) {
                $this->logger->warn('gemini', 'Rate limited (429), waiting 10s', ['attempt' => $attempt]);
                if ($attempt >= 1) {
                    throw new \RuntimeException('Gemini rate limited after retry: ' . substr($res['body'], 0, 300));
                }
                sleep(10);
                $attempt++;
                continue;
            }

            if ($status >= 500 || $res['error'] !== null) {
                if ($attempt < count($delays)) {
                    $this->logger->warn('gemini', 'Transient failure, retrying', ['status' => $status, 'attempt' => $attempt]);
                    sleep($delays[$attempt]);
                    $attempt++;
                    continue;
                }
                throw new \RuntimeException('Gemini failed after retries (HTTP ' . $status . '): ' . substr($res['body'], 0, 300));
            }

            throw new \RuntimeException('Gemini API error (HTTP ' . $status . '): ' . substr($res['body'], 0, 300));
        }
    }
}
