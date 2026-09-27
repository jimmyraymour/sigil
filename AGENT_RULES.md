# AGENT_RULES.md — Sigil

**Read this before doing anything else in this repository.**

Sigil is worked on by coding agents from different vendors (Claude Code, Codex, Antigravity,
Cursor, …). No agent's private memory carries over to the next one, so **this file is the shared
memory**. It is committed, vendor-neutral, and authoritative. If you learn something the next
agent would need, it belongs here — not in your own notes.

Companion documents in this repo:

| File | What it holds |
|---|---|
| `AGENT_RULES.md` (this) | How to work here, and how to report to Meridian |
| `README.md` | What Sigil is, how it runs, how the pipeline works |
| `PLAN.md` | Phases, mirrored to the Meridian tracker |
| `MERIDIAN_GUIDE.md` | Full API guide. **Git-ignored — it contains a live key.** Re-fetch: §2.4 |

---

# Part 1 — Meridian is not optional

Meridian is the operator's portfolio tracker. It holds this project's task tree and a log of every
agent work session. Keeping it current is **part of the work, not a separate favour**.

> **Never ask the operator whether to update the tracker. Never offer it as an option.**
> You finished something → you update the item. You got blocked → you set `blocked` and raise an
> alert. You ended a session → you post a report. Silently, as a matter of course, the same way you
> would save a file.

The operator's most common complaint is a tracker that has gone stale. A phase marked `done` that
never produced working output is worse than one honestly marked `partial`.

## 1.1 Credentials

| What | Value |
|---|---|
| Project id | `sigil` (your key can touch nothing else) |
| API base | `https://meridian.raymour.lat/api` — **https, via the vhost**. The old `http://localhost/meridian/api` is dead (404) and silently broke reporting for a month; never reintroduce it. |
| Auth header | `X-MC-Key: $MERIDIAN_API_KEY` on **every** request, no exceptions |
| Content type | `application/json` on every request with a body |

The key lives in `.env` as `MERIDIAN_API_KEY` (also `MERIDIAN_API_URL`, `MERIDIAN_PROJECT_ID`).
`.env` is git-ignored — **never commit the key, never paste it into a report, an alert, a log, or
a commit message.** If `.env` has no key, ask the operator for it; you cannot mint one.

> Use `http://`. The host also answers on `https://` but with a self-signed certificate, which
> makes every client fail on verification unless it disables checks. Plain `http` is the
> documented, working route.

## 1.2 Start of session — orient yourself first

```sh
# What is the last reported phase, is the project flagged, what alerts are open?
curl -s -H "X-MC-Key: $MERIDIAN_API_KEY" "$MERIDIAN_API_URL/dashboard"

# The current task tree — flat array, rebuild it via each item's parent_id
curl -s -H "X-MC-Key: $MERIDIAN_API_KEY" "$MERIDIAN_API_URL/items?project_id=sigil"
```

Do this before planning. It tells you what the previous agent (possibly from another vendor) left
behind and what the operator is still waiting on.

## 1.3 During work — keep items current

The tree is `phase` (top level) → `subphase` (child) → `task` (deeper). **`item_type` is derived
from depth by the server — never send it.**

```sh
# Update: POST with item_id. Send only what changes; omitted fields keep their value.
curl -s -X POST "$MERIDIAN_API_URL/items" \
  -H "X-MC-Key: $MERIDIAN_API_KEY" -H "Content-Type: application/json" \
  -d '{"project_id":"sigil","item_id":2337,"status":"done"}'

# Create: same endpoint, no item_id. title required; parent_id nests it.
curl -s -X POST "$MERIDIAN_API_URL/items" \
  -H "X-MC-Key: $MERIDIAN_API_KEY" -H "Content-Type: application/json" \
  -d '{"project_id":"sigil","tracker_type":"dev","title":"Phase 19 — Thing","status":"in_progress","priority":"high","notes":"why this exists"}'
```

Fields you may set: `title`, `status`, `priority`, `section_tag`, `notes`.
`sort_order` and re-parenting are UI-only — create items in the order you want them.

### Pick the status honestly — progress bars depend on it

| Status | Means | Weight |
|---|---|---|
| `pending` | Not started | 0 (counts in denominator) |
| `in_progress` | Being worked on now | 0 (counts in denominator) |
| `partial` | Genuinely half-done | 0.5 |
| `done` | **Complete and verified** | 1.0 |
| `blocked` | Cannot proceed — also raise an alert | 0 (counts in denominator) |
| `deferred` | Consciously postponed | excluded entirely |
| `external` | Waiting on someone outside the project | excluded entirely |

**`done` means it produced real, verified output.** Not "the code path runs". Not "it would work
once a key is added" — that is `partial` or `blocked`. See Part 2, which exists because this
project was rejected for getting exactly this wrong.

Deletion (`DELETE /items?project_id=sigil&item_id=<id>`) is permanent, refuses items with children
(`409`), and has no undo. Prefer `deferred` for work that is merely being dropped.

## 1.4 When blocked — alert immediately, don't wait

```sh
curl -s -X POST "$MERIDIAN_API_URL/alerts" \
  -H "X-MC-Key: $MERIDIAN_API_KEY" -H "Content-Type: application/json" \
  -d '{"project_id":"sigil","source":"<your-agent-name>","alert_type":"agent_blocked",
       "severity":"critical","title":"[sigil] Short summary",
       "body":"What you tried, what failed, what you need","source_ref":"commit abc123"}'
```

`severity`: `info` · `warning` · `critical`.
`alert_type`: `agent_blocked` · `build_failure` · `payment_issue` · `platform_warning` ·
`customer_complaint` · `channel_alert` · `general`.

Set the matching tracker item to `blocked` too. If you later resolve it yourself:
`POST /alerts/ack` with `{"id":<alert_id>}`. **Only ack what you actually resolved** — open alert
ids come from `GET /dashboard`.

## 1.5 End of session — always post a report

Required every session. A project silent for 3 days is auto-flagged yellow.

```sh
curl -s -X POST "$MERIDIAN_API_URL/reports" \
  -H "X-MC-Key: $MERIDIAN_API_KEY" -H "Content-Type: application/json" \
  -d '{"project_id":"sigil","tracker_type":"dev","submitted_by":"<your-agent-name>",
       "health_status":"green","phase":"Phase N — label",
       "goals_completed":"Concretely what got done","blockers":"none",
       "next_milestone":"The next concrete step","requires_human":false,
       "artifact_ref":"commit <hash>","api_cost_usd":0,"notes":""}'
```

All of `project_id`, `tracker_type`, `submitted_by`, `health_status`, `phase`, `goals_completed`,
`blockers`, `next_milestone` are **required and non-empty** — write `"none"` for no blockers.
`health_status`: `green` on track · `yellow` watch/waiting · `red` blocked.

- **Always send `artifact_ref`** — a commit hash or file path. It is your proof of work.
- Set `requires_human: true` when you need a credential or a decision; it opens a triage alert
  automatically (critical if `health_status` is `red`, else warning).
- Put session spend in `api_cost_usd`; it books a cost-ledger row by itself.

**The report is the narrative; the tracker is the state. Doing one does not excuse skipping the
other.**

## 1.6 Spend outside a session

```sh
curl -s -X POST "$MERIDIAN_API_URL/costs" -H "X-MC-Key: $MERIDIAN_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"project_id":"sigil","amount_usd":0.1250,"category":"api_other",
       "cost_date":"2026-08-19","description":"Nightly job","submitted_by":"cron"}'
```

`category` must be one of `api_openrouter` · `api_anthropic` · `api_other` · `vps` · `domain` ·
`subscription` · `other`. Cost rows have no delete — **do not write test rows.** To prove the
endpoint works, send a deliberately invalid `category` and confirm the `422`.

## 1.7 Endpoint map (re-verified live 2026-09-27 against `https://meridian.raymour.lat/api`)

| Method | Endpoint | Purpose | Verified |
|---|---|---|---|
| `GET` | `/projects` | Confirm identity / key health | 200 (1 project) |
| `GET` | `/items?project_id=sigil&tracker_id=42` | Read the tree (`&status=`, `&section_tag=`) | 200 (109 items) |
| `POST` | `/items` | Create (no `item_id`) or update (with `item_id`) | 200 |
| `DELETE` | `/items?project_id=sigil&item_id=<id>` | Delete a childless item | — |
| `GET` | `/dashboard?project_id=sigil` | Last report, flags, open alerts | 200 |
| `POST` | `/reports` | Work-session report | 201 |
| `POST` | `/alerts` | Raise an alert | — |
| `POST` | `/alerts/ack` | Acknowledge one you resolved | — |
| `POST` | `/costs` | Standalone spend | — |
| `GET` | `/reminders` | Expense reminders | 200 |
| `GET` | `/guide` | Re-download the full guide | 200 |

All six read/write paths were exercised on 2026-09-27 with the real key from `sigil/.env`; the base
URL in `.env`, `.env.example` and §1.1 is `https://meridian.raymour.lat/api`. The previous revision
of this table was verified against `http://localhost/meridian/api`, which has since 404'd — if a row
says "verified" against a base URL that is not the one above, re-verify it before trusting it.

`POST /projects` is master-key-only and answers `403 Forbidden: master key required`. **That is
expected, not a misconfiguration.** Sigil is already registered; never try to register it.

Trackers: `dev` = Development = `tracker_id` 42. It is the only one provisioned; you cannot create
another with a project key.

## 1.8 Errors, and what they actually mean

| Status | Meaning | Fix |
|---|---|---|
| `401 Unauthorized` | Header missing/misspelled, or key revoked | Check `X-MC-Key` is set in the environment you actually run in |
| `403 …scoped to project sigil` | You sent a different `project_id` | Only `sigil` |
| `403 master key required` | Master-only route | Expected — see §1.7 |
| `404 Unknown endpoint` | Wrong path | Paths are exactly as §1.7 |
| `404 Item not found in this project` | Bad `item_id` | Re-read the tree |
| `405 Method not allowed` | Wrong verb | The message names the accepted verbs |
| `409 Item has children` | Deleting a parent | Re-parent or delete children first |
| `422 Missing required field: X` | Empty/absent required field | Add it |
| `422 Invalid status: X` | Outside the enum | See §1.3 |
| `500` | Genuine server fault | Retry once, then raise an alert |

Every call — success or failure — is audited with your key label, endpoint, status and duration.
The operator can see exactly what you tried.

## 1.9 Re-fetch the guide (no new key needed)

```sh
curl -s -H "X-MC-Key: $MERIDIAN_API_KEY" "$MERIDIAN_API_URL/guide" -o MERIDIAN_GUIDE.md
```

Add `?format=json` for a JSON envelope. `MERIDIAN_GUIDE.md` is git-ignored because it embeds the
key. Never commit it. If the key leaks, tell the operator — they revoke and re-issue, and you just
update `MERIDIAN_API_KEY`.

---

# Part 2 — How to work on Sigil

## 2.1 The rule this project was rejected for breaking

Sigil was rejected once at "85% complete". It had **never produced a logo**. Every "successful"
render in the database was an 800-byte placeholder rectangle from a `StubProvider`; every real
provider call had failed for a missing API key; the smoke test passed by asserting against the
placeholder; and the web UI's API key had been revoked, so it returned 401 to every call.

Consequences, which are now project rules:

- **Mark `done` only what has produced real, verified output.** Run it, look at the artifact.
- **Never add a fallback that can be recorded as a success.** There is deliberately no stub
  provider. The free model needs no key, so offline testing is not a reason to fake output.
- **Blocked on a credential is `partial` or `blocked`, never `done`.**
- **Report progress against working output, not against checked boxes.**

## 2.2 Design decisions you should not undo without reason

- **Free by default.** Pollinations needs no key and costs nothing; it must stay the default.
  Premium models (Gemini/"Nano Banana", Recraft, Ideogram) are opt-in per request and hidden in
  the UI until their key is present. The operator wants to pay nothing for routine use.
- **Two input fields.** What the entity does (required) and what the logo should look like
  (optional — blank means Sigil proposes). Do not collapse them into one.
- **Generic entity types.** company / app / organization / nonprofit / political / product /
  event / personal / other. Sigil is not app-specific.
- **A run returns several options**, and the variants are distinct LLM-proposed concepts rather
  than the same prompt at different seeds.
- **Image-prompt discipline lives in code** (`LogoPromptBuilder`), not in the LLM's output. Its
  shape was chosen by generating images and looking at them: style tokens lead, the concept is
  trimmed short, `solid white background` sits early or the canvas comes back dark, and long
  negative lists make results worse.
- **Backgrounds are corrected arithmetically** (`ImagePostProcessor`), because image models ignore
  "white background" — the free one returns flat grey around 212–218. It is conservative on
  purpose and leaves genuinely dark or tinted fields alone.

## 2.3 Environment facts that will cost you time

Re-measured on this host 2026-09-27 (Linux, not XAMPP/Windows). Items marked ⚠ were wrong before.

- ⚠ **RAM: 12GB total, but often only ~300-500MB free** with swap in use — the box runs several
  agent sessions and a 5GB `llama-server` alongside. A model that fits on paper can still be
  evicted the instant a request arrives: Ollama then returns HTTP 200 with an **empty content and
  `done_reason: "load"` in 0.0s**, which looks exactly like a broken model. Check `free -m` and
  `/api/ps` before concluding a model is wrong.
- ⚠ **The vision model is `qwen3-vl:4b`** (3.3GB), not `qwen2.5vl:3b` — the latter is not
  installed, and Bifrost job `brief.vision` was pinned to it until 2026-09-27, so vision iteration
  could not run at all. Confirmed working: it answers a vision prompt correctly when it can stay
  resident.
- **Vision still times out on this host** at Bifrost's `brief.vision` 120s job timeout, because the
  model is evicted mid-request. The payload was reduced ~6.4x (768²/155KB -> 512²/25KB, see
  `llm.vision_max_side` and `Engine::downscaleForVision`) and that is necessary but not sufficient
  while memory is this tight. It is a capacity problem, not a code problem.
- `qwen2.5:3b` is the largest *text* model that comfortably fits. `llama3.1:8b` (4.9GB) does not.
- ⚠ **Served by its own vhost at `https://sigil.raymour.lat`**, behind Bifrost SSO (an
  unauthenticated GET / returns 302 to the Bifrost login). It is no longer mounted at
  `http://localhost/sigil/`; that path 404s. `BasePath` still detects the mount per request.
- **The project-root `.htaccess` is load-bearing.** It routes into `public/` and blocks everything
  above it. Without it Apache serves a directory listing of the whole repo including `.env`. Do
  not delete it.
- **Apache runs as `daemon`.** `storage/` must stay group-writable or every render fails with
  "Failed to write asset". The CLI works regardless because it runs as root — so test through the
  web server, not only the CLI.
- **Apache does not pass `Authorization` to PHP** without the `SetEnvIf`/rewrite in `.htaccess`;
  remove it and every authenticated API call 401s.
- **PHP `max_execution_time` is 120s** under Apache. Rendering a whole batch in one request
  overruns it — the browser renders one variant per request for this reason.
- **`imagepng($im, $path, 85)` fails under memory pressure** with "gd-png: fatal libpng error: bad
  parameters to zlib", silently writing a corrupt ~54-byte file. Use `imagejpeg()` (works, and is
  smaller) or `imagepng($im, $path, 6)`. This cost a real debugging cycle in the vision work.
- **The free image endpoint rate-limits bursts.** The smoke test calls it live and is therefore
  not perfectly deterministic; an occasional single-variant failure is the endpoint, not the code.

## 2.4 Before you call it done

```sh
php tests/smoke.php     # generates real images and asserts they are not placeholders
php bin/sigil health    # DB, Bifrost reachability, and which models are actually configured
```

`smoke.php` reports a failed check rather than dying when a provider is down (since 2026-09-27),
so a partial run still prints a summary and a non-zero exit. Read the whole output: a `FAIL … could
not run` line means the section was skipped by an outage, not that it passed.

Then open `https://sigil.raymour.lat` and look at the output. The suite cannot tell you whether a
logo is any good; only you can.
