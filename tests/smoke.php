<?php
declare(strict_types=1);

/**
 * Sigil smoke test. Run with:
 *   php tests/smoke.php
 *
 * This exercises the real pipeline: the local LLM writes a concept and the
 * free model generates actual images. It deliberately has no placeholder
 * provider — the previous build passed its smoke test while never producing
 * a logo, because the only thing it rendered was a stub rectangle.
 *
 * Needs Ollama running and outbound HTTPS. Takes a couple of minutes.
 * Exit code 0 = all checks passed, 1 = a check failed.
 */

$app = require dirname(__DIR__) . '/bootstrap.php';
$engine = $app->engine();

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok   $label\n";
    } else {
        $fail++;
        echo "  FAIL $label" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

/**
 * Run a suite section, turning any uncaught throwable into ONE failed check instead of a fatal.
 *
 * This is the fix for the behaviour recorded in item 3496 / 2518: a thrown ApiException at
 * `iterate` used to kill the process with exit 255 and a PHP fatal, so every check after it —
 * the real renders, the post-processing assertions and the four Phase 17 guard rails — was
 * never exercised and no summary ever printed. A provider outage therefore looked like "the
 * suite was never run" rather than "these N checks could not run", and the tracker sat at
 * partial with no evidence either way.
 *
 * Sections that genuinely depend on an upstream call are reported as failed with the reason,
 * and the run continues. A section that is a pure in-process assertion cannot throw in normal
 * operation, so nothing is being hidden: anything caught here is a real failure.
 */
function section(string $title, callable $body): void
{
    global $fail;
    echo $title . "\n";
    try {
        $body();
    } catch (\Throwable $e) {
        $fail++;
        echo '  FAIL ' . $title . ' could not run — ' . get_class($e) . ': '
            . str_replace("\n", ' ', $e->getMessage()) . "\n";
    }
}

echo "Sigil smoke test\n";

// 1. Health -----------------------------------------------------------
echo "health\n";
$health = $engine->health();
check('db ok', ($health['db'] ?? '') === 'ok', json_encode($health));
check('bifrost ok', ($health['bifrost'] ?? '') === 'ok', 'Bifrost must be reachable and this project must have granted routes');
check('a free model is available', ($health['models']['pollinations'] ?? '') === 'ok');
check('default model needs no key', $engine->registry()->isAvailable($health['default_model']));

// 2. Model catalog ----------------------------------------------------
echo "models\n";
$models = $engine->models();
check('catalog is not empty', $models !== []);
check('free tier is listed first', ($models[0]['tier'] ?? '') === 'free');
check('every model declares a cost', array_reduce($models, static fn($c, $m) => $c && isset($m['cost_estimate']), true));
check('premium models are gated on a key', array_reduce(
    $models,
    static fn($c, $m) => $c && ($m['tier'] !== 'premium' || $m['requires_key'] !== null),
    true
));

// 3-5. The LLM-backed sections -----------------------------------------
// refineBrief / iterate / render all call out to Bifrost, so any of them can throw when a
// provider is down. They share one guard: on the first failure, record it and stop entering
// further upstream sections rather than cascading the same error three times.
$brief = $iterated = $batch = null;

section('concept', static function () use ($engine, &$brief): void {
    $brief = $engine->refineBrief([
        'description' => 'A neighbourhood bicycle repair co-operative run by volunteers',
        'logo_description' => 'a single bicycle gear, very simple, one colour',
        'entity_type' => 'organization',
        'brand_name' => 'Spoke',
        'style' => 'auto',
    ]);
    check('concept has id', isset($brief['id']) && strlen($brief['id']) === 36);
    check('concept text is 40-800 chars', mb_strlen($brief['refined_brief']) >= 40 && mb_strlen($brief['refined_brief']) <= 800);
    check('concept ran on the local model', str_starts_with((string) $brief['model_used'], 'local-ollama-01:'));
    check('second field was stored', $brief['logo_description'] !== null);
    check('entity type was stored', $brief['entity_type'] === 'organization');
    check('no label blocks leaked through', !preg_match('/^(subject|style|palette|mood)\s*:/im', $brief['refined_brief']));
    check('concept is a single paragraph', !str_contains($brief['refined_brief'], "\n"));
    check('concept is English only', !preg_match('/[\x{3000}-\x{9FFF}]/u', $brief['refined_brief']));

    // 4. Concept with the logo field left blank
    $suggested = $engine->refineBrief([
        'description' => 'A political campaign for a candidate running on housing affordability',
        'entity_type' => 'political',
    ]);
    check('works with no logo description', mb_strlen($suggested['refined_brief']) >= 40);
    check('works for non-company entities', $suggested['entity_type'] === 'political');
});

if ($brief !== null) {
    section('iterate', static function () use ($engine, $brief, &$iterated): void {
        $iterated = $engine->iterate(['brief_id' => $brief['id'], 'feedback' => 'drop the outer circle, make it bolder']);
        check('iterate returns a child', ($iterated['parent_brief_id'] ?? '') === $brief['id']);
        check('iterate changed the concept', $iterated['refined_brief'] !== $brief['refined_brief']);
        check('iterate carried the brand name', $iterated['brand_name'] === $brief['brand_name']);
    });
}

// 6. Render a batch on the free model ---------------------------------
if ($brief !== null) {
    section('render (free model, real images)', static function () use ($engine, $brief, &$batch): void {
        $batch = $engine->render(['brief_id' => $brief['id'], 'provider' => 'pollinations', 'variants' => 2]);
        check('batch has an id', strlen($batch['batch_id']) === 36);
        check('batch cost nothing', $batch['cost_usd'] === 0.0);
        check('at least one variant succeeded', $batch['succeeded'] >= 1, json_encode($batch['renders'][0]['error_message'] ?? ''));
        check('batch returned both variants', count($batch['renders']) === 2);

        $store = new Sigil\Render\AssetStore(SIGIL_STORAGE . '/renders');
        foreach ($batch['renders'] as $r) {
            if (($r['status'] ?? '') !== 'succeeded') {
                continue;
            }
            $abs = $store->open($r['output_path']);
            check('variant ' . $r['variant_index'] . ' file exists', is_file($abs));
            check('variant ' . $r['variant_index'] . ' is a real image', (bool) @getimagesize($abs));
            // The whole point: the bytes must be a generated image, not a placeholder.
            check('variant ' . $r['variant_index'] . ' is larger than a placeholder', filesize($abs) > 5000, filesize($abs) . ' bytes');
            check('variant ' . $r['variant_index'] . ' prompt carries logo constraints', str_contains($r['prompt_sent'], 'flat vector logo'));
        }

        if ($batch['succeeded'] >= 2) {
            check('variants used different prompts', $batch['renders'][0]['prompt_sent'] !== $batch['renders'][1]['prompt_sent']);
        }
    });
}

// 6b. Post-processing -------------------------------------------------
echo "post-processing\n";
$pp = new Sigil\Render\ImagePostProcessor(['enabled' => true, 'trim' => true, 'transparent' => false]);

// A flat grey field is what the free model actually returns; it must end white.
$grey = imagecreatetruecolor(200, 200);
imagefilledrectangle($grey, 0, 0, 199, 199, imagecolorallocate($grey, 215, 215, 215));
imagefilledellipse($grey, 100, 100, 90, 90, imagecolorallocate($grey, 20, 20, 20));
ob_start(); imagejpeg($grey, null, 92); $greyBytes = (string) ob_get_clean();
$out = $pp->process($greyBytes, 'jpg');
check('grey background is corrected', $out['changed'] === true);
check('corrected output is png', $out['format'] === 'png');
if ($out['changed']) {
    $im = imagecreatefromstring($out['bytes']);
    $corner = imagecolorat($im, 1, 1);
    check('corner is pure white', (($corner >> 16) & 255) === 255 && (($corner >> 8) & 255) === 255 && ($corner & 255) === 255);
}

// A deliberately dark field is a design choice and must survive untouched.
$dark = imagecreatetruecolor(200, 200);
imagefilledrectangle($dark, 0, 0, 199, 199, imagecolorallocate($dark, 18, 20, 28));
imagefilledellipse($dark, 100, 100, 90, 90, imagecolorallocate($dark, 240, 240, 240));
ob_start(); imagejpeg($dark, null, 92); $darkBytes = (string) ob_get_clean();
$darkOut = $pp->process($darkBytes, 'jpg');
check('dark background is left alone', $darkOut['changed'] === false);

// SVG must never be rasterised.
$svgOut = $pp->process('<svg xmlns="http://www.w3.org/2000/svg"><rect width="10" height="10"/></svg>', 'svg');
check('svg passes through untouched', $svgOut['changed'] === false && $svgOut['format'] === 'svg');

// 7. Chain integrity ---------------------------------------------------
// Depends on `iterate` having produced a child, so it is skipped (and counted as a skipped
// section, not a silent pass) when the upstream section failed.
if ($iterated !== null) {
    section('chain', static function () use ($engine, $iterated): void {
        $chain = $engine->getBriefChain($iterated['id']);
        check('chain has 2 briefs', count($chain) === 2);
        check('chain root holds the description', $chain[0]['raw_description'] !== null);
        check('chain child holds the feedback', $chain[1]['feedback'] !== null);
    });
} else {
    echo "chain\n  SKIP chain — no iterated brief (upstream section failed)\n";
}

// 8. Guard rails -------------------------------------------------------
// The first rail (empty description) is self-contained. The rest need a real brief id, so they
// run only when the concept section produced one.
section('guards (self-contained)', static function () use ($engine): void {
    try {
        $engine->refineBrief(['description' => '']);
        check('empty description is rejected', false);
    } catch (Sigil\Util\ApiException $e) {
        check('empty description is rejected', true);
    }
});

if ($brief !== null) {
    section('guards (spend guards)', static function () use ($engine, $brief): void {
        $briefId = $brief['id'];
        try {
            $engine->render(['brief_id' => $briefId, 'provider' => 'recraft-svg']);
            check('unconfigured model is refused before spending', false);
        } catch (Sigil\Util\ApiException $e) {
            check('unconfigured model is refused before spending', str_contains($e->getMessage(), 'Bifrost'));
        }
        try {
            $engine->render(['brief_id' => $briefId, 'provider' => 'pollinations', 'output_format' => 'svg']);
            check('unsupported format is refused', false);
        } catch (Sigil\Util\ApiException $e) {
            check('unsupported format is refused', str_contains($e->getMessage(), 'cannot output'));
        }
        try {
            $engine->render(['brief_id' => $briefId, 'provider' => 'nonexistent']);
            check('unknown model is refused', false);
        } catch (Sigil\Util\ApiException $e) {
            check('unknown model is refused', true);
        }
    });
} else {
    echo "guards (spend guards)\n  SKIP — no brief to guard against (upstream section failed)\n";
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
