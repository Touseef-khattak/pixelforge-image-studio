<?php

use App\Models\Generation;
use App\Models\LedgerEntry;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('refuses to charge the same generation twice', function () {
    $generation = Generation::factory()->succeeded()->create();
    $charge = [
        'user_id' => $generation->user_id,
        'generation_id' => $generation->id,
        'type' => LedgerEntry::CHARGE,
        'amount_nanos' => 68_312_500,
        'provider_cost_nanos' => 54_650_000,
    ];

    LedgerEntry::create($charge);

    expect(fn () => LedgerEntry::create($charge))->toThrow(UniqueConstraintViolationException::class);
});

it('allows many top-ups for the same user', function () {
    $generation = Generation::factory()->create();

    foreach ([1, 2] as $_) {
        LedgerEntry::create(['user_id' => $generation->user_id, 'type' => LedgerEntry::TOPUP, 'amount_nanos' => 5_000_000_000]);
    }

    expect(LedgerEntry::where('type', LedgerEntry::TOPUP)->count())->toBe(2);
});
