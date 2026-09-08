# Sigil

> **Working on this repo as a coding agent? Read [`AGENT_RULES.md`](AGENT_RULES.md) first.**
> It is the shared, vendor-neutral memory for this project: how it is built, the decisions behind
> it, the environment traps, and the mandatory Meridian tracker protocol.

A **logo generator**. Describe what a company, app or organization does, and Sigil gives you a
set of logo options back. The default model is **free** — no API key, no account, no cost.
Premium models are there for when a result is worth paying for.

Built with **vanilla PHP 8.1+**, **MySQL 8**, and **plain ES6 JavaScript**. No framework, no
Composer, no external packages — everything is plain PHP you can read and debug yourself.

## The flow

```
two descriptions  →  concept (free, local LLM)  →  refine (free, local LLM)  →  images (free by default)
```

You fill in two fields:

1. **What does it do?** — required. "A community land trust that leases farmland to
   first-generation farmers."
2. **What should the logo look like?** — optional. Leave it blank and Sigil proposes the
   concepts itself; that is the normal way to use it.

Then pick a model and how many options you want. One run gives you a grid of variants to
choose from, not a single take — and the variants are genuinely different visual ideas, because
the local LLM proposes each one before anything is rendered. That step is free.

## Models

| Model | Tier | Cost | Output | Notes |
|---|---|---|---|---|
| **Pollinations** | free | **$0.00** | PNG/JPG | Default. No key, no account. |
| Nano Banana (Gemini) | premium | ~$0.04 | PNG | Strong prompt-following. `GEMINI_API_KEY` |
| Recraft | premium | ~$0.04 | **SVG**/PNG | The only true editable-vector output. `RECRAFT_API_KEY` |
| Ideogram | premium | ~$0.10 | PNG | Best at readable text — use for wordmarks. `IDEOGRAM_API_KEY` |

A premium model stays greyed out in the UI until its key is in `.env`, so you can never pick a
model that is guaranteed to fail. Adding another model is a config edit plus a provider class —
no schema migration.

> **Verification status:** the free path is verified end to end by generating real images. The
> three premium providers are coded and gated but have **never been called with a live key** —
> treat them as unverified until you run one.

## Requirements

- PHP 8.1+ with `pdo_mysql`, `curl`, `json`, `mbstring`, `fileinfo`, `openssl`
- MySQL 8
- Ollama running locally with a **text** model — `qwen2.5:3b` by default
  - A larger model writes better concepts, but `llama3.1:8b` needs ~5GB and will not load
    on a 7GB box alongside MySQL.
  - A vision model (`qwen2.5vl:3b`) is used only to look at a previous render while iterating.
- No image API key is needed to use Sigil.

## Where it runs

Sigil detects its own mount point, so the same code works at a domain root or in a
subdirectory with no configuration.

| Setup | URL |
|---|---|
| XAMPP (this box) | `http://localhost/sigil/` |
| PHP dev server | `http://127.0.0.1:8001/` |
| nginx | wherever you point the root |

The project-root `.htaccess` is what makes the XAMPP case work: it routes requests into
`public/` and **blocks everything above it**. Without it Apache serves a directory listing of
the whole repository, including `.env` and the Meridian guide. Do not delete it.

Two things the web server needs:

```bash
# Apache workers (daemon:daemon under XAMPP) must be able to write renders and logs.
chgrp -R daemon storage && chmod -R g+w storage

# Apache must pass the Authorization header to PHP, or every API call 401s.
# Handled by the root .htaccess; nothing to do unless you replace it.
```

Set `APP_BASE_PATH` in `.env` only if auto-detection guesses wrong.

## Quick start

```bash
# 1. Configure
cp .env.example .env
# edit .env: DB_PASS, APP_KEY (php -r "echo bin2hex(random_bytes(32));")
# image API keys are optional — the default model needs none

# 2. Database
mysql -u root -p < database/schema.sql
# upgrading an existing install:
mysql -u root -p sigil < database/migrations/001_logo_redirect.sql
mysql -u root -p sigil < database/migrations/002_brand_name.sql

# 3. Create an API key for the web UI
php bin/sigil key:create web        # copy the returned key into .env as WEB_API_KEY

# 4. Run (dev)
php -S 127.0.0.1:8001 -t public public/dev-router.php
# open http://127.0.0.1:8001
```

## CLI

```
php bin/sigil help
php bin/sigil health
php bin/sigil models                       # what is configured, and what each costs
php bin/sigil key:create <name>            # plaintext shown once

# The two fields: the description is positional, --logo is the optional second one.
php bin/sigil brief "A neighbourhood bike repair co-op run by volunteers" \
  --logo="a single gear, very simple, one colour" --name="Spoke" --type=organization

php bin/sigil iterate <brief-id> "drop the outer circle, make it bolder"

# Free by default. --variants gives you options to choose from.
php bin/sigil render --brief=<brief-id> --variants=4 --out=/tmp/logos/

# Premium, named explicitly so it is never a surprise charge:
php bin/sigil render --brief=<brief-id> --model=recraft --format=svg --out=/tmp/logos/
```

## REST API

All endpoints are under `/api/v1` and need `Authorization: Bearer <key>` except `/health`.

| Method | Path | What it does |
|---|---|---|
| GET | `/health` | DB / Ollama / model status |
| GET | `/models` | Model roster, cost, and whether each is configured |
| POST | `/brief` | `description` (required), `logo_description`, `entity_type`, `brand_name`, `style`, `palette_hint` |
| GET | `/brief/{id}` | A brief plus its full revision chain |
| POST | `/iterate` | `brief_id`, `feedback` — free, local LLM |
| POST | `/render` | `brief_id`, `provider`, `variants`, `output_format` — returns a batch |
| GET | `/batch/{id}` | Every variant in one batch |
| GET | `/render/{id}` | One render |
| GET | `/library` | Recent renders |

## Entity types

Sigil is not app-specific. `entity_type` accepts `company`, `app`, `organization`, `nonprofit`,
`political`, `product`, `event`, `personal` and `other`, and it steers the concept accordingly.

## How the image prompt is built

The local LLM writes only the **concept** — what the mark depicts. Everything else (style tokens,
palette, background, text policy) is composed deterministically in `LogoPromptBuilder`, because a
small local model asked to describe "style, palette, mood" drifts into photographic prose, and
whatever it writes goes straight to the image model.

The prompt's shape was chosen by testing against the real model rather than by reasoning about it:

- style tokens go **first** — a prompt that opens with wordy prose comes back as a muddy painting
- the concept is **trimmed** to ~160 characters
- `solid white background` sits **early**; placed last it gets ignored and the canvas goes dark
- there is **no long list of negatives** — these models have no negative-prompt channel, so
  naming a thing can summon it

## Post-processing

Image models routinely ignore "solid white background" — the free one returns marks on flat grey
(measured 212–218 across runs), floating in a wide margin. That is not something you can drop
into a document, and it does not need another model to fix, so Sigil corrects it arithmetically
after the render:

1. Detect a flat background from the border ring.
2. Snap near-background pixels to pure white (or to transparency).
3. Trim the dead margin and re-frame the mark square with even padding.

It is deliberately conservative. If the border is not flat, or not light and neutral, the image
is returned untouched — a deep-navy emblem on a navy field is a design decision, not a defect.
SVG is never rasterised. Corrected output is always PNG, because re-encoding to JPEG would
reintroduce halos around the edges just cleaned.

```
POSTPROCESS_ENABLED=true       # master switch
POSTPROCESS_TRIM=true          # crop the margin and re-centre
POSTPROCESS_TRANSPARENT=false  # true = transparent PNG instead of white
```

## Cost control

- The free model is the default and never touches the cost cap.
- The UI shows the total before you click: *"4 images · about $0.16 total"*.
- `COST_LIMIT_USD_PER_DAY` is checked against the **projected** batch cost, so a batch is
  refused before it spends rather than after.
- Unconfigured models are rejected up front with the name of the env var they need.

## Storage

Renders live in `storage/renders/` and are never served directly by the web server — access goes
through `render.php`, gated by an API key or a signed, expiring URL.

## Testing

```bash
php tests/smoke.php
```

This generates **real images** on the free model and asserts they are not placeholders. It needs
Ollama running and outbound HTTPS, and takes a couple of minutes. There is deliberately no stub
provider: the previous build passed its smoke test while never producing a logo, because the only
thing it ever rendered was a placeholder rectangle.
