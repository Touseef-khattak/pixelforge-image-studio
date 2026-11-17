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

## Phase 2: Data model
- [ ] `generations` migration + model
- [ ] `webhook_events` migration + model
- [ ] `ledger_entries` migration + model (unique per generation + type)

## Phase 3: Provider client and submission
- [ ] `PixelforgeClient` (create, get, list, usage)
- [ ] Generate request validation + controller storing a queued generation
- [ ] `SubmitGeneration` job with idempotency key and error handling (429s, 503, permanent errors)

## Phase 4: Webhooks and finalization
- [ ] Signature verifier (raw body, timestamp tolerance)
- [ ] Webhook controller with event dedupe
- [ ] `FinalizeGeneration` action: monotonic state machine, exactly-once cost + ledger
- [ ] Lookup by provider id or `metadata.local_id` (event before create returns)
- [ ] Polling reconciliation command + schedule

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

## Open questions
(none yet)
