<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Reports\SpendReport;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class SpendReportController extends Controller
{
    public function index(Request $request, SpendReport $report): Response
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $data = $report->between(
            isset($filters['from']) ? Carbon::parse($filters['from'], 'UTC') : null,
            isset($filters['to']) ? Carbon::parse($filters['to'], 'UTC') : null,
        );

        return Inertia::render('Admin/Report', [
            'filters' => ['from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null],
            'totals' => self::present($data['totals']),
            'days' => array_map(self::present(...), $data['days']),
            'users' => array_map(self::present(...), $data['users']),
            'dayUsers' => array_map(self::present(...), $data['day_users']),
        ]);
    }

    /**
     * Money is formatted here so the browser never does money math. Amounts stay in the same keys
     * as strings; margin is profit as a share of what users were charged.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function present(array $row): array
    {
        $tenths = $row['charged'] > 0 ? intdiv($row['profit'] * 1000, $row['charged']) : 0;

        return array_merge($row, [
            'charged' => Money::format($row['charged']),
            'provider_cost' => Money::format($row['provider_cost']),
            'profit' => Money::format($row['profit']),
            'margin' => intdiv($tenths, 10).'.'.($tenths % 10).'%',
        ]);
    }
}
