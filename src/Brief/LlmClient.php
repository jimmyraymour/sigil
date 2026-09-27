<?php
declare(strict_types=1);

namespace Sigil\Brief;

use Sigil\Util\Logger;

/**
 * Bifrost is the only thing that talks to AI providers (root CLAUDE.md;
 * docs/superpowers/specs/2026-09-10-bifrost-ai-routes-design.md). The
 * fallback order (Local Ollama 01 -> the shared portfolio OpenRouter key)
 * lives in Bifrost's `brief` job, not here; this class only shapes sigil's
 * system/user/image input into one Bifrost::ai() call and back into the
 * {text, model, backend} shape BriefRefiner already expects.
 *
 * Vision (iterate() looking at the previous render) is a SEPARATE job,
 * `brief.vision`, rather than a per-call model pin on `brief`: AiRunner's
 * pin (options.route+model) bypasses a job's configured fallback steps
 * entirely, so pinning per-call would have cost the text job its real
 * Ollama -> OpenRouter fallback on every plain call too. brief.vision has
 * only one step (the local vision model) and no fallback of its own — no
 * free OpenRouter model available to this project is actually vision-
 * capable, so there is nothing honest to fall back to. A previous version
 * of this class retried the local model text-only when it specifically
 * rejected an attached image before falling through to OpenRouter; that
 * three-tier degradation has no equivalent in Bifrost's flat step list and
 * is not reproduced — accepted trade-off, not an oversight.
 */
final class LlmClient
{
    /**
     * Client-side HTTP budget for the brief.vision job, in seconds.
     *
     * Sized from measurement, not guesswork: on this CPU-only host the vision call spends ~31s
     * decoding the image into vision tokens and then generates at ~2.2 tokens/sec, so a ~300
     * token concept takes ~215s. 300s leaves headroom without letting a genuinely dead provider
     * hold a request open indefinitely. Must stay >= the job's own ai_jobs.timeout_seconds, or
     * the client gives up before the server has finished working.
     */
    private const VISION_TIMEOUT_SECONDS = 300;

    public function __construct(private array $cfg, private Logger $logger)
    {
        require_once dirname(__DIR__, 3) . '/bifrost/shared/php/BifrostClient.php';
    }

    /**
     * @return array{text: string, model: string, backend: string}
     */
    public function complete(string $system, string $user, ?string $imagePath = null): array
    {
        $input = ['system' => $system, 'prompt' => $user];
        $options = [
            'temperature' => (float) ($this->cfg['temperature'] ?? 0.7),
            // A reasoning-capable free OpenRouter model otherwise spends the whole max_tokens
            // budget on hidden thinking before writing a word of the actual concept (the exact
            // failure meridian's strategist job hit — see BifrostClient.php's Bifrost::ai()
            // caller in meridian/scripts/mc_strategist.php). Ignored by every other engine.
            'extra' => ['reasoning' => ['effort' => 'none', 'exclude' => true]],
        ];
        $job = 'brief';

        $hasImage = $imagePath !== null && is_file($imagePath);
        if ($hasImage) {
            $mime = (string) (mime_content_type($imagePath) ?: 'image/png');
            $input['images'] = [['mime' => $mime, 'base64' => base64_encode((string) file_get_contents($imagePath))]];
            $job = 'brief.vision';
            // Vision needs a far longer client-side budget than text. Measured on this host
            // (Ollama, CPU-only, qwen3-vl:4b, 512px input): ~31s to decode the image into vision
            // tokens, then generation at only ~2.2 tokens/sec. A concept paragraph is ~300 tokens,
            // so the call needs ~215s end to end. BifrostClient::ai() defaults to a 150s HTTP
            // timeout, so the client gave up at 150s and reported "Bifrost unreachable" — which
            // looks exactly like Bifrost being down, and sent me looking at the wrong layer. The
            // server-side job timeout (ai_jobs.timeout_seconds) bounds the work separately; this
            // is the client waiting long enough to hear back. Text keeps the 150s default because
            // the local text model answers in seconds and a long wait there is just a hang.
            $options['timeout'] = self::VISION_TIMEOUT_SECONDS;
        }

        try {
            $result = \Bifrost::ai($job, $input, $options);
        } catch (\BifrostAiError $e) {
            $this->logger->warn('llm', 'Bifrost AI job failed', ['job' => $job, 'error' => $e->getMessage()]);
            throw new BriefRefinementFailed('Both LLM backends failed: ' . $e->getMessage(), 0, $e);
        }

        $text = trim((string) $result['text']);
        if ($text === '') {
            throw new BriefRefinementFailed('Bifrost returned an empty response for job "' . $job . '"');
        }
        return [
            'text' => $text,
            'model' => $result['route'] . ':' . $result['model'],
            'backend' => (string) $result['route'],
        ];
    }
}
