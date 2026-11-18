# End-to-end test guide (deployed app)

Run this against the deployed site with the **production** Pixelforge key. Each scenario says what to do and what must be true afterwards. Money is stored as integer nano-dollars (1 USD = 1,000,000,000).

## 0. Pre-flight (all must be true before testing)
- `GET /up` returns 200; `/login` loads styled.
- The database has the 12 tables (`docs/pixelforge_schema.sql` imported) and an account exists.
- Server `.env` has the **production** `PIXELFORGE_API_KEY` and that same key's `PIXELFORGE_WEBHOOK_SECRET`.
- In the Pixelforge dashboard, the production key's webhook URL is `https://<your-domain>/webhooks/pixelforge`.
- Two cron jobs run every minute: `schedule:run` and `queue:work --max-time=55`.
- Webhook reachability: in the Pixelforge dashboard, send a test (`ping`) event. The delivery log must show **204**. A 401 means the secret is wrong; a 404 means routing (`.htaccess`) is wrong.

Timing to expect: a submission leaves the app within about 1 minute (queue cron). Pixelforge then takes about 2s queued and about 20s processing. With working webhooks the status updates immediately; if a webhook is lost, `generations:reconcile` finishes it within about 2 to 3 minutes.

## 1. Scenarios

| # | Do this | Must happen |
|---|---|---|
| 1 | Prompt `a red fox [succeed]`, low, 1024x1024 | Queued, Processing, Succeeded. Image shows. "Final cost" appears and lies inside the estimate range. |
| 2 | Same with `high` quality and 2 reference image URLs | Estimate is clearly higher than #1. Final cost includes image tokens (usage shows `input_image_tokens` 1530). |
| 3 | Prompt `x [fail]` | Status Failed, message shown, "You were not charged", cost $0.00. No ledger row. |
| 4 | Prompt `x [duplicate]` | Pixelforge sends every webhook twice. Exactly one ledger row; cost not doubled. |
| 5 | Prompt `x [slow]` | Stays Processing 60 to 90s, then Succeeded. The page updated by itself without refresh. |
| 6 | Prompt `x [blocked]` | Fails right away with the content policy message. Not charged. |
| 7 | Prompt `x [overloaded]` | Stays Queued while retrying (always 503). Do not wait: it gives up after 15 min as "could not reach provider", not charged. |
| 8 | Submit 5 generations quickly (`[succeed]`) | Pixelforge allows 3 active at once; the rest wait Queued, retry, and all finish. None lost, none doubled. |
| 9 | Submit an empty prompt, 5 reference URLs, a non-URL reference | Inline validation errors; nothing created. |
| 10 | Log out, open `/generations` | Redirects to login. |
| 11 | Register a second user | Sees an empty history, never the first user's generations. |
| 12 | `POST` unsigned to `/webhooks/pixelforge` | 401 and nothing recorded. |
| 13 | In the Pixelforge dashboard, resend an old `succeeded` event | Answered 2xx. Still one ledger row for that generation. |
| 14 | (Lost webhook) Temporarily break the webhook URL in the dashboard, submit `[succeed]`, restore it afterwards | Still reaches Succeeded within about 3 minutes via the reconcile cron. Charged once. |

## 2. Database checks (phpMyAdmin, SQL tab)
Every query below should return the stated result.

```sql
-- Never charged twice (expect 0 rows)
SELECT generation_id, COUNT(*) c FROM ledger_entries WHERE type='charge' GROUP BY generation_id HAVING c > 1;

-- No duplicate webhook events (expect 0 rows)
SELECT event_id, COUNT(*) c FROM webhook_events GROUP BY event_id HAVING c > 1;

-- Failed generations are free (expect 0)
SELECT COUNT(*) FROM generations g JOIN ledger_entries l ON l.generation_id = g.id WHERE g.status = 'failed';

-- Every success has exactly one charge and charge = cost x 1.25 (expect 0)
SELECT COUNT(*) FROM generations
WHERE status = 'succeeded'
  AND (charged_nanos <> CEIL(provider_cost_nanos * 125 / 100)
       OR (SELECT COUNT(*) FROM ledger_entries l WHERE l.generation_id = generations.id AND l.type = 'charge') <> 1);

-- Ledger matches generations (expect 0)
SELECT COUNT(*) FROM generations g JOIN ledger_entries l ON l.generation_id = g.id
WHERE l.amount_nanos <> g.charged_nanos OR l.provider_cost_nanos <> g.provider_cost_nanos;

-- Nothing stuck (expect none older than ~5 minutes)
SELECT id, status, created_at FROM generations WHERE status IN ('queued','processing') ORDER BY created_at;
```

## 3. Reconcile with Pixelforge (the number reviewers will check)
Provider cost per UTC day, from our records:

```sql
SELECT DATE(completed_at) AS day, COUNT(*) AS generations, SUM(provider_cost_nanos) AS cost_nanos
FROM generations WHERE status = 'succeeded' GROUP BY DATE(completed_at);
```

Pixelforge's own figure for the same key (run from any terminal; do not paste the key anywhere public):

```
curl -s "https://pixelforge.promptedit.dev/v1/usage?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD" -H "Authorization: Bearer <PRODUCTION KEY>"
```

For each day, `cost_usd` x 1,000,000,000 must equal `cost_nanos`, and `generations` must match the count. Generations made with the **local** key are not in the production database or its usage report; compare each environment with its own key.

## 3b. Automated browser tests (Playwright)
The suite lives in `e2e/` and drives the installed Chrome, so no browser download is needed.

| Command | What it runs | Provider quota |
|---|---|---|
| `npm run e2e` | 9 UI tests: register, login, logout, redirects, estimate range and its growth with quality, size and references, disabled button, four-reference limit | none |
| `npm run e2e:live` | 6 tests tagged `@live`: success with exact cost, references priced in, `[fail]`, `[blocked]`, `[duplicate]`, per-user isolation | 6 generations (free) |

Point either at any running copy of the app with `E2E_BASE_URL`:

```
E2E_BASE_URL=https://your-domain npm run e2e          # macOS/Linux
$env:E2E_BASE_URL='https://your-domain'; npm run e2e   # PowerShell
```

Requirements for `e2e:live`: the app's queue worker and scheduler must be running. Without webhooks (local), each generation finishes through the reconcile command and takes about 3 minutes, so the suite takes about 12 minutes. On the deployed site with webhooks it takes well under a minute per test. The tests create throwaway users named `e2e+<timestamp>@example.test`; do not run them against the site you submit unless you are happy with those extra rows.

Local gotchas found while running them:
- The scheduler starts `generations:reconcile` as a child process. If PHP has no CA bundle (common on Windows), that child fails with a silent SSL error and generations never finish. Fix it for every child process with an extra ini file: put `curl.cainfo` and `openssl.cafile` in a `ca.ini` inside a folder and set `PHP_INI_SCAN_DIR` to that folder before starting `artisan serve`, `queue:work` and `schedule:work`. (`SSL_CERT_FILE` is not honoured by this PHP build.)
- If another project already uses port 8000, run this app on a free port and pass it through `E2E_BASE_URL`.

## 4. Leave it ready to submit
Keep a handful of generations on the production site: at least one success with references, one `[fail]`, one `[duplicate]`. Do not delete rows afterwards, so the reviewers' numbers match.
