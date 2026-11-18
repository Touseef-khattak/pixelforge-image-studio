<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sends a queued generation to Pixelforge. Safe to run more than once: the generation's UUID is the
 * Idempotency-Key, so a retry after a timeout returns the same Pixelforge generation.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    /** Retries are bounded by time (see retryUntil), not by attempt count. */
    public int $tries = 0;

    private const DEFAULT_RETRY_SECONDS = 5;

    public function __construct(public readonly string $generationId) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(15);
    }

    public function handle(PixelforgeClient $client): void
    {
        $generation = Generation::find($this->generationId);

        if (! $generation || $generation->isFinal() || $generation->provider_generation_id) {
            return;
        }

        try {
            $response = $client->createGeneration($this->requestBody($generation), $generation->id);
        } catch (PixelforgeException $e) {
            $this->handleRejection($generation, $e);

            return;
        }

        // Only fill the provider id. Status comes from webhooks and polling, so this cannot undo
        // an event that already arrived (webhooks can beat the create response).
        Generation::whereKey($generation->id)
            ->whereNull('provider_generation_id')
            ->update(['provider_generation_id' => $response['id'], 'rates' => $response['rates'] ?? null]);
    }

    /** Called when retryUntil passes or an unexpected exception exhausts the job. */
    public function failed(?Throwable $exception): void
    {
        Generation::find($this->generationId)?->markFailed(
            'submission_failed',
            'We could not reach the image provider. You were not charged.'
        );
    }

    private function handleRejection(Generation $generation, PixelforgeException $e): void
    {
        if ($e->isRetryable()) {
            // Honor Retry-After (concurrency limit: 5s, overload: 2s), plus jitter to avoid lockstep retries.
            $this->release(($e->retryAfter ?? self::DEFAULT_RETRY_SECONDS) + random_int(0, 3));

            return;
        }

        $generation->markFailed($e->errorCode, $e->getMessage());
    }

    /** @return array<string, mixed> */
    private function requestBody(Generation $generation): array
    {
        return array_filter([
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'reference_images' => $generation->reference_images ?: null,
            // Echoed back in webhooks so an event can be matched before we know the provider id.
            'metadata' => ['local_id' => $generation->id],
        ]);
    }
}
