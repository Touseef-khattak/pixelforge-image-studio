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

it('marks a generation failed at no cost', function () {
    $generation = Generation::factory()->create();

    $generation->markFailed('content_policy_violation', 'Rejected.');

    expect($generation->fresh())
        ->status->toBe(Generation::FAILED)
        ->error_code->toBe('content_policy_violation')
        ->charged_nanos->toBeNull()
        ->completed_at->not->toBeNull();
});

it('never changes a generation that already succeeded', function () {
    $generation = Generation::factory()->succeeded()->create();

    $generation->markFailed('internal_error', 'late failure');

    expect($generation->fresh()->status)->toBe(Generation::SUCCEEDED);
});

it('treats succeeded and failed as final', function () {
    expect(Generation::factory()->succeeded()->make()->isFinal())->toBeTrue()
        ->and(Generation::factory()->failed()->make()->isFinal())->toBeTrue();
});
