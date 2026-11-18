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

## Phase 5: UI [x]
- [x] Generate page with live estimate range
- [x] History page with live status, estimate vs actual, image, errors
- [x] Navigation (New image, History); `/dashboard` redirects to the generate page
- [ ] Not yet seen in a real browser (see Phase 5 notes)

## Phase 6: Deploy and verify [~]
- [x] Deploy to Hostinger shared PHP hosting, with its own Pixelforge key, webhook URL and secret
- [x] Queue and reconciliation running from a single `schedule:run` cron
- [x] Real webhook delivery from Pixelforge accepted by the deployed endpoint
- [ ] Leave test generations on the deployed key (success, failure, slow, duplicate)
- [ ] Compare the production ledger with `GET /v1/usage` for the production key (steps in `testing-guide.md`)

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

### Phase 5
- The live estimate is fetched from `GET /generations/estimate`, which calls `PricingService`. No pricing arithmetic exists in JavaScript, so the number on the form is the number stored. Requests are debounced (300ms) and a response overtaken by a newer request is ignored.
- Money reaches the browser already formatted (`Money::format`) by `GenerationResource`. The browser never does money math. A failed generation shows `$0.00`; a running one shows "Known when finished".
- The history page polls every 3s only while a generation is unfinished, and pauses while the tab is hidden. Polling reloads only the `generations` prop.
- Expired image links are hidden server-side (`image_url_expires_at` in the past) and flagged `image_expired`; a link that fails to load shows the same "Image link expired" message.
- History shows the latest 50 generations. There is no pagination yet.
- The estimate uses `Rates::published()`, not live `GET /v1/pricing`. If Pixelforge changed rates, the estimate would drift while the final charge (which uses each generation's own `rates`) stays right.
- Feature tests now call `withoutVite()`, so they pass on a fresh clone without `npm run build`. Verified by temporarily removing `public/build`.
- **Not verified visually:** the Chrome extension was not connected, so the pages were checked only through Inertia prop tests and a successful `npm run build`. A manual pass in a browser is still needed (layout, polling behavior, estimate updates while typing).

### Phase 6 (deployment on Hostinger shared hosting)
- **Host type matters.** Hostinger's Node/static "web app" deploy cannot run Laravel: it only runs `npm install` and `npm run build`, expects a `dist` folder and never runs Composer or PHP. The app needs a PHP site (PHP 8.3 here), MySQL and cron.
- **Frontend build is independent of Composer.** `app.js` imported Ziggy from `vendor/`, which broke any build that does not run `composer install`. It now imports the `ziggy-js` npm package. `public/build` is gitignored, so it is built locally and uploaded by hand.
- **Folder layout.** The domain serves the project root (`public_html`), not `public/`. Two `.htaccess` files are needed: the root one rewrites everything to `public/` (with a `!^/public/` condition to avoid loops), and `public/.htaccess` is Laravel's standard file. Putting both blocks in one file broke routing, and with only Laravel's block the project files (`composer.json`, `artisan`) were publicly readable until the root rewrite was added. `/.env` and `storage/logs` stay blocked.
- **Database.** No working terminal at first, so the schema was imported through phpMyAdmin from `docs/pixelforge_schema.sql`, generated from the real migrations and including the `migrations` rows. A user is added with `docs/admin_user.sql` (gitignored, holds a credential).
- **Queue.** A separate `queue:work` cron never ran, and jobs sat at `attempts = 0`; once a worker finally ran, such jobs were failed by their 15 minute lifetime (`MaxAttemptsExceededException`) and their generations were marked failed at no cost, as designed. The fix was to schedule `queue:work --stop-when-empty --max-time=50` from `routes/console.php`, so one cron (`cd <app> && /opt/alt/php83/usr/bin/php artisan schedule:run`) does everything. The cron command needs `cd` before the path, and the full PHP path, because a bare `php` can be an older version.
- **Separate keys per environment.** The first deployment reused the local key and webhook secret. A signed test ping confirmed this; it was fixed with a dedicated production key, and the same test now returns 401. Keys must not be shared, otherwise `GET /v1/usage` mixes local and production generations.
- **Local test environment gotcha.** On Windows, the scheduler's child process lacked the CA bundle fix, so reconcile failed silently. Fixed locally with `PHP_INI_SCAN_DIR` (see the testing guide).

### Local development note
Laragon/WAMP PHP often has no CA bundle, so HTTPS calls fail with cURL error 60. The client treats that as a transient error and retries, so it looks like a hang rather than a crash. Fix with `curl.cainfo` and `openssl.cafile` in `php.ini`, or run with `php -d curl.cainfo=<bundle> -d openssl.cafile=<bundle>`. Git for Windows ships a bundle at `C:\Program Files\Git\mingw64\etc\ssl\certs\ca-bundle.crt`.

## Not yet decided
- The user's balance column. It is a stretch goal and can be derived as topups minus charges from the ledger.

## Open questions
(none yet)
