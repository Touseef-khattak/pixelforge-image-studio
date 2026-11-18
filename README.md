# Pixelforge Image Studio

A Laravel 12 + Vue 3 + Inertia app where signed-in users generate images through the (fictional) Pixelforge API. Users pay Pixelforge's cost plus a 25% markup, and every cost is recorded exactly.

Built for the PromptEdit Senior Product Engineer take-home. The task brief is in [`docs/assessment-brief.md`](docs/assessment-brief.md).

## What it does
- **Generate:** prompt, size, quality and up to four reference image URLs, with a live price range before submitting.
- **Price:** provider cost + 25%. Part of the cost (reasoning tokens) is only known when a generation finishes, so the form shows an estimate range, and the final cost is recorded from the real usage.
- **History:** live status (queued, processing, succeeded, failed), the estimate, the exact final cost and the image. Failed generations are free and say so.
- **Webhooks:** signed, deduplicated and order-independent. A polling command recovers lost ones.

## How the money stays exact
- Money is an **integer number of nano-dollars** (1 USD = 1,000,000,000), never a float. This matches the nine decimals of Pixelforge's `GET /v1/usage`, so provider costs reconcile to the unit.
- All cost arithmetic lives in one class, `App\Pricing\PricingService`: provider cost is tokens times per-token rates, and the user charge is `ceil(cost x 125 / 100)`. Rates come from each generation's own `rates` object, parsed from strings without floats.
- The estimate is a range: text tokens use the documented `ceil(chars / 4)` with its +-15% tolerance, output tokens are exact, and reasoning tokens span the per-quality range. The browser asks the server for the estimate, so the number shown is the number stored.
- Format details: [`docs/money-model.md`](docs/money-model.md).

## How webhooks are handled
Pixelforge delivers at least once, possibly out of order, and sometimes before the create request returns.

1. **Verify first.** The `Pixelforge-Signature` header (`t=...,v1=...`) is checked against the raw body with HMAC-SHA256, a constant-time comparison and a timestamp tolerance, before anything is parsed.
2. **One action applies every update.** `ApplyGenerationUpdate` is used by both the webhook and the poller. It runs in a transaction with a row lock. Status only moves forward, `succeeded` and `failed` are final, a success is priced and charged once, and a failure writes nothing.
3. **Exactly-once is enforced by the database,** not just code: a unique `webhook_events.event_id` and a unique `ledger_entries (generation_id, type)`.
4. **Event and effect commit together.** If applying fails, the event row rolls back and the endpoint answers 5xx, so Pixelforge's retry gets a clean attempt instead of being treated as a duplicate.
5. **Early events are matched** by the `metadata.local_id` sent on create.
6. **Lost webhooks:** `php artisan generations:reconcile` (scheduled every minute) re-fetches generations idle for two minutes and applies them through the same action.

## Submitting to Pixelforge
`SubmitGeneration` is a queued job. It sends the generation's UUID as the `Idempotency-Key`, so a retry after a timeout cannot create or bill a second generation. Rate limits, the concurrency limit (3) and overload are retried after `Retry-After` plus jitter for up to 15 minutes. Permanent errors (content policy, validation, daily quota) fail the generation at no cost.

## Run it locally
Requires PHP 8.2+, Composer, Node 20+.

```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate
npm install && npm run build
```

Add your Pixelforge key and the secret of that key's webhook to `.env`:

```
PIXELFORGE_API_KEY=
PIXELFORGE_WEBHOOK_SECRET=
```

Then run these three (separate terminals):

```bash
php artisan serve
php artisan queue:work
php artisan schedule:work     # recovers generations when no public webhook URL is available
```

Webhooks need a public URL; use a tunnel (Expose, ngrok or Cloudflare) and set it as the key's webhook URL, or rely on the scheduler locally.

## Tests
```bash
php artisan test              # Pest: pricing, signatures, webhooks, idempotency, polling, pages
npm run e2e                   # Playwright UI tests (no provider quota)
npm run e2e:live              # Playwright against the real provider (uses quota)
```

See [`docs/testing-guide.md`](docs/testing-guide.md) for the end-to-end checklist and the SQL checks that reconcile our ledger with `GET /v1/usage`.

## Deployment
The app runs on shared PHP hosting with cron jobs for the queue worker and the scheduler. Notes and pitfalls are in [`docs/implementation.md`](docs/implementation.md), and a schema for manual import is in [`docs/pixelforge_schema.sql`](docs/pixelforge_schema.sql).

## Decisions and known limits
- No pagination yet: history shows the latest 50 generations.
- The up-front estimate uses the published rates; the final charge always uses the generation's own rates.
- Generated images are not copied to our storage, so they stop loading when Pixelforge's links expire after 24 hours (allowed by the brief).
- Stretch goals (admin report, balances) are not built yet.
- The phased plan, decisions and open questions are tracked in [`docs/implementation.md`](docs/implementation.md).
