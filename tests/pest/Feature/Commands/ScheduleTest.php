<?php

use App\Console\Kernel;
use Illuminate\Console\Scheduling\Schedule;

it('schedules SPDX license sync monthly', function () {
    $schedule = app(Schedule::class);
    $kernel = app(Kernel::class);

    $method = new ReflectionMethod($kernel, 'schedule');
    $method->setAccessible(true);
    $method->invoke($kernel, $schedule);

    $event = collect($schedule->events())
        ->first(fn ($event) => str_contains($event->command, 'spdx:sync-licenses'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 1 * *');
});

