<?php

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('requires sign-in', function () {
    $this->get('/generations')->assertRedirect('/login');
});

it('lists only the signed-in user\'s generations, newest first', function () {
    $user = User::factory()->create();
    $older = Generation::factory()->for($user)->create(['created_at' => now()->subMinute()]);
    $newer = Generation::factory()->for($user)->create();
    Generation::factory()->create(); // someone else's

    $this->actingAs($user)->get('/generations')->assertInertia(fn (Assert $page) => $page
        ->component('Generations/Index')
        ->has('generations', 2)
        ->where('generations.0.id', $newer->id)
        ->where('generations.1.id', $older->id));
});

it('formats money and never shows a price before it is known', function () {
    $user = User::factory()->create();
    Generation::factory()->for($user)->create(['estimated_min_nanos' => 50_000_000, 'estimated_max_nanos' => 373_875_000]);

    $this->actingAs($user)->get('/generations')->assertInertia(fn (Assert $page) => $page
        ->where('generations.0.estimate', ['min' => '$0.05', 'max' => '$0.373875'])
        ->where('generations.0.charged', null)
        ->where('generations.0.is_active', true));
});

it('shows the exact charge once finished and $0.00 for a failure', function () {
    $user = User::factory()->create();
    Generation::factory()->for($user)->succeeded()->create(['charged_nanos' => 15_471_875, 'created_at' => now()->subMinute()]);
    Generation::factory()->for($user)->failed()->create();

    $this->actingAs($user)->get('/generations')->assertInertia(fn (Assert $page) => $page
        ->where('generations.0.charged', '$0.00')
        ->where('generations.0.is_active', false)
        ->where('generations.1.charged', '$0.015471875'));
});

it('hides an expired image link and flags it', function () {
    $user = User::factory()->create();
    Generation::factory()->for($user)->succeeded()->create([
        'image_url' => 'https://pf.test/old.png', 'image_url_expires_at' => now()->subHour(),
    ]);
    Generation::factory()->for($user)->succeeded()->create([
        'image_url' => 'https://pf.test/new.png', 'image_url_expires_at' => now()->addHour(), 'created_at' => now()->subMinute(),
    ]);

    $this->actingAs($user)->get('/generations')->assertInertia(fn (Assert $page) => $page
        ->where('generations.0.image_url', null)
        ->where('generations.0.image_expired', true)
        ->where('generations.1.image_url', 'https://pf.test/new.png')
        ->where('generations.1.image_expired', false));
});
