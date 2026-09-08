# Sigil — Build Specification

A build spec for a coding agent. Implement exactly what is described here. No extra features, no extra dependencies.

---

## 1. Purpose

Sigil is a **logo-generation tool**. Given a project description, it produces a tight image brief via a local LLM, hands the brief to a hosted image model, returns a rendered logo (PNG and/or SVG), and supports chat-style iteration on that logo against the same model.

It is the second half of a two-tool system. The first is **NameSweep** (name clearance) — kept in a separate codebase with no shared runtime, no shared data, no shared auth.

**Primary user:** the owner, via the web UI for the chat-iteration flow, via the CLI for scripted generation, and via the REST API so a coding agent working on the brand project can call it programmatically.

---

## 2. Why this tool exists (do not reframe)

The source spec is explicit: **local image generation on CPU-only VPSes is out** for any usable model (Flux.1 is the only self-hostable option and it needs a GPU; Recraft / Ideogram / Firefly are hosted-only). OpenRouter credits don't cover image models. So the rendering step is a hosted API call.

What stays local and free is the **LLM layer** — refining a sloppy human description into a tight image brief, and handling the chat-iteration feedback. Only the final render costs money.

The two provider choices:
- **Recraft** — the only mainstream model with true editable SVG output. Default for actual logo files.
- **Ideogram 3** — best wordmark / text accuracy (~90–95%) when the brand name-as-text is the point.

Both cost roughly $0.03–$0.10 per image. The coding agent's job is to wire the choice cleanly so the owner can switch per request.

---

## 3. Tech Stack (locked)

| Layer | Choice | Why |
|------|--------|-----|
| Language | **PHP 8.1+** (vanilla, no framework) | Matches NameSweep and the owner's other SaaS. |
| Web server | PHP built-in (dev) / nginx + PHP-FPM (prod) | Same as NameSweep. |
| Database | **MySQL 8.x** | Same as NameSweep — separate database (`sigil`), not shared. |
| HTTP client | cURL via thin wrapper | No Guzzle, no SDKs. |
| Autoloading | Composer autoloader only | PSR-4. |
| Frontend | Plain JavaScript (ES6 modules), plain CSS | No React/Vue/Svelte. |
| Icons | Inline SVG only | No icon libraries. |
| Image storage | Local filesystem under `storage/renders/` | No S3, no cloud. The owner has a small VPS and a small library. |
| LLM (brief refinement, chat iteration) | **Ollama** (primary, local), **OpenRouter** (fallback) | Owner already runs Ollama on the box. |
| Image model | **Recraft** (default) and **Ideogram** (alternative) | Both via hosted HTTP API. |

**Composer dependencies (composer.json):**
```json
{
    "name": "owner/sigil",
    "type": "project",
    "require": {
        "php": ">=8.1",
        "ext-curl": "*",
        "ext-pdo": "*",
        "ext-json": "*",
        "ext-mbstring": "*",
        "ext-fileinfo": "*"
    },
    "autoload": {
        "psr-4": {
            "Sigil\\": "src/"
        }
    }
}
```

**No other Composer packages. Do not add any.**

---

## 4. Directory Structure

```
/workspace/sigil/
├── public/
│   ├── index.php                 # Web UI entry
│   ├── api.php                   # REST API entry
│   ├── render.php                # Serves a generated asset by ID (auth-gated)
│   ├── .htaccess
│   └── assets/
│       ├── css/
│       │   ├── base.css
│       │   ├── chat.css
│       │   └── library.css
│       └── js/
│           ├── chat.js           # Chat-iteration UI
│           ├── library.js        # Library list
│           └── api.js            # Thin fetch wrapper
├── src/
│   ├── Engine.php                # Top-level orchestrator
│   ├── Brief/
│   │   ├── BriefRefiner.php
│   │   └── LlmClient.php         # Ollama + OpenRouter
│   ├── Render/
│   │   ├── RenderService.php     # Picks provider, calls it, saves asset
│   │   ├── RecraftProvider.php
│   │   ├── IdeogramProvider.php
│   │   ├── AssetStore.php        # Filesystem-backed
│   │   └── ImageMetadata.php     # Reads PNG/SVG headers for size etc.
│   ├── Iterate/
│   │   ├── IterationService.php  # Chat-iteration loop
│   │   └── FeedbackParser.php    # LLM-assisted parsing of "more like X" feedback
│   ├── Storage/
│   │   ├── Database.php
│   │   ├── BriefRepository.php
│   │   ├── RenderRepository.php
│   │   ├── IterationRepository.php
│   │   └── ApiKeyRepository.php
│   ├── Api/
│   │   ├── Router.php
│   │   ├── Auth.php
│   │   ├── BriefController.php
│   │   ├── RenderController.php
│   │   └── IterateController.php
│   ├── Cli/
│   │   └── Command.php
│   ├── Web/
│   │   ├── Pages/
│   │   │   ├── HomePage.php
│   │   │   ├── LibraryPage.php
│   │   │   └── SettingsPage.php
│   │   └── Layout.php
│   └── Util/
│       ├── HttpClient.php
│       ├── Logger.php
│       ├── Env.php
│       ├── RateLimiter.php
│       └── Uuid.php
├── bin/
│   └── sigil                     # CLI entry
├── config/
│   ├── app.php
│   ├── providers.php             # Recraft + Ideogram credentials + endpoints
│   └── prompts.php
├── database/
│   ├── schema.sql
│   └── seed.sql
├── storage/
│   ├── logs/
│   ├── renders/                  # Generated assets
│   │   └── .gitkeep
│   └── tmp/
├── .env.example
├── .gitignore
├── composer.json
└── README.md
```

**Every file listed above is required.**

---

## 5. Database Schema (MySQL 8)

Database name: `sigil`. Separate from `namesweep`. Charset `utf8mb4`, collation `utf8mb4_0900_ai_ci`.

### 5.1 `api_keys`

Same shape as NameSweep's, but the keys are independent. A key issued for NameSweep does **not** work against Sigil and vice versa.

```sql
CREATE TABLE api_keys (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(64)  NOT NULL,
    key_hash      CHAR(64)     NOT NULL,
    key_prefix    CHAR(8)      NOT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at  DATETIME     NULL,
    revoked_at    DATETIME     NULL,
    UNIQUE KEY uniq_key_hash (key_hash),
    KEY idx_prefix (key_prefix)
) ENGINE=InnoDB;
```

### 5.2 `briefs`

The refined image brief. One row per refine call.

```sql
CREATE TABLE briefs (
    id              CHAR(36)        NOT NULL PRIMARY KEY,              -- UUIDv4
    raw_description TEXT            NOT NULL,                          -- what the user typed
    refined_brief   TEXT            NOT NULL,                          -- LLM-refined
    style           ENUM('wordmark','lettermark','abstract','pictorial','combination','emoticon') NOT NULL DEFAULT 'combination',
    palette_hint    VARCHAR(255)    NULL,                              -- e.g. "deep blue + warm white"
    model_used      VARCHAR(64)     NOT NULL,                          -- 'ollama:llama3.1:8b' / 'openrouter:...'
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_created (created_at)
) ENGINE=InnoDB;
```

### 5.3 `renders`

One row per generated asset.

```sql
CREATE TABLE renders (
    id              CHAR(36)        NOT NULL PRIMARY KEY,
    brief_id        CHAR(36)        NOT NULL,
    provider        ENUM('recraft','ideogram') NOT NULL,
    provider_job_id VARCHAR(128)    NULL,                              -- returned by hosted API, if any
    prompt_sent     TEXT            NOT NULL,                          -- exact prompt sent to the image API
    output_format   ENUM('png','svg') NOT NULL,
    output_path     VARCHAR(512)    NOT NULL,                          -- relative to storage/
    output_url      VARCHAR(512)    NULL,                              -- populated by some providers
    width_px        INT UNSIGNED    NULL,
    height_px       INT UNSIGNED    NULL,
    cost_usd        DECIMAL(6,4)    NULL,                              -- logged if provider returns it
    status          ENUM('queued','rendering','succeeded','failed') NOT NULL DEFAULT 'queued',
    error_message   TEXT            NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at    DATETIME        NULL,
    KEY idx_brief (brief_id),
    KEY idx_status (status),
    CONSTRAINT fk_render_brief FOREIGN KEY (brief_id) REFERENCES briefs(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

### 5.4 `iterations`

A chat thread. Each message in the chat is a row. The owner can iterate on a render by sending free-form feedback; the LLM rewrites the prompt for the next render, and a new `renders` row is created. Iteration is a linked list.

```sql
CREATE TABLE iterations (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    parent_render_id CHAR(36)       NOT NULL,                         -- the previous render
    child_render_id  CHAR(36)       NULL,                              -- filled when the user accepts / requests another
    role            ENUM('user','assistant','system') NOT NULL,
    content         TEXT            NOT NULL,                          -- human-readable message
    llm_rewritten_prompt TEXT       NULL,                              -- what the LLM extracted as the next image prompt
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_parent (parent_render_id),
    KEY idx_child  (child_render_id)
) ENGINE=InnoDB;
```

The first message in a thread has `parent_render_id` pointing at the original render; the row's `content` is the user's first feedback.

### 5.5 `projects` (optional, owner convenience)

```sql
CREATE TABLE projects (
    id          CHAR(36)        NOT NULL PRIMARY KEY,
    name        VARCHAR(128)    NOT NULL,
    notes       TEXT            NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_name (name)
) ENGINE=InnoDB;
```

A render or a brief can be tagged with a project_id in a follow-up migration. Not required for v1.

---

## 6. Core Engine Contract

`src/Engine.php` is the only class the UI, CLI, and API call. It exposes three operations: `refineBrief`, `render`, and `iterate`.

### 6.1 Data shapes

```php
namespace Sigil;

final class BriefRequest
{
    public function __construct(
        public readonly string $rawDescription,
        public readonly string $style = 'combination',     // wordmark|lettermark|abstract|pictorial|combination|emoticon
        public readonly ?string $paletteHint = null
    ) {}
}

final class Brief
{
    public function __construct(
        public readonly string $id,                // UUIDv4
        public readonly string $rawDescription,
        public readonly string $refinedBrief,
        public readonly string $style,
        public readonly ?string $paletteHint,
        public readonly string $modelUsed,
        public readonly string $createdAt
    ) {}
}

final class RenderRequest
{
    public function __construct(
        public readonly string $briefId,
        public readonly string $provider = 'recraft',   // recraft|ideogram
        public readonly string $outputFormat = 'svg',   // svg|png
        public readonly ?int $seed = null               // for reproducibility when supported
    ) {}
}

final class Render
{
    public function __construct(
        public readonly string $id,
        public readonly string $briefId,
        public readonly string $provider,
        public readonly string $promptSent,
        public readonly string $outputFormat,
        public readonly string $outputPath,             // relative to storage/
        public readonly ?string $outputUrl,
        public readonly ?int $widthPx,
        public readonly ?int $heightPx,
        public readonly ?float $costUsd,
        public readonly string $status,
        public readonly ?string $errorMessage,
        public readonly string $createdAt,
        public readonly ?string $completedAt
    ) {}
}

final class IterationRequest
{
    public function __construct(
        public readonly string $parentRenderId,
        public readonly string $userMessage
    ) {}
}
```

### 6.2 Engine entry point

```php
final class Engine
{
    public function refineBrief(BriefRequest $req): Brief;
    public function render(RenderRequest $req): Render;
    public function iterate(IterationRequest $req): Render;  // returns the new render
    public function getRender(string $id): ?Render;
    public function listRenders(int $limit = 50, int $offset = 0): array;
}
```

Behavior contract:
- `refineBrief` is **idempotent in storage** but not cached: every call hits the LLM. The LLM is local, fast, free, and the user might have edited the description.
- `render` is the only call that costs money. It must be safe to retry on transient failure (network error, provider 5xx): re-trying the same `(briefId, provider, seed)` should produce the same provider job. The local `renders.status` is updated at every state transition.
- `iterate` is the chat loop. It always produces a new render — it does not edit the previous one in place. The chain of `iterations.parent_render_id` is the chat history.
- All three operations write to the database. The `Engine` is the only class that calls the providers.

---

## 7. Brief Refiner

`src/Brief/BriefRefiner.php` takes a `BriefRequest` and returns a `Brief`.

**Prompt template** (lives in `config/prompts.php`, editable from the Settings page):

```
SYSTEM: You are a brand-image brief writer. Given a loose project description, output a tight image-generation brief in 2-4 sentences. Always include: subject, style, palette, mood, composition. Be specific. Avoid cliches ("modern", "sleek", "cutting-edge"). Return plain text only — no JSON, no markdown.

USER: Project: {raw}
Style hint: {style}
Palette hint: {palette_hint}
```

**Post-processing rules** the refiner applies after the LLM returns:
- Strip surrounding markdown fences if present.
- Collapse multiple blank lines.
- Hard cap at 800 chars. If the LLM exceeds, truncate at the last sentence boundary before 800 and append " [truncated]".
- If the LLM returns empty or null, raise a typed exception `BriefRefinementFailed` and the API returns 502 `upstream_error`.

**Model selection:**
- Primary: `OLLAMA_MODEL` from env (default `llama3.1:8b`).
- Fallback: `OPENROUTER_*` if Ollama returns empty, errors, or 3 consecutive identical outputs.
- The model used is recorded in `briefs.model_used` as `ollama:<model>` or `openrouter:<model>`.

---

## 8. Render Service

`src/Render/RenderService.php` dispatches to `RecraftProvider` or `IdeogramProvider` based on `RenderRequest::$provider`. Both implement:

```php
namespace Sigil\Render;

interface RenderProviderInterface
{
    public function name(): string;        // 'recraft' | 'ideogram'
    public function render(string $prompt, string $outputFormat, ?int $seed): ProviderRenderResult;
}

final class ProviderRenderResult
{
    public function __construct(
        public readonly string $status,             // 'succeeded' | 'failed'
        public readonly ?string $outputPath = null, // path to local file under storage/renders/
        public readonly ?string $outputUrl = null,  // provider-hosted URL if any
        public readonly ?int $widthPx = null,
        public readonly ?int $heightPx = null,
        public readonly ?string $providerJobId = null,
        public readonly ?float $costUsd = null,
        public readonly ?string $errorMessage = null
    ) {}
}
```

### 8.1 RecraftProvider

Recraft (`https://api.recraft.ai/v1/`) — the only mainstream model with true editable SVG output. **This is the default.**

- Endpoint and auth: per Recraft's docs at build time. Auth via `Authorization: Bearer <RECRAPT_API_KEY>` (confirm in code and document in README). Do not guess the endpoint.
- Request body: `{ "prompt": "<brief>", "style": "<style>", "format": "svg" | "png", "model": "recraftv3" }` (or whatever the current model identifier is — confirm and document).
- Response: either inline base64 OR a URL to fetch. The provider class handles both. The downloaded/ decoded content is written to `storage/renders/<render-id>.<ext>`.
- SVG output MUST be valid SVG (starts with `<?xml` or `<svg`, has a closing `</svg>`). If the response claims `format=svg` but the bytes don't parse as SVG, the provider raises a typed exception and the render is marked `failed`.

### 8.2 IdeogramProvider

Ideogram (`https://api.ideogram.ai/`) — best wordmark / text accuracy.

- Endpoint and auth: per Ideogram's docs at build time. Auth via `Api-Key: <IDEOGRAM_API_KEY>` header (confirm in code and document in README).
- Use this when the brief contains brand text-as-text, OR when the user explicitly requests it.
- Default `output_format` is `png` (Ideogram's typical output). SVG is supported in newer tiers — coding agent must confirm and document.
- Same download / write semantics as Recraft.

### 8.3 AssetStore

`src/Render/AssetStore.php` is filesystem-backed. Methods:
- `save(string $renderId, string $format, string $bytes): string` returns relative path.
- `open(string $relativePath): string` returns absolute path.
- `delete(string $relativePath): void`.

Paths are always under `storage/renders/`. Filenames are `<render-id>.<ext>`. MIME type is detected via `finfo_file` and stored in `ImageMetadata` but not in the DB.

`storage/renders/` is **not** web-served directly. All access goes through `public/render.php?id=<uuid>&key=<api-key>` (or, for the web UI, an authenticated route). This is to keep the asset off the open web even on a misconfigured nginx.

### 8.4 Concurrency / retries

- The render call is **synchronous from the API's perspective**: the caller waits. The web UI shows a "rendering…" state with a polling endpoint.
- Retries: on 5xx or network error, retry up to 3 times with exponential backoff (1s, 2s, 4s). On 4xx (other than 429), do not retry — mark `failed` with the upstream error.
- 429 from the provider: respect `Retry-After` if present, else back off 10s and retry once.

---

## 9. Iteration Service

`src/Iterate/IterationService.php` implements the chat loop.

Flow on `iterate(parentRenderId, userMessage)`:
1. Load the parent render and its brief.
2. Append the user message to the `iterations` table as `role='user'`.
3. Send the LLM a structured prompt containing: the original brief, the previous prompt, the parent render's URL (so the LLM can describe what it sees if the model is multimodal — Ollama supports image-aware models; OpenRouter routes to multimodal models), and the user's new message. Ask the LLM to produce a **rewritten image prompt** that incorporates the feedback.
4. Insert a row in `iterations` with `role='assistant'` and `llm_rewritten_prompt` set.
5. Call `Engine::render(...)` with the new prompt. Insert a row in `iterations` with `parent_render_id=<new render>`, `role='system'`, and the new render's id as `child_render_id`.
6. Return the new `Render`.

**LLM prompt template** (in `config/prompts.php`):
```
SYSTEM: You are a logo-iteration assistant. Given a prior image brief, the prior image, and a user message describing what to change, produce a NEW image brief that incorporates the feedback. Be specific. If the user says "more like X", describe X concretely. Output plain text only.

USER:
Previous brief: {previous_brief}
Previous image: {previous_image_url_or_path}
User feedback: {user_message}
Style: {style}
Write the new brief.
```

**Web UI behavior:** the chat is non-blocking. The user types, hits enter, sees their message appear immediately, then a "thinking…" indicator while the LLM runs, then a "rendering…" indicator, then the new image. No page reload.

---

## 10. REST API

Base path: `/api/v1/`. All responses JSON. Auth: `Authorization: Bearer <api-key>` (sha256-lookup, same pattern as NameSweep). `/api/v1/health` is open.

### 10.1 Error shape

Identical to NameSweep:
```json
{ "error": { "code": "string", "message": "string", "details": {} } }
```
Codes: `invalid_input`, `unauthorized`, `not_found`, `rate_limited`, `upstream_error`, `internal_error`.

### 10.2 Endpoints

| Method | Path | Purpose |
|--------|------|---------|
| GET  | `/health` | `{"status":"ok","db":"ok","recraft":"ok\|down","ideogram":"ok\|down","ollama":"ok\|down","openrouter":"ok\|down"}` |
| POST | `/brief` | Refine a description. Body below. |
| GET  | `/brief/{id}` | Get a brief. |
| POST | `/render` | Render a brief. Body below. |
| GET  | `/render/{id}` | Get a render (no bytes — see `/render/{id}/asset`). |
| GET  | `/render/{id}/asset` | Stream the binary. Auth required. `Content-Type` from `finfo`. |
| POST | `/iterate` | Iterate on a render. Body below. |
| GET  | `/iterations/{renderId}` | Get the chat history for a render. |
| GET  | `/library?limit=50&offset=0` | List renders. |

### 10.3 POST /brief

Request:
```json
{
  "description": "A fintech app for Haitian small businesses, focused on mobile money.",
  "style": "wordmark",
  "palette_hint": "deep teal and warm white"
}
```

Response:
```json
{
  "id": "uuid-v4",
  "raw_description": "A fintech app for ...",
  "refined_brief": "Clean wordmark reading 'Kasav' in a humanist sans-serif. Deep teal (#0E5C5A) on warm white (#FAF7F2). Slight upward slant suggesting growth. No additional glyphs.",
  "style": "wordmark",
  "palette_hint": "deep teal and warm white",
  "model_used": "ollama:llama3.1:8b",
  "created_at": "2026-08-16T20:31:15Z"
}
```

### 10.4 POST /render

Request:
```json
{
  "brief_id": "uuid-v4",
  "provider": "recraft",
  "output_format": "svg",
  "seed": null
}
```

Response (the `status` field tells the caller whether to poll):
```json
{
  "id": "uuid-v4",
  "brief_id": "uuid-v4",
  "provider": "recraft",
  "prompt_sent": "...",
  "output_format": "svg",
  "output_path": "renders/<uuid>.svg",
  "output_url": null,
  "width_px": null,
  "height_px": null,
  "cost_usd": 0.04,
  "status": "succeeded",
  "error_message": null,
  "created_at": "2026-08-16T20:31:15Z",
  "completed_at": "2026-08-16T20:31:48Z"
}
```

If `status="failed"`, `error_message` is set and the caller should not retry without changing inputs.

### 10.5 POST /iterate

Request:
```json
{
  "parent_render_id": "uuid-v4",
  "user_message": "make the glyph thinner and shift the teal toward a more saturated emerald"
}
```

Response: same shape as `/render`.

### 10.6 Auth (`src/Api/Auth.php`)

Identical pattern to NameSweep: sha256 lookup, `last_used_at` update, 60 req/min per key sliding window. Independent of NameSweep's keys.

### 10.7 Serving assets

`public/render.php` (or a route in `api.php`) handles `GET /api/v1/render/{id}/asset`:
- Auth: same as the rest of the API. The key can be passed via `Authorization` header OR `?key=` query string (so it works for `<img src>` tags in the web UI, but the key is required).
- `Content-Type`: detected via `finfo` (`image/svg+xml`, `image/png`).
- For SVG, also set `Content-Security-Policy: default-src 'none'` and `X-Content-Type-Options: nosniff`.
- Caching: `Cache-Control: private, max-age=86400` — assets are immutable (new render = new UUID).
- 404 if the row is missing, the file is missing, or the key is invalid.

---

## 11. CLI

Entry: `php bin/sigil <command> [args]`. Help on `php bin/sigil help` and `php bin/sigil <command> --help`.

| Command | Args | Output |
|---------|------|--------|
| `brief` | `<description> [--style=wordmark] [--palette="..."]` | Prints refined brief to stdout. JSON with `--json`. |
| `render` | `--brief=<brief-id> [--provider=recraft] [--format=svg] [--seed=42] [--out=/path/to/save]` | Saves the asset, prints metadata. |
| `iterate` | `<parent-render-id> <feedback> [--provider=recraft] [--format=svg]` | Saves new asset, prints metadata. |
| `library` | `[--limit=20]` | Table of recent renders. |
| `show` | `<render-id>` | Full metadata, plus prints the path. |
| `key:create` | `<name>` | Prints plaintext once. |
| `key:list` | — | Table. |
| `key:revoke` | `<id>` | Marks revoked. |
| `health` | — | Same as `/health`. |

**No interactive prompts.**

**Exit codes:** 0 success, 2 user error (bad flags), 3 internal error (DB down, both LLM backends down, both image providers down). The exit code does NOT reflect whether a render succeeded — the caller must check `status` in the JSON.

---

## 12. Web UI

Pages (rendered server-side by `src/Web/Pages/*.php` via `Layout.php`):

- `/` — Chat interface. Left column: project description textarea at the top, current brief shown below, "Generate" button. Right column: chat history for the current render, with the latest image at the top, a feedback input at the bottom. Switching renders via the library swaps the right column.
- `/library` — Grid of all renders, newest first. Each card shows the image, the brief snippet, the provider, the date, and "Iterate" / "Open" buttons.
- `/settings` — Edit prompts, view API keys, test provider connectivity.

**No client-side framework.** All JS is plain ES6 modules. `chat.js` polls `GET /api/v1/render/{id}` every 2s while `status != 'succeeded' && status != 'failed'`. Stop polling on terminal state.

**No build step.** CSS and JS served as-is.

**Mobile-responsive.** Flexbox + CSS grid only. The chat view on mobile is a single column: the image, then the brief, then the chat, then the input — all stacked.

**No external CDN requests.** All assets local.

---

## 13. Environment & Configuration

### 13.1 `.env.example`

```dotenv
# App
APP_ENV=development
APP_DEBUG=true
APP_BASE_URL=http://localhost:8001
APP_TIMEZONE=America/Santo_Domingo

# Database
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=sigil
DB_USER=sigil
DB_PASS=changeme

# LLM (brief + iteration)
OLLAMA_BASE_URL=http://127.0.0.1:11434
OLLAMA_MODEL=llama3.1:8b
OPENROUTER_API_KEY=
OPENROUTER_MODEL=meta-llama/llama-3.1-8b-instruct:free

# Image providers
RECRAFT_API_KEY=
RECRAFT_API_URL=https://api.recraft.ai/v1
IDEOGRAM_API_KEY=
IDEOGRAM_API_URL=https://api.ideogram.ai

# Defaults
DEFAULT_PROVIDER=recraft
DEFAULT_OUTPUT_FORMAT=svg

# HTTP
HTTP_TIMEOUT_SECONDS=60
HTTP_USER_AGENT=Sigil/1.0 (+https://example.com)

# Rate limiting
RATE_LIMIT_PER_MINUTE=60
```

### 13.2 `.gitignore`

```
.env
/vendor/
storage/logs/*
storage/renders/*
storage/tmp/*
!storage/logs/.gitkeep
!storage/renders/.gitkeep
!storage/tmp/.gitkeep
```

### 13.3 `config/app.php` excerpt

```php
return [
    'default_provider' => getenv('DEFAULT_PROVIDER') ?: 'recraft',
    'default_output_format' => getenv('DEFAULT_OUTPUT_FORMAT') ?: 'svg',
    'max_brief_chars' => 800,
    'style_choices' => ['wordmark','lettermark','abstract','pictorial','combination','emoticon'],
];
```

### 13.4 `config/providers.php` excerpt

```php
return [
    'recraft' => [
        'api_key_env' => 'RECRAFT_API_KEY',
        'api_url_env' => 'RECRAFT_API_URL',
        'default_format' => 'svg',
    ],
    'ideogram' => [
        'api_key_env' => 'IDEOGRAM_API_KEY',
        'api_url_env' => 'IDEOGRAM_API_URL',
        'default_format' => 'png',
    ],
];
```

### 13.5 `config/prompts.php` excerpt

```php
return [
    'brief_system' => "You are a brand-image brief writer. Given a loose project description, output a tight image-generation brief in 2-4 sentences. Always include: subject, style, palette, mood, composition. Be specific. Avoid cliches. Return plain text only — no JSON, no markdown.",
    'brief_user'   => "Project: {raw}\nStyle hint: {style}\nPalette hint: {palette_hint}",
    'iterate_system' => "You are a logo-iteration assistant. Given a prior image brief, the prior image, and a user message describing what to change, produce a NEW image brief that incorporates the feedback. Be specific. Output plain text only.",
    'iterate_user'   => "Previous brief: {previous_brief}\nPrevious image: {previous_image_url_or_path}\nUser feedback: {user_message}\nStyle: {style}\nWrite the new brief.",
];
```

---

## 14. Setup & Run

```bash
# 1. Clone and install
cd /workspace/sigil
composer install --no-dev

# 2. Configure
cp .env.example .env
# edit .env, set DB_PASS, RECRAFT_API_KEY, IDEOGRAM_API_KEY (at least Recraft is required for the default)

# 3. Database
mysql -u root -p -e "CREATE DATABASE sigil CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
mysql -u root -p sigil < database/schema.sql

# 4. Web server (dev)
php -S 127.0.0.1:8001 -t public

# 5. CLI smoke
php bin/sigil health
php bin/sigil key:create "my-coding-agent"
php bin/sigil brief "A fintech app for Haiti, focused on mobile money" --style=wordmark
# copy the brief id from the output, then:
php bin/sigil render --brief=<id> --provider=recraft --format=svg --out=/tmp/logo.svg

# 6. API smoke
curl -H "Authorization: Bearer <key>" http://127.0.0.1:8001/api/v1/health
curl -H "Authorization: Bearer <key>" -H "Content-Type: application/json" \
     -d '{"description":"A fintech app for Haiti","style":"wordmark"}' \
     http://127.0.0.1:8001/api/v1/brief
```

Production: nginx with PHP-FPM, same pattern as NameSweep. The render asset directory is outside the docroot; the API streams it via `render.php`.

---

## 15. Logging

`src/Util/Logger.php` writes to `storage/logs/app-YYYY-MM-DD.log`. Levels DEBUG, INFO, WARN, ERROR. Format: `[ISO8601] [LEVEL] [module] message {context_json}`.

- INFO: every brief refine, every render request, every iteration.
- INFO with `cost_usd` field on every successful render — the owner will grep for this to track spend.
- WARN: provider 429, retry triggered, Ollama fallback used.
- ERROR: provider failure, LLM both backends down, DB failure.

---

## 16. Acceptance Criteria

The coding agent's work is graded against these. Every item must pass.

### 16.1 Structural
- [ ] All files in §4 exist and are non-empty.
- [ ] `composer.json` matches §3 exactly. No extra packages.
- [ ] `database/schema.sql` runs cleanly on a fresh MySQL 8 instance.
- [ ] No file under `public/` references `../src/` directly.
- [ ] No use of `eval`, no `shell_exec` with user input, no `system` calls.
- [ ] `storage/renders/` is not web-served directly — confirmed by attempting to fetch a known file at `https://<host>/storage/renders/...` and getting 404.

### 16.2 Engine contract
- [ ] `Engine::refineBrief` writes one row in `briefs` per call.
- [ ] `Engine::render` writes one row in `renders` and updates it through `queued → rendering → succeeded|failed`.
- [ ] `Engine::iterate` writes three rows in `iterations`: user, assistant, system.
- [ ] Provider exceptions are caught, logged at ERROR, and surface as `status=failed` in the DB and the API response. They do not bubble up as 500s to the caller.

### 16.3 Brief refiner
- [ ] Output is between 50 and 800 chars for a non-trivial input.
- [ ] Empty / null LLM response raises `BriefRefinementFailed` and the API returns 502.
- [ ] Ollama-down + OpenRouter-key-set + OpenRouter-call-fails → 502 with `code=upstream_error`, not a stack trace.
- [ ] Ollama-down + no OpenRouter key → same 502.
- [ ] `briefs.model_used` is populated correctly for both backends.

### 16.4 Render service
- [ ] Recraft is the default provider when `provider` is not specified.
- [ ] Recraft `format=svg` produces a file that starts with `<?xml` or `<svg` and contains `</svg>`. Verified by reading the file and asserting with `strpos`.
- [ ] Ideogram `format=png` produces a valid PNG (verified by `getimagesize` returning non-false).
- [ ] Provider 5xx triggers retry with exponential backoff up to 3 times.
- [ ] Provider 429 respects `Retry-After` if present.
- [ ] Failed renders log the upstream error verbatim in `renders.error_message`.
- [ ] `cost_usd` is populated when the provider returns it; NULL when it doesn't.

### 16.5 Iteration
- [ ] First iteration: `iterations` has rows for the user message, the assistant's rewritten brief, and the system note linking the new render. Total 3 rows.
- [ ] Second iteration chained off the first: `parent_render_id` of the new rows points at the previous render's id.
- [ ] The new render's `prompt_sent` differs from the parent's `prompt_sent` whenever the user's feedback wasn't empty.

### 16.6 REST API
- [ ] All endpoints in §10.2 respond with the documented shape.
- [ ] Missing/invalid `Authorization` header → 401 with the error shape from §10.1.
- [ ] `GET /api/v1/render/{id}/asset?key=<bad>` → 401. Without `key` and without `Authorization` → 401. With a valid key → 200 and the binary.
- [ ] Rate limit: 61st request in a minute from the same key returns 429 with `Retry-After`.
- [ ] No CORS wildcard.

### 16.7 CLI
- [ ] `php bin/sigil help` lists every command in §11.
- [ ] `php bin/sigil brief "..." --json` output parses as JSON and contains `id`, `refined_brief`, `model_used`.
- [ ] `php bin/sigil render --brief=<id> --out=/tmp/x.svg` writes a file and exits 0.
- [ ] Exit codes match §11.

### 16.8 Web UI
- [ ] `/` renders on mobile (375px wide) without horizontal scroll.
- [ ] Submitting a description on `/` and clicking Generate shows the brief within 5s, then the first render within 60s.
- [ ] Typing feedback in the chat and hitting enter shows the new render without a full page reload.
- [ ] No JS errors in the browser console on `/`, `/library`, `/settings`.
- [ ] No external CDN requests — all assets local.

### 16.9 Security
- [ ] `.env` in `.gitignore`, real file never committed.
- [ ] API keys stored only as sha256 hashes.
- [ ] All DB queries use PDO prepared statements.
- [ ] All user input rendered in templates is `htmlspecialchars`-escaped.
- [ ] SVG responses set `Content-Security-Policy: default-src 'none'` and `X-Content-Type-Options: nosniff`.

---

## 17. Cost Guardrails

Because the render API costs real money, the coding agent must implement these specific guardrails. The owner can override each via env, but the defaults are conservative.

| Setting | Default | Env var |
|---------|---------|---------|
| Max renders per API key per hour | 30 | `RATE_LIMIT_RENDERS_PER_HOUR` |
| Max renders per project (via project_id tag) per day | 50 | `RATE_LIMIT_RENDERS_PER_DAY` |
| Max USD per day across all keys | 5.00 | `COST_LIMIT_USD_PER_DAY` |
| Default `temperature` on LLM calls | 0.7 (brief), 0.7 (iterate) | `LLM_TEMPERATURE` |

When a limit is hit, the API returns 429 with `error.code=rate_limited` and a clear message naming the limit that was hit. The CLI exit code is still 2 (user error — they need to back off) when a limit is the cause.

**The cost cap is hard.** When the daily USD cap is hit, no further renders are accepted until midnight in `APP_TIMEZONE`. The cap is enforced by reading `cost_usd` from successful renders over the last 24h; the owner can audit this with `SELECT SUM(cost_usd) FROM renders WHERE status='succeeded' AND created_at > NOW() - INTERVAL 1 DAY;`.

---

## 18. Out of Scope

The coding agent must NOT add any of the following unless the owner explicitly asks:

- User accounts, login, OAuth.
- Email sending.
- Payment integration.
- Sharing / public links to renders.
- A gallery view for unauthenticated users.
- Webhooks.
- A Docker setup.
- CI/CD config.
- Test framework beyond a single `tests/smoke.php` script covering: `health`, `brief "test"`, render with a stubbed provider, `iterate` on the result.
- A README written for the public.

---

## 19. Handoff Checklist

When the coding agent is done, the owner should be able to:

1. `cd /workspace/sigil && cp .env.example .env`, edit DB credentials and add at least `RECRAFT_API_KEY`.
2. `composer install --no-dev`.
3. `mysql ... < database/schema.sql`.
4. `php -S 127.0.0.1:8001 -t public`.
5. Open `http://localhost:8001`, type a description, click Generate, see a logo within 60s.
6. Type "make it more minimal" in the chat, hit enter, see a new logo.
7. Open a terminal, `php bin/sigil key:create my-agent`, get a key.
8. `curl -H "Authorization: Bearer <key>" -d '{"description":"...","style":"wordmark"}' -H "Content-Type: application/json" http://localhost:8001/api/v1/brief` and get a refined brief.
9. Hand the key to a coding agent that needs to generate logo options for the project; the agent calls `/brief`, then `/render`, then `/iterate` as needed, and saves the SVG outputs locally.

If any of these nine steps fails, the build is not done.

---

## 20. Relationship to NameSweep

These are two separate codebases. They are intentionally not merged. The only conceptual link is the workflow:

```
NameSweep (find a name)  →  Sigil (logo for the name)
```

The two share no data. The owner (or a third orchestrator) wires them. A coding agent working on a brand project can call NameSweep to get a list of available names, then call Sigil to get a logo for each one. Each tool's API key is independent.
