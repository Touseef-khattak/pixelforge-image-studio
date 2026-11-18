<?php

use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function client(): PixelforgeClient
{
    return new PixelforgeClient('https://pf.test/v1', 'pf_key');
}

it('creates a generation with the bearer key and idempotency key', function () {
    Http::fake(['pf.test/*' => Http::response(['id' => 'gen_1', 'status' => 'queued'], 202)]);

    $generation = client()->createGeneration(['prompt' => 'a fox'], 'local-uuid');

    expect($generation['id'])->toBe('gen_1');
    Http::assertSent(fn (Request $r) => $r->url() === 'https://pf.test/v1/generations'
        && $r->hasHeader('Authorization', 'Bearer pf_key')
        && $r->hasHeader('Idempotency-Key', 'local-uuid')
        && $r['prompt'] === 'a fox');
});

it('turns API errors into exceptions carrying code, status and retry-after', function () {
    Http::fake(['pf.test/*' => Http::response(
        ['error' => ['code' => 'overloaded', 'message' => 'busy']], 503, ['Retry-After' => '2']
    )]);

    try {
        client()->createGeneration(['prompt' => 'a fox'], 'k');
        $this->fail('expected exception');
    } catch (PixelforgeException $e) {
        expect($e->errorCode)->toBe('overloaded')
            ->and($e->status)->toBe(503)
            ->and($e->retryAfter)->toBe(2)
            ->and($e->isRetryable())->toBeTrue();
    }
});

it('classifies which errors are retryable', function (int $status, string $code, bool $retryable) {
    expect((new PixelforgeException($status, $code, 'x'))->isRetryable())->toBe($retryable);
})->with([
    'rate limit' => [429, 'rate_limit_exceeded', true],
    'concurrency limit' => [429, 'concurrency_limit_exceeded', true],
    'overloaded' => [503, 'overloaded', true],
    'daily quota' => [429, 'daily_limit_exceeded', false],
    'content policy' => [400, 'content_policy_violation', false],
    'validation' => [422, 'validation_failed', false],
    'bad key' => [401, 'invalid_api_key', false],
]);

it('fetches a generation', function () {
    Http::fake(['pf.test/v1/generations/gen_1' => Http::response(['id' => 'gen_1', 'status' => 'processing'])]);

    expect(client()->getGeneration('gen_1')['status'])->toBe('processing');
});
