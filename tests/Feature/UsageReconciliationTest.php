<?php

use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Reports\UsageReconciliation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function recordCharge(string $completedAt, int $cost): void
{
    $user = User::factory()->create();
    $generation = Generation::factory()->for($user)->create([
        'status' => Generation::SUCCEEDED, 'completed_at' => $completedAt,
        'provider_cost_nanos' => $cost, 'charged_nanos' => intdiv($cost * 125 + 99, 100),
    ]);
    LedgerEntry::create([
        'user_id' => $user->id, 'generation_id' => $generation->id, 'type' => LedgerEntry::CHARGE,
        'amount_nanos' => intdiv($cost * 125 + 99, 100), 'provider_cost_nanos' => $cost,
    ]);
}

function fakeUsage(array $rows): void
{
    Http::fake(['*/usage*' => Http::response(['object' => 'usage', 'data' => $rows])]);
}

function usageRow(string $date, int $generations, string $costUsd): array
{
    return ['date' => $date, 'generations' => $generations, 'cost_usd' => $costUsd];
}

it('matches when our provider cost equals Pixelforge to the nano-dollar', function () {
    recordCharge('2026-11-18 09:36:02', 12_377_500);
    recordCharge('2026-11-18 10:00:00', 26_947_500);
    fakeUsage([usageRow('2026-11-18', 2, '0.039325000')]);

    $result = app(UsageReconciliation::class)->compare(Carbon::parse('2026-11-18'), Carbon::parse('2026-11-18'));

    expect($result['matches'])->toBeTrue()
        ->and($result['days'][0])->toMatchArray(['our_cost' => 39_325_000, 'pixelforge_cost' => 39_325_000, 'difference' => 0]);
});

it('flags a one nano-dollar difference', function () {
    recordCharge('2026-11-18 09:36:02', 12_377_500);
    fakeUsage([usageRow('2026-11-18', 1, '0.012377501')]);

    $result = app(UsageReconciliation::class)->compare(Carbon::parse('2026-11-18'), Carbon::parse('2026-11-18'));

    expect($result['matches'])->toBeFalse()
        ->and($result['days'][0]['difference'])->toBe(-1);
});

it('flags a generation count mismatch even when the cost agrees', function () {
    recordCharge('2026-11-18 09:36:02', 12_377_500);
    fakeUsage([usageRow('2026-11-18', 2, '0.012377500')]);

    expect(app(UsageReconciliation::class)->compare(Carbon::parse('2026-11-18'), Carbon::parse('2026-11-18'))['matches'])->toBeFalse();
});

it('catches a day Pixelforge billed that we never recorded, and the reverse', function () {
    recordCharge('2026-11-19 08:00:00', 5_000_000);
    fakeUsage([usageRow('2026-11-18', 1, '0.010000000'), usageRow('2026-11-19', 0, '0.000000000')]);

    $days = collect(app(UsageReconciliation::class)->compare(Carbon::parse('2026-11-18'), Carbon::parse('2026-11-19'))['days'])->keyBy('date');

    expect($days['2026-11-18']['matches'])->toBeFalse()   // they billed, we have nothing
        ->and($days['2026-11-19']['matches'])->toBeFalse(); // we billed, they report zero
});

it('treats days with no activity on either side as matching', function () {
    fakeUsage([usageRow('2026-11-18', 0, '0.000000000')]);

    expect(app(UsageReconciliation::class)->compare(Carbon::parse('2026-11-18'), Carbon::parse('2026-11-18'))['matches'])->toBeTrue();
});

it('exits successfully from the command when everything matches', function () {
    recordCharge('2026-11-18 09:36:02', 12_377_500);
    fakeUsage([usageRow('2026-11-18', 1, '0.012377500')]);

    $this->artisan('usage:reconcile --from=2026-11-18 --to=2026-11-18')
        ->expectsOutputToContain('match Pixelforge exactly')
        ->assertSuccessful();
});

it('fails the command and says MISMATCH when totals differ', function () {
    recordCharge('2026-11-18 09:36:02', 12_377_500);
    fakeUsage([usageRow('2026-11-18', 1, '0.020000000')]);

    $this->artisan('usage:reconcile --from=2026-11-18 --to=2026-11-18')
        ->expectsOutputToContain('MISMATCH')
        ->assertFailed();
});
