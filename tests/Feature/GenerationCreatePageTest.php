<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('requires sign-in', function () {
    $this->get('/generations/create')->assertRedirect('/login');
});

it('offers the documented sizes, qualities and reference limit', function () {
    $this->actingAs(User::factory()->create())->get('/generations/create')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Generations/Create')
            ->where('sizes', ['1024x1024', '1536x1024', '1024x1536'])
            ->where('qualities', ['low', 'medium', 'high'])
            ->where('maxReferences', 4)
            ->where('markupPercent', 25));
});
