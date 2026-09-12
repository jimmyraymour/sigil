<?php
declare(strict_types=1);

namespace Sigil\Render;

use Sigil\Util\Logger;

/**
 * The image-model roster, sourced from Bifrost::aiRoutes('image') — Bifrost
 * is the only thing that knows which providers exist, what they cost, and
 * whether a key is sealed for them (root CLAUDE.md). What stays local is
 * purely presentational: which output FORMAT a given Bifrost route always
 * produces is a Sigil-side fact (the route itself doesn't report one), which
 * is also why Recraft is two routes here (recraft-svg, recraft-png) rather
 * than one route with a format-dependent model swap.
 */
final class ProviderRegistry
{
    private const FORMATS = [
        'pollinations'       => 'png',
        'sigil-gemini-image' => 'png',
        'recraft-svg'        => 'svg',
        'recraft-png'        => 'png',
        'sigil-ideogram'     => 'png',
    ];

    /**
     * @var array<string,array{route:string,label:string,model:?string,formats:array,
     *   default_format:string,cost_estimate:float,available:bool}>|null
     */
    private ?array $providers = null;

    public function __construct(private Logger $logger)
    {
    }

    /**
     * get()'s and catalog()'s shared per-provider shape: route/model for make(), plus every
     * field Engine.php reads directly off get() (default_format, formats, cost_estimate).
     *
     * @return array<string,array{route:string,label:string,model:?string,formats:array,
     *   default_format:string,cost_estimate:float,available:bool}>
     */
    private function load(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }
        require_once dirname(__DIR__, 3) . '/bifrost/shared/php/BifrostClient.php';

        $out = [];
        foreach (\Bifrost::aiRoutes('image') as $route) {
            $slug = (string) $route['slug'];
            $default = null;
            foreach ((array) $route['models'] as $m) {
                if ($default === null || ($m['is_default'] ?? false)) {
                    $default = $m;
                }
            }
            $format = self::FORMATS[$slug] ?? 'png';
            $out[$slug] = [
                'route'           => $slug,
                'label'           => (string) $route['name'],
                'model'           => $default !== null ? (string) $default['model'] : null,
                'formats'         => [$format],
                'default_format'  => $format,
                'cost_estimate'   => $default !== null && $default['price_per_call'] !== null ? (float) $default['price_per_call'] : 0.0,
                'available'       => (bool) ($route['available'] ?? false),
            ];
        }
        return $this->providers = $out;
    }

    public function has(string $name): bool
    {
        return isset($this->load()[$name]);
    }

    public function get(string $name): array
    {
        if (!$this->has($name)) {
            throw new \RuntimeException('Unknown model: ' . $name);
        }
        return $this->load()[$name];
    }

    public function isAvailable(string $name): bool
    {
        return $this->has($name) && $this->load()[$name]['available'];
    }

    public function names(): array
    {
        return array_keys($this->load());
    }

    /**
     * Provider metadata for the UI / API, free tier first, then premium.
     *
     * @return array<int, array{name: string, label: string, tier: string, blurb: string,
     *                          formats: array, default_format: string, cost_estimate: float,
     *                          available: bool, requires_key: ?string}>
     */
    public function catalog(): array
    {
        $out = [];
        foreach ($this->load() as $name => $cfg) {
            $cost = $cfg['cost_estimate'];
            $out[] = [
                'name'            => $name,
                'label'           => $cfg['label'],
                'tier'            => $cost <= 0.0 ? 'free' : 'premium',
                'blurb'           => '',
                'formats'         => $cfg['formats'],
                'default_format'  => $cfg['default_format'],
                'cost_estimate'   => $cost,
                'available'       => $cfg['available'],
                // No longer an env var name — the key, if any, lives in Bifrost, not in this
                // app's own config. A non-null value only ever meant "this needs one somewhere";
                // it still does, so premium models keep a (generic) truthy marker here.
                'requires_key'    => $cost > 0.0 ? 'bifrost' : null,
            ];
        }
        usort($out, static function (array $a, array $b): int {
            $rank = static fn (array $x): int => ($x['tier'] === 'free' ? 0 : 1);
            return [$rank($a), $a['cost_estimate']] <=> [$rank($b), $b['cost_estimate']];
        });
        return $out;
    }

    /** The cheapest available model — what the UI preselects. */
    public function defaultProvider(): string
    {
        foreach ($this->catalog() as $p) {
            if ($p['available']) {
                return $p['name'];
            }
        }
        return 'pollinations';
    }

    public function make(string $name): RenderProviderInterface
    {
        $cfg = $this->get($name);
        return new BifrostRenderProvider($cfg['route'], $cfg['model'], $this->logger);
    }
}
