<?php
declare(strict_types=1);

namespace Sigil\Render;

use Sigil\Util\HttpClient;
use Sigil\Util\Logger;

/**
 * Pollinations — https://image.pollinations.ai
 *
 * The free tier: no API key, no account, no cost. The whole prompt travels
 * in the URL path, and the image comes back as the response body (JPEG or
 * PNG depending on the upstream model), so there is no second fetch.
 *
 * This is Sigil's default. It replaced the old StubProvider, which drew a
 * placeholder rectangle and recorded it as a successful render.
 */
final class PollinationsProvider implements RenderProviderInterface
{
    public function __construct(private array $cfg, private Logger $logger)
    {
    }

    public function name(): string
    {
        return 'pollinations';
    }

    public function render(string $prompt, string $format, ?int $seed): array
    {
        $base = rtrim((string) ($this->cfg['api_url'] ?? 'https://image.pollinations.ai/prompt/'), '/') . '/';
        $size = (int) ($this->cfg['size'] ?? 1024);

        $query = [
            'width' => $size,
            'height' => $size,
            'nologo' => 'true',
            'model' => (string) ($this->cfg['model'] ?? 'flux'),
            'referrer' => 'sigil',
        ];
        if ($seed !== null) {
            $query['seed'] = $seed;
        }

        $url = $base . rawurlencode($prompt) . '?' . http_build_query($query);

        $bytes = $this->fetchWithRetry($url);
        $detected = $this->sniff($bytes);
        if ($detected === null) {
            throw new \RuntimeException(
                'Pollinations returned something that is not an image: ' . substr($bytes, 0, 200)
            );
        }

        return [
            'bytes' => $bytes,
            // Honest about what actually came back rather than what was asked
            // for — the upstream model decides JPEG vs PNG.
            'format' => $detected,
            'provider_job_id' => null,
            'output_url' => null,
            'cost_usd' => 0.0,
        ];
    }

    /** @return 'png'|'jpg'|null */
    private function sniff(string $bytes): ?string
    {
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return 'png';
        }
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'jpg';
        }
        return null;
    }

    /**
     * The free endpoint queues under load and rate-limits a burst of
     * requests, which a multi-variant batch is by definition. 429 backs off
     * much harder than a transient 5xx — a batch that waits is fine, a batch
     * that returns half-empty is not.
     */
    private function fetchWithRetry(string $url): string
    {
        $timeout = (int) ($this->cfg['timeout'] ?? 120);
        $transient = [2, 5, 10];
        $throttled = [15, 30, 60];
        $attempt = 0;

        while (true) {
            try {
                return HttpClient::get($url, [], $timeout);
            } catch (\Throwable $e) {
                $isThrottled = str_contains($e->getMessage(), 'HTTP 429');
                $delays = $isThrottled ? $throttled : $transient;

                if ($attempt >= count($delays)) {
                    throw new \RuntimeException(
                        ($isThrottled ? 'Pollinations is rate limiting this box (free tier); try again shortly: ' : 'Pollinations failed after retries: ')
                        . $e->getMessage()
                    );
                }

                $this->logger->warn('pollinations', $isThrottled ? 'Rate limited, backing off' : 'Transient failure, retrying', [
                    'attempt' => $attempt,
                    'wait_s' => $delays[$attempt],
                    'error' => $e->getMessage(),
                ]);
                sleep($delays[$attempt]);
                $attempt++;
            }
        }
    }

}
