# Implementation plan and status

Rule: every commit is small enough for a human to review easily in a PR (see `CLAUDE.md`). This file is updated after each phase, in its own commit.

Status legend: [ ] todo, [~] in progress, [x] done.

## Phase 0: Foundations
- [ ] Docs folder, CLAUDE.md, API and money docs
- [ ] Check toolchain (PHP, Composer, Node, git); `git init`
- [ ] Scaffold Laravel + Vue + Inertia (starter kit) as its own commit
- [ ] Env config for Pixelforge (`PIXELFORGE_API_KEY`, `PIXELFORGE_WEBHOOK_SECRET`, base URL)

## Phase 1: Money core
- [ ] `Money` helper / nano-dollar formatting
- [ ] `PricingService`: cost, charge, estimate range (with tests, incl. the $0.373875 worst case)

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
(Append here after each phase.)

## Open questions
(none yet)
