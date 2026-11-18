<?php

use App\Actions\ApplyGenerationUpdate;
use App\Models\Generation;
use App\Models\LedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Payload shaped like a real completed generation (low, 1024x1024: cost 12,377,500, charge 15,471,875). */
function payload(Generation $generation, string $status, array $overrides = []): array
{
    $base = [
        'id' => 'gen_real',
        'object' => 'generation',
        'status' => $status,
        'metadata' => ['local_id' => $generation->id],
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
        'usage' => null,
        'image_url' => null,
        'image_url_expires_at' => null,
        'error' => null,
        'completed_at' => null,
    ];

    if ($status === 'succeeded') {
        $base['usage'] = ['input_text_tokens' => 8, 'input_image_tokens' => 0, 'output_tokens' => 280, 'reasoning_tokens' => 91, 'total_tokens' => 379];
        $base['image_url'] = 'https://pf.test/img.png';
        $base['image_url_expires_at'] = '2026-11-19T09:36:02Z';
        $base['completed_at'] = '2026-11-18T09:36:02Z';
    }

    if ($status === 'failed') {
        $base['error'] = ['code' => 'generation_failed', 'message' => 'Boom'];
        $base['completed_at'] = '2026-11-18T09:36:02Z';
    }

    return array_merge($base, $overrides);
}

function apply(array $payload): ?Generation
{
    return app(ApplyGenerationUpdate::class)($payload);
}

it('prices and charges a succeeded generation exactly', function () {
    $generation = Generation::factory()->create();

    apply(payload($generation, 'succeeded'));

    expect($generation->fresh())
        ->status->toBe(Generation::SUCCEEDED)
        ->provider_generation_id->toBe('gen_real')
        ->provider_cost_nanos->toBe(12_377_500)
        ->charged_nanos->toBe(15_471_875)
        ->image_url->toBe('https://pf.test/img.png');

    $entry = LedgerEntry::sole();
    expect($entry->amount_nanos)->toBe(15_471_875)
        ->and($entry->provider_cost_nanos)->toBe(12_377_500)
        ->and($entry->user_id)->toBe($generation->user_id);
});

it('charges once when the same success arrives twice', function () {
    $generation = Generation::factory()->create();

    apply(payload($generation, 'succeeded'));
    apply(payload($generation, 'succeeded'));

    expect(LedgerEntry::count())->toBe(1)
        ->and($generation->fresh()->charged_nanos)->toBe(15_471_875);
});

it('ignores a late processing event after success', function () {
    $generation = Generation::factory()->create();

    apply(payload($generation, 'succeeded'));
    apply(payload($generation, 'processing'));

    expect($generation->fresh()->status)->toBe(Generation::SUCCEEDED)
        ->and(LedgerEntry::count())->toBe(1);
});

it('moves forward from processing to succeeded', function () {
    $generation = Generation::factory()->create();

    apply(payload($generation, 'processing'));
    expect($generation->fresh()->status)->toBe(Generation::PROCESSING);

    apply(payload($generation, 'succeeded'));
    expect($generation->fresh()->status)->toBe(Generation::SUCCEEDED);
});

it('goes straight to succeeded if processing was never seen', function () {
    $generation = Generation::factory()->create();

    apply(payload($generation, 'succeeded'));

    expect($generation->fresh()->status)->toBe(Generation::SUCCEEDED);
});

it('fails a generation at no cost', function () {
    $generation = Generation::factory()->create();

    apply(payload($generation, 'failed'));

    expect($generation->fresh())
        ->status->toBe(Generation::FAILED)
        ->error_message->toBe('Boom')
        ->charged_nanos->toBeNull()
        ->provider_cost_nanos->toBeNull()
        ->and(LedgerEntry::count())->toBe(0);
});

it('never lets a late failure undo a success, or a late success undo a failure', function () {
    $succeeded = Generation::factory()->create();
    apply(payload($succeeded, 'succeeded', ['id' => 'gen_a']));
    apply(payload($succeeded, 'failed', ['id' => 'gen_a']));

    $failed = Generation::factory()->create();
    apply(payload($failed, 'failed', ['id' => 'gen_b']));
    apply(payload($failed, 'succeeded', ['id' => 'gen_b']));

    expect($succeeded->fresh()->status)->toBe(Generation::SUCCEEDED)
        ->and($failed->fresh()->status)->toBe(Generation::FAILED)
        ->and(LedgerEntry::count())->toBe(1);
});

it('matches by provider id when the metadata is missing', function () {
    $generation = Generation::factory()->create(['provider_generation_id' => 'gen_real']);

    apply(payload($generation, 'succeeded', ['metadata' => []]));

    expect($generation->fresh()->status)->toBe(Generation::SUCCEEDED);
});

it('matches by local id before the provider id is stored', function () {
    $generation = Generation::factory()->create(['provider_generation_id' => null]);

    apply(payload($generation, 'processing'));

    expect($generation->fresh())
        ->provider_generation_id->toBe('gen_real')
        ->status->toBe(Generation::PROCESSING);
});

it('ignores generations that are not ours', function () {
    Generation::factory()->create();

    expect(apply(['id' => 'gen_stranger', 'status' => 'succeeded', 'metadata' => []]))->toBeNull()
        ->and(LedgerEntry::count())->toBe(0);
});

it('rolls back the status change if the charge cannot be recorded', function () {
    $generation = Generation::factory()->create();
    LedgerEntry::create([
        'user_id' => $generation->user_id, 'generation_id' => $generation->id,
        'type' => LedgerEntry::CHARGE, 'amount_nanos' => 1,
    ]);

    expect(fn () => apply(payload($generation, 'succeeded')))->toThrow(Exception::class);
    expect($generation->fresh()->status)->toBe(Generation::QUEUED);
});
