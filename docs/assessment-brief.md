# Assessment brief (PromptEdit, Senior Product Engineer)

Build a Laravel + Vue + Inertia app where users generate images via the fictional Pixelforge API.

## Required
- **Generating:** prompt, size, quality, optional reference image URLs; show cost before submit.
- **Pricing:** user pays Pixelforge cost + 25%. Part of the cost is known only after completion, so the up-front price is an estimate; record the actual cost when done. Failed generations cost nothing. Fractions of a cent are handled however I choose.
- **History:** live status (queued, processing, succeeded, failed), estimated and actual cost, the image. Expired URLs are fine.
- **Webhooks:** receive and verify signatures; handle duplicate and out-of-order events; never record an event twice.
- **Deploy** publicly (Laravel Cloud suggested).

## Stretch (bonus)
- **Admin report:** credit spend, provider costs and profit by day and by user; provider costs must match `GET /v1/usage`.
- **Balances:** per-user balance, fake "Buy credits", block generating when unaffordable.

## Submit
Public GitHub repo, deployed URL (with a few generations left on it), and the Pixelforge account email.

## Judged on
Correct money, robustness, product sense/UX, code quality. Correctness before polish. Be ready to explain every line in the interview.

## Tips
Prompt keywords force behaviors; the Pixelforge dashboard shows webhook deliveries and can resend; use a tunnel locally; one API key per environment.
