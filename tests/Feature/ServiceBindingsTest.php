<?php

use App\Pixelforge\PixelforgeClient;
use App\Pricing\PricingService;

it('resolves the Pixelforge client and pricing service from the container', function () {
    expect(app(PixelforgeClient::class))->toBeInstanceOf(PixelforgeClient::class)
        ->and(app(PricingService::class)->charge(1_000_000_000))->toBe(1_250_000_000);
});

it('applies the configured markup', function () {
    config(['pixelforge.markup_percent' => 50]);
    app()->forgetInstance(PricingService::class);

    expect(app(PricingService::class)->charge(1_000_000_000))->toBe(1_500_000_000);
});
