# Sigil — Technical Specification Document
## For Coding Agent — Vanilla PHP Build

---

## 1. Overview

Sigil is a logo generation tool that takes a project description, uses a local LLM (Ollama) to refine it into a tight image-generation brief, then calls a hosted image API (Recraft for SVG logos, Ideogram for wordmarks) to produce the final render. The user can iterate via chat-style prompts ("more like X", "make it bolder", etc.) without incurring cost until the final render.

**Interface modes:** CLI (primary for agent integration), REST API, Web Dashboard.

**Stack:** Vanilla PHP 8.2+, MySQL 8.0+, vanilla JavaScript (frontend), Ollama (local LLM for brief refinement), Recraft API (SVG logos), Ideogram API (wordmarks with text).

**Important:** Sigil is a completely separate application from NameSweep. No shared database, no shared code, no shared config. They may run on the same VPS but are independent repos/projects.

---

## 2. Project Structure

```
/sigil/
├── .env                          # Secrets (gitignored)
├── .env.example                  # Template with blank keys
├── config.php                    # Central config loader
├── bootstrap.php                 # Autoloader, DB connection, constants
├── database.sql                  # Full schema dump
├── cli.php                       # CLI entry point
├── api.php                       # REST API entry point
├── index.html                    # Web dashboard (SPA-style)
├── /assets/
│   └── app.js                    # Vanilla JS dashboard
├── /src/
│   ├── /Core/
│   │   ├── Database.php          # PDO wrapper
│   │   ├── Config.php            # .env + hard defaults
│   │   ├── Response.php          # Standardized JSON/CLI output formatter
│   │   ├── Storage.php           # File storage for generated images
│   │   └── Logger.php            # File-based logging
│   ├── /LLM/
│   │   ├── BriefGenerator.php    # Ollama wrapper: description → image brief
│   │   └── ChatIterator.php      # Handles iteration: "more like X" → refined brief
│   ├── /Render/
│   │   ├── RecraftClient.php     # Recraft API wrapper (SVG output)
│   │   ├── IdeogramClient.php    # Ideogram API wrapper (PNG wordmark)
│   │   └── RenderEngine.php      # Chooses renderer, handles call, stores result
│   ├── /Pipeline/
│   │   └── GenerationPipeline.php # Orchestrates: describe → brief → render → store
│   └── /API/
│       ├── Router.php            # Simple request router
│       ├── Middleware.php        # Auth, rate limiting, CORS
│       └── Endpoints/
│           ├── GenerateEndpoint.php
│           ├── IterateEndpoint.php
│           ├── HistoryEndpoint.php
│           └── StatusEndpoint.php
├── /storage/
│   ├── /generated/               # Final rendered images (SVG, PNG)
│   ├── /thumbnails/              # Small previews
│   └── /temp/                    # Temporary files
├── /logs/                        # Runtime logs
└── /tests/                       # Test runner
```

---

## 3. Database Schema (MySQL)

```sql
-- Run this first: database.sql
CREATE DATABASE IF NOT EXISTS sigil CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE sigil;

-- Generation sessions (one per logo request)
CREATE TABLE generation_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_uuid VARCHAR(36) NOT NULL UNIQUE,   -- UUIDv4 for API/CLI reference
    description TEXT NOT NULL,                  -- Original user description
    brief TEXT,                                 -- LLM-refined image brief
    model_used VARCHAR(64),                     -- "recraft" or "ideogram"
    renderer_model VARCHAR(128),                -- e.g. "recraft-v3", "ideogram-3"
    status VARCHAR(32) NOT NULL DEFAULT 'pending', -- pending | brief_ready | rendering | completed | failed
    output_path VARCHAR(500),                   -- Relative path in /storage/generated/
    output_format VARCHAR(10),                  -- svg | png
    output_url VARCHAR(500),                    -- Public URL to access the file
    cost_usd DECIMAL(6,4) DEFAULT 0,            -- Actual API cost for this render
    error_message TEXT,
    created_at DATETIME DEFAULT NOW(),
    completed_at DATETIME,
    INDEX idx_uuid (session_uuid),
    INDEX idx_status (status)
) ENGINE=InnoDB;

-- Iteration history (chat-style back-and-forth)
CREATE TABLE iteration_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    iteration_number INT NOT NULL DEFAULT 1,    -- 1 = initial, 2+ = iterations
    user_prompt TEXT NOT NULL,                  -- "make it bolder", "more minimal", etc.
    previous_brief TEXT,                        -- Brief before this iteration
    refined_brief TEXT,                         -- Brief after LLM refinement
    was_rendered TINYINT(1) DEFAULT 0,          -- Did user actually render this iteration?
    output_path VARCHAR(500),
    cost_usd DECIMAL(6,4) DEFAULT 0,
    created_at DATETIME DEFAULT NOW(),
    FOREIGN KEY (session_id) REFERENCES generation_sessions(id) ON DELETE CASCADE,
    INDEX idx_session (session_id, iteration_number)
) ENGINE=InnoDB;

-- API keys / access control
CREATE TABLE api_keys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    key_hash VARCHAR(64) NOT NULL,
    name VARCHAR(100),
    created_at DATETIME DEFAULT NOW(),
    last_used_at DATETIME,
    is_active TINYINT(1) DEFAULT 1
) ENGINE=InnoDB;

-- Cost tracking (per API)
CREATE TABLE cost_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    api_name VARCHAR(32) NOT NULL,              -- "recraft" | "ideogram" | "openrouter"
    endpoint VARCHAR(128),
    cost_usd DECIMAL(8,4) DEFAULT 0,
    request_size_bytes INT,
    response_size_bytes INT,
    created_at DATETIME DEFAULT NOW()
) ENGINE=InnoDB;
```

---

## 4. Configuration (config.php + .env)

```php
<?php
// config.php — loads .env, provides defaults

$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($k, $v) = explode('=', $line, 2);
        $_ENV[trim($k)] = trim($v);
    }
}

return [
    'db' => [
        'host'     => $_ENV['DB_HOST']     ?? 'localhost',
        'name'     => $_ENV['DB_NAME']     ?? 'sigil',
        'user'     => $_ENV['DB_USER']     ?? 'sigil',
        'pass'     => $_ENV['DB_PASS']     ?? '',
        'charset'  => 'utf8mb4'
    ],
    'ollama' => [
        'url'      => $_ENV['OLLAMA_URL']  ?? 'http://localhost:11434',
        'model'    => $_ENV['OLLAMA_MODEL'] ?? 'llama3.1:8b',
        'timeout'  => (int)($_ENV['OLLAMA_TIMEOUT'] ?? 120)
    ],
    'recraft' => [
        'api_key'  => $_ENV['RECRAFT_API_KEY'] ?? '',
        'base_url' => 'https://external.api.recraft.ai/v1',
        'default_style' => $_ENV['RECRAFT_STYLE'] ?? 'vector_illustration',
        'svg_enabled' => true  // Recraft is the only one with true SVG
    ],
    'ideogram' => [
        'api_key'  => $_ENV['IDEOGRAM_API_KEY'] ?? '',
        'base_url' => 'https://api.ideogram.ai',
        'model'    => $_ENV['IDEOGRAM_MODEL'] ?? 'V_3',
        'magic_prompt_option' => 'AUTO'
    ],
    'api' => [
        'rate_limit_per_min' => (int)($_ENV['RATE_LIMIT'] ?? 30),
        'require_key'        => filter_var($_ENV['API_REQUIRE_KEY'] ?? 'false', FILTER_VALIDATE_BOOLEAN),
        'base_url'           => $_ENV['API_BASE_URL'] ?? 'http://localhost:8080'
    ],
    'storage' => [
        'generated_dir'  => __DIR__ . '/storage/generated',
        'thumbnails_dir' => __DIR__ . '/storage/thumbnails',
        'temp_dir'       => __DIR__ . '/storage/temp',
        'max_file_age_days' => 30  // Auto-cleanup old files
    ],
    'defaults' => [
        'default_renderer' => $_ENV['DEFAULT_RENDERER'] ?? 'recraft', // 'recraft' or 'ideogram'
        'max_iterations'   => 10,
        'image_size'       => '1024x1024' // or 'vector' for Recraft SVG
    ]
];
```

**.env.example:**
```
DB_HOST=localhost
DB_NAME=sigil
DB_USER=sigil
DB_PASS=your_mysql_password

OLLAMA_URL=http://localhost:11434
OLLAMA_MODEL=llama3.1:8b
OLLAMA_TIMEOUT=120

RECRAFT_API_KEY=your_recraft_key_here
RECRAFT_STYLE=vector_illustration

IDEOGRAM_API_KEY=your_ideogram_key_here
IDEOGRAM_MODEL=V_3

DEFAULT_RENDERER=recraft
API_REQUIRE_KEY=false
RATE_LIMIT=30
API_BASE_URL=http://localhost:8080
```

---

## 5. Core Contracts

### 5.1 Standard Response Format

```php
<?php
// src/Core/Response.php

class Response {
    public static function success(array $data, string $message = ''): array {
        return [
            'ok'      => true,
            'message' => $message,
            'data'    => $data,
            'meta'    => ['timestamp' => date('c'), 'version' => '1.0.0']
        ];
    }

    public static function error(string $message, int $code = 400, array $context = []): array {
        return [
            'ok'      => false,
            'message' => $message,
            'code'    => $code,
            'context' => $context,
            'meta'    => ['timestamp' => date('c')]
        ];
    }

    public static function toCli(array $payload): void {
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }

    public static function toHttp(array $payload, int $httpCode = 200): void {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
```

---

## 6. LLM Layer (src/LLM/)

### 6.1 BriefGenerator.php

Takes a raw project description and converts it into a structured, tight image-generation brief.

**System prompt for Ollama:**
```
You are a logo design brief writer. Take the user's project description and convert it into a concise, vivid image-generation prompt suitable for an AI image model.

Rules:
- Output ONLY the brief text. No markdown, no explanations, no JSON.
- Keep it under 200 words.
- Include: style (minimalist, vintage, modern, playful), colors, mood, key visual elements.
- If the brand name is mentioned, include it as text in the brief.
- Do NOT add framing like "Here is the brief:" — just the brief itself.
```

**Example:**
- Input: "A Caribbean fintech app called Velora that handles remittances. I want something modern and trustworthy."
- Output: "Modern minimalist logo for 'Velora', a Caribbean fintech app. Clean geometric shapes suggesting movement and connection, ocean blue and coral orange palette, sans-serif wordmark, white background, vector illustration style, professional and trustworthy mood."

**Ollama call:**
```bash
curl -X POST http://localhost:11434/api/generate   -d '{
    "model": "llama3.1:8b",
    "prompt": "{system_prompt}\n\nUser description: {description}\n\nBrief:",
    "stream": false
  }'
```

### 6.2 ChatIterator.php

Handles the iteration loop. When user says "make it bolder":

1. Fetch previous brief from `iteration_history`.
2. Send to Ollama:
```
Previous brief: {previous_brief}
User feedback: {feedback}

Rewrite the brief incorporating the feedback. Output ONLY the new brief text, no explanations.
```
3. Store new brief in `iteration_history`.
4. Do NOT call image API yet — wait for user to confirm "render this".

**This is the key UX insight:** Iteration is free (local LLM). Only the final "render" call costs money.

---

## 7. Render Layer (src/Render/)

### 7.1 RecraftClient.php (Primary — SVG output)

**API:** `POST https://external.api.recraft.ai/v1/images/generations`

**Request:**
```json
{
  "prompt": "{brief}",
  "style": "vector_illustration",
  "size": "1024x1024",
  "response_format": "svg"
}
```

**Response:**
```json
{
  "data": [
    {
      "url": "https://.../image.svg",
      "revised_prompt": "..."
    }
  ]
}
```

**Implementation:**
```php
class RecraftClient {
    private string $apiKey;
    private string $baseUrl;
    private Logger $logger;

    public function __construct(array $config, Logger $logger) {
        $this->apiKey = $config['recraft']['api_key'];
        $this->baseUrl = $config['recraft']['base_url'];
        $this->logger = $logger;
    }

    /**
     * @param string $brief The image generation brief
     * @return array ['url' => string, 'revised_prompt' => string, 'cost_usd' => float]
     * @throws Exception on API error
     */
    public function generate(string $brief): array {
        $ch = curl_init("{$this->baseUrl}/images/generations");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$this->apiKey}",
                "Content-Type: application/json"
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'prompt' => $brief,
                'style' => 'vector_illustration',
                'size' => '1024x1024',
                'response_format' => 'svg'
            ]),
            CURLOPT_TIMEOUT => 60
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("Recraft API error: HTTP $httpCode — $response");
        }

        $data = json_decode($response, true);
        if (empty($data['data'][0]['url'])) {
            throw new Exception("Recraft returned empty image URL");
        }

        // Download and store locally
        $imageUrl = $data['data'][0]['url'];
        $svgContent = file_get_contents($imageUrl);
        $filename = 'sigil_' . uniqid() . '.svg';
        $path = $this->config['storage']['generated_dir'] . '/' . $filename;
        file_put_contents($path, $svgContent);

        // Cost: Recraft is ~$0.03–0.05 per SVG generation
        $cost = 0.04;

        return [
            'url' => $this->config['api']['base_url'] . '/storage/generated/' . $filename,
            'local_path' => $path,
            'revised_prompt' => $data['data'][0]['revised_prompt'] ?? $brief,
            'cost_usd' => $cost,
            'format' => 'svg'
        ];
    }
}
```

### 7.2 IdeogramClient.php (Fallback — Wordmarks with text)

**API:** `POST https://api.ideogram.ai/generate`

**Request:**
```json
{
  "image_request": {
    "prompt": "{brief}",
    "model": "V_3",
    "magic_prompt_option": "AUTO",
    "aspect_ratio": "ASPECT_1_1"
  }
}
```

**Response:** Contains `data[0].url` (PNG).

**When to use Ideogram instead of Recraft:**
- User explicitly wants a wordmark (text-heavy logo where brand name accuracy is critical)
- Recraft returns poor text rendering
- User requests raster/photographic style instead of vector

**Cost:** ~$0.08–0.10 per image.

### 7.3 RenderEngine.php

```php
class RenderEngine {
    private RecraftClient $recraft;
    private IdeogramClient $ideogram;
    private array $config;

    public function __construct(array $config, Logger $logger) {
        $this->config = $config;
        $this->recraft = new RecraftClient($config, $logger);
        $this->ideogram = new IdeogramClient($config, $logger);
    }

    /**
     * @param string $brief        The refined image brief
     * @param string $renderer      'recraft' | 'ideogram' | 'auto'
     * @return array               ['path', 'url', 'format', 'cost_usd', 'renderer']
     */
    public function render(string $brief, string $renderer = 'auto'): array {
        if ($renderer === 'auto') {
            $renderer = $this->config['defaults']['default_renderer'];
        }

        if ($renderer === 'recraft') {
            $result = $this->recraft->generate($brief);
        } elseif ($renderer === 'ideogram') {
            $result = $this->ideogram->generate($brief);
        } else {
            throw new Exception("Unknown renderer: $renderer");
        }

        return array_merge($result, ['renderer' => $renderer]);
    }
}
```

---

## 8. Pipeline (src/Pipeline/GenerationPipeline.php)

```php
class GenerationPipeline {
    private BriefGenerator $briefGen;
    private ChatIterator $iterator;
    private RenderEngine $renderer;
    private Database $db;
    private Logger $logger;

    /**
     * Step 1: Create a new session from description
     * Returns session UUID (no render yet, no cost)
     */
    public function createSession(string $description): string {
        $uuid = $this->generateUuid();
        $brief = $this->briefGen->generate($description);

        $stmt = $this->db->prepare(
            "INSERT INTO generation_sessions (session_uuid, description, brief, status) VALUES (?, ?, ?, 'brief_ready')"
        );
        $stmt->execute([$uuid, $description, $brief]);

        $sessionId = $this->db->lastInsertId();

        // Record initial iteration
        $stmt2 = $this->db->prepare(
            "INSERT INTO iteration_history (session_id, iteration_number, user_prompt, refined_brief) VALUES (?, 1, ?, ?)"
        );
        $stmt2->execute([$sessionId, $description, $brief]);

        return $uuid;
    }

    /**
     * Step 2: Iterate (free — local LLM only)
     * Returns updated brief (no render, no cost)
     */
    public function iterate(string $uuid, string $feedback): array {
        $session = $this->getSession($uuid);
        $lastIteration = $this->getLastIteration($session['id']);

        $newBrief = $this->iterator->refine($lastIteration['refined_brief'], $feedback);
        $newIterationNum = $lastIteration['iteration_number'] + 1;

        $stmt = $this->db->prepare(
            "INSERT INTO iteration_history (session_id, iteration_number, user_prompt, previous_brief, refined_brief) VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$session['id'], $newIterationNum, $feedback, $lastIteration['refined_brief'], $newBrief]);

        return [
            'session_uuid' => $uuid,
            'iteration' => $newIterationNum,
            'brief' => $newBrief,
            'cost_so_far' => 0.00
        ];
    }

    /**
     * Step 3: Render (costs money — calls image API)
     */
    public function render(string $uuid, string $renderer = 'auto'): array {
        $session = $this->getSession($uuid);
        $lastIteration = $this->getLastIteration($session['id']);
        $brief = $lastIteration['refined_brief'];

        // Update status
        $this->db->prepare("UPDATE generation_sessions SET status='rendering' WHERE id=?")
            ->execute([$session['id']]);

        try {
            $result = $this->renderer->render($brief, $renderer);

            // Update session
            $stmt = $this->db->prepare(
                "UPDATE generation_sessions SET status='completed', output_path=?, output_format=?, output_url=?, cost_usd=?, renderer_model=?, completed_at=NOW() WHERE id=?"
            );
            $stmt->execute([
                $result['local_path'],
                $result['format'],
                $result['url'],
                $result['cost_usd'],
                $result['renderer'],
                $session['id']
            ]);

            // Update iteration
            $this->db->prepare(
                "UPDATE iteration_history SET was_rendered=1, output_path=?, cost_usd=? WHERE session_id=? AND iteration_number=?"
            )->execute([$result['local_path'], $result['cost_usd'], $session['id'], $lastIteration['iteration_number']]);

            // Log cost
            $this->db->prepare(
                "INSERT INTO cost_log (api_name, cost_usd) VALUES (?, ?)"
            )->execute([$result['renderer'], $result['cost_usd']]);

            return [
                'session_uuid' => $uuid,
                'status' => 'completed',
                'output_url' => $result['url'],
                'output_format' => $result['format'],
                'brief_used' => $brief,
                'cost_usd' => $result['cost_usd'],
                'renderer' => $result['renderer']
            ];

        } catch (Exception $e) {
            $this->db->prepare("UPDATE generation_sessions SET status='failed', error_message=? WHERE id=?")
                ->execute([$e->getMessage(), $session['id']]);
            throw $e;
        }
    }

    private function generateUuid(): string {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
```

---

## 9. CLI Interface (cli.php)

```bash
# Create a new generation session (returns UUID, no cost)
php cli.php generate --description="A Caribbean fintech app called Velora, modern and trustworthy"

# Output:
# {
#   "ok": true,
#   "data": {
#     "session_uuid": "a1b2c3d4-...",
#     "brief": "Modern minimalist logo for 'Velora'...",
#     "status": "brief_ready",
#     "message": "Session created. Use this UUID to iterate or render."
#   }
# }

# Iterate (free — refines brief via local LLM)
php cli.php iterate --uuid="a1b2c3d4-..." --feedback="make it bolder, add a wave element"

# Render (costs money — calls image API)
php cli.php render --uuid="a1b2c3d4-..." --renderer="recraft"

# Render with specific renderer
php cli.php render --uuid="a1b2c3d4-..." --renderer="ideogram"

# Show session history
php cli.php history --uuid="a1b2c3d4-..."

# List all sessions
php cli.php list --limit=20

# Quick one-shot (create + render in one command)
php cli.php quick --description="..." --renderer="recraft"

# Health check
php cli.php health

# Show total cost spent
php cli.php costs
```

**CLI output:** Always JSON to stdout. Use `--pretty` for human-readable.

---

## 10. REST API (api.php)

### Endpoints

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | `/api/v1/generate` | Optional key | Create session from description |
| POST | `/api/v1/iterate` | Optional key | Refine brief with feedback (free) |
| POST | `/api/v1/render` | Optional key | Generate image from current brief (costs $) |
| GET | `/api/v1/session/{uuid}` | Optional key | Get session + all iterations |
| GET | `/api/v1/session/{uuid}/image` | None | Direct image file access |
| GET | `/api/v1/sessions` | Optional key | List recent sessions |
| GET | `/api/v1/costs` | Optional key | Total spend summary |
| GET | `/api/v1/status` | None | Health check |

### Request/Response Examples

**POST /api/v1/generate**
```json
// Request
{
  "description": "A Caribbean fintech app called Velora, modern and trustworthy",
  "renderer_preference": "recraft"
}

// Response
{
  "ok": true,
  "data": {
    "session_uuid": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "brief": "Modern minimalist logo for 'Velora', a Caribbean fintech app...",
    "status": "brief_ready",
    "cost_so_far": 0.00,
    "message": "Session created. Iterate for free, then render when ready."
  }
}
```

**POST /api/v1/iterate**
```json
// Request
{
  "session_uuid": "a1b2c3d4-...",
  "feedback": "make it bolder, add a wave element, use darker blue"
}

// Response
{
  "ok": true,
  "data": {
    "session_uuid": "a1b2c3d4-...",
    "iteration": 2,
    "brief": "Bold modern logo for 'Velora' with wave motif, deep navy blue...",
    "cost_so_far": 0.00
  }
}
```

**POST /api/v1/render**
```json
// Request
{
  "session_uuid": "a1b2c3d4-...",
  "renderer": "recraft"
}

// Response
{
  "ok": true,
  "data": {
    "session_uuid": "a1b2c3d4-...",
    "status": "completed",
    "output_url": "http://localhost:8080/storage/generated/sigil_abc123.svg",
    "output_format": "svg",
    "brief_used": "Bold modern logo for 'Velora'...",
    "cost_usd": 0.04,
    "renderer": "recraft"
  }
}
```

### Simple Router
```php
<?php
// api.php
require 'bootstrap.php';

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? [];

// Auth middleware
if ($config['api']['require_key']) {
    $key = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (!validateApiKey($key)) {
        Response::toHttp(Response::error('Invalid or missing API key', 401), 401);
    }
}

// Route
if ($uri === '/api/v1/generate' && $method === 'POST') {
    $uuid = $pipeline->createSession($input['description'] ?? '');
    $session = $pipeline->getSession($uuid);
    Response::toHttp(Response::success(['session_uuid' => $uuid, 'brief' => $session['brief'], 'status' => 'brief_ready']));
} elseif ($uri === '/api/v1/iterate' && $method === 'POST') {
    $result = $pipeline->iterate($input['session_uuid'] ?? '', $input['feedback'] ?? '');
    Response::toHttp(Response::success($result));
} elseif ($uri === '/api/v1/render' && $method === 'POST') {
    $result = $pipeline->render($input['session_uuid'] ?? '', $input['renderer'] ?? 'auto');
    Response::toHttp(Response::success($result));
} elseif (preg_match('#^/api/v1/session/([a-f0-9-]+)$#', $uri, $m) && $method === 'GET') {
    $session = $pipeline->getSession($m[1]);
    Response::toHttp(Response::success($session));
} elseif ($uri === '/api/v1/status' && $method === 'GET') {
    Response::toHttp(Response::success(healthCheck()));
} else {
    Response::toHttp(Response::error('Not found', 404), 404);
}
```

---

## 11. Web Dashboard (index.html + assets/app.js)

### Features
- **Create Tab:** Textarea for description → "Create Brief" → shows refined brief
- **Iterate Tab:** Chat-style interface. User types feedback → brief updates instantly (free). Shows iteration history.
- **Render Tab:** "Render Now" button → calls API → shows generated image. "Download SVG/PNG" button.
- **History Tab:** List of past sessions with thumbnails, cost, and status.

### Key JS Functions
```javascript
// assets/app.js skeleton

let currentSessionUuid = null;

async function createSession(description) {
    const res = await fetch('/api/v1/generate', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({description})
    });
    const data = await res.json();
    currentSessionUuid = data.data.session_uuid;
    renderBrief(data.data.brief);
    return data;
}

async function iterate(feedback) {
    const res = await fetch('/api/v1/iterate', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({session_uuid: currentSessionUuid, feedback})
    });
    const data = await res.json();
    renderBrief(data.data.brief);
    addToChat('user', feedback);
    addToChat('system', `Brief updated (iteration ${data.data.iteration})`);
    return data;
}

async function renderLogo(renderer = 'recraft') {
    const res = await fetch('/api/v1/render', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({session_uuid: currentSessionUuid, renderer})
    });
    const data = await res.json();
    showImage(data.data.output_url);
    showCost(data.data.cost_usd);
    return data;
}

function renderBrief(brief) { /* update DOM */ }
function showImage(url) { /* create img element */ }
function showImage(url) {
    const container = document.getElementById('image-container');
    container.innerHTML = '';
    const img = document.createElement('img');
    img.src = url;
    img.style.maxWidth = '512px';
    img.style.border = '1px solid #ddd';
    container.appendChild(img);

    const download = document.createElement('a');
    download.href = url;
    download.download = 'logo.svg';
    download.textContent = 'Download';
    container.appendChild(download);
}
function showCost(cost) {
    document.getElementById('cost-display').textContent = `Cost: $${cost.toFixed(2)}`;
}
function addToChat(role, text) { /* append to chat log */ }
```

### Dashboard Layout (HTML skeleton)
```html
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Sigil — Logo Generator</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 900px; margin: 0 auto; padding: 20px; }
        .tabs { display: flex; gap: 10px; border-bottom: 2px solid #ddd; margin-bottom: 20px; }
        .tab { padding: 10px 20px; cursor: pointer; }
        .tab.active { border-bottom: 2px solid #007bff; color: #007bff; }
        .panel { display: none; }
        .panel.active { display: block; }
        textarea { width: 100%; height: 100px; font-family: inherit; }
        button { padding: 10px 20px; background: #007bff; color: white; border: none; cursor: pointer; }
        button:hover { background: #0056b3; }
        #brief-display { background: #f5f5f5; padding: 15px; border-radius: 8px; margin: 15px 0; white-space: pre-wrap; }
        #chat-log { height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; margin: 10px 0; }
        .chat-msg { margin: 5px 0; padding: 8px; border-radius: 4px; }
        .chat-msg.user { background: #e3f2fd; text-align: right; }
        .chat-msg.system { background: #f5f5f5; }
        #image-container { margin: 20px 0; text-align: center; }
        #cost-display { font-weight: bold; color: #28a745; }
    </style>
</head>
<body>
    <h1>Sigil</h1>
    <div class="tabs">
        <div class="tab active" onclick="showPanel('create')">1. Create</div>
        <div class="tab" onclick="showPanel('iterate')">2. Iterate</div>
        <div class="tab" onclick="showPanel('render')">3. Render</div>
        <div class="tab" onclick="showPanel('history')">History</div>
    </div>

    <div id="create" class="panel active">
        <h2>Describe your project</h2>
        <textarea id="description" placeholder="A Caribbean fintech app called Velora..."></textarea>
        <br><br>
        <button onclick="handleCreate()">Create Brief</button>
        <div id="brief-display"></div>
    </div>

    <div id="iterate" class="panel">
        <h2>Refine the brief</h2>
        <div id="chat-log"></div>
        <input type="text" id="feedback" placeholder="make it bolder, add a wave..." style="width:70%">
        <button onclick="handleIterate()">Refine</button>
    </div>

    <div id="render" class="panel">
        <h2>Generate Logo</h2>
        <select id="renderer">
            <option value="recraft">Recraft (SVG, editable)</option>
            <option value="ideogram">Ideogram (Wordmark, best text)</option>
        </select>
        <button onclick="handleRender()">Render Now</button>
        <div id="cost-display"></div>
        <div id="image-container"></div>
    </div>

    <div id="history" class="panel">
        <h2>Past Sessions</h2>
        <div id="history-list"></div>
    </div>

    <script src="/assets/app.js"></script>
</body>
</html>
```

---

## 12. Error Handling & Edge Cases

| Scenario | Behavior |
|----------|----------|
| Ollama down / timeout | Return error: "Local LLM unavailable. Check Ollama status." |
| Recraft API key invalid / exhausted | Return error with HTTP status from Recraft |
| Ideogram API key invalid | Return error, suggest checking `.env` |
| Invalid session UUID | Return 404 "Session not found" |
| Render called on non-existent brief | Return 400 "No brief found for this session. Create or iterate first." |
| Image download fails after API success | Retry once; if still fail, mark session failed, refund logic TBD |
| Storage directory not writable | Return 500 with explicit path in error message |
| MySQL down | Return 503, log to file fallback |
| Feedback is empty string | Return 400 "Feedback cannot be empty" |
| Description is empty | Return 400 "Description cannot be empty" |
| Rate limit exceeded | Return 429 with Retry-After header |

---

## 13. Build Order (DO NOT SKIP)

1. **Bootstrap:** `config.php`, `bootstrap.php`, `.env`, MySQL schema, `database.sql`
2. **Core:** `Database.php`, `Config.php`, `Response.php`, `Storage.php`, `Logger.php`
3. **LLM Layer:** `BriefGenerator.php` — test Ollama connectivity, validate prompt quality
4. **CLI:** `cli.php` with `generate` command — agent can create briefs immediately
5. **API:** `api.php` + `/api/v1/generate` endpoint
6. **Render Layer:** `RecraftClient.php` — test with a real API key (one $0.04 call)
7. **Pipeline:** `GenerationPipeline.php` — wire brief → render → store
8. **CLI:** Add `render`, `iterate`, `history` commands
9. **API:** Add `/iterate`, `/render`, `/session/{uuid}` endpoints
10. **Web Dashboard:** `index.html` + `assets/app.js` — full create/iterate/render flow
11. **Render Layer:** `IdeogramClient.php` — test as secondary renderer
12. **ChatIterator:** `ChatIterator.php` — iteration history and feedback refinement

---

## 14. Testing Checklist (Validate Before Marking Done)

- [ ] `php cli.php generate --description="A modern tech startup called Nexus"` returns a coherent brief
- [ ] `php cli.php iterate --uuid="..." --feedback="make it more minimal"` returns a refined brief (free, no API call)
- [ ] `php cli.php render --uuid="..." --renderer="recraft"` produces a valid SVG file in `/storage/generated/`
- [ ] `php cli.php render --uuid="..." --renderer="ideogram"` produces a valid PNG file
- [ ] Dashboard: Create → Iterate → Render flow works end-to-end in browser
- [ ] API: All endpoints return valid JSON
- [ ] `php cli.php health` reports DB connected, Ollama reachable, Recraft/Ideogram keys present
- [ ] Cost tracking: `cost_log` table shows accurate entries after each render
- [ ] Session history: `php cli.php history --uuid="..."` shows all iterations in order
- [ ] File cleanup: Old files in `/storage/generated/` older than 30 days are auto-deleted (cron or on-demand)

---

## 15. Cost Awareness

| Action | Cost | Notes |
|--------|------|-------|
| Create brief | $0.00 | Local Ollama |
| Iterate brief | $0.00 | Local Ollama |
| Render via Recraft | ~$0.03–0.05 | Per SVG |
| Render via Ideogram | ~$0.08–0.10 | Per PNG |

**The whole point of the 3-step pipeline:** Iterate as much as you want for free. Only pay when you render.

---

## 16. Agent Integration Notes

The coding agent (or any external tool) should call Sigil via:

**CLI (preferred for local/agent use):**
```bash
# Step 1: Create brief
php /path/to/sigil/cli.php generate --description="A Caribbean fintech app called Velora" --pretty
# → captures session_uuid

# Step 2: Iterate (free)
php /path/to/sigil/cli.php iterate --uuid="a1b2c3d4-..." --feedback="add wave element, navy blue"

# Step 3: Render (costs money)
php /path/to/sigil/cli.php render --uuid="a1b2c3d4-..." --renderer="recraft"
# → returns output_url to download SVG
```

**API (preferred for remote/services):**
```bash
# Create
curl -X POST http://sigil.local/api/v1/generate   -H "Content-Type: application/json"   -d '{"description":"A Caribbean fintech app called Velora"}'

# Iterate
curl -X POST http://sigil.local/api/v1/iterate   -H "Content-Type: application/json"   -d '{"session_uuid":"a1b2c3d4-...","feedback":"make it bolder"}'

# Render
curl -X POST http://sigil.local/api/v1/render   -H "Content-Type: application/json"   -d '{"session_uuid":"a1b2c3d4-...","renderer":"recraft"}'
```

Both return the same JSON structure. The agent parses `data.output_url` to retrieve the generated file.
