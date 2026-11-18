<?php

use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function chargedAt(User $user, string $completedAt, int $cost, int $charge): void
{
    $generation = Generation::factory()->for($user)->create([
        'status' => Generation::SUCCEEDED, 'completed_at' => $completedAt,
        'provider_cost_nanos' => $cost, 'charged_nanos' => $charge,
    ]);
    LedgerEntry::create([
        'user_id' => $user->id, 'generation_id' => $generation->id, 'type' => LedgerEntry::CHARGE,
        'amount_nanos' => $charge, 'provider_cost_nanos' => $cost,
    ]);
}

it('sends signed-out visitors to login', function () {
    $this->get('/admin/report')->assertRedirect('/login');
});

it('forbids regular users', function () {
    $this->actingAs(User::factory()->create())->get('/admin/report')->assertForbidden();
});

it('shows admins formatted totals, days and users', function () {
    $customer = User::factory()->create(['name' => 'Customer']);
    chargedAt($customer, '2026-11-18 09:36:02', 12_377_500, 15_471_875);
    chargedAt($customer, '2026-11-19 10:00:00', 11_742_500, 14_678_125);

    $this->actingAs(User::factory()->admin()->create())->get('/admin/report')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Report')
            ->where('totals.generations', 2)
            ->where('totals.charged', '$0.03015')
            ->where('totals.provider_cost', '$0.02412')
            ->where('totals.profit', '$0.00603')
            ->where('totals.margin', '20.0%')
            ->has('days', 2)
            ->where('days.0.date', '2026-11-19')
            ->where('users.0.name', 'Customer')
            ->has('dayUsers', 2));
});

it('filters by date', function () {
    $user = User::factory()->create();
    chargedAt($user, '2026-11-17 12:00:00', 1_000_000, 1_250_000);
    chargedAt($user, '2026-11-18 12:00:00', 2_000_000, 2_500_000);

    $this->actingAs(User::factory()->admin()->create())->get('/admin/report?from=2026-11-18&to=2026-11-18')
        ->assertInertia(fn (Assert $page) => $page
            ->where('totals.generations', 1)
            ->where('totals.provider_cost', '$0.002')
            ->where('filters', ['from' => '2026-11-18', 'to' => '2026-11-18']));
});

it('rejects a malformed or reversed date range', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/admin/report?from=yesterday')->assertSessionHasErrors('from');
    $this->actingAs($admin)->get('/admin/report?from=2026-11-19&to=2026-11-18')->assertSessionHasErrors('to');
});

it('shows zero margin instead of dividing by zero when nothing was charged', function () {
    $this->actingAs(User::factory()->admin()->create())->get('/admin/report')
        ->assertInertia(fn (Assert $page) => $page->where('totals.margin', '0.0%')->where('totals.charged', '$0.00'));
});
