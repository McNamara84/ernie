<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Models\AssistantSuggestion;
use App\Models\FundingAwardUriReview;
use App\Models\FundingReference;
use App\Models\LandingPage;
use App\Models\OaiPmhHarvest;
use App\Models\User;
use App\Services\BotProtection\LandingPageRenderDataCacheService;
use App\Services\DataCiteSyncResult;
use App\Services\DataCiteSyncService;
use App\Services\ResourceStorageService;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Assistants\FundingAwardUriEnrichment\Assistant;
use Tests\Support\FundingAwardUriFixtures as F;

beforeEach(fn () => F::fake());

it('invalidates cached funding even when the resource timestamp and reviewer stay the same', function (): void {
    $this->freezeTime();
    $target = F::target();
    $actor = User::factory()->admin()->create();
    $target->resource->update(['updated_by_user_id' => $actor->id]);
    $landing = LandingPage::factory()->create(['resource_id' => $target->resource_id, 'is_published' => true]);
    $cache = app(LandingPageRenderDataCacheService::class);
    $render = fn (): array => ['template' => 'default_gfz', 'props' => ['award_uri' => $target->fresh()->award_uri]];
    expect($cache->remember($landing, $render)['props']['award_uri'])->toBeNull();
    $suggestion = F::discover();
    Cache::tags(CacheKey::RESOURCE_DETAIL->tags())->put(CacheKey::RESOURCE_DETAIL->key($target->resource_id), 'stale', 60);
    $harvest = OaiPmhHarvest::create(['item_count' => 1, 'expires_at' => now()->addDay()]);
    DB::table('oai_pmh_harvest_items')->insert(['harvest_id' => $harvest->id, 'position' => 0, 'kind' => 'resource', 'identity_id' => $target->resource_id]);
    $this->mock(DataCiteSyncService::class)->shouldReceive('syncIfRegistered')->once()->andReturn(DataCiteSyncResult::notRequired());
    expect(app(Assistant::class)->acceptSuggestion($suggestion->id, F::input($suggestion), $actor)['success'])->toBeTrue()
        ->and($harvest->fresh()->expires_at->lte(now()))->toBeTrue()
        ->and($cache->remember($landing, $render)['props']['award_uri'])->toBe(F::URI)
        ->and(Cache::tags(CacheKey::RESOURCE_DETAIL->tags())->get(CacheKey::RESOURCE_DETAIL->key($target->resource_id)))->toBeNull();
});

it('invalidates pending previews when the editor replaces funding rows', function (): void {
    $target = F::target();
    $suggestion = F::discover();
    Cache::tags(CacheKey::ASSISTANCE_TOTAL_PENDING_COUNT->tags())->put(CacheKey::ASSISTANCE_TOTAL_PENDING_COUNT->value, 1, 60);
    app(ResourceStorageService::class)->storePrepared(['resourceId' => $target->resource_id, 'titles' => [], 'fundingReferences' => [[
        'funderName' => $target->funder_name, 'funderIdentifier' => $target->funder_identifier, 'funderIdentifierType' => 'Crossref Funder ID',
        'awardNumber' => $target->award_number, 'awardTitle' => $target->award_title,
    ]]]);
    expect(AssistantSuggestion::find($suggestion->id))->toBeNull()
        ->and(Cache::tags(CacheKey::ASSISTANCE_TOTAL_PENDING_COUNT->tags())->get(CacheKey::ASSISTANCE_TOTAL_PENDING_COUNT->value))->toBeNull()
        ->and($target->fresh())->toBeNull();
});

it('rolls back the value, review and pending suggestion together if the resource write fails', function (): void {
    $target = F::target();
    $suggestion = F::discover();
    $actor = User::factory()->admin()->create();
    $dispatcher = clone FundingReference::getEventDispatcher();
    FundingReference::updated(static fn () => throw new RuntimeException('Simulated storage failure'));
    try {
        expect(fn () => app(Assistant::class)->acceptSuggestion($suggestion->id, F::input($suggestion), $actor))->toThrow(RuntimeException::class, 'Simulated storage failure');
        expect($target->fresh()->award_uri)->toBeNull()->and(FundingAwardUriReview::count())->toBe(0)
            ->and(AssistantSuggestion::find($suggestion->id))->not->toBeNull();
    } finally {
        FundingReference::setEventDispatcher($dispatcher);
    }
});

it('keeps the accepted value and review when DataCite sync fails and supplies a retry', function (): void {
    $target = F::target();
    $suggestion = F::discover();
    $this->mock(DataCiteSyncService::class)->shouldReceive('syncIfRegistered')->once()->andReturn(DataCiteSyncResult::failed('10.5880/example', 'Offline'));
    $this->travel(5)->seconds();
    $before = $target->resource->updated_at;
    $result = app(Assistant::class)->acceptSuggestion($suggestion->id, F::input($suggestion), User::factory()->admin()->create());
    expect($result['success'])->toBeTrue()->and($result['datacite_sync_retry_url'])->toContain('/assistance/')
        ->and($target->fresh()->award_uri)->toBe(F::URI)->and(FundingAwardUriReview::count())->toBe(1)
        ->and($target->resource->fresh()->updated_at->gt($before))->toBeTrue();
});

it('resumes a partial scan after the last completed resource', function (): void {
    $first = F::target();
    $second = F::target();
    $assistant = app(Assistant::class);
    $assistant->runDiscovery(function (string $message): void {
        if (str_starts_with($message, 'Checked ')) {
            config(['funding_award_uri.enabled' => false]);
        }
    });
    expect($assistant->discoveryDetails()['partial'])->toBeTrue()
        ->and((int) DB::table('funding_award_uri_discovery_states')->value('last_resource_id'))->toBe($first->resource_id);
    config(['funding_award_uri.enabled' => true]);
    expect($assistant->runDiscovery(fn () => null))->toBe(1)
        ->and(AssistantSuggestion::where('resource_id', $second->resource_id)->count())->toBe(1)
        ->and((int) DB::table('funding_award_uri_discovery_states')->value('last_resource_id'))->toBe(0);
});

it('rechecks duplicate local references both after HTTP discovery and before acceptance', function (bool $duringDiscovery): void {
    $target = F::target();
    if ($duringDiscovery) {
        Http::swap(new Factory);
        Http::fake(function () use ($target) {
            $target->replicate()->save();

            return Http::response(['message' => ['total-results' => 1, 'items' => [F::grant()]]]);
        });
        expect(app(Assistant::class)->runDiscovery(fn () => null))->toBe(0);
    } else {
        $suggestion = F::discover();
        $target->replicate()->save();
        expect(app(Assistant::class)->acceptSuggestion($suggestion->id, F::input($suggestion), User::factory()->admin()->create())['success'])->toBeFalse();
    }
    expect($target->fresh()->award_uri)->toBeNull()->and(FundingAwardUriReview::count())->toBe(0);
})->with([true, false]);
