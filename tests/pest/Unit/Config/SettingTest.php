<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('settings table has no timestamp columns', function () {
    expect(Schema::hasColumn('settings', 'created_at'))->toBeFalse();
    expect(Schema::hasColumn('settings', 'updated_at'))->toBeFalse();
});
