<?php
declare(strict_types=1);

use Sigil\Util\Env;

/**
 * The model roster. Adding a model is an edit here plus a provider class —
 * no schema migration (renders.provider is a VARCHAR).
 *
 * `tier`: 'free'    — costs nothing, needs no key, safe to use by default.
 *         'premium' — costs money per image, requires a key in .env.
 *
 * `key_env` empty means the provider needs no credentials at all.
 */
return [
    'pollinations' => [
        'label' => 'Pollinations',
        'tier' => 'free',
        'blurb' => 'Free, no API key, no account. Good enough for exploring ideas.',
        'class' => Sigil\Render\PollinationsProvider::class,
        'key_env' => '',
        'formats' => ['png'],
        'default_format' => 'png',
        'cost_estimate' => 0.0,
        'supports_variants' => true,
        'api_url' => Env::get('POLLINATIONS_API_URL', 'https://image.pollinations.ai/prompt/'),
        'model' => Env::get('POLLINATIONS_MODEL', 'flux'),
        'size' => Env::int('POLLINATIONS_SIZE', 1024),
        'timeout' => Env::int('POLLINATIONS_TIMEOUT', 120),
        // Free tier throttles bursts; space the variants out a little.
        'batch_delay' => Env::int('POLLINATIONS_BATCH_DELAY', 3),
    ],

    'gemini' => [
        'label' => 'Nano Banana (Gemini)',
        'tier' => 'premium',
        'blurb' => 'Google Gemini image model. Strong prompt-following. Needs GEMINI_API_KEY.',
        'class' => Sigil\Render\GeminiProvider::class,
        'key_env' => 'GEMINI_API_KEY',
        'formats' => ['png'],
        'default_format' => 'png',
        'cost_estimate' => (float) Env::get('GEMINI_COST_ESTIMATE', '0.04'),
        'supports_variants' => true,
        'api_url' => Env::get('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'model' => Env::get('GEMINI_MODEL', 'gemini-2.5-flash-image'),
        'timeout' => Env::int('GEMINI_TIMEOUT', 120),
    ],

    'recraft' => [
        'label' => 'Recraft',
        'tier' => 'premium',
        'blurb' => 'The only model here that returns true editable SVG. Needs RECRAFT_API_KEY.',
        'class' => Sigil\Render\RecraftProvider::class,
        'key_env' => 'RECRAFT_API_KEY',
        'formats' => ['svg', 'png'],
        'default_format' => 'svg',
        'cost_estimate' => (float) Env::get('RECRAFT_COST_ESTIMATE', '0.04'),
        'supports_variants' => true,
        'api_key_env' => 'RECRAFT_API_KEY',
        'api_url_env' => 'RECRAFT_API_URL',
        'api_url' => 'https://external.api.recraft.ai/v1',
        'model_svg' => Env::get('RECRAFT_MODEL_SVG', 'recraftv4_1_vector'),
        'model_png' => Env::get('RECRAFT_MODEL_PNG', 'recraftv4_1'),
        'style' => Env::get('RECRAFT_STYLE', ''),
        'size' => Env::get('RECRAFT_SIZE', ''),
    ],

    'ideogram' => [
        'label' => 'Ideogram',
        'tier' => 'premium',
        'blurb' => 'Best at rendering readable text — use for wordmarks. Needs IDEOGRAM_API_KEY.',
        'class' => Sigil\Render\IdeogramProvider::class,
        'key_env' => 'IDEOGRAM_API_KEY',
        'formats' => ['png'],
        'default_format' => 'png',
        'cost_estimate' => (float) Env::get('IDEOGRAM_COST_ESTIMATE', '0.10'),
        'supports_variants' => true,
        'api_key_env' => 'IDEOGRAM_API_KEY',
        'api_url_env' => 'IDEOGRAM_API_URL',
        'api_url' => 'https://api.ideogram.ai',
        'path' => '/v1/ideogram-v3/generate',
        'aspect_ratio' => Env::get('IDEOGRAM_ASPECT_RATIO', '1x1'),
        'rendering_speed' => Env::get('IDEOGRAM_RENDERING_SPEED', 'TURBO'),
        'style_type' => Env::get('IDEOGRAM_STYLE_TYPE', 'AUTO'),
    ],
];
