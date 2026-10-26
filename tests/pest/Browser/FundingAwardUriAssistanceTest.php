<?php

declare(strict_types=1);

use App\Models\FundingAwardUriReview;
use App\Models\User;
use Illuminate\Foundation\Vite;
use Modules\Assistants\FundingAwardUriEnrichment\Assistant;
use Tests\Support\FundingAwardUriFixtures as F;

uses()->group('assistant', 'browser', 'funding-award-uri');

beforeEach(function (): void {
    app(Vite::class)->useHotFile(storage_path('framework/testing-vite.hot'))->useBuildDirectory('build')
        ->createAssetPathsUsing(static fn (string $path): string => '/'.ltrim($path, '/'));
    F::fake();
});

it('reviews the award preview in the browser and keeps an accepted or declined decision', function (string $action): void {
    $target = F::target();
    F::discover();
    F::register();
    $this->actingAs(User::factory()->admin()->create());
    expect(app(Vite::class)->asset('resources/js/app.tsx'))->toStartWith('/build/');
    visit('/assistance')->assertNoSmoke()->assertSee('Current award URI')->assertSee('Proposed award URI')
        ->click('Evidence and provenance')->assertSee('Crossref Grants')->assertSee('218300')
        ->click('Select all compatible')->click($action)->assertSee('1 suggestion(s) '.($action === 'Accept' ? 'accepted.' : 'declined.'));
    expect($target->fresh()->award_uri)->toBe($action === 'Accept' ? F::URI : null)
        ->and(FundingAwardUriReview::count())->toBe(1);
    if ($action === 'Decline') {
        expect(app(Assistant::class)->runDiscovery(fn () => null))->toBe(0);
    }
})->with(['Accept', 'Decline']);
