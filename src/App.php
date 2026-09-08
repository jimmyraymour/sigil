<?php
declare(strict_types=1);

namespace Sigil;

/**
 * Tiny service container. Every entry point (web, API, CLI) boots once
 * via bootstrap.php and gets a configured App instance.
 */
final class App
{
    private array $config;
    private array $providers;
    private array $prompts;

    private ?Util\Logger $logger = null;
    private ?Storage\Database $db = null;
    private ?Brief\LlmClient $llm = null;
    private ?Util\UrlSigner $urlSigner = null;
    private ?Api\Auth $auth = null;
    private ?Engine $engine = null;

    public function __construct(array $config, array $providers, array $prompts)
    {
        $this->config = $config;
        $this->providers = $providers;
        $this->prompts = $prompts;
    }

    public function config(): array
    {
        return $this->config;
    }

    public function providers(): array
    {
        return $this->providers;
    }

    public function prompts(): array
    {
        return $this->prompts;
    }

    public function logger(): Util\Logger
    {
        return $this->logger ??= new Util\Logger(SIGIL_STORAGE . '/logs');
    }

    public function db(): Storage\Database
    {
        return $this->db ??= new Storage\Database($this->config['db']);
    }

    public function llm(): Brief\LlmClient
    {
        return $this->llm ??= new Brief\LlmClient($this->config['llm'], $this->logger());
    }

    public function urlSigner(): Util\UrlSigner
    {
        return $this->urlSigner ??= new Util\UrlSigner(Util\Env::get('APP_KEY', 'sigil-dev-insecure-key'));
    }

    public function auth(): Api\Auth
    {
        return $this->auth ??= new Api\Auth(
            new Storage\ApiKeyRepository($this->db()),
            new Util\RateLimiter($this->db()),
            $this->config
        );
    }

    public function engine(): Engine
    {
        return $this->engine ??= new Engine($this);
    }
}
