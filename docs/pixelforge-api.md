# Pixelforge API reference (condensed)

Source: https://pixelforge.promptedit.dev/docs (re-check the live docs if anything here seems off).

Base URL `https://pixelforge.promptedit.dev/v1`. JSON, UTC ISO 8601, ids `gen_` / `evt_`, money as exact decimal strings in USD. Auth: `Authorization: Bearer <key>`. Each key has its own webhook URL, signing secret and usage report.

## Endpoints
- `POST /v1/generations` -> 202, status `queued`. Fields: `prompt` (max 4000 chars), `size` (`1024x1024` default, `1536x1024`, `1024x1536`), `quality` (`low`, `medium` default, `high`), `reference_images` (max 4 URLs, 765 input image tokens each), `metadata` (max 20 string keys, values max 500 chars). Unknown params -> 422. `Idempotency-Key` header (max 255 chars): replay returns the same generation with `Idempotent-Replayed: true`; same key + different params -> 409.
- `GET /v1/generations/{id}`, `GET /v1/generations` (`limit` 1-100, `status`, `cursor`).
- `GET /v1/pricing` (no auth).
- `GET /v1/usage`: `start_date`, `end_date` inclusive UTC, max span 92 days. Each generation billed on the UTC day it **completed**. `cost_usd` has 9 decimals. Includes `total`.

## Generation lifecycle
`queued` -> `processing` -> `succeeded` | `failed` (final). `usage` is null until success. `rates` are fixed at creation. Failures (`generation_failed`, `internal_error`) are not billed. The API never returns a price.

## Pricing (per 1M tokens)
| Token | Rate | Known |
|---|---|---|
| input text | $5.00 | estimate `ceil(chars/4)` (+-15%), exact after completion |
| input image | $10.00 | 765 per reference image, up front |
| output | $40.00 | exact up front by quality and size |
| reasoning | $12.50 | only after completion |

Live `GET /v1/pricing` (verified): `rates` is `{input_text_tokens, input_image_tokens, output_tokens, reasoning_tokens}` as decimal strings per 1M tokens (`"5.00"`, `"10.00"`, `"40.00"`, `"12.50"`). `tokens` carries `characters_per_text_token` (4), `text_token_variance` (0.15), `per_reference_image` (765) and the output and reasoning tables below. A generation's own `rates` object probably uses the same keys; its `usage` field names are **unverified** until a real generation completes.

Output tokens: low 280 (1024x1024) / 420 (other); medium 1100 / 1650; high 4300 / 6450.
Reasoning ranges: low 0-500, medium 200-2000, high 1000-8000.
Worst case example: high, 1536x1024, 400 chars, 2 reference images = $0.373875.

## Limits (per account, across all keys)
60 req/min (`rate_limit_exceeded`), 3 active generations (`concurrency_limit_exceeded`, `Retry-After: 5`), 500 per UTC day (`daily_limit_exceeded`). Rejected requests create nothing.

## Errors
`invalid_api_key` 401, `validation_failed` 422, `content_policy_violation` 400, `not_found` 404, `idempotency_key_reused` 409, `rate_limit_exceeded` 429, `concurrency_limit_exceeded` 429, `daily_limit_exceeded` 429, `overloaded` 503 (retry after 2s), `date_range_too_long` 422.

## Webhooks
Events: `generation.processing`, `generation.succeeded`, `generation.failed`, `ping`. Body: `id`, `type`, `created_at`, `data.object` (full generation). Headers: `Pixelforge-Event-Id`, `Pixelforge-Event-Type`, `Pixelforge-Delivery-Attempt`, `Pixelforge-Signature`.

Signature: `t=<timestamp>,v1=<hex>`; `v1 = HMAC_SHA256("<t>.<raw body>", whsec_secret)`. Constant-time compare, reject old timestamps, verify before parsing.

Delivery: 2xx within 10s. Retries after 10s, 30s, 2m, 10m, 15m (6 attempts max). No redirects. At least once, possibly out of order, may arrive before the create request returns. Dedupe by event id. Poll if delivery fails. URLs must be public (use a tunnel locally).

## Images
Signed PNG, expires 24h after completion.

## Test keywords (in prompt, case-insensitive)
`[blocked]` 400, `[overloaded]` 503, `[fail]` fails in processing, `[succeed]` never fails randomly, `[slow]` 60-90s, `[duplicate]` every webhook sent twice. Without keywords: random failures, some 503s on create, some duplicate webhooks.
