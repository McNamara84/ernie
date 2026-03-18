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
    // Temporarily rename the file
    $path = resource_path('data/changelog.json');
    $backupPath = $path . '.bak';

    File::move($path, $backupPath);

    try {
        $response = $this->getJson('/api/changelog');
        $response->assertOk()->assertJson([]);
    } finally {
        File::move($backupPath, $path);
    }
});

it('returns error when changelog JSON is invalid', function () {
    $path = resource_path('data/changelog.json');
    $backupPath = $path . '.bak';
    $originalContent = File::get($path);

    File::move($path, $backupPath);
    File::put($path, '{invalid json content!!!');

    try {
        $response = $this->getJson('/api/changelog');
        $response->assertStatus(500)
            ->assertJsonStructure(['error']);
    } finally {
        File::delete($path);
        File::move($backupPath, $path);
    }
});
