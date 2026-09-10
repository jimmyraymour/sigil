<?php
declare(strict_types=1);

namespace Sigil\Render;

use Sigil\Util\Env;
use Sigil\Util\Logger;

/**
 * Reads config/providers.php and answers "what can this box actually run
 * right now?". A provider is *available* when it needs no key, or when its
 * key is present in .env — the UI greys out the rest instead of offering a
 * choice that is guaranteed to fail.
 */
final class ProviderRegistry
{
    public function __construct(private array $providers, private Logger $logger)
    {
    }

    public function has(string $name): bool
    {
        return isset($this->providers[$name]);
    }

    public function get(string $name): array
    {
        if (!$this->has($name)) {
            throw new \RuntimeException('Unknown model: ' . $name);
        }
        return $this->providers[$name];
    }


/** env var -> central Bifrost credential slot (Phase 5). */
const CENTRAL_SLOTS = [
    'OPENROUTER_API_KEY' => 'openrouter',
    'GEMINI_API_KEY'     => 'gemini',
    'GOOGLE_API_KEY'     => 'gemini',
    'RECRAFT_API_KEY'    => 'recraft',
    'IDEOGRAM_API_KEY'   => 'ideogram',
];

/**
 * A provider key: Bifrost first, the local env var as the migration fallback.
 * Memoized per request by the client, so repeat calls cost nothing.
 */
public static function centralKey(string $keyEnv): string
{
    if ($keyEnv === '') {
        return '';
    }
    $local = (string) Env::get($keyEnv, '');
    $slot = self::CENTRAL_SLOTS[$keyEnv] ?? null;
    if ($slot === null || !class_exists('Bifrost', false)) {
        return $local;
    }
    try {
        return \Bifrost::secret($slot, $keyEnv);
    } catch (\Throwable) {
        return $local;
    }
}

    public function isAvailable(string $name): bool
    {
        if (!$this->has($name)) {
            return false;
        }
        $keyEnv = (string) ($this->providers[$name]['key_env'] ?? '');
        return $keyEnv === '' || self::centralKey($keyEnv) !== '';
    }

    public function names(): array
    {
        return array_keys($this->providers);
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
        foreach ($this->providers as $name => $cfg) {
            $keyEnv = (string) ($cfg['key_env'] ?? '');
            $out[] = [
                'name' => $name,
                'label' => (string) ($cfg['label'] ?? $name),
                'tier' => (string) ($cfg['tier'] ?? 'premium'),
                'blurb' => (string) ($cfg['blurb'] ?? ''),
                'formats' => (array) ($cfg['formats'] ?? ['png']),
                'default_format' => (string) ($cfg['default_format'] ?? 'png'),
                'cost_estimate' => (float) ($cfg['cost_estimate'] ?? 0.0),
                'available' => $this->isAvailable($name),
                'requires_key' => $keyEnv === '' ? null : $keyEnv,
            ];
        }
        usort($out, static function (array $a, array $b): int {
            $rank = static fn(array $x): int => ($x['tier'] === 'free' ? 0 : 1);
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
        $class = (string) ($cfg['class'] ?? '');
        if ($class === '' || !class_exists($class)) {
            throw new \RuntimeException('Model ' . $name . ' has no provider class');
        }
        $obj = new $class($cfg, $this->logger);
        if (!$obj instanceof RenderProviderInterface) {
            throw new \RuntimeException($class . ' does not implement RenderProviderInterface');
        }
        return $obj;
    }
}
