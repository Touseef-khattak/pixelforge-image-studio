<?php

use App\Pricing\PricingService;
use App\Pricing\Rates;
use App\Pricing\TokenUsage;

it('computes provider cost exactly from token usage', function () {
    $usage = new TokenUsage(inputText: 100, inputImage: 765, output: 1100, reasoning: 200);

    // 100*5000 + 765*10000 + 1100*40000 + 200*12500
    expect((new PricingService)->providerCost($usage, Rates::published()))
        ->toBe(500_000 + 7_650_000 + 44_000_000 + 2_500_000);
});

it('costs nothing for zero usage', function () {
    $usage = new TokenUsage(0, 0, 0, 0);

    expect((new PricingService)->providerCost($usage, Rates::published()))->toBe(0);
});

it('adds the 25% markup exactly', function () {
    expect((new PricingService(25))->charge(54_650_000))->toBe(68_312_500);
});

it('matches the documented worst-case provider cost', function () {
    // high, 1536x1024, 400 characters, 2 reference images: documented as at most $0.373875
    $estimate = (new PricingService)->estimate(400, 'high', '1536x1024', 2, Rates::published());

    expect($estimate->providerMax)->toBe(373_875_000);
});

it('estimates a charge range that includes the markup', function () {
    // low, 1024x1024, 40 chars (10 text tokens, no images): min 8 text + 0 reasoning, max 12 text + 500 reasoning
    $estimate = (new PricingService)->estimate(40, 'low', '1024x1024', 0, Rates::published());

    expect($estimate->providerMin)->toBe(8 * 5_000 + 280 * 40_000)
        ->and($estimate->providerMax)->toBe(12 * 5_000 + 280 * 40_000 + 500 * 12_500)
        ->and($estimate->chargeMax)->toBe(intdiv($estimate->providerMax * 125, 100));
});

it('rounds the charge up when the markup is not exact', function () {
    // 1 nano * 1.25 = 1.25, rounded up to 2
    expect((new PricingService(25))->charge(1))->toBe(2);
});
