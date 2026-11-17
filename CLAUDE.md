# Project instructions: PromptEdit x Pixelforge assessment

Laravel + Vue + Inertia app that generates images through the Pixelforge API, charges users provider cost + 25%, and records every cost exactly.

## Commit rules (mandatory)

- **Every commit must be small enough for a human to review easily in a PR.** One logical change per commit, ideally under ~200 changed lines (excluding lockfiles and generated scaffolding, which go in their own commit).
- Never mix refactors, features, tests and config in one commit. Tests may ship with the code they cover, if the combined diff stays small.
- Split work into tasks sized to this rule *before* starting a phase. If a task cannot fit in a reviewable commit, break it down further.
- Commit messages: imperative subject line, plus a short body saying why.
- Do not add attribution lines to commit messages.

## Docs rules (mandatory)

- All documentation lives in `docs/`. Index: `docs/README.md`.
- **After every phase is completed, update `docs/implementation.md`**: mark the phase done, record decisions made, deviations from the plan, and anything left open. This update is its own commit.
- Pixelforge API facts live in `docs/pixelforge-api.md`. Money rules live in `docs/money-model.md`. Update them if behavior is learned that contradicts them.

## Money rules

- Never use floats for money. Store integer **nano-dollars** (1 USD = 1e9). See `docs/money-model.md`.
- Pricing logic lives only in `PricingService`. No cost arithmetic anywhere else.

## Webhook rules

- Verify the signature against the raw body before parsing.
- Deduplicate by event id (unique constraint). Never move a generation out of `succeeded` or `failed`.
- Finalizing a generation (cost + ledger) happens exactly once, inside a transaction with a row lock.
