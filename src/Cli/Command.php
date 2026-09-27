<?php
declare(strict_types=1);

namespace Sigil\Cli;

use Sigil\App;
use Sigil\Render\AssetStore;
use Sigil\Util\ApiException;

/**
 * CLI: php bin/sigil <command> [args]
 * Exit codes: 0 success · 2 user error (bad flags/input) · 3 internal error.
 */
final class Command
{
    public function __construct(private App $app)
    {
    }

    public function run(array $argv): int
    {
        $args = array_slice($argv, 1);
        if ($args === []) {
            $this->help();
            return 0;
        }
        $cmd = array_shift($args);

        $opts = [];
        $pos = [];
        foreach ($args as $a) {
            if (str_starts_with($a, '--')) {
                $pair = substr($a, 2);
                if (str_contains($pair, '=')) {
                    [$k, $v] = explode('=', $pair, 2);
                    $opts[$k] = $v;
                } else {
                    $opts[$pair] = true;
                }
            } else {
                $pos[] = $a;
            }
        }

        try {
            return match ($cmd) {
                'help' => $this->help(),
                'brief' => $this->cmdBrief($pos, $opts),
                'iterate' => $this->cmdIterate($pos, $opts),
                'render' => $this->cmdRender($pos, $opts),
                'models' => $this->cmdModels($opts),
                'library' => $this->cmdLibrary($opts),
                'show' => $this->cmdShow($pos, $opts),
                'key:create' => $this->cmdKeyCreate($pos, $opts),
                'key:list' => $this->cmdKeyList($opts),
                'key:revoke' => $this->cmdKeyRevoke($pos, $opts),
                'health' => $this->cmdHealth($opts),
                default => $this->err("Unknown command: {$cmd} (run 'php bin/sigil help')", 2),
            };
        } catch (ApiException $e) {
            return $this->err($e->getMessage(), 2);
        } catch (\Throwable $e) {
            $this->app->logger()->error('cli', 'Command failed', ['error' => $e->getMessage()]);
            fwrite(STDERR, 'Internal error: ' . $e->getMessage() . PHP_EOL);
            return 3;
        }
    }

    // ------------------------------------------------------------ helpers

    private function out(array $data, array $opts, ?string $human = null): int
    {
        if (isset($opts['json'])) {
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        } elseif ($human !== null) {
            echo $human . PHP_EOL;
        } else {
            foreach ($data as $k => $v) {
                echo $k . ': ' . (is_scalar($v) ? (string) $v : json_encode($v)) . PHP_EOL;
            }
        }
        return 0;
    }

    private function err(string $message, int $code): int
    {
        fwrite(STDERR, $message . PHP_EOL);
        return $code;
    }

    private function help(): int
    {
        echo <<<HELP
        Sigil — logo generation tool

        Usage: php bin/sigil <command> [args]

        Commands:
          brief   <description> [--logo="..."] [--name="..."] [--type=company]
                  [--style=auto] [--palette="..."] [--json]
                  Turn a description into a logo concept (free, local LLM).
                  --logo is the second field: what the logo should look like.
                  Omit it and Sigil proposes the concept itself.
          iterate <brief-id> "<feedback>" [--json]
                  Rework the concept with feedback via the local LLM (free).
          render  --brief=<brief-id> [--model=pollinations] [--variants=4]
                  [--format=png] [--seed=42] [--out=/dir] [--json]
                  Generate logo images. The default model is free; premium
                  models cost money and are named explicitly.
          models  [--json]
                  List available models, their cost and whether they are configured.
          library [--limit=20] [--json]
                  List recent renders.
          show    <render-id> [--json]
                  Show full metadata for a render.
          key:create <name> [--json]
                  Create an API key (plaintext shown once).
          key:list [--json]
                  List API keys.
          key:revoke <id> [--json]
                  Revoke an API key.
          health  [--json]
                  Report DB / LLM / model status.

        Exit codes: 0 success, 2 user error, 3 internal error.
        render exits 0 if at least one variant succeeded — check "succeeded"/"failed".

        HELP;
        return 0;
    }

    // ---------------------------------------------------------- commands

    private function cmdBrief(array $pos, array $opts): int
    {
        $desc = $pos[0] ?? '';
        if ($desc === '') {
            return $this->err('Usage: php bin/sigil brief "<description>" [--logo="..."] [--name="..."] [--type=company] [--style=auto] [--palette="..."] [--json]', 2);
        }
        $cfg = $this->app->config();
        $brief = $this->app->engine()->refineBrief([
            'description' => $desc,
            'logo_description' => isset($opts['logo']) ? (string) $opts['logo'] : null,
            'brand_name' => isset($opts['name']) ? (string) $opts['name'] : null,
            'entity_type' => (string) ($opts['type'] ?? $cfg['default_entity_type']),
            'style' => (string) ($opts['style'] ?? $cfg['default_style']),
            'palette_hint' => isset($opts['palette']) ? (string) $opts['palette'] : null,
        ]);
        $human = "Concept created: {$brief['id']}\n\n{$brief['refined_brief']}\n\nmodel: {$brief['model_used']}";
        return $this->out($brief, $opts, $human);
    }

    private function cmdIterate(array $pos, array $opts): int
    {
        $briefId = $pos[0] ?? '';
        $feedback = $pos[1] ?? '';
        if ($briefId === '' || $feedback === '') {
            return $this->err('Usage: php bin/sigil iterate <brief-id> "<feedback>" [--json]', 2);
        }
        $brief = $this->app->engine()->iterate(['brief_id' => $briefId, 'feedback' => $feedback]);
        $human = "Concept revised: {$brief['id']}\n\n{$brief['refined_brief']}";
        return $this->out($brief, $opts, $human);
    }

    private function cmdRender(array $pos, array $opts): int
    {
        $briefId = (string) ($opts['brief'] ?? ($pos[0] ?? ''));
        if ($briefId === '') {
            return $this->err('Usage: php bin/sigil render --brief=<id> [--model=pollinations] [--variants=4] [--format=png] [--seed=42] [--out=/dir] [--json]', 2);
        }
        $input = ['brief_id' => $briefId];
        // --provider stays accepted as an alias so old scripts keep working.
        $model = $opts['model'] ?? $opts['provider'] ?? null;
        if ($model !== null) {
            $input['provider'] = (string) $model;
        }
        if (isset($opts['format'])) {
            $input['output_format'] = (string) $opts['format'];
        }
        if (isset($opts['variants'])) {
            $input['variants'] = (int) $opts['variants'];
        }
        if (isset($opts['seed'])) {
            $input['seed'] = (int) $opts['seed'];
        }

        $batch = $this->app->engine()->render($input);

        if (!empty($opts['out'])) {
            $store = new AssetStore(SIGIL_STORAGE . '/renders');
            $dir = rtrim((string) $opts['out'], '/');
            foreach ($batch['renders'] as $i => $r) {
                if (($r['status'] ?? '') !== 'succeeded' || empty($r['output_path'])) {
                    continue;
                }
                $dest = is_dir($dir)
                    ? $dir . '/sigil-' . substr($r['id'], 0, 8) . '.' . $r['output_format']
                    : (string) $opts['out'];
                if (copy($store->open($r['output_path']), $dest)) {
                    $batch['renders'][$i]['saved_to'] = $dest;
                }
            }
        }

        $human = sprintf(
            "Batch %s · %s · %d ok, %d failed · $%.4f\n",
            $batch['batch_id'],
            $batch['provider'],
            $batch['succeeded'],
            $batch['failed'],
            $batch['cost_usd']
        );
        foreach ($batch['renders'] as $r) {
            $human .= sprintf(
                "  [%d] %-9s %-4s %s\n",
                $r['variant_index'],
                $r['status'],
                $r['output_format'],
                $r['saved_to'] ?? ($r['output_path'] ?? ($r['error_message'] ?? ''))
            );
        }
        return $this->out($batch, $opts, rtrim($human));
    }

    private function cmdLibrary(array $opts): int
    {
        $limit = min(100, max(1, (int) ($opts['limit'] ?? 20)));
        $renders = $this->app->engine()->listRenders($limit, 0);
        $total = $this->app->engine()->countRenders();
        $human = "Renders ({$total} total):\n";
        foreach ($renders as $r) {
            $human .= sprintf("  %s  %-8s %-10s %-5s %s\n", $r['id'], $r['provider'], $r['status'], $r['output_format'], $r['created_at']);
        }
        return $this->out(['renders' => $renders, 'total' => $total], $opts, $human);
    }

    private function cmdShow(array $pos, array $opts): int
    {
        $id = $pos[0] ?? '';
        if ($id === '') {
            return $this->err('Usage: php bin/sigil show <render-id> [--json]', 2);
        }
        $render = $this->app->engine()->getRender($id);
        if ($render === null) {
            return $this->err('Render not found: ' . $id, 2);
        }
        $abs = (new AssetStore(SIGIL_STORAGE . '/renders'))->open($render['output_path'] ?? '');
        $human = "Render: {$render['id']}\n  provider: {$render['provider']}\n  status: {$render['status']}\n  format: {$render['output_format']}\n  cost_usd: " . ($render['cost_usd'] ?? 'null') . "\n  created: {$render['created_at']}\n  path: " . ($render['output_path'] ?? '') . "\n  absolute: " . $abs . ($render['error_message'] ? "\n  error: {$render['error_message']}" : '');
        return $this->out($render, $opts, $human);
    }

    private function cmdKeyCreate(array $pos, array $opts): int
    {
        $name = $pos[0] ?? '';
        if ($name === '') {
            return $this->err('Usage: php bin/sigil key:create <name> [--json]', 2);
        }
        $key = $this->app->engine()->createApiKey($name);
        $human = "Key created: id={$key['id']} name={$key['name']}\n\nKey: {$key['key']}\n\nStore this now — it is shown only once.";
        return $this->out($key, $opts, $human);
    }

    private function cmdKeyList(array $opts): int
    {
        $keys = $this->app->engine()->listApiKeys();
        $human = "API keys:\n";
        foreach ($keys as $k) {
            $revoked = $k['revoked_at'] !== null ? ' (revoked)' : '';
            $human .= sprintf("  %d  %-16s %s%s\n", $k['id'], $k['name'], $k['key_prefix'], $revoked);
        }
        return $this->out(['keys' => $keys], $opts, $human);
    }

    private function cmdKeyRevoke(array $pos, array $opts): int
    {
        $id = (int) ($pos[0] ?? 0);
        if ($id <= 0) {
            return $this->err('Usage: php bin/sigil key:revoke <id> [--json]', 2);
        }
        if (!$this->app->engine()->revokeApiKey($id)) {
            return $this->err('Key not found or already revoked: ' . $id, 2);
        }
        return $this->out(['revoked' => true, 'id' => $id], $opts, "Key {$id} revoked.");
    }

    private function cmdModels(array $opts): int
    {
        $models = $this->app->engine()->models();
        $default = $this->app->engine()->registry()->defaultProvider();
        $human = '';
        foreach ($models as $m) {
            $human .= sprintf(
                "%-13s %-8s %-16s %s%s\n",
                $m['name'],
                $m['tier'],
                $m['cost_estimate'] > 0 ? sprintf('~$%.2f/image', $m['cost_estimate']) : 'free',
                $m['available'] ? 'ready' : 'needs ' . $m['requires_key'],
                $m['name'] === $default ? '  (default)' : ''
            );
        }
        return $this->out(['models' => $models, 'default' => $default], $opts, rtrim($human));
    }

    private function cmdHealth(array $opts): int
    {
        $h = $this->app->engine()->health();
        // Read bifrost, not the old ollama/openrouter keys. Engine::health() stopped reporting
        // those two when the AI-routes cutover landed — Sigil can no longer ping either provider
        // itself — and this line was left behind, so every `sigil health` printed two PHP
        // "Undefined array key" warnings above a blank value. A health command that emits
        // warnings and misreports its own dependencies is worse than no health command.
        $human = "status: {$h['status']}\ndb: {$h['db']}\nbifrost: {$h['bifrost']}\ndefault model: {$h['default_model']}";
        foreach ($h['models'] as $name => $state) {
            $human .= "\n  {$name}: {$state}";
        }
        return $this->out($h, $opts, $human);
    }
}
