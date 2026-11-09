<?php

declare(strict_types=1);

use App\Console\Commands\DeduplicateSubjects;
use App\Models\AssistantDismissed;
use App\Models\AssistantSuggestion;
use App\Models\LandingPage;
use App\Models\Subject;
use App\Services\BotProtection\LandingPageRenderDataCacheService;
use App\Services\DataCiteJsonExporter;
use App\Services\ResourceCacheService;
use App\Services\Subjects\SubjectDuplicateCleanupService;
use App\Services\Subjects\SubjectEquivalentCleanupService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SubjectDuplicateFixtures as F;

covers(DeduplicateSubjects::class, SubjectEquivalentCleanupService::class);

beforeEach(function (): void {
    F::fake();
    Http::preventStrayRequests();
});

it('audits then applies each reported case with complete journal and idempotent retries', function (string $case): void {
    $resource = F::resource($case);
    $page = LandingPage::factory()->published()->create(['resource_id' => $resource->id]);
    $oldIds = $resource->subjects()->pluck('id')->all();
    $base = ['assistant_id' => 'subject-metadata-enrichment', 'resource_id' => $resource->id, 'target_id' => $oldIds[0],
        'suggested_value' => 'test', 'suggested_label' => 'Test', 'discovered_at' => now()];
    $suggestion = AssistantSuggestion::create([...$base, 'target_type' => 'subject']);
    $unrelated = AssistantSuggestion::create([...$base, 'target_type' => 'description']);
    expect(app(SubjectDuplicateCleanupService::class)->run()['duplicate_groups'])->toBe(0);
    $cache = app(LandingPageRenderDataCacheService::class);
    $cache->remember($page, fn () => ['template' => 'default_gfz', 'props' => ['old' => true]]);
    $datestamp = $resource->fresh()->updated_at;
    $this->travel(2)->seconds();
    $reportPath = Storage::disk('local')->path('review.json');
    $journalPath = Storage::disk('local')->path('apply.jsonl');
    $this->artisan('subjects:deduplicate', ['--mode' => 'equivalent', '--report' => $reportPath])->assertSuccessful();
    $report = json_decode(file_get_contents($reportPath), true, flags: JSON_THROW_ON_ERROR);
    expect($resource->subjects()->count())->toBe(2)
        ->and($report['resources'][0]['analysis']['groups'])->toHaveCount(1);
    $this->artisan('subjects:deduplicate', ['--mode' => 'equivalent', '--apply' => true, '--from-report' => $reportPath, '--report' => $journalPath])->assertSuccessful();
    expect($resource->subjects()->count())->toBe(1)
        ->and($resource->fresh()->updated_at->greaterThan($datestamp))->toBeTrue()
        ->and(AssistantSuggestion::find($suggestion->id))->toBeNull()
        ->and(AssistantSuggestion::find($unrelated->id))->not->toBeNull()
        ->and(file_get_contents($journalPath))->toContain('"status":"before"', '"status":"applied"', '"suggestions"')
        ->and($cache->remember($page, fn () => ['template' => 'default_gfz', 'props' => ['new' => true]])['props'])->toBe(['new' => true])
        ->and(app(DataCiteJsonExporter::class)->export($resource->fresh())['data']['attributes']['subjects'])->toHaveCount(1);
    $this->artisan('subjects:deduplicate', ['--mode' => 'equivalent', '--apply' => true, '--from-report' => $reportPath, '--report' => Storage::disk('local')->path('retry.jsonl')])->assertSuccessful();
    expect($resource->subjects()->count())->toBe(1);
    Http::assertNothingSent();
})->with(['aquifers', 'instruments']);

it('rejects a reviewed snapshot after subject or vocabulary changes', function (string $change): void {
    $resource = F::resource();
    $service = app(SubjectEquivalentCleanupService::class);
    $report = $service->audit();
    if ($change === 'subject') {
        $resource->subjects()->orderBy('id')->first()->update(['classification_code' => 'changed']);
        // Keep another duplicate group so apply must perform full snapshot revalidation.
        $resource->subjects()->create(F::rows()[0]);
    } else {
        $data = json_decode(Storage::disk('local')->get('gcmd-science-keywords.json'), true);
        $data['lastUpdated'] = '2026-11-07';
        Storage::disk('local')->put('gcmd-science-keywords.json', json_encode($data));
    }
    $before = $resource->subjects()->get()->toArray();
    $result = $service->apply($report, static function (array $entry): void {});
    expect($result[0]['status'])->toBe('error')->and($result[0]['message'])->toContain('changed')
        ->and($resource->subjects()->get()->toArray())->toBe($before);
})->with(['subject', 'vocabulary']);

it('requires a durable before journal and rolls back mutations on failure', function (string $failure): void {
    $resource = F::resource();
    $service = app(SubjectEquivalentCleanupService::class);
    $report = $service->audit();
    $before = $resource->subjects()->get()->toArray();
    if ($failure === 'delete') {
        $fired = false;
        DB::listen(function ($query) use (&$fired): void {
            if (! $fired && str_contains($query->sql, 'delete from "subjects"')) {
                $fired = true;
                throw new RuntimeException('Simulated delete failure');
            }
        });
    }
    $result = $service->apply($report, static function (array $entry) use ($failure): void {
        if ($failure === 'journal' && $entry['status'] === 'before') {
            throw new RuntimeException('Disk full');
        }
    });
    expect($result[0]['status'])->toBe('error')->and($resource->subjects()->get()->toArray())->toBe($before);
})->with(['journal', 'delete']);

it('reports unresolved variants and respects alias DOI resume and free keyword filters', function (): void {
    $first = F::resource();
    $second = F::resource('instruments');
    $second->update(['doi' => '10.5880/issue.1531.test']);
    $service = app(SubjectEquivalentCleanupService::class);
    $report = $service->audit(dois: ['https://doi.org/10.5880/ISSUE.1531.TEST'], schemes: ['GCMD Instruments'], after: $first->id, limit: 1, chunk: 1);
    expect($report['resources'])->toHaveCount(1)->and($report['resources'][0]['subjects'])->toHaveCount(2);
    Storage::disk('local')->delete('gcmd-instruments.json');
    $report = $service->audit(dois: [$second->doi]);
    expect($report['resources'][0]['analysis']['groups'])->toBeEmpty()
        ->and($report['resources'][0]['analysis']['diagnostics'])->toHaveCount(1);
    $second->subjects()->createMany([['value' => 'free'], ['value' => 'free']]);
    expect($service->audit(dois: [$second->doi])['resources'][0]['analysis']['groups'])->toBeEmpty()
        ->and($service->audit(dois: [$second->doi], schemes: [''])['resources'])->toBeEmpty()
        ->and($service->audit(dois: [$second->doi], includeFree: true)['resources'][0]['analysis']['groups'])->toHaveCount(1);
});

it('rejects missing reports invalid modes and overwriting an existing file', function (): void {
    F::resource();
    $this->artisan('subjects:deduplicate', ['--mode' => 'wrong'])->assertFailed();
    $this->artisan('subjects:deduplicate', ['--mode' => 'equivalent', '--apply' => true])->assertFailed();
    $this->artisan('subjects:deduplicate', ['--mode' => 'equivalent', '--report' => Storage::disk('local')->path('new.json'), '--apply' => true])->assertFailed();
    Storage::disk('local')->put('existing.json', 'preserve me');
    $this->artisan('subjects:deduplicate', ['--mode' => 'equivalent', '--report' => Storage::disk('local')->path('existing.json')])->assertFailed();
    expect(Storage::disk('local')->get('existing.json'))->toBe('preserve me')->and(Subject::count())->toBe(2);
});

it('rejects malformed reviewed reports before making changes', function (): void {
    F::resource();
    expect(fn () => app(SubjectEquivalentCleanupService::class)->apply(['version' => 'invalid'], static function (array $row): void {}))
        ->toThrow(InvalidArgumentException::class);
    expect(Subject::count())->toBe(2);
    $service = app(SubjectEquivalentCleanupService::class);
    $report = $service->audit();
    $report['resources'][0]['analysis']['groups'] = [];
    expect(fn () => $service->apply($report, static function (array $row): void {}))->toThrow(InvalidArgumentException::class, 'modified');
    expect(Subject::count())->toBe(2);
});

it('can refresh caches from a reviewed report without changing any subjects', function (): void {
    $resource = F::resource();
    $service = app(SubjectEquivalentCleanupService::class);
    $before = $resource->subjects()->get()->toArray();
    $result = $service->apply($service->audit(), static function (array $entry): void {}, refreshOnly: true);
    expect($result[0]['status'])->toBe('caches_refreshed')->and($resource->subjects()->get()->toArray())->toBe($before);
});

it('recovers a cache failure after commit without deleting more subjects', function (): void {
    $resource = F::resource();
    $service = app(SubjectEquivalentCleanupService::class);
    $report = $service->audit();
    $this->mock(ResourceCacheService::class)->shouldReceive('invalidateResourceCache')->once()->andThrow(new RuntimeException('Cache unavailable'));
    $journal = [];
    $write = static function (array $entry) use (&$journal): void {
        $journal[] = $entry;
    };
    expect($service->apply($report, $write)[0]['status'])->toBe('cache_error')
        ->and($resource->subjects()->count())->toBe(1);
    $ids = $resource->subjects()->pluck('id')->all();
    app()->forgetInstance(ResourceCacheService::class);
    expect($service->apply($report, $write)[0]['status'])->toBe('unchanged')
        ->and($resource->subjects()->pluck('id')->all())->toBe($ids)
        ->and(array_column($journal, 'status'))->toBe(['before', 'cache_error', 'unchanged']);
});

it('invalidates affected assistance while preserving unrelated hierarchy decisions', function (): void {
    $resource = F::resource();
    $base = ['assistant_id' => 'subject-hierarchy-correction', 'resource_id' => $resource->id,
        'target_type' => 'subject_hierarchy', 'target_id' => $resource->id, 'suggested_label' => 'Review', 'discovered_at' => now()];
    $affected = AssistantSuggestion::create([...$base, 'suggested_value' => 'science', 'metadata' => ['scheme' => 'Science Keywords']]);
    $unrelated = AssistantSuggestion::create([...$base, 'suggested_value' => 'instrument', 'metadata' => ['scheme' => 'Instruments']]);
    $dismissed = AssistantDismissed::create(['assistant_id' => $base['assistant_id'], 'target_type' => $base['target_type'],
        'target_id' => $resource->id, 'dismissed_value' => 'instrument']);
    $subjectDismissed = AssistantDismissed::create(['assistant_id' => 'subject-metadata-enrichment', 'target_type' => 'subject',
        'target_id' => $resource->subjects()->firstOrFail()->id, 'dismissed_value' => 'old']);
    $service = app(SubjectEquivalentCleanupService::class);
    expect($service->apply($service->audit(), static function (array $entry): void {})[0]['status'])->toBe('applied')
        ->and($affected->fresh())->toBeNull()->and($subjectDismissed->fresh())->toBeNull()
        ->and($unrelated->fresh())->not->toBeNull()->and($dismissed->fresh())->not->toBeNull();
});

it('reports a resume cursor even when no duplicates remain in the scanned batch', function (): void {
    $resource = F::resource();
    $resource->subjects()->orderBy('id')->firstOrFail()->delete();
    $report = app(SubjectEquivalentCleanupService::class)->audit(limit: 1);
    expect($report['resources'])->toBeEmpty()->and($report['resources_scanned'])->toBe(1)
        ->and($report['last_resource_id'])->toBe($resource->id);
});

it('refuses to repair a resource awaiting embargo reconciliation', function (): void {
    $resource = F::resource();
    $resource->forceFill(['embargo_registration_started_at' => now()])->save();
    $service = app(SubjectEquivalentCleanupService::class);
    expect($service->apply($service->audit(), static function (array $entry): void {})[0]['status'])->toBe('error')
        ->and($resource->subjects()->count())->toBe(2);
});
