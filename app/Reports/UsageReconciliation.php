<?php

namespace App\Reports;

use App\Pixelforge\PixelforgeClient;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Compares our recorded provider cost with what Pixelforge reports in GET /v1/usage for this
 * API key, day by day (UTC, by completion day). Both sides are exact nano-dollars.
 */
class UsageReconciliation
{
    public function __construct(
        private readonly PixelforgeClient $client,
        private readonly SpendReport $report,
    ) {}

    /**
     * @return array{days: list<array<string, mixed>>, matches: bool}
     */
    public function compare(CarbonInterface $from, CarbonInterface $to): array
    {
        $remote = collect($this->client->usage($from->toDateString(), $to->toDateString())['data'] ?? [])->keyBy('date');
        $ours = collect($this->report->between($from, $to)['days'])->keyBy('date');

        $days = [];

        // Walk every day in the range so a day with spend on only one side is still caught.
        for ($day = Carbon::parse($from->toDateString(), 'UTC'); $day->lte($to); $day = $day->copy()->addDay()) {
            $date = $day->toDateString();

            $ourCost = $ours[$date]['provider_cost'] ?? 0;
            $theirCost = Money::parseUsd($remote[$date]['cost_usd'] ?? '0');
            $ourCount = $ours[$date]['generations'] ?? 0;
            $theirCount = (int) ($remote[$date]['generations'] ?? 0);

            $days[] = [
                'date' => $date,
                'our_cost' => $ourCost,
                'pixelforge_cost' => $theirCost,
                'difference' => $ourCost - $theirCost,
                'our_generations' => $ourCount,
                'pixelforge_generations' => $theirCount,
                'matches' => $ourCost === $theirCost && $ourCount === $theirCount,
            ];
        }

        return ['days' => $days, 'matches' => collect($days)->every(fn ($d) => $d['matches'])];
    }
}
