<?php
declare(strict_types=1);

namespace Sigil\Web\Pages;

use Sigil\App;
use Sigil\Web\Layout;
use Sigil\Util\BasePath;

final class HomePage
{
    public function __construct(private App $app)
    {
    }

    public function render(): void
    {
        $cfg = $this->app->config();
        $engine = $this->app->engine();

        $entityOptions = $this->options($cfg['entity_types'], $cfg['default_entity_type']);
        $styleOptions = $this->options($cfg['style_choices'], $cfg['default_style'], [
            'auto' => 'auto — let the concept decide',
        ]);

        $models = $engine->models();
        $default = $engine->registry()->defaultProvider();
        $modelCards = '';
        foreach ($models as $m) {
            $modelCards .= $this->modelCard($m, $m['name'] === $default);
        }

        $variantOptions = '';
        for ($i = 1; $i <= (int) $cfg['max_variants']; $i++) {
            $sel = $i === (int) $cfg['default_variants'] ? ' selected' : '';
            $variantOptions .= '<option value="' . $i . '"' . $sel . '>' . $i . '</option>';
        }

        $jsUrl = BasePath::url('/assets/js/chat.js');

        $body = <<<HTML
        <section class="hero">
          <h1>Sigil</h1>
          <p>Describe what it does. Get logo options back. The default model is free.</p>
        </section>

        <div class="studio">
          <div class="panel brief-panel">
            <h2><span class="step">1</span> Describe it</h2>

            <label class="field">
              <span class="field-label">What does it do? <em>required</em></span>
              <textarea id="description" rows="4"
                placeholder="A community land trust that buys farmland and leases it affordably to first-generation farmers."></textarea>
            </label>

            <label class="field">
              <span class="field-label">What should the logo look like? <em>optional</em></span>
              <textarea id="logo-description" rows="3"
                placeholder="Leave this blank and Sigil will propose the concepts itself. Or be specific: a single oak leaf, deep green, no text."></textarea>
            </label>

            <div class="row wrap">
              <label>Name <span class="hint">for wordmarks</span>
                <input id="brand-name" placeholder="Greenfield Trust" autocomplete="off">
              </label>
              <label>Type
                <select id="entity-type">{$entityOptions}</select>
              </label>
              <label>Style
                <select id="style">{$styleOptions}</select>
              </label>
              <label>Palette <span class="hint">optional</span>
                <input id="palette" placeholder="deep green + bone white" autocomplete="off">
              </label>
            </div>

            <button id="generate-btn" class="btn primary wide">Write the concept — free</button>

            <div id="brief-box" class="brief-box hidden">
              <h3>Concept</h3>
              <div id="brief-text" class="brief-text"></div>
            </div>
          </div>

          <div class="panel chat-panel">
            <h2><span class="step">2</span> Refine <span class="tag free">free</span></h2>
            <p class="muted small">Reworking the concept costs nothing — it runs on your local model. Only generating images can cost money.</p>
            <div id="chat" class="chat"><p class="muted">Write a concept first.</p></div>
            <form id="feedback-form" class="row hidden">
              <input id="feedback" placeholder="make it bolder, drop the circle, try a seed shape…" autocomplete="off">
              <button type="submit" class="btn">Send</button>
            </form>
          </div>

          <div class="panel render-panel">
            <h2><span class="step">3</span> Generate</h2>

            <div class="model-picker" id="model-picker">{$modelCards}</div>

            <div class="row wrap render-controls">
              <label>Options
                <select id="variants">{$variantOptions}</select>
              </label>
              <label>Format
                <select id="format"></select>
              </label>
              <button id="render-btn" class="btn primary" disabled>Generate logos</button>
            </div>
            <div id="cost-note" class="muted small"></div>

            <div id="render-status" class="muted"></div>
            <div id="results" class="results"></div>
          </div>
        </div>

        <script type="module" src="{$jsUrl}"></script>
        HTML;

        Layout::render($this->app, ['title' => 'Sigil — logo generator', 'nav' => 'studio', 'css' => 'chat.css', 'body' => $body]);
    }

    /**
     * A model is only selectable when its key is present — offering a choice
     * that is guaranteed to fail is how the old build ended up looking done
     * while rendering nothing.
     */
    private function modelCard(array $m, bool $checked): string
    {
        $name = htmlspecialchars($m['name']);
        $label = htmlspecialchars($m['label']);
        $blurb = htmlspecialchars($m['blurb']);
        $free = $m['tier'] === 'free';
        $cost = $free ? 'free' : '~$' . number_format($m['cost_estimate'], 2) . ' / image';
        $disabled = $m['available'] ? '' : ' disabled';
        $classes = 'model-card' . ($m['available'] ? '' : ' unavailable');
        $tag = $free ? '<span class="tag free">free</span>' : '<span class="tag premium">paid</span>';

        $note = $m['available']
            ? ''
            : '<div class="model-missing">Set <code>' . htmlspecialchars((string) $m['requires_key']) . '</code> in .env to enable</div>';

        $formats = htmlspecialchars(implode(',', $m['formats']));
        $defaultFormat = htmlspecialchars($m['default_format']);
        $checkedAttr = ($checked && $m['available']) ? ' checked' : '';

        return <<<HTML
        <label class="{$classes}">
          <input type="radio" name="model" value="{$name}" data-formats="{$formats}"
                 data-default-format="{$defaultFormat}" data-cost="{$m['cost_estimate']}"{$checkedAttr}{$disabled}>
          <span class="model-body">
            <span class="model-head">{$label} {$tag}<span class="model-cost">{$cost}</span></span>
            <span class="model-blurb">{$blurb}</span>
            {$note}
          </span>
        </label>
        HTML;
    }

    private function options(array $values, string $selected, array $labels = []): string
    {
        $out = '';
        foreach ($values as $v) {
            $label = $labels[$v] ?? $v;
            $sel = $v === $selected ? ' selected' : '';
            $out .= '<option value="' . htmlspecialchars($v) . '"' . $sel . '>' . htmlspecialchars($label) . '</option>';
        }
        return $out;
    }
}
