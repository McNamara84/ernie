<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Vite;
use Illuminate\Http\UploadedFile;

uses()->group('issue-1199', 'browser', 'uploads');

beforeEach(function (): void {
    config()->set('cache.default', 'file');

    app(Vite::class)
        ->useHotFile(storage_path('framework/testing-vite.hot'))
        ->useBuildDirectory('build');
});

it('shows imported GEMET and MSL subjects only once in the editor', function (): void {
    $user = User::factory()->create(['role' => UserRole::CURATOR]);
    $this->actingAs($user);

    $xml = file_get_contents(base_path('tests/pest/dataset-examples/issue-1199-subjects.xml'));
    expect($xml)->toBeString();

    $upload = $this->postJson('/dashboard/upload-xml', [
        'file' => UploadedFile::fake()->createWithContent('issue-1199-subjects.xml', $xml),
    ])->assertOk();
    $resourceId = (int) $upload->json('resourceId');
    expect($resourceId)->toBeGreaterThan(0);

    $page = visit('/editor?resourceId='.$resourceId)
        ->waitForText('geophysics')
        ->assertNoSmoke()
        ->assertCount('button[aria-label="Remove geophysics"]', 1)
        ->assertCount('button[aria-label="Remove Materials > Mineralogy"]', 1);

    expect($page->script('() => document.querySelectorAll(`[aria-label="Remove geophysics"], [aria-label="Remove Materials > Mineralogy"]`).length'))
        ->toBe(2);
});
