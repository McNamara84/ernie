<?php

use App\Console\Kernel;
use App\Services\VocabularyCacheService;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;

it('schedules SPDX license sync monthly', function () {
    $schedule = app(Schedule::class);
    $kernel = app(Kernel::class);

    $method = new ReflectionMethod($kernel, 'schedule');
    $method->invoke($kernel, $schedule);

    $event = collect($schedule->events())
        ->first(fn ($event) => str_contains($event->command, 'spdx:sync-licenses'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 1 * *');
});

it('schedules license usage count update weekly', function () {
    $schedule = app(Schedule::class);
    $kernel = app(Kernel::class);

    $method = new ReflectionMethod($kernel, 'schedule');
    $method->invoke($kernel, $schedule);

    $event = collect($schedule->events())
        ->first(fn ($event) => str_contains($event->command, 'rights:update-usage-count'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 * * 0');
});

it('schedules vocabulary cache touch twice daily', function () {
    $schedule = app(Schedule::class);

    $event = collect($schedule->events())
        ->first(fn ($event) => $event instanceof CallbackEvent
            && $event->description === 'touch-vocabulary-caches');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 1,13 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('vocabulary cache touch callback invokes touchAllVocabularyCaches', function () {
    $mock = Mockery::mock(VocabularyCacheService::class);
    $mock->shouldReceive('touchAllVocabularyCaches')->once();
    $this->app->instance(VocabularyCacheService::class, $mock);

    $schedule = app(Schedule::class);

    $event = collect($schedule->events())
        ->first(fn ($event) => $event instanceof CallbackEvent
            && $event->description === 'touch-vocabulary-caches');

    expect($event)->not->toBeNull();

    // Execute the callback to cover the console.php closure body
    $event->run($this->app);
});

it('schedules relation discovery weekly on Sundays at 02:00', function () {
    $schedule = app(Schedule::class);

    $event = collect($schedule->events())
        ->first(fn ($event) => $event instanceof CallbackEvent
            && $event->description === 'discover-relations');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 2 * * 0')
        ->and($event->withoutOverlapping)->toBeTrue();
});
