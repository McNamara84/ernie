<?php

declare(strict_types=1);

use App\Http\Controllers\ChangelogController;
use Illuminate\Support\Facades\File;

covers(ChangelogController::class);

it('returns changelog data as JSON', function () {
    $response = $this->getJson('/api/changelog');

    $response->assertOk()
        ->assertJsonIsArray();
});

it('returns empty array when changelog file does not exist', function () {
    File::shouldReceive('exists')
        ->once()
        ->with(resource_path('data/changelog.json'))
        ->andReturn(false);

    $response = $this->getJson('/api/changelog');
    $response->assertOk()->assertJson([]);
});

it('returns error when changelog JSON is invalid', function () {
    File::shouldReceive('exists')
        ->once()
        ->with(resource_path('data/changelog.json'))
        ->andReturn(true);

    File::shouldReceive('get')
        ->once()
        ->with(resource_path('data/changelog.json'))
        ->andReturn('{invalid json content!!!');

    $response = $this->getJson('/api/changelog');
    $response->assertStatus(500)
        ->assertJsonStructure(['error']);
});
