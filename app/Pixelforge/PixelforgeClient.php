<?php

namespace App\Pixelforge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class PixelforgeClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
    ) {}

    /**
     * Create a generation. The idempotency key makes a retry after a timeout return the same
     * generation instead of creating (and billing) a second one.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed> the generation object
     */
    public function createGeneration(array $params, string $idempotencyKey): array
    {
        return $this->send(
            fn (PendingRequest $http) => $http->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->post('/generations', $params)
        );
    }

    /** @return array<string, mixed> the generation object */
    public function getGeneration(string $id): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get("/generations/{$id}"));
    }

    /**
     * Daily billing for this key, by the UTC day each generation completed. Dates are inclusive.
     *
     * @return array<string, mixed> the usage object (`data` rows and `total`)
     */
    public function usage(string $startDate, string $endDate): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get('/usage', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]));
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->timeout(15);
    }

    /** @param  callable(PendingRequest): Response  $request */
    private function send(callable $request): array
    {
        try {
            $response = $request($this->http());
        } catch (ConnectionException $e) {
            // No response at all: treat like a transient server problem so the caller retries.
            throw new PixelforgeException(503, 'connection_failed', $e->getMessage());
        }

        if ($response->failed()) {
            $error = $response->json('error') ?? [];
            $retryAfter = $response->header('Retry-After');

            throw new PixelforgeException(
                $response->status(),
                $error['code'] ?? 'unknown_error',
                $error['message'] ?? 'Unexpected response from Pixelforge.',
                $retryAfter === '' ? null : (int) $retryAfter,
            );
        }

        return $response->json();
    }
}
