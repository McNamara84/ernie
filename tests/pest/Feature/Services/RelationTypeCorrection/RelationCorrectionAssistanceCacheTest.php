<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Datacenter;
use App\Models\RelationType;
use App\Services\Assistance\AssistanceReviewService;
use App\Services\RelationTypeCorrection\RelationCorrectionReviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Modules\Assistants\RelationTypeCorrection\Assistant;
use Tests\Support\RelationCorrectionFixtures as F;

beforeEach(function (): void {
    Cache::flush();
    F::fake();
    F::registerAssistant();
    $this->actingAs(F::actor());
});

function withRelationAssistanceCache(string $driver, Closure $callback): void
{
    $defaultDriver = Cache::getDefaultDriver();
    $directory = sys_get_temp_dir().'/ernie-relation-assistance-cache-'.bin2hex(random_bytes(8));
    if ($driver === 'file') {
        config(['cache.stores.relation_assistance_test' => ['driver' => 'file', 'path' => $directory]]);
    }
    Cache::setDefaultDriver($driver === 'file' ? 'relation_assistance_test' : $driver);

    try {
        $callback();
    } finally {
        Cache::setDefaultDriver($defaultDriver);
        if ($driver === 'file') {
            Cache::purge('relation_assistance_test');
            File::deleteDirectory($directory);
        }
    }
}

/** @return array{total: int, datacenters: list<array{id: int, name: string}>} */
function relationAssistanceSummary(): array
{
    $request = Request::create('/assistance');
    $request->setUserResolver(fn () => Auth::user());
    $shared = app(HandleInertiaRequests::class)->share($request);
    $summary = app(AssistanceReviewService::class)->summary();

    return ['total' => $shared['pendingAssistanceTotalCount'], 'datacenters' => $summary['datacenterOptions']];
}

it('refreshes the sidebar count and datacenter options when discovery creates proposals', function (string $driver): void {
    withRelationAssistanceCache($driver, function (): void {
        $target = F::target();
        $datacenter = Datacenter::factory()->create();
        $target->resource->update(['datacenter_id' => $datacenter->id]);
        expect(relationAssistanceSummary())->toBe(['total' => 0, 'datacenters' => []]);

        expect(app(Assistant::class)->runDiscovery(fn () => null))->toBe(1)
            ->and(relationAssistanceSummary())->toBe([
                'total' => 1,
                'datacenters' => [['id' => $datacenter->id, 'name' => $datacenter->name]],
            ]);
    });
})->with(['array', 'file']);

it('refreshes assistance summaries when discovery only removes obsolete or orphaned proposals', function (string $driver, string $state): void {
    withRelationAssistanceCache($driver, function () use ($state): void {
        $target = F::target();
        $datacenter = Datacenter::factory()->create();
        $target->resource->update(['datacenter_id' => $datacenter->id]);
        F::discover();
        expect(relationAssistanceSummary())->toBe([
            'total' => 1,
            'datacenters' => [['id' => $datacenter->id, 'name' => $datacenter->name]],
        ]);

        if ($state === 'orphaned') {
            $target->delete();
        } else {
            $target->update(['relation_type_id' => RelationType::where('slug', 'IsPartOf')->value('id')]);
        }
        $assistant = app(Assistant::class);
        expect($assistant->runDiscovery(fn () => null))->toBe(0)
            ->and($assistant->discoveryDetails()['stale_suggestions_removed'])->toBe(1)
            ->and(relationAssistanceSummary())->toBe(['total' => 0, 'datacenters' => []]);
    });
})->with([
    ['array', 'obsolete'], ['array', 'orphaned'],
    ['file', 'obsolete'], ['file', 'orphaned'],
]);

it('refreshes assistance summaries after accepting or declining a relation correction', function (string $driver, string $decision): void {
    withRelationAssistanceCache($driver, function () use ($decision): void {
        $target = F::target();
        $datacenter = Datacenter::factory()->create();
        $target->resource->update(['datacenter_id' => $datacenter->id]);
        $suggestion = F::discover();
        expect(relationAssistanceSummary())->toBe([
            'total' => 1,
            'datacenters' => [['id' => $datacenter->id, 'name' => $datacenter->name]],
        ]);

        // Exercise the review service itself; the generic acceptance wrapper
        // also invalidates these caches and can mask a service-level failure.
        $result = app(RelationCorrectionReviewService::class)->review(
            $suggestion, Auth::user(), $decision,
            [...F::input($suggestion), 'defer_datacite_sync' => true], 'Reviewed original metadata',
        );
        expect($result['success'])->toBeTrue()
            ->and(relationAssistanceSummary())->toBe(['total' => 0, 'datacenters' => []]);
    });
})->with([
    ['array', 'accepted'], ['array', 'declined'],
    ['file', 'accepted'], ['file', 'declined'],
]);
