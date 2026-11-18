<?php

use App\Models\Generation;
use App\Models\LedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function remoteSucceeded(Generation $generation): array
{
    return [
        'id' => $generation->provider_generation_id, 'status' => 'succeeded', 'metadata' => ['local_id' => $generation->id],
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
        'usage' => ['input_text_tokens' => 8, 'input_image_tokens' => 0, 'output_tokens' => 280, 'reasoning_tokens' => 91, 'total_tokens' => 379],
        'image_url' => 'https://pf.test/img.png', 'image_url_expires_at' => null, 'error' => null,
        'completed_at' => '2026-11-18T09:36:02Z',
    ];
}

function staleGeneration(array $attributes = []): Generation
{
    $generation = Generation::factory()->create(array_merge(['provider_generation_id' => 'gen_'.fake()->unique()->numerify('####')], $attributes));
    Generation::whereKey($generation->id)->update(['updated_at' => now()->subMinutes(10)]);

    return $generation->fresh();
}

it('finishes a generation whose webhook never arrived, charging once', function () {
    $generation = staleGeneration();
    Http::fake(['*/generations/*' => Http::response(remoteSucceeded($generation))]);

    $this->artisan('generations:reconcile')->assertSuccessful();
    $this->artisan('generations:reconcile')->assertSuccessful();

    expect($generation->fresh())->status->toBe(Generation::SUCCEEDED)->charged_nanos->toBe(15_471_875)
        ->and(LedgerEntry::count())->toBe(1);
});

it('leaves recent, final and unsubmitted generations alone', function () {
    Http::fake();
    Generation::factory()->create(['provider_generation_id' => 'gen_recent']);
    staleGeneration(['status' => Generation::SUCCEEDED]);
    Generation::factory()->create(['provider_generation_id' => null]);

    $this->artisan('generations:reconcile')->assertSuccessful();

    Http::assertNothingSent();
});

it('keeps going when Pixelforge errors on one generation', function () {
    $broken = staleGeneration();
    $good = staleGeneration();
    Http::fake([
        "*/generations/{$broken->provider_generation_id}" => Http::response(['error' => ['code' => 'not_found', 'message' => 'x']], 404),
        "*/generations/{$good->provider_generation_id}" => Http::response(remoteSucceeded($good)),
    ]);

    $this->artisan('generations:reconcile')->assertSuccessful();

    expect($broken->fresh()->status)->toBe(Generation::QUEUED)
        ->and($good->fresh()->status)->toBe(Generation::SUCCEEDED);
});
