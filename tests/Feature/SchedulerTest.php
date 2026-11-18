<?php

use Illuminate\Console\Scheduling\Schedule;

function scheduledEvents(): array
{
    return app(Schedule::class)->events();
}

it('runs the reconcile command and drains the queue every minute', function () {
    $commands = collect(scheduledEvents())->mapWithKeys(fn ($event) => [$event->command => $event->expression]);

    $reconcile = $commands->filter(fn ($_, $command) => str_contains($command, 'generations:reconcile'));
    $queue = $commands->filter(fn ($_, $command) => str_contains($command, 'queue:work --stop-when-empty'));

    expect($reconcile)->toHaveCount(1)
        ->and($queue)->toHaveCount(1)
        ->and($reconcile->first())->toBe('* * * * *')
        ->and($queue->first())->toBe('* * * * *');
});

it('does not let a killed queue run block the queue for long', function () {
    $queue = collect(scheduledEvents())->first(fn ($event) => str_contains($event->command, 'queue:work'));

    expect($queue->withoutOverlapping)->toBeTrue()
        ->and($queue->expiresAt)->toBe(2);
});
