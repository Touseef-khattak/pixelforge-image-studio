<?php

use App\Models\Generation;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores a queued generation with a uuid and integer money', function () {
    $generation = Generation::factory()->create();

    expect($generation->id)->toBeString()->toHaveLength(36)
        ->and($generation->status)->toBe(Generation::QUEUED)
        ->and($generation->estimated_max_nanos)->toBeInt()
        ->and($generation->charged_nanos)->toBeNull()
        ->and($generation->isFinal())->toBeFalse();
});

it('treats succeeded and failed as final', function () {
    expect(Generation::factory()->succeeded()->make()->isFinal())->toBeTrue()
        ->and(Generation::factory()->failed()->make()->isFinal())->toBeTrue();
});
