# Sigil — Development Plan

> Mirrored in the Meridian tracker (`project_id: sigil`, tracker `dev`, id 42). Phase numbers
> here match tracker item numbering. Progress is Meridian's weighted figure: `done` = 1,
> `partial` = 0.5, `deferred`/`external` excluded. **81%** as of 2026-08-19; the live tracker
> is authoritative if this mirror lags.

Sigil is a **logo generator**. You describe what something does, and it gives you logo options
back. The default model is free; premium models are available when a result is worth paying for.

---

## How progress is measured

The previous version of this file reported **"85% — build complete and verified"** while the tool
had never produced a single logo. Every "successful" render in the database was an 800-byte
placeholder rectangle drawn by a `StubProvider`, and every real provider call had failed for a
missing API key. The plumbing was genuinely finished; the product did not exist.

So progress here is measured against **output you would actually use**, not against checked boxes.
A phase is not done because the code path runs — it is done when it produces a logo.

**Current state: the free path works end to end.** Concept → variants → real images, at zero cost,
verified by generating them. The premium paths are coded but unverified — see Phase 4.

---

## Phases 1–8 — the original build

Kept in the tracker as history. Six items that reported `done` were corrected on 2026-08-19 to
`partial`/`deferred`/`blocked`, because they described code that ran rather than output that
existed: the Recraft and Ideogram providers (never called with a key), the stub provider (since
deleted), the 17/17 smoke run (passed against placeholders), the "web tested" claim (its API key
had been revoked), and the paid-render item (no key has ever been present).

---

## Phase 9 — Redirect: free-first model roster ✅ done

- [x] Add a genuinely free model (Pollinations: no key, no account, no cost) as the default
- [x] Delete `StubProvider` entirely — a fake render must never be mistakable for a real one
- [x] Add Gemini / "Nano Banana" as the premium option
- [x] Config-driven model registry (`ProviderRegistry`) — adding a model is a config edit
- [x] `renders.provider` / `output_format` / `briefs.style` off ENUMs onto VARCHARs
- [x] A model is only offered when it can actually run; premium models are gated on their key

## Phase 10 — Two input fields ✅ done

- [x] Field 1: what the entity does (required)
- [x] Field 2: what the logo should look like (optional — blank means "you suggest")
- [x] Entity type (company / app / organization / nonprofit / political / product / event /
      personal / other) so it is not app-specific
- [x] Optional brand name, needed because no image model can infer exact lettering
- [x] `logo_description`, `entity_type`, `brand_name` persisted on `briefs`

## Phase 11 — Suggest options, not one take ✅ done

- [x] One run produces a batch of variants (default 4) in a picker grid
- [x] Variants are distinct **concepts**, not seed jitter: the local LLM proposes N different
      visual ideas from the same brief, for free, before anything is rendered
- [x] Batch grouping on `renders` (`batch_id`, `variant_index`) + `/api/v1/batch/{id}`
- [x] Cost shown per model and per batch before you click

## Phase 12 — Concept and prompt quality ✅ done (free path)

- [x] Moved brief authoring off `qwen2.5vl:3b` — a *vision* model that was writing stock-photo
      prose ("a business woman using a smartphone") — onto `qwen2.5:3b`, a text model
      - `llama3.1:8b` writes better but needs ~5GB and will not load on this 7GB box
- [x] Vision model kept, but only for looking at a previous render during iteration
- [x] Rewrote prompts: concepts only, no "Subject:/Style:/Palette:/Mood:" label blocks
- [x] `LogoPromptBuilder` composes the image prompt deterministically in code
      - style tokens lead, concept is trimmed, no long negative list — **shape chosen by testing
        against the real model**, not by reasoning about it
- [x] Strip qwen's bilingual leakage (it restates answers in Chinese)

## Phase 13 — Verification ✅ done

- [x] `tests/smoke.php` rewritten to generate **real images** and assert they are not placeholders
- [x] Free path verified end to end by generating and looking at the output
- [ ] Review a full batch of final-shape renders for quality
- [x] Web UI walked through in a browser (Apache subdirectory and dev-server root)

## Phase 14 — Premium models ⏸ blocked on keys (owner action)

None of these have ever been called successfully, because no key has ever been present. They are
coded and gated, and each is **unverified** until it renders something:

- [ ] `GEMINI_API_KEY` → live Nano Banana render
- [ ] `RECRAFT_API_KEY` → live Recraft render (the only true SVG path)
- [ ] `IDEOGRAM_API_KEY` → live Ideogram render (wordmarks with real text)

## Phase 15 — Deploy 🔄 local preparation complete

- [ ] Push to github.com/jimmyraymour/sigil (owner approval)
- [x] nginx production config + deploy notes (`deploy/nginx/sigil.conf`, `DEPLOY.md`)
- [x] Hardening artifacts: PHP limits retained, nginx edge burst limit, logrotate, backups

---

## De-scoped (kept, not invested in)

The CLI, REST auth, API keys, rate limiting, signed asset URLs and cost caps all still work and
still pass. They are not part of the plan any more. For a single user on one box they absorbed
effort that belonged in output quality, which is what went wrong the first time.
