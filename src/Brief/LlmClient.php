<?php
declare(strict_types=1);

namespace Sigil\Brief;

use Sigil\Util\HttpClient;
use Sigil\Util\Logger;

/**
 * Local-first LLM client. Ollama is primary (free, local); OpenRouter is
 * the fallback. Iteration can pass the previous render image to vision
 * models; if the model rejects the image the call retries text-only.
 */
final class LlmClient
{
    public function __construct(private array $cfg, private Logger $logger)
    {
    }

    /**
     * @return array{text: string, model: string, backend: string}
     */
    public function complete(string $system, string $user, ?string $imagePath = null): array
    {
        try {
            return $this->ollama($system, $user, $imagePath);
        } catch (\Throwable $e) {
            $this->logger->warn('llm', 'Ollama failed, retrying without image', ['error' => $e->getMessage()]);
            if ($imagePath !== null) {
                try {
                    return $this->ollama($system, $user, null);
                } catch (\Throwable $e2) {
                    $this->logger->warn('llm', 'Ollama failed without image, falling back to OpenRouter', ['error' => $e2->getMessage()]);
                }
            }
            try {
                return $this->openRouter($system, $user, $imagePath);
            } catch (\Throwable $fallbackError) {
                // Keep the failure typed so the API can return its documented
                // upstream_error shape instead of leaking a 500/stack trace.
                throw new BriefRefinementFailed(
                    'Both LLM backends failed: ' . $fallbackError->getMessage(),
                    0,
                    $fallbackError
                );
            }
        }
    }

    private function ollama(string $system, string $user, ?string $imagePath): array
    {
        $url = rtrim((string) $this->cfg['ollama_url'], '/') . '/api/generate';
        // Only swap in the vision model when there is actually an image to
        // look at — it is smaller and writes weaker prose than the text model.
        $model = ($imagePath !== null && is_file($imagePath))
            ? (string) ($this->cfg['ollama_vision_model'] ?? $this->cfg['ollama_model'])
            : (string) $this->cfg['ollama_model'];
        $body = [
            'model' => $model,
            'prompt' => $system . "\n\n" . $user,
            'stream' => false,
            'options' => ['temperature' => $this->cfg['temperature'] ?? 0.7],
        ];
        if ($imagePath !== null && is_file($imagePath)) {
            $body['images'] = [base64_encode((string) file_get_contents($imagePath))];
        }
        $res = HttpClient::postJson($url, $body, [], (int) ($this->cfg['ollama_timeout'] ?? 120));
        if ($res['status'] !== 200) {
            throw new \RuntimeException('Ollama HTTP ' . $res['status'] . ': ' . substr($res['body'], 0, 300));
        }
        $data = json_decode($res['body'], true);
        $text = trim((string) ($data['response'] ?? ''));
        if ($text === '') {
            throw new \RuntimeException('Ollama returned an empty response');
        }
        return ['text' => $text, 'model' => 'ollama:' . $model, 'backend' => 'ollama'];
    }

    private function openRouter(string $system, string $user, ?string $imagePath): array
    {
        // Phase 5: central key, local config value as the migration fallback.
        $key = \Sigil\Render\ProviderRegistry::centralKey('OPENROUTER_API_KEY')
            ?: (string) ($this->cfg['openrouter_api_key'] ?? '');
        if ($key === '') {
            throw new \RuntimeException('OpenRouter is not configured (OPENROUTER_API_KEY is empty)');
        }
        $url = 'https://openrouter.ai/api/v1/chat/completions';
        $userContent = $user;
        if ($imagePath !== null && is_file($imagePath)) {
            $mime = (string) (mime_content_type($imagePath) ?: 'image/png');
            $userContent = [
                ['type' => 'text', 'text' => $user],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($imagePath))]],
            ];
        }
        $body = [
            'model' => $this->cfg['openrouter_model'],
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $userContent],
            ],
            'temperature' => $this->cfg['temperature'] ?? 0.7,
        ];
        $res = HttpClient::postJson($url, $body, ['Authorization' => 'Bearer ' . $key], 120);
        if ($res['status'] !== 200) {
            throw new \RuntimeException('OpenRouter HTTP ' . $res['status'] . ': ' . substr($res['body'], 0, 300));
        }
        $data = json_decode($res['body'], true);
        $text = trim((string) ($data['choices'][0]['message']['content'] ?? ''));
        if ($text === '') {
            throw new \RuntimeException('OpenRouter returned an empty response');
        }
        return ['text' => $text, 'model' => 'openrouter:' . $this->cfg['openrouter_model'], 'backend' => 'openrouter'];
    }
}
