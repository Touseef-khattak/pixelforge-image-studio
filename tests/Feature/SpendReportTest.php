<?php

use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Reports\SpendReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/** A finished, charged generation, as the webhook would leave it. */
function charged(User $user, string $completedAtUtc, int $cost, ?int $charge = null): Generation
{
    $charge ??= intdiv($cost * 125 + 99, 100);
    $generation = Generation::factory()->for($user)->create([
        'status' => Generation::SUCCEEDED,
        'completed_at' => $completedAtUtc,
        'provider_cost_nanos' => $cost,
        'charged_nanos' => $charge,
    ]);
    LedgerEntry::create([
        'user_id' => $user->id, 'generation_id' => $generation->id, 'type' => LedgerEntry::CHARGE,
        'amount_nanos' => $charge, 'provider_cost_nanos' => $cost,
    ]);

    return $generation;
}

it('adds up charges, provider cost and profit exactly', function () {
    $user = User::factory()->create();
    charged($user, '2026-11-18 09:36:02', 12_377_500);   // charge 15,471,875
    charged($user, '2026-11-18 10:00:00', 11_742_500);   // charge 14,678,125

    $report = (new SpendReport)->between();

    expect($report['totals'])->toBe([
        'generations' => 2,
        'charged' => 30_150_000,
        'provider_cost' => 24_120_000,
        'profit' => 6_030_000,
    ]);
});

it('groups by the UTC day a generation completed, splitting at midnight', function () {
    $user = User::factory()->create();
    charged($user, '2026-11-18 23:59:59', 10_000_000);
    charged($user, '2026-11-19 00:00:00', 20_000_000);

    $days = (new SpendReport)->between()['days'];

    expect($days)->toHaveCount(2)
        ->and($days[0])->toMatchArray(['date' => '2026-11-19', 'generations' => 1, 'provider_cost' => 20_000_000])
        ->and($days[1])->toMatchArray(['date' => '2026-11-18', 'generations' => 1, 'provider_cost' => 10_000_000]);
});

it('splits spend per user, biggest spender first', function () {
    $small = User::factory()->create(['name' => 'Small']);
    $big = User::factory()->create(['name' => 'Big']);
    charged($small, '2026-11-18 08:00:00', 10_000_000);
    charged($big, '2026-11-18 08:00:00', 30_000_000);
    charged($big, '2026-11-18 09:00:00', 30_000_000);

    $users = (new SpendReport)->between()['users'];

    expect($users)->toHaveCount(2)
        ->and($users[0])->toMatchArray(['name' => 'Big', 'generations' => 2, 'provider_cost' => 60_000_000])
        ->and($users[1])->toMatchArray(['name' => 'Small', 'generations' => 1]);
});

it('breaks each day down by user', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    charged($a, '2026-11-18 08:00:00', 10_000_000);
    charged($b, '2026-11-18 09:00:00', 20_000_000);
    charged($a, '2026-11-19 08:00:00', 40_000_000);

    $rows = (new SpendReport)->between()['day_users'];

    expect($rows)->toHaveCount(3)
        ->and(collect($rows)->where('date', '2026-11-18')->sum('provider_cost'))->toBe(30_000_000);
});

it('ignores failed, uncharged and unfinished generations', function () {
    $user = User::factory()->create();
    charged($user, '2026-11-18 08:00:00', 10_000_000);
    Generation::factory()->for($user)->failed()->create();
    Generation::factory()->for($user)->create(); // still queued

    expect((new SpendReport)->between()['totals']['generations'])->toBe(1);
});

it('filters by inclusive UTC dates', function () {
    $user = User::factory()->create();
    charged($user, '2026-11-17 23:59:59', 1_000_000);
    charged($user, '2026-11-18 00:00:00', 2_000_000);
    charged($user, '2026-11-18 23:59:59', 4_000_000);
    charged($user, '2026-11-19 00:00:00', 8_000_000);

    $report = (new SpendReport)->between(Carbon::parse('2026-11-18'), Carbon::parse('2026-11-18'));

    expect($report['totals']['provider_cost'])->toBe(6_000_000);
});

it('returns zeros and empty lists when nothing was charged', function () {
    expect((new SpendReport)->between())->toMatchArray([
        'days' => [], 'users' => [], 'day_users' => [],
        'totals' => ['generations' => 0, 'charged' => 0, 'provider_cost' => 0, 'profit' => 0],
    ]);
});
