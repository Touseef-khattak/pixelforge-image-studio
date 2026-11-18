<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('sends visitors from the root URL to the login page', function () {
    $this->get('/')->assertRedirect('/login');
});

it('shows the login form when following the root URL', function () {
    $this->followingRedirects()->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Auth/Login'));
});

it('takes a signed-in user from the root URL straight to the app', function () {
    $this->actingAs(User::factory()->create())->followingRedirects()->get('/')
        ->assertInertia(fn (Assert $page) => $page->component('Generations/Create'));
});
