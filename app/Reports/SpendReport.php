<?php

namespace App\Reports;

use App\Models\LedgerEntry;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Credit spend (what users were charged), provider cost (what Pixelforge charged us) and profit,
 * per UTC day and per user. All amounts are integer nano-dollars.
 *
 * A generation counts on the UTC day it *completed*, because that is how Pixelforge's
 * GET /v1/usage bills it, so the provider-cost column reconciles with that report.
 */
class SpendReport
{
    /**
     * @return array{
     *     days: list<array<string, mixed>>,
     *     users: list<array<string, mixed>>,
     *     day_users: list<array<string, mixed>>,
     *     totals: array<string, int>
     * }
     */
    public function between(?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $days = $users = $dayUsers = [];
        $totals = self::blank();

        foreach ($this->rows($from, $to) as $row) {
            $day = Carbon::parse($row->completed_at, config('app.timezone'))->utc()->toDateString();

            self::add($totals, $row);
            self::add($days[$day], $row, ['date' => $day]);
            self::add($users[$row->user_id], $row, ['user_id' => $row->user_id, 'name' => $row->name, 'email' => $row->email]);
            self::add($dayUsers["{$day}|{$row->user_id}"], $row, [
                'date' => $day, 'user_id' => $row->user_id, 'name' => $row->name, 'email' => $row->email,
            ]);
        }

        krsort($days);
        uasort($users, fn ($a, $b) => $b['charged'] <=> $a['charged']);
        krsort($dayUsers);

        return [
            'days' => array_values($days),
            'users' => array_values($users),
            'day_users' => array_values($dayUsers),
            'totals' => $totals,
        ];
    }

    /** One row per charge: the amounts from the ledger and the completion time from the generation. */
    private function rows(?CarbonInterface $from, ?CarbonInterface $to): iterable
    {
        return DB::table('ledger_entries as l')
            ->join('generations as g', 'g.id', '=', 'l.generation_id')
            ->join('users as u', 'u.id', '=', 'l.user_id')
            ->where('l.type', LedgerEntry::CHARGE)
            ->whereNotNull('g.completed_at')
            // Inclusive UTC days: [start of $from, start of the day after $to).
            ->when($from, fn ($q) => $q->where('g.completed_at', '>=', $from->copy()->utc()->startOfDay()))
            ->when($to, fn ($q) => $q->where('g.completed_at', '<', $to->copy()->utc()->addDay()->startOfDay()))
            ->orderBy('g.completed_at')
            ->select(['g.completed_at', 'l.user_id', 'u.name', 'u.email', 'l.amount_nanos', 'l.provider_cost_nanos'])
            ->cursor();
    }

    /** @return array<string, int> */
    private static function blank(): array
    {
        return ['generations' => 0, 'charged' => 0, 'provider_cost' => 0, 'profit' => 0];
    }

    /**
     * @param  array<string, mixed>|null  $bucket
     * @param  array<string, mixed>  $labels
     */
    private static function add(?array &$bucket, object $row, array $labels = []): void
    {
        $bucket ??= $labels + self::blank();
        $bucket['generations']++;
        $bucket['charged'] += (int) $row->amount_nanos;
        $bucket['provider_cost'] += (int) $row->provider_cost_nanos;
        $bucket['profit'] = $bucket['charged'] - $bucket['provider_cost'];
    }
}
