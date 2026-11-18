<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('makes users non-admin by default', function () {
    expect(User::factory()->create()->fresh()->is_admin)->toBeFalse();
});

it('can create an admin through the factory state', function () {
    expect(User::factory()->admin()->create()->fresh()->is_admin)->toBeTrue();
});

it('cannot be granted through mass assignment', function () {
    $user = User::create(['name' => 'X', 'email' => 'x@example.test', 'password' => 'secret-password', 'is_admin' => true]);

    expect($user->fresh()->is_admin)->toBeFalse();
});
