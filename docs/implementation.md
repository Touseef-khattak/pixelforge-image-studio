# Implementation plan and status

Rule: every commit is small enough for a human to review easily in a PR (see `CLAUDE.md`). This file is updated after each phase, in its own commit.

Status legend: [ ] todo, [~] in progress, [x] done.

## Phase 0: Foundations [x]
- [x] Docs folder, CLAUDE.md, API and money docs
- [x] Check toolchain (PHP 8.2.0, Composer 2.5.8, Node 22, git); `git init`
- [x] Scaffold Laravel 12 + Breeze (Vue + Inertia, Pest) as separate generated-code commits
- [x] Env config for Pixelforge (`config/pixelforge.php`, `.env.example`)

## Phase 1: Money core [x]
- [x] `App\Support\Money`: nano-dollar formatting
- [x] `App\Pricing\PricingService`: cost, charge, estimate range (tests include the $0.373875 worst case)

## Phase 2: Data model [x]
- [x] `generations` migration + model + factory
- [x] `webhook_events` migration + model
- [x] `ledger_entries` migration + model (unique per generation + type)

## Phase 3: Provider client and submission [x]
- [x] `PixelforgeClient` (create, get); `usage` is added with the admin report in Phase 7
- [x] Generate request validation + controller storing a queued generation (`POST /generations`)
- [x] `SubmitGeneration` job with idempotency key and error handling (429s, 503, permanent errors)

## Phase 4: Webhooks and finalization [x]
- [x] Signature verifier (raw body, timestamp tolerance)
- [x] Webhook controller with event dedupe
- [x] `ApplyGenerationUpdate` action: monotonic state machine, exactly-once cost + ledger
- [x] Lookup by provider id or `metadata.local_id` (event before create returns)
- [x] Polling reconciliation command + schedule
- [x] Verified end to end against the real API (polling path); real webhook delivery is still to be verified once a public URL exists (Phase 6)

## Phase 5: UI
- [ ] Generate page with live estimate range
- [ ] History page with live status, estimate vs actual, image, errors

## Phase 6: Deploy and verify
- [ ] Deploy (queue worker + scheduler), set webhook URL, secrets
- [ ] Leave test generations on the deployed key (success, failure, slow, duplicate)
- [ ] Compare with `GET /v1/usage`

## Phase 7: Stretch goals
- [ ] Balances, buy credits, block when unaffordable
- [ ] Admin report by day and user, with usage reconciliation command

## Decisions log
### Phase 0
- Laravel 12 (not 13): the local PHP is 8.2.0, which Laravel 12 supports.
- Breeze (Vue + Inertia) for auth instead of the newer starter kits, because it installs with Composer alone. 25 stock tests pass.
- Local DB is SQLite, queue driver is `database` (Laravel 12 defaults). Production DB is chosen in Phase 6.
- Generated scaffolding is committed on its own so hand-written diffs stay small.

### Phase 1
- Rates are per-token nano-dollars (`$X/M` = `X*1000`). `Rates::published()` holds the documented rates; a `Rates` built from a generation's own `rates` object comes in Phase 3, once the payload shape is confirmed against the live API.
- The documented worst case ($0.373875) only reconciles if text tokens are scaled up by the full +15% (100 tokens -> 115). So the max estimate uses `ceil(est*1.15)` and the min uses `floor(est*0.85)`.
- The charge is `ceil(cost * 125 / 100)`. With published rates the cost is always a multiple of 500, so this is exact today; the rounding is only a safety net, and it lives in one place.
- Estimates are stored and shown as a range (min to max). Balance checks will use the max.

### Phase 2
- `generations.id` is a UUID. It is the `Idempotency-Key` sent to Pixelforge, and the value I will put in request `metadata` so a webhook that arrives before `provider_generation_id` is stored can still be matched.
- Money columns are `unsignedBigInteger` nano-dollars: `estimated_min/max_nanos`, `provider_cost_nanos`, `charged_nanos`.
- Exactly-once guarantees are enforced by the database, not just code: unique `webhook_events.event_id` and unique `ledger_entries (generation_id, type)`. Both have tests.
- `ledger_entries` holds `charge` and `topup` rows with positive amounts; the `type` gives the direction. Each charge row also stores `provider_cost_nanos` so profit is a plain sum later.
### Phase 3
- Verified against the live API with the local key: one real generation (cost 12,377,500 nano-dollars, charge 15,471,875) is now a test fixture, and error bodies are `{"error": {"type", "code", "message"}}` with `Retry-After` on 503.
- `Rates::fromApi` parses rates as strings (no floats) and rejects more than 3 decimals per 1M tokens, so a rate change can never silently introduce rounding.
- Retry policy in `SubmitGeneration`: retryable = 429 (except daily quota), 5xx and connection failures. It releases the job after `Retry-After` plus 0-3s jitter, bounded by a 15 minute window rather than an attempt count (the concurrency limit of 3 makes many retries normal). Permanent errors fail the generation immediately with the provider's message; exhausted retries fail it as `submission_failed`. Neither charges anything.
- The job writes only `provider_generation_id` and `rates`, using `whereNull(...)->update()`, so it can never regress a status set by an earlier webhook.
- The estimate saved on a generation already includes the markup (`estimated_min/max_nanos` are user-facing charges), computed from `Rates::published()`. A later improvement is to read live rates from `GET /v1/pricing`.
- `reference_images` are validated as http(s) URLs (max 4). Prompt length uses `mb_strlen`; whether Pixelforge counts characters or bytes for non-ASCII prompts is unverified.
- The endpoint redirects back with a flash message for now; Phase 5 redirects to the history page.

### Phase 4
- One action, `ApplyGenerationUpdate`, is the only code that moves a generation forward or charges it. The webhook and the poller both call it, so there is a single pricing path.
- Exactly-once charging has three layers: the final-state check inside a transaction with `lockForUpdate`, the unique `webhook_events.event_id`, and the unique `ledger_entries (generation_id, type)`. A test proves a failed ledger insert rolls the status change back.
- The webhook records the event and applies it in one transaction. If applying throws, the event row rolls back and the endpoint answers 5xx, so Pixelforge's retry (10s, 30s, 2m, 10m, 15m) gets a clean second attempt instead of being swallowed as a duplicate.
- The webhook route is registered in `bootstrap/app.php` outside the `web` middleware group, so it has no session, cookies or CSRF. Authenticity comes only from the HMAC.
- Signature timestamp: the code accepts unix seconds, and falls back to `strtotime` for an ISO string. **Unverified** until a real delivery arrives, because the docs give only `t=<timestamp>`. Tolerance is 300s (`PIXELFORGE_WEBHOOK_TOLERANCE`).
- Polling: `generations:reconcile` runs every minute and picks generations with a provider id, not final, and no update for 2 minutes. Rows with no provider id are left to `SubmitGeneration`'s own retries. The scheduler (`schedule:run` every minute) must be running on every environment, or lost webhooks are never recovered.
- Real check: 3 real generations (one made by hand, two through the app code and the poller) totalled 38,325,000 nano-dollars, and `GET /v1/usage` reported `0.038325000`. Resubmitting an already-created generation returned the same Pixelforge id (idempotency confirmed live).

### Local development note
Laragon/WAMP PHP often has no CA bundle, so HTTPS calls fail with cURL error 60. The client treats that as a transient error and retries, so it looks like a hang rather than a crash. Fix with `curl.cainfo` and `openssl.cafile` in `php.ini`, or run with `php -d curl.cainfo=<bundle> -d openssl.cafile=<bundle>`. Git for Windows ships a bundle at `C:\Program Files\Git\mingw64\etc\ssl\certs\ca-bundle.crt`.

## Not yet decided
- The user's balance column. It is a stretch goal and can be derived as topups minus charges from the ledger.

## Open questions
(none yet)
