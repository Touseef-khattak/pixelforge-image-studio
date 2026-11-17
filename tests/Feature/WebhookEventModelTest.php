<?php

use App\Models\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('refuses to record the same event id twice', function () {
    $attributes = ['event_id' => 'evt_1', 'type' => 'generation.succeeded', 'payload' => ['id' => 'evt_1']];

    WebhookEvent::create($attributes);

    expect(fn () => WebhookEvent::create($attributes))->toThrow(UniqueConstraintViolationException::class);
});
