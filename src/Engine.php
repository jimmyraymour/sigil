<?php
declare(strict_types=1);

namespace Sigil;

use Sigil\Brief\BriefRefiner;
use Sigil\Brief\BriefRefinementFailed;
use Sigil\Brief\LogoPromptBuilder;
use Sigil\Render\AssetStore;
use Sigil\Render\ImagePostProcessor;
use Sigil\Render\ProviderRegistry;
use Sigil\Render\RenderService;
use Sigil\Storage\ApiKeyRepository;
use Sigil\Storage\BriefRepository;
use Sigil\Storage\RenderRepository;
use Sigil\Util\ApiException;
use Sigil\Util\Uuid;

/**
 * The single orchestrator used by the web UI, the REST API and the CLI.
 *
 * Flow: refineBrief (free) -> iterate (free) -> render a batch of variants.
 * Rendering costs nothing on the free model and real money on the premium
 * ones, so the model choice is always explicit in the request.
 */
final class Engine
{
    private BriefRefiner $refiner;
    private LogoPromptBuilder $promptBuilder;
    private RenderService $renderService;
    private ProviderRegistry $registry;
    private BriefRepository $briefs;
    private RenderRepository $renders;

    /** Used to keep variants distinct when the LLM's concept spread falls short. */
    private const FALLBACK_ANGLES = [
        'as a single enclosed badge',
        'as an open asymmetric mark with no container',
        'built from two overlapping shapes',
        'reduced to one continuous line',
        'as a solid silhouette with cut-out negative space',
        'as a compact stacked arrangement',
        'as a wide horizontal mark',
    ];

    public function __construct(private App $app)
    {
        $cfg = $app->config();
        $this->refiner = new BriefRefiner($app->llm(), $app->prompts(), $cfg['max_brief_chars']);
        $this->promptBuilder = new LogoPromptBuilder($cfg['style_choices']);
        $this->registry = new ProviderRegistry($app->logger());
        $this->renderService = new RenderService(
            new AssetStore(SIGIL_STORAGE . '/renders'),
            $app->logger(),
            $this->registry,
            new ImagePostProcessor($cfg['postprocess'] ?? [], $app->logger())
        );
        $this->briefs = new BriefRepository($app->db());
        $this->renders = new RenderRepository($app->db());
    }

    public function registry(): ProviderRegistry
    {
        return $this->registry;
    }

    /** The model roster for the UI: free tier first, availability included. */
    public function models(): array
    {
        return $this->registry->catalog();
    }

    // ---------------------------------------------------------- brief

    /**
     * Two inputs drive this: what the entity does (required) and what the
     * logo should look like (optional — blank means "suggest something").
     */
    public function refineBrief(array $input, ?int $apiKeyId = null): array
    {
        $cfg = $this->app->config();

        $description = trim((string) ($input['description'] ?? ''));
        if ($description === '') {
            throw ApiException::badRequest('description is required — say what the company, app or organization does');
        }

        $logoDescription = $this->nullableText($input['logo_description'] ?? null);
        $palette = $this->nullableText($input['palette_hint'] ?? null);
        $brandName = $this->nullableText($input['brand_name'] ?? null, 128);

        $entityType = (string) ($input['entity_type'] ?? $cfg['default_entity_type']);
        if (!in_array($entityType, $cfg['entity_types'], true)) {
            throw ApiException::badRequest('invalid entity_type (expected one of: ' . implode(', ', $cfg['entity_types']) . ')');
        }

        $style = (string) ($input['style'] ?? $cfg['default_style']);
        if (!in_array($style, $cfg['style_choices'], true)) {
            throw ApiException::badRequest('invalid style (expected one of: ' . implode(', ', $cfg['style_choices']) . ')');
        }

        try {
            $result = $this->refiner->refine($description, $logoDescription, $entityType, $style, $palette);
        } catch (BriefRefinementFailed $e) {
            $this->app->logger()->error('brief', 'Refinement failed', ['error' => $e->getMessage()]);
            throw ApiException::upstreamError('Concept generation failed: ' . $e->getMessage());
        }

        $id = Uuid::v4();
        $this->briefs->createRoot(
            $id,
            $description,
            $logoDescription,
            $entityType,
            $brandName,
            $result['text'],
            $style,
            $palette,
            $result['model_used']
        );
        $this->app->logger()->info('brief', 'Concept written', ['brief_id' => $id, 'model' => $result['model_used']]);

        return $this->briefs->find($id);
    }

    // ------------------------------------------------------ iterate (free)

    public function iterate(array $input, ?int $apiKeyId = null): array
    {
        $briefId = (string) ($input['brief_id'] ?? '');
        $feedback = trim((string) ($input['feedback'] ?? ''));
        if ($briefId === '') {
            throw ApiException::badRequest('brief_id is required');
        }
        if ($feedback === '') {
            throw ApiException::badRequest('feedback cannot be empty');
        }
        $parent = $this->briefs->find($briefId);
        if ($parent === null) {
            throw ApiException::notFound('Brief not found');
        }

        $prevImage = $this->latestSucceededImageForBrief($briefId);

        $system = $this->app->prompts()['iterate_system'];
        $user = strtr($this->app->prompts()['iterate_user'], [
            '{previous_brief}' => $parent['refined_brief'],
            '{user_message}' => $feedback,
            '{style}' => $parent['style'],
        ]);

        try {
            $result = $this->app->llm()->complete($system, $user, $prevImage);
            $text = $this->refiner->clean($result['text']);
            if ($text === '') {
                throw new BriefRefinementFailed('LLM returned an empty revised concept');
            }
        } catch (BriefRefinementFailed $e) {
            $this->app->logger()->error('iterate', 'Iteration failed', ['error' => $e->getMessage()]);
            throw ApiException::upstreamError('Iteration failed: ' . $e->getMessage());
        }

        $id = Uuid::v4();
        $this->briefs->createChild(
            $id,
            $parent['id'],
            $feedback,
            $text,
            (string) ($parent['entity_type'] ?? 'company'),
            $parent['brand_name'] ?? null,
            $parent['style'],
            $parent['palette_hint'],
            $result['model']
        );
        $this->app->logger()->info('iterate', 'Concept revised', ['brief_id' => $id, 'parent_id' => $parent['id']]);

        return $this->briefs->find($id);
    }

    // ---------------------------------------------------- concepts (free)

    /**
     * Proposes the distinct concepts for a batch without rendering anything.
     *
     * Splitting this out lets the browser render one variant per request. A
     * whole batch in a single call takes minutes and dies on PHP's
     * max_execution_time under Apache (120s here), and it leaves the user
     * staring at a blank panel until every image is done.
     *
     * @return array{batch_id: string, concepts: array<int, string>}
     */
    public function concepts(array $input, ?int $apiKeyId = null): array
    {
        $cfg = $this->app->config();
        $brief = $this->briefs->find((string) ($input['brief_id'] ?? ''));
        if ($brief === null) {
            throw ApiException::notFound('Brief not found');
        }

        $count = (int) ($input['count'] ?? $cfg['default_variants']);
        $count = max(1, min((int) $cfg['max_variants'], $count));

        return [
            'batch_id' => Uuid::v4(),
            'brief_id' => $brief['id'],
            'concepts' => $this->conceptsForBatch($brief, $count),
        ];
    }

    // ------------------------------------------------------------- render

    /**
     * Renders a batch of variants for one brief and returns them all.
     *
     * Variants are not seed jitter: when more than one is asked for, the
     * local LLM (free) first proposes that many genuinely different concepts
     * from the same brief, so the result is a set of options rather than the
     * same mark N times. If that step fails, every variant falls back to the
     * base concept with a different seed.
     *
     * @return array{batch_id: string, renders: array, cost_usd: float,
     *               succeeded: int, failed: int}
     */
    public function render(array $input, ?int $apiKeyId = null): array
    {
        $cfg = $this->app->config();

        $briefId = (string) ($input['brief_id'] ?? '');
        $brief = $this->briefs->find($briefId);
        if ($brief === null) {
            throw ApiException::notFound('Brief not found');
        }

        $provider = (string) ($input['provider'] ?? '');
        if ($provider === '') {
            $provider = (string) ($cfg['default_provider'] ?: $this->registry->defaultProvider());
        }
        if (!$this->registry->has($provider)) {
            throw ApiException::badRequest('unknown model: ' . $provider . ' (expected one of: ' . implode(', ', $this->registry->names()) . ')');
        }
        if (!$this->registry->isAvailable($provider)) {
            throw ApiException::badRequest('model ' . $provider . ' is not configured — add its API key in Bifrost → AI & Keys');
        }

        $providerCfg = $this->registry->get($provider);
        $format = (string) ($input['output_format'] ?? $providerCfg['default_format']);
        if (!in_array($format, (array) $providerCfg['formats'], true)) {
            throw ApiException::badRequest(
                $provider . ' cannot output ' . $format . ' (supports: ' . implode(', ', (array) $providerCfg['formats']) . ')'
            );
        }

        // A caller may hand in one already-chosen concept (the browser does
        // this, one variant per request) instead of asking for a whole batch.
        $singleConcept = $this->nullableText($input['concept'] ?? null);
        $variants = $singleConcept !== null
            ? 1
            : max(1, min((int) $cfg['max_variants'], (int) ($input['variants'] ?? $cfg['default_variants'])));

        $baseSeed = (isset($input['seed']) && $input['seed'] !== null && $input['seed'] !== '')
            ? (int) $input['seed']
            : random_int(1, 999999);

        $this->enforceRenderLimits($apiKeyId, $variants, (float) $providerCfg['cost_estimate']);

        // Free step: turn one concept into N distinct ones (skipped when the
        // caller already picked one).
        $concepts = $singleConcept !== null
            ? [$singleConcept]
            : $this->conceptsForBatch($brief, $variants);

        $batchId = $this->nullableText($input['batch_id'] ?? null, 36) ?? Uuid::v4();
        $indexOffset = max(0, (int) ($input['variant_index'] ?? 0));
        $style = (string) $brief['style'];
        $palette = $brief['palette_hint'] ?? null;
        $brandName = $brief['brand_name'] ?? null;

        // Free endpoints throttle a burst, and a batch is a burst by
        // definition. Spacing the calls costs a few seconds and avoids
        // handing back a half-empty grid.
        $batchDelay = (int) ($providerCfg['batch_delay'] ?? 0);

        // Image generation is slow (~45s each on the free model) and the web
        // SAPI's default ceiling is well under a multi-variant batch.
        if (function_exists('set_time_limit')) {
            @set_time_limit(120 + (count($concepts) * 120));
        }

        $ids = [];
        $usedPrompts = [];
        foreach ($concepts as $i => $concept) {
            if ($i > 0 && $batchDelay > 0) {
                sleep($batchDelay);
            }
            $prompt = $this->promptBuilder->build($concept, $style, $palette, $brandName);

            // A small local model can produce alternatives that differ only
            // after the prompt builder's 160-character concept budget. Do
            // not send duplicate image prompts: append a tested composition
            // angle until this batch contains genuinely distinct requests.
            $angle = 0;
            while (isset($usedPrompts[$prompt]) && $angle < count(self::FALLBACK_ANGLES)) {
                $prompt = $this->promptBuilder->build(
                    $concept . ' Rendered ' . self::FALLBACK_ANGLES[($i + $angle) % count(self::FALLBACK_ANGLES)] . '.',
                    $style,
                    $palette,
                    $brandName
                );
                $angle++;
            }
            $usedPrompts[$prompt] = true;

            $renderId = Uuid::v4();
            $ids[] = $renderId;
            $this->renders->createQueued($renderId, $briefId, $batchId, $indexOffset + $i, $apiKeyId, $provider, $prompt, $format);

            $this->renders->markRendering($renderId);
            try {
                $result = $this->renderService->render($provider, $prompt, $format, $baseSeed + $i, $renderId);
                $this->renders->markSucceeded($renderId, $result);
                $this->app->logger()->info('render', 'Variant rendered', [
                    'render_id' => $renderId,
                    'batch_id' => $batchId,
                    'variant' => $i,
                    'provider' => $provider,
                    'cost_usd' => $result['cost_usd'],
                ]);
            } catch (\Throwable $e) {
                $this->renders->markFailed($renderId, $e->getMessage());
                $this->app->logger()->error('render', 'Variant failed', [
                    'render_id' => $renderId,
                    'batch_id' => $batchId,
                    'variant' => $i,
                    'provider' => $provider,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $rows = array_values(array_filter(
            $this->renders->findBatch($batchId),
            static fn(array $r): bool => in_array($r['id'], $ids, true)
        ));
        $succeeded = 0;
        $failed = 0;
        $cost = 0.0;
        foreach ($rows as $r) {
            if ($r['status'] === 'succeeded') {
                $succeeded++;
                $cost += (float) ($r['cost_usd'] ?? 0);
            } else {
                $failed++;
            }
        }

        // A batch where nothing rendered is a failure, not an empty success —
        // surfacing the provider's own message is far more useful than an
        // empty grid.
        if ($succeeded === 0 && $rows !== []) {
            throw ApiException::upstreamError('All ' . count($rows) . ' variants failed: ' . (string) ($rows[0]['error_message'] ?? 'unknown error'));
        }

        return [
            'batch_id' => $batchId,
            'brief_id' => $briefId,
            'provider' => $provider,
            'renders' => $rows,
            'cost_usd' => round($cost, 4),
            'succeeded' => $succeeded,
            'failed' => $failed,
        ];
    }

    /**
     * @return array<int, string> exactly $variants concepts
     */
    private function conceptsForBatch(array $brief, int $variants): array
    {
        $base = (string) $brief['refined_brief'];
        if ($variants === 1) {
            return [$base];
        }

        $root = $this->briefs->root((string) $brief['id']) ?? $brief;
        $description = (string) ($root['raw_description'] ?? '');
        $entityType = (string) ($brief['entity_type'] ?? 'company');

        $concepts = [];
        try {
            $concepts = $this->refiner->concepts($description, $entityType, $base, $variants);
        } catch (\Throwable $e) {
            $this->app->logger()->warn('render', 'Concept spread failed, using base concept for every variant', [
                'error' => $e->getMessage(),
            ]);
        }

        // Always lead with the concept the user actually reviewed.
        array_unshift($concepts, $base);
        $concepts = array_values(array_unique(array_filter($concepts, static fn(string $c): bool => trim($c) !== '')));

        // A small local model does not always return a parseable list. Padding
        // with the base concept would render the same image N times, so each
        // filler gets a different composition angle instead — the options stay
        // visually distinct even when the LLM step produces nothing usable.
        if (count($concepts) < $variants) {
            $this->app->logger()->warn('render', 'Concept spread under-delivered, padding with composition angles', [
                'wanted' => $variants,
                'got' => count($concepts),
            ]);
        }
        $angles = self::FALLBACK_ANGLES;
        $a = 0;
        while (count($concepts) < $variants) {
            $concepts[] = $base . ' Rendered ' . $angles[$a % count($angles)] . '.';
            $a++;
        }

        return array_slice($concepts, 0, $variants);
    }

    private function enforceRenderLimits(?int $apiKeyId, int $variants, float $unitCost): void
    {
        $cfg = $this->app->config();

        $perHour = (int) $cfg['rate_limit_renders_per_hour'];
        if ($apiKeyId !== null && $perHour > 0) {
            $n = $this->renders->countByKeySince($apiKeyId, '1 HOUR');
            if ($n + $variants > $perHour) {
                throw ApiException::rateLimited('Render limit reached for this key (' . $perHour . '/hour)', 60);
            }
        }

        // Free models never touch the cost cap — the whole point of having one.
        if ($unitCost <= 0.0) {
            return;
        }

        $cap = (float) $cfg['cost_limit_usd_per_day'];
        if ($cap > 0) {
            $spent = $this->renders->sumCostSince('1 DAY');
            $projected = $spent + ($unitCost * $variants);
            if ($projected > $cap) {
                throw ApiException::rateLimited(
                    sprintf(
                        'Daily cost cap would be exceeded: $%.2f spent + $%.2f for this batch > $%.2f cap',
                        $spent,
                        $unitCost * $variants,
                        $cap
                    ),
                    3600
                );
            }
        }
    }

    private function nullableText(mixed $value, int $maxLen = 2000): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        return mb_substr($text, 0, $maxLen);
    }

    // ------------------------------------------------------------ reads

    public function getBrief(string $id): ?array
    {
        return $this->briefs->find($id);
    }

    public function getBriefChain(string $id): array
    {
        return $this->briefs->chain($id);
    }

    public function getRender(string $id): ?array
    {
        return $this->renders->find($id);
    }

    public function getBatch(string $batchId): array
    {
        return $this->renders->findBatch($batchId);
    }

    public function listRenders(int $limit, int $offset): array
    {
        return $this->renders->list($limit, $offset);
    }

    public function countRenders(): int
    {
        return $this->renders->count();
    }

    public function latestSucceededImageForBrief(string $briefId): ?string
    {
        $chain = $this->briefs->chain($briefId);
        $ids = array_column($chain, 'id');
        $render = $this->renders->latestSucceededForBriefIds($ids);
        if ($render === null || empty($render['output_path'])) {
            return null;
        }
        // Ollama vision models read raster images only — SVG can't be passed
        // (and no SVG->PNG converter is guaranteed on the box). Iteration on
        // an SVG render falls back to text-only, which works fine.
        if (!in_array($render['output_format'] ?? '', ['png', 'jpg', 'jpeg'], true)) {
            return null;
        }
        // Vision can be slow on CPU-only boxes; disable with VISION_ITERATION_ENABLED=false.
        if (($this->app->config()['llm']['vision_iteration_enabled'] ?? true) !== true) {
            return null;
        }
        $abs = (new AssetStore(SIGIL_STORAGE . '/renders'))->open($render['output_path']);
        return is_file($abs) ? $abs : null;
    }

    // ---------------------------------------------------------- health

    public function health(): array
    {
        $db = 'down';
        try {
            $this->app->db()->execute('SELECT 1');
            $db = 'ok';
        } catch (\Throwable $e) {
            $this->app->logger()->error('health', 'DB check failed', ['error' => $e->getMessage()]);
        }

        // Sigil no longer talks to Ollama or OpenRouter directly (root CLAUDE.md) — it cannot
        // ping either one itself any more than it can see their keys. Whether Bifrost is
        // reachable and this project's routes are granted is what "models" below actually
        // measures (an empty catalog means one of those two is wrong), so that doubles as the
        // health signal instead of a separate direct check.
        $models = [];
        foreach ($this->registry->catalog() as $p) {
            $models[$p['name']] = $p['available'] ? 'ok' : 'missing_key';
        }
        $bifrost = $models !== [] ? 'ok' : 'down';

        return [
            'status' => ($db === 'ok' && $bifrost === 'ok') ? 'ok' : 'degraded',
            'db' => $db,
            'bifrost' => $bifrost,
            'models' => $models,
            'default_model' => $this->registry->defaultProvider(),
            'time' => date('c'),
        ];
    }

    // ------------------------------------------------------ api keys

    public function createApiKey(string $name): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 64) {
            throw ApiException::badRequest('name is required (max 64 chars)');
        }
        $repo = new ApiKeyRepository($this->app->db());
        $key = 'sigil_' . bin2hex(random_bytes(20));
        $prefix = substr($key, 6, 8);
        $id = $repo->create($name, hash('sha256', $key), $prefix);
        return ['id' => $id, 'name' => $name, 'key' => $key, 'key_prefix' => $prefix];
    }

    public function listApiKeys(): array
    {
        return (new ApiKeyRepository($this->app->db()))->all();
    }

    public function revokeApiKey(int $id): bool
    {
        return (new ApiKeyRepository($this->app->db()))->revoke($id);
    }
}
