<?php

namespace App\Console\Commands;

use App\Pixelforge\PixelforgeException;
use App\Reports\UsageReconciliation;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class ReconcileUsage extends Command
{
    protected $signature = 'usage:reconcile {--from= : First UTC date (YYYY-MM-DD), default 29 days ago} {--to= : Last UTC date, default today}';

    protected $description = 'Compare our provider costs with Pixelforge GET /v1/usage, per UTC day';

    public function handle(UsageReconciliation $reconciliation): int
    {
        $to = Carbon::parse($this->option('to') ?? 'today', 'UTC');
        $from = Carbon::parse($this->option('from') ?? $to->copy()->subDays(29), 'UTC');

        try {
            $result = $reconciliation->compare($from, $to);
        } catch (PixelforgeException $e) {
            $this->error("Pixelforge refused the usage request: {$e->errorCode}");

            return self::FAILURE;
        }

        $this->table(
            ['Date (UTC)', 'Ours', 'Pixelforge', 'Difference', 'Gens ours', 'Gens PF', ''],
            collect($result['days'])
                // Hide empty days that agree, so the table stays readable.
                ->reject(fn ($d) => $d['matches'] && $d['our_generations'] === 0)
                ->map(fn ($d) => [
                    $d['date'], Money::format($d['our_cost']), Money::format($d['pixelforge_cost']),
                    $d['difference'] === 0 ? '0' : ($d['difference'] > 0 ? '+' : '-').abs($d['difference']).' nano',
                    $d['our_generations'], $d['pixelforge_generations'], $d['matches'] ? 'OK' : 'MISMATCH',
                ])->all(),
        );

        $result['matches']
            ? $this->info('Provider costs match Pixelforge exactly.')
            : $this->error('Provider costs do NOT match Pixelforge. Compare each MISMATCH day.');

        return $result['matches'] ? self::SUCCESS : self::FAILURE;
    }
}
