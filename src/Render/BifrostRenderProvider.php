<?php
declare(strict_types=1);

namespace Sigil\Render;

use Sigil\Util\Logger;

/**
 * Every image route (Pollinations, Recraft, Ideogram, Gemini) is now just
 * "call Bifrost's render job, pinned to this route" (design D5: an app with
 * a user-facing picker lists its allowed routes/models from Bifrost and
 * pins one per call) — the wire protocol for each provider lives in
 * Bifrost's own engines, never here. Replaces PollinationsProvider,
 * RecraftProvider, IdeogramProvider and GeminiProvider, which each spoke
 * directly to their provider's HTTP API.
 */
final class BifrostRenderProvider implements RenderProviderInterface
{
    public function __construct(
        private string $route,
        private ?string $model,
        private Logger $logger,
    ) {
        require_once dirname(__DIR__, 3) . '/bifrost/shared/php/BifrostClient.php';
    }

    public function name(): string
    {
        return $this->route;
    }

    public function render(string $prompt, string $format, ?int $seed): array
    {
        $options = ['route' => $this->route, 'format' => $format];
        if ($this->model !== null) {
            $options['model'] = $this->model;
        }
        if ($seed !== null) {
            $options['seed'] = $seed;
        }

        try {
            $result = \Bifrost::ai('render', ['prompt' => $prompt], $options);
        } catch (\BifrostAiError $e) {
            throw new \RuntimeException($this->route . ': ' . $e->getMessage(), 0, $e);
        }

        $media = $result['media'] ?? null;
        if (!is_array($media) || !is_string($media['base64'] ?? null) || $media['base64'] === '') {
            throw new \RuntimeException($this->route . ' returned no image.');
        }
        $bytes = base64_decode($media['base64'], true);
        if ($bytes === false || $bytes === '') {
            throw new \RuntimeException($this->route . ' returned image data that is not valid base64.');
        }

        // Honest about what actually came back rather than what was asked for (ported from
        // PollinationsProvider's own comment — the upstream model, not this call, decides).
        $mime = (string) ($media['mime'] ?? '');
        $actualFormat = match (true) {
            str_contains($mime, 'svg') => 'svg',
            str_contains($mime, 'jpeg'), str_contains($mime, 'jpg') => 'jpg',
            default => 'png',
        };

        return [
            'bytes'           => $bytes,
            'format'          => $actualFormat,
            'provider_job_id' => null,
            'output_url'      => null,
            'cost_usd'        => isset($result['usage']['cost_usd']) ? (float) $result['usage']['cost_usd'] : null,
        ];
    }
}
