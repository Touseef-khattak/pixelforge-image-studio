<?php

namespace App\Actions;

use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Pricing\PricingService;
use App\Pricing\Rates;
use App\Pricing\TokenUsage;
use Illuminate\Support\Facades\DB;

/**
 * Applies a Pixelforge generation object (from a webhook or from polling) to our row.
 *
 * Both paths go through here, so the rules live in one place:
 *  - succeeded and failed are final: a later or duplicate event never changes them;
 *  - status only moves forward (queued -> processing -> final);
 *  - a success is priced and charged exactly once, in the same transaction as the status change;
 *  - a failure costs nothing and writes no ledger entry.
 */
class ApplyGenerationUpdate
{
    public function __construct(private readonly PricingService $pricing) {}

    /** @param  array<string, mixed>  $payload  Pixelforge generation object */
    public function __invoke(array $payload): ?Generation
    {
        return DB::transaction(function () use ($payload) {
            $generation = $this->lockMatching($payload);

            if (! $generation) {
                return null;
            }

            if (! $generation->provider_generation_id) {
                $generation->provider_generation_id = $payload['id'];
            }

            if (! $generation->isFinal()) {
                match ($payload['status'] ?? null) {
                    'processing' => $this->markProcessing($generation),
                    'succeeded' => $this->succeed($generation, $payload),
                    'failed' => $this->fail($generation, $payload),
                    default => null, // queued or unknown: nothing to move forward to
                };
            }

            $generation->save();

            return $generation;
        });
    }

    /** Match by provider id, or by the local id we sent as metadata (events can beat the create response). */
    private function lockMatching(array $payload): ?Generation
    {
        $query = Generation::query()->lockForUpdate();
        $localId = $payload['metadata']['local_id'] ?? null;

        return $query->where('provider_generation_id', $payload['id'] ?? null)
            ->when($localId, fn ($q) => $q->orWhere('id', $localId))
            ->first();
    }

    private function markProcessing(Generation $generation): void
    {
        if ($generation->status === Generation::QUEUED) {
            $generation->status = Generation::PROCESSING;
        }
    }

    private function succeed(Generation $generation, array $payload): void
    {
        $rates = $payload['rates'] ?? $generation->rates;
        $cost = $this->pricing->providerCost(TokenUsage::fromApi($payload['usage']), Rates::fromApi($rates));

        $generation->fill([
            'status' => Generation::SUCCEEDED,
            'usage' => $payload['usage'],
            'rates' => $rates,
            'provider_cost_nanos' => $cost,
            'charged_nanos' => $this->pricing->charge($cost),
            'image_url' => $payload['image_url'] ?? null,
            'image_url_expires_at' => $payload['image_url_expires_at'] ?? null,
            'completed_at' => $payload['completed_at'] ?? now(),
        ]);

        // The unique (generation_id, type) index is the last line of defence against a double charge.
        LedgerEntry::create([
            'user_id' => $generation->user_id,
            'generation_id' => $generation->id,
            'type' => LedgerEntry::CHARGE,
            'amount_nanos' => $generation->charged_nanos,
            'provider_cost_nanos' => $cost,
        ]);
    }

    private function fail(Generation $generation, array $payload): void
    {
        $generation->fill([
            'status' => Generation::FAILED,
            'error_code' => $payload['error']['code'] ?? 'generation_failed',
            'error_message' => $payload['error']['message'] ?? 'The image could not be generated.',
            'completed_at' => $payload['completed_at'] ?? now(),
        ]);
    }
}
