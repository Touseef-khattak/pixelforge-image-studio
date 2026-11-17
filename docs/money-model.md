# Money model

## Unit
Integer **nano-dollars** (1 USD = 1,000,000,000). Matches the 9 decimals of `GET /v1/usage`, so provider costs reconcile exactly. Columns are `bigint`. No floats anywhere.

## Provider cost
A rate of $X per 1M tokens is `X * 1000` nano-dollars per token.

```
cost_nanos = input_text * 5000 + input_image * 10000 + output * 40000 + reasoning * 12500
```

Use the `rates` object on the generation rather than hard-coded values where possible. Failed generations cost 0.

## User charge
`charge_nanos = cost_nanos * 125 / 100`. Cost is always a multiple of 500 with the default rates, so this is exact. If a rate ever makes it inexact, round up once, in `PricingService` only.

## Estimate (before submit)
- text tokens: `ceil(chars / 4)`
- image tokens: `765 * reference_count`
- output tokens: exact, by quality and size
- reasoning: unknown, so show a range: min to max for the quality

Stored: `estimated_min_nanos` and `estimated_max_nanos` (both with the markup applied). The UI shows the range and labels it an estimate. Balance checks use the max.

## Display
Format nano-dollars to USD with up to 6 decimals where needed. Never round the stored value.

## Reporting
Group by the UTC date of `completed_at`, matching Pixelforge's usage billing.
