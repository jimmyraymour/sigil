<?php
declare(strict_types=1);

use Sigil\Util\Env;

return [
    'app' => [
        'env' => Env::get('APP_ENV', 'development'),
        'debug' => Env::bool('APP_DEBUG', true),
        'base_url' => Env::get('APP_BASE_URL', 'http://127.0.0.1:8001'),
        'timezone' => Env::get('APP_TIMEZONE', 'America/Santo_Domingo'),
        'web_api_key' => Env::get('WEB_API_KEY', ''),
    ],

    'db' => [
        'host' => Env::get('DB_HOST', '127.0.0.1'),
        'port' => Env::get('DB_PORT', '3306'),
        'name' => Env::get('DB_NAME', 'sigil'),
        'user' => Env::get('DB_USER', 'sigil'),
        'pass' => Env::get('DB_PASS', ''),
        'charset' => 'utf8mb4',
    ],

    'llm' => [
        'ollama_url' => Env::get('OLLAMA_URL', 'http://127.0.0.1:11434'),
        // Text authoring wants a text model. The old build used a 3B *vision*
        // model here and it wrote stock-photo prose instead of logo concepts.
        // llama3.1:8b writes better but needs ~5GB and will not load on a 7GB
        // box alongside MySQL — qwen2.5:3b is the largest that fits.
        'ollama_model' => Env::get('OLLAMA_MODEL', 'qwen2.5:3b'),
        // Vision is only used to look at a previous render during iteration.
        'ollama_vision_model' => Env::get('OLLAMA_VISION_MODEL', 'qwen2.5vl:3b'),
        'ollama_timeout' => Env::int('OLLAMA_TIMEOUT', 120),
        'openrouter_api_key' => Env::get('OPENROUTER_API_KEY', ''),
        'openrouter_model' => Env::get('OPENROUTER_MODEL', 'meta-llama/llama-3.1-8b-instruct:free'),
        'temperature' => (float) Env::get('LLM_TEMPERATURE', '0.7'),
        'ollama_vision' => Env::bool('OLLAMA_VISION', true),
    ],

    'http' => [
        'timeout' => Env::int('HTTP_TIMEOUT_SECONDS', 60),
        'user_agent' => Env::get('HTTP_USER_AGENT', 'Sigil/1.0'),
    ],

    'rate_limit_per_minute' => Env::int('RATE_LIMIT_PER_MINUTE', 60),
    'rate_limit_renders_per_hour' => Env::int('RATE_LIMIT_RENDERS_PER_HOUR', 30),
    'cost_limit_usd_per_day' => (float) Env::get('COST_LIMIT_USD_PER_DAY', '5.00'),
    'asset_url_ttl_seconds' => Env::int('ASSET_URL_TTL_SECONDS', 3600),

    // Image models routinely ignore "solid white background" — the free one
    // returns marks on flat grey. This corrects that arithmetically after the
    // render, and is conservative: a dark or tinted field is left alone.
    'postprocess' => [
        'enabled' => Env::bool('POSTPROCESS_ENABLED', true),
        'trim' => Env::bool('POSTPROCESS_TRIM', true),
        'transparent' => Env::bool('POSTPROCESS_TRANSPARENT', false),
    ],

    'max_brief_chars' => 800,

    // 'auto' lets the concept pick its own construction — the right default
    // when the point is "suggest me something".
    'style_choices' => [
        'auto', 'wordmark', 'lettermark', 'monogram', 'abstract',
        'pictorial', 'combination', 'emblem', 'mascot',
    ],
    'default_style' => Env::get('DEFAULT_STYLE', 'auto'),

    // Sigil is not app-specific: the entity type only steers the concept.
    'entity_types' => [
        'company', 'app', 'organization', 'nonprofit', 'political',
        'product', 'event', 'personal', 'other',
    ],
    'default_entity_type' => Env::get('DEFAULT_ENTITY_TYPE', 'company'),

    // One run produces a set to choose from, not a single take.
    'default_variants' => Env::int('DEFAULT_VARIANTS', 4),
    'max_variants' => Env::int('MAX_VARIANTS', 8),

    // Empty means "cheapest model that is actually usable right now", which
    // the ProviderRegistry resolves at request time.
    'default_provider' => Env::get('DEFAULT_PROVIDER', ''),
];
