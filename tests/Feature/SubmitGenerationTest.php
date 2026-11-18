<?php

use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function runSubmit(Generation $generation, ?SubmitGeneration $job = null): SubmitGeneration
{
    $job ??= new SubmitGeneration($generation->id);
    $job->handle(new PixelforgeClient('https://pf.test/v1', 'pf_key'));

    return $job;
}

function pixelforgeError(int $status, string $code, array $headers = [])
{
    return Http::response(['error' => ['code' => $code, 'message' => "msg for {$code}"]], $status, $headers);
}

it('submits with the uuid as idempotency key and local id in metadata', function () {
    Http::fake(['pf.test/*' => Http::response(['id' => 'gen_abc', 'rates' => ['output_tokens' => '40.00']], 202)]);
    $generation = Generation::factory()->create(['reference_images' => ['https://img.test/a.png']]);

    runSubmit($generation);

    Http::assertSent(fn (Request $r) => $r->hasHeader('Idempotency-Key', $generation->id)
        && $r['metadata'] === ['local_id' => $generation->id]
        && $r['reference_images'] === ['https://img.test/a.png']
        && $r['prompt'] === $generation->prompt);
    expect($generation->fresh())
        ->provider_generation_id->toBe('gen_abc')
        ->status->toBe(Generation::QUEUED);
});

it('does not submit twice or touch a generation that is already final', function () {
    Http::fake();

    runSubmit(Generation::factory()->create(['provider_generation_id' => 'gen_existing']));
    runSubmit(Generation::factory()->failed()->create());

    Http::assertNothingSent();
});

it('does not overwrite status when a webhook already advanced the generation', function () {
    Http::fake(['pf.test/*' => Http::response(['id' => 'gen_abc'], 202)]);
    $generation = Generation::factory()->succeeded()->create();

    runSubmit($generation);

    expect($generation->fresh()->status)->toBe(Generation::SUCCEEDED);
});

it('keeps the generation queued and retries on limits and overload', function (int $status, string $code, array $headers) {
    Http::fake(['pf.test/*' => pixelforgeError($status, $code, $headers)]);
    $generation = Generation::factory()->create();
    $job = Mockery::mock(SubmitGeneration::class.'[release]', [$generation->id]);
    $job->shouldReceive('release')->once()->withArgs(fn (int $delay) => $delay >= 2);

    runSubmit($generation, $job);

    expect($generation->fresh()->status)->toBe(Generation::QUEUED);
})->with([
    'concurrency limit' => [429, 'concurrency_limit_exceeded', ['Retry-After' => '5']],
    'rate limit' => [429, 'rate_limit_exceeded', []],
    'overloaded' => [503, 'overloaded', ['Retry-After' => '2']],
]);

it('fails permanently without charging on non-retryable errors', function (int $status, string $code) {
    Http::fake(['pf.test/*' => pixelforgeError($status, $code)]);
    $generation = Generation::factory()->create();

    runSubmit($generation);

    expect($generation->fresh())
        ->status->toBe(Generation::FAILED)
        ->error_code->toBe($code)
        ->charged_nanos->toBeNull();
})->with([
    'content policy' => [400, 'content_policy_violation'],
    'validation' => [422, 'validation_failed'],
    'daily quota' => [429, 'daily_limit_exceeded'],
    'bad api key' => [401, 'invalid_api_key'],
]);

it('fails the generation when retries are exhausted', function () {
    $generation = Generation::factory()->create();

    (new SubmitGeneration($generation->id))->failed(null);

    expect($generation->fresh())
        ->status->toBe(Generation::FAILED)
        ->error_code->toBe('submission_failed');
});
