<?php
declare(strict_types=1);

namespace Sigil\Web\Pages;

use Sigil\App;
use Sigil\Web\Layout;

final class SettingsPage
{
    public function __construct(private App $app)
    {
    }

    public function render(): void
    {
        $engine = $this->app->engine();
        $health = $engine->health();
        $keys = $engine->listApiKeys();
        $prompts = $this->app->prompts();
        $cfg = $this->app->config();

        $healthRows = '';
        foreach ($health as $k => $v) {
            // health['models'] is a per-model map; it has its own table below.
            if (is_array($v)) {
                continue;
            }
            $cls = in_array($v, ['ok', 'disabled', 'missing_key'], true) ? $v : 'down';
            $healthRows .= '<tr><td>' . htmlspecialchars((string) $k) . '</td><td class="' . $cls . '">' . htmlspecialchars((string) $v) . '</td></tr>';
        }

        $keyRows = '';
        if ($keys === []) {
            $keyRows = '<tr><td colspan="4" class="muted">No API keys yet. Create one with <code>php bin/sigil key:create &lt;name&gt;</code>.</td></tr>';
        }
        foreach ($keys as $k) {
            $revoked = $k['revoked_at'] !== null ? ' (revoked)' : '';
            $keyRows .= '<tr><td>' . (int) $k['id'] . '</td><td>' . htmlspecialchars((string) $k['name']) . '</td><td><code>' . htmlspecialchars((string) $k['key_prefix']) . '…</code></td><td>' . htmlspecialchars((string) ($k['last_used_at'] ?? 'never')) . $revoked . '</td></tr>';
        }

        $promptBlocks = '';
        foreach ($prompts as $name => $text) {
            $promptBlocks .= '<h3>' . htmlspecialchars((string) $name) . '</h3><pre>' . htmlspecialchars((string) $text) . '</pre>';
        }

        $defaultModel = htmlspecialchars($engine->registry()->defaultProvider());
        $modelRows = '';
        foreach ($engine->models() as $m) {
            $cost = $m['cost_estimate'] > 0 ? '~$' . number_format($m['cost_estimate'], 2) . ' / image' : 'free';
            $state = $m['available']
                ? '<span class="ok">ready</span>'
                : '<span class="warn">needs ' . htmlspecialchars((string) $m['requires_key']) . '</span>';
            $modelRows .= '<tr><td>' . htmlspecialchars($m['label']) . '</td><td>' . htmlspecialchars($m['tier'])
                . '</td><td>' . $cost . '</td><td>' . $state . '</td></tr>';
        }

        $body = <<<HTML
        <section class="hero">
          <h1>Settings</h1>
          <p>Status, API keys and prompts. Prompts live in <code>config/prompts.php</code> — edit the file and reload.</p>
        </section>

        <div class="settings-grid">
          <div class="panel">
            <h2>Status</h2>
            <table class="kv"><tbody>{$healthRows}</tbody></table>
          </div>

          <div class="panel">
            <h2>API keys</h2>
            <table class="kv"><thead><tr><th>id</th><th>name</th><th>prefix</th><th>last used</th></tr></thead><tbody>{$keyRows}</tbody></table>
            <p class="muted">Create keys from the CLI: <code>php bin/sigil key:create &lt;name&gt;</code></p>
          </div>

          <div class="panel">
            <h2>Models</h2>
            <table class="kv"><thead><tr><th>model</th><th>tier</th><th>cost</th><th>status</th></tr></thead><tbody>{$modelRows}</tbody></table>
            <p class="muted">Premium models stay hidden in the studio until their key is set in <code>.env</code>.</p>
          </div>

          <div class="panel">
            <h2>Guardrails</h2>
            <table class="kv"><tbody>
              <tr><td>Rate limit (requests/min/key)</td><td>{$cfg['rate_limit_per_minute']}</td></tr>
              <tr><td>Renders/hour/key</td><td>{$cfg['rate_limit_renders_per_hour']}</td></tr>
              <tr><td>Daily cost cap (USD)</td><td>{$cfg['cost_limit_usd_per_day']}</td></tr>
              <tr><td>Options per run</td><td>{$cfg['default_variants']} (max {$cfg['max_variants']})</td></tr>
              <tr><td>Default model</td><td>{$defaultModel}</td></tr>
            </tbody></table>
          </div>

          <div class="panel">
            <h2>Prompts</h2>
            {$promptBlocks}
          </div>
        </div>
        HTML;

        Layout::render($this->app, ['title' => 'Settings', 'nav' => 'settings', 'css' => 'base.css', 'body' => $body]);
    }
}
