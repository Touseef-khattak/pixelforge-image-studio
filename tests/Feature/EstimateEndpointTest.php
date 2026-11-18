<?php

use App\Models\User;
use App\Pricing\PricingService;
use App\Pricing\Rates;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns the same range PricingService computes, with the markup included', function () {
    $expected = app(PricingService::class)->estimate(400, 'high', '1536x1024', 2, Rates::published());

    $this->actingAs(User::factory()->create())
        ->getJson('/generations/estimate?'.http_build_query([
            'prompt' => str_repeat('a', 400), 'size' => '1536x1024', 'quality' => 'high', 'reference_count' => 2,
        ]))
        ->assertOk()
        ->assertExactJson([
            'min' => Money::format($expected->chargeMin),
            'max' => Money::format($expected->chargeMax),
        ]);
});

it('estimates an empty prompt so the form can show a price before typing', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/generations/estimate?size=1024x1024&quality=low')
        ->assertOk()
        ->assertJsonStructure(['min', 'max']);
});

it('rejects invalid options and requires sign-in', function () {
    $this->getJson('/generations/estimate?size=1024x1024&quality=low')->assertUnauthorized();

    $this->actingAs(User::factory()->create())
        ->getJson('/generations/estimate?size=1x1&quality=ultra')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['size', 'quality']);
});
