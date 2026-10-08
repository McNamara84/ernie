<?php

use Tests\Helpers\NativeSleep;

afterEach(fn () => NativeSleep::restore());

it('records service backoff and rate-limit delays only while explicitly faked', function () {
    NativeSleep::fake();

    expect(\App\Services\sleep(2))->toBe(0);
    \App\Services\usleep(500000);
    expect(NativeSleep::seconds())->toBe([2])
        ->and(NativeSleep::microseconds())->toBe([500000]);

    NativeSleep::restore();
    expect(\App\Services\sleep(0))->toBe(0);
    \App\Services\usleep(0);
    expect(NativeSleep::seconds())->toBeEmpty()
        ->and(NativeSleep::microseconds())->toBeEmpty();
});

it('preserves native rejection of negative delays', function () {
    NativeSleep::fake();

    expect(fn () => \App\Services\sleep(-1))->toThrow(ValueError::class)
        ->and(fn () => \App\Services\usleep(-1))->toThrow(ValueError::class);
});
