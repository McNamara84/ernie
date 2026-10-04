<?php

declare(strict_types=1);

use App\Models\AssistantSuggestion;
use App\Models\RelationTypeCorrectionReview;
use App\Services\DataCiteSyncResult;
use App\Services\DataCiteSyncService;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Cache;
use Modules\Assistants\RelationTypeCorrection\Assistant;
use Tests\Support\RelationCorrectionFixtures as F;

uses()->group('assistant', 'browser', 'relation-type-correction');

beforeEach(function (): void {
    app(Vite::class)->useHotFile(storage_path('framework/testing-vite.hot'))->useBuildDirectory('build');
    Cache::flush();
    F::fake();
});

it('shows the directed preview and accepts the exact correction with an audit record', function (): void {
    $target = F::target();
    $suggestion = F::discover();
    $this->actingAs(F::actor());
    $this->mock(DataCiteSyncService::class)->shouldReceive('syncIfRegistered')->andReturn(DataCiteSyncResult::notRequired());
    F::registerAssistant();
    visit('/assistance')->assertNoSmoke()->assertSee('Relation Type Correction')
        ->assertSee('Current relation type')->assertSee('Proposed relation type')->assertSee('High confidence')
        ->click('[aria-label="Select Relation Type Correction: Is Part Of"]')->click('Accept')->assertSee('1 suggestion(s) accepted.');
    expect($target->fresh()->relationType->slug)->toBe('IsPartOf')->and(RelationTypeCorrectionReview::count())->toBe(1)
        ->and(AssistantSuggestion::find($suggestion->id))->toBeNull();
});

it('declines the preview and suppresses the same context on the next discovery', function (): void {
    $target = F::target();
    $suggestion = F::discover();
    $this->actingAs(F::actor());
    F::registerAssistant();
    visit('/assistance')->assertNoSmoke()
        ->click('[aria-label="Select Relation Type Correction: Is Part Of"]')->click('Decline')->assertSee('1 suggestion(s) declined.');
    expect($target->fresh()->relationType->slug)->toBe('HasPart')->and(RelationTypeCorrectionReview::first()->decision)->toBe('declined');
    app(Assistant::class)->runDiscovery(fn () => null);
    expect(AssistantSuggestion::where('assistant_id', 'relation-type-correction')->count())->toBe(0);
});
