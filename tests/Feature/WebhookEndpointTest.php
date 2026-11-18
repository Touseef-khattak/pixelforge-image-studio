<?php

use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => config(['pixelforge.webhook_secret' => 'whsec_test']));

/** Post a signed event exactly as Pixelforge would: raw JSON body plus Pixelforge-Signature. */
function deliver(array $event, ?string $signatureOverride = null, ?int $timestamp = null)
{
    $body = json_encode($event);
    $timestamp ??= time();
    $signature = $signatureOverride ?? "t={$timestamp},v1=".hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_test');

    return test()->call('POST', '/webhooks/pixelforge', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => $signature,
    ], $body);
}

function eventFor(Generation $generation, string $type, string $eventId = 'evt_1'): array
{
    $status = str_replace('generation.', '', $type);
    $object = [
        'id' => 'gen_real', 'status' => $status, 'metadata' => ['local_id' => $generation->id],
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
        'usage' => null, 'error' => null, 'completed_at' => null,
    ];

    if ($status === 'succeeded') {
        $object['usage'] = ['input_text_tokens' => 8, 'input_image_tokens' => 0, 'output_tokens' => 280, 'reasoning_tokens' => 91, 'total_tokens' => 379];
        $object['completed_at'] = '2026-11-18T09:36:02Z';
    }

    return ['id' => $eventId, 'type' => $type, 'created_at' => '2026-11-18T09:36:02Z', 'data' => ['object' => $object]];
}

it('rejects a bad signature without recording or changing anything', function () {
    $generation = Generation::factory()->create();

    deliver(eventFor($generation, 'generation.succeeded'), 't='.time().',v1=bad')->assertUnauthorized();

    expect(WebhookEvent::count())->toBe(0)
        ->and($generation->fresh()->status)->toBe(Generation::QUEUED);
});

it('rejects an unsigned request', function () {
    $this->postJson('/webhooks/pixelforge', ['id' => 'evt_1', 'type' => 'ping'])->assertUnauthorized();
});

it('rejects a validly signed but stale delivery', function () {
    $generation = Generation::factory()->create();

    deliver(eventFor($generation, 'generation.succeeded'), timestamp: time() - 3600)->assertUnauthorized();

    expect($generation->fresh()->status)->toBe(Generation::QUEUED);
});

it('applies a signed success and charges the user', function () {
    $generation = Generation::factory()->create();

    deliver(eventFor($generation, 'generation.succeeded'))->assertNoContent();

    expect($generation->fresh())->status->toBe(Generation::SUCCEEDED)->charged_nanos->toBe(15_471_875);
    expect(WebhookEvent::sole())->event_id->toBe('evt_1')->processed_at->not->toBeNull();
});

it('records an event delivered twice once and charges once', function () {
    $generation = Generation::factory()->create();
    $event = eventFor($generation, 'generation.succeeded');

    deliver($event)->assertNoContent();
    deliver($event)->assertNoContent();

    expect(WebhookEvent::count())->toBe(1)
        ->and(LedgerEntry::count())->toBe(1);
});

it('handles events in the wrong order', function () {
    $generation = Generation::factory()->create();

    deliver(eventFor($generation, 'generation.succeeded', 'evt_2'))->assertNoContent();
    deliver(eventFor($generation, 'generation.processing', 'evt_1'))->assertNoContent();

    expect($generation->fresh()->status)->toBe(Generation::SUCCEEDED)
        ->and(WebhookEvent::count())->toBe(2)
        ->and(LedgerEntry::count())->toBe(1);
});

it('acknowledges a ping without touching generations', function () {
    deliver(['id' => 'evt_ping', 'type' => 'ping'])->assertNoContent();

    expect(WebhookEvent::sole()->type)->toBe('ping');
});

it('rejects a signed but malformed event', function () {
    deliver(['hello' => 'world'])->assertStatus(400);
});

it('answers 5xx and keeps no event row when applying fails, so the retry can succeed', function () {
    $generation = Generation::factory()->create();
    $event = eventFor($generation, 'generation.succeeded');
    $event['data']['object']['usage'] = ['bogus' => 1]; // cannot be priced

    $this->withoutExceptionHandling();
    expect(fn () => deliver($event))->toThrow(Exception::class);

    expect(WebhookEvent::count())->toBe(0)
        ->and($generation->fresh()->status)->toBe(Generation::QUEUED);
});
