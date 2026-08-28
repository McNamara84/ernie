<?php

declare(strict_types=1);

use App\Console\Commands\DeduplicateSubjects;
use App\Models\AssistantDismissed;
use App\Models\AssistantSuggestion;
use App\Models\IgsnMetadata;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\Subject;
use App\Services\BotProtection\LandingPageRenderDataCacheService;
use App\Services\PortalKeywordCacheInvalidationService;
use App\Services\Subjects\SubjectDuplicateCleanupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

covers(DeduplicateSubjects::class, SubjectDuplicateCleanupService::class);

/**
 * @param  array<string, mixed>  $overrides
 */
function issue1199Subject(Resource $resource, array $overrides = []): Subject
{
    return Subject::withoutEvents(fn (): Subject => Subject::query()->create(array_merge([
        'resource_id' => $resource->id,
        'value' => 'geophysics',
        'language' => 'en',
        'subject_scheme' => 'GEMET - GEneral Multilingual Environmental Thesaurus',
        'scheme_uri' => 'https://www.eionet.europa.eu/gemet/',
        'value_uri' => 'http://www.eionet.europa.eu/gemet/concept/3650',
        'classification_code' => null,
        'breadcrumb_path' => 'GEMET > geophysics',
    ], $overrides)));
}

it('dry-runs safely then removes exact duplicates, stale assistance, and invalidates caches once', function (): void {
    $resource = Resource::factory()->create(['doi' => '10.5880/issue.1199']);
    $landingPage = LandingPage::factory()->draft()->create(['resource_id' => $resource->id]);
    $survivor = issue1199Subject($resource);
    $duplicate = issue1199Subject($resource);

    AssistantSuggestion::query()->create([
        'assistant_id' => 'subject-enrichment',
        'resource_id' => $resource->id,
        'target_type' => 'subject',
        'target_id' => $duplicate->id,
        'suggested_value' => 'https://example.test/concept',
        'suggested_label' => 'Concept',
        'discovered_at' => now(),
    ]);
    AssistantDismissed::query()->create([
        'assistant_id' => 'subject-enrichment',
        'target_type' => 'subject',
        'target_id' => $duplicate->id,
        'dismissed_value' => 'https://example.test/dismissed',
    ]);
    $unrelatedSuggestion = AssistantSuggestion::query()->create([
        'assistant_id' => 'subject-enrichment',
        'resource_id' => $resource->id,
        'target_type' => 'description',
        'target_id' => $duplicate->id,
        'suggested_value' => 'https://example.test/unrelated',
        'suggested_label' => 'Unrelated',
        'discovered_at' => now(),
    ]);

    $keywordCache = Mockery::mock(PortalKeywordCacheInvalidationService::class);
    $keywordCache->shouldReceive('scheduleAfterCommit')->once();
    $landingPageCache = Mockery::mock(LandingPageRenderDataCacheService::class);
    $landingPageCache->shouldReceive('forgetById')->once()->with($landingPage->id)->andReturnTrue();
    $cleanup = new SubjectDuplicateCleanupService($keywordCache, $landingPageCache);

    $dryRun = $cleanup->run();

    expect($dryRun)->toMatchArray([
        'resources_scanned' => 1,
        'subjects_scanned' => 2,
        'duplicate_groups' => 1,
        'duplicate_subjects' => 1,
        'assistant_rows' => 0,
        'unchanged_resources' => 0,
        'errors' => 0,
    ])->and($dryRun['records'][0])->toMatchArray([
        'survivor_id' => $survivor->id,
        'duplicate_ids' => (string) $duplicate->id,
        'group_size' => 2,
        'status' => 'would_delete',
    ])->and(Subject::query()->where('resource_id', $resource->id)->count())->toBe(2)
        ->and(AssistantSuggestion::query()->count())->toBe(2)
        ->and(AssistantDismissed::query()->count())->toBe(1);

    $applied = $cleanup->run(apply: true);

    expect($applied)->toMatchArray([
        'duplicate_groups' => 1,
        'duplicate_subjects' => 1,
        'assistant_rows' => 2,
        'errors' => 0,
    ])->and(Subject::query()->pluck('id')->all())->toBe([$survivor->id])
        ->and(AssistantSuggestion::query()->pluck('id')->all())->toBe([$unrelatedSuggestion->id])
        ->and(AssistantDismissed::query()->count())->toBe(0)
        ->and($cleanup->run(apply: true))->toMatchArray([
            'resources_scanned' => 1,
            'duplicate_groups' => 0,
            'duplicate_subjects' => 0,
            'unchanged_resources' => 1,
            'errors' => 0,
        ]);
});

it('keeps every non-identical field variant and excludes free keywords by default', function (): void {
    $resource = Resource::factory()->create();
    $base = issue1199Subject($resource);
    $duplicate = issue1199Subject($resource);

    issue1199Subject($resource, ['value' => 'geology']);
    issue1199Subject($resource, ['language' => 'de']);
    issue1199Subject($resource, ['subject_scheme' => 'Custom vocabulary']);
    issue1199Subject($resource, ['scheme_uri' => '']);
    issue1199Subject($resource, ['value_uri' => 'https://example.test/other']);
    issue1199Subject($resource, ['classification_code' => '3650']);
    issue1199Subject($resource, ['breadcrumb_path' => 'Environment > geophysics']);
    $freeSurvivor = issue1199Subject($resource, [
        'value' => 'free keyword',
        'subject_scheme' => null,
        'scheme_uri' => null,
        'value_uri' => null,
        'breadcrumb_path' => null,
    ]);
    $freeDuplicate = issue1199Subject($resource, [
        'value' => 'free keyword',
        'subject_scheme' => null,
        'scheme_uri' => null,
        'value_uri' => null,
        'breadcrumb_path' => null,
    ]);

    $defaultRun = app(SubjectDuplicateCleanupService::class)->run(apply: true);

    expect($defaultRun)->toMatchArray([
        'subjects_scanned' => 9,
        'duplicate_groups' => 1,
        'duplicate_subjects' => 1,
    ])->and(Subject::query()->whereKey($base->id)->exists())->toBeTrue()
        ->and(Subject::query()->whereKey($duplicate->id)->exists())->toBeFalse()
        ->and(Subject::query()->whereKey($freeSurvivor->id)->exists())->toBeTrue()
        ->and(Subject::query()->whereKey($freeDuplicate->id)->exists())->toBeTrue()
        ->and(Subject::query()->count())->toBe(10);

    $withFree = app(SubjectDuplicateCleanupService::class)->run(apply: true, includeFree: true);

    expect($withFree)->toMatchArray([
        'subjects_scanned' => 10,
        'duplicate_groups' => 1,
        'duplicate_subjects' => 1,
    ])->and(Subject::query()->whereKey($freeSurvivor->id)->exists())->toBeTrue()
        ->and(Subject::query()->whereKey($freeDuplicate->id)->exists())->toBeFalse()
        ->and(Subject::query()->count())->toBe(9);
});

it('honors DOI, scheme, resume, limit, and chunk filters', function (): void {
    $first = Resource::factory()->create(['doi' => '10.5880/issue.1199.first']);
    issue1199Subject($first);
    issue1199Subject($first);

    $selected = Resource::factory()->create(['doi' => '10.5880/ISSUE.1199.SELECTED']);
    issue1199Subject($selected);
    issue1199Subject($selected);
    issue1199Subject($selected, [
        'value' => 'mineralogy',
        'subject_scheme' => 'EPOS MSL vocabulary',
        'scheme_uri' => 'https://epos-msl.uu.nl/voc/',
        'value_uri' => 'https://epos-msl.uu.nl/voc/mineralogy',
        'breadcrumb_path' => 'mineralogy',
    ]);
    issue1199Subject($selected, [
        'value' => 'mineralogy',
        'subject_scheme' => 'EPOS MSL vocabulary',
        'scheme_uri' => 'https://epos-msl.uu.nl/voc/',
        'value_uri' => 'https://epos-msl.uu.nl/voc/mineralogy',
        'breadcrumb_path' => 'mineralogy',
    ]);

    $third = Resource::factory()->create(['doi' => '10.5880/issue.1199.third']);
    issue1199Subject($third);
    issue1199Subject($third);

    $result = app(SubjectDuplicateCleanupService::class)->run(
        afterResourceId: $first->id,
        limit: 1,
        chunk: 1,
        dois: ['https://doi.org/10.5880/issue.1199.selected'],
        schemes: ['GEMET - GEneral Multilingual Environmental Thesaurus'],
    );

    expect($result)->toMatchArray([
        'resources_scanned' => 1,
        'subjects_scanned' => 2,
        'duplicate_groups' => 1,
        'duplicate_subjects' => 1,
        'errors' => 0,
    ])->and($result['records'][0]['resource_id'])->toBe($selected->id)
        ->and($result['records'][0]['scheme'])->toBe('GEMET - GEneral Multilingual Environmental Thesaurus');
});

it('writes a CSV report and remains non-mutating unless apply is requested', function (): void {
    $resource = Resource::factory()->create(['doi' => '10.5880/issue.1199.command']);
    $survivor = issue1199Subject($resource);
    $duplicate = issue1199Subject($resource);
    $reportPath = storage_path('framework/testing/issue-1199-'.Str::uuid().'.csv');

    try {
        $this->artisan('subjects:deduplicate', [
            '--doi' => ['doi:10.5880/issue.1199.command'],
            '--chunk' => 1,
            '--report' => $reportPath,
        ])->expectsOutputToContain('Dry run only; no data was changed.')
            ->assertExitCode(Command::SUCCESS);

        expect(Subject::query()->whereKey($duplicate->id)->exists())->toBeTrue()
            ->and(File::get($reportPath))->toContain('resource_id,doi,scheme,survivor_id,duplicate_ids,group_size,status,message')
            ->toContain('10.5880/issue.1199.command')
            ->toContain('would_delete');

        $this->artisan('subjects:deduplicate', [
            '--apply' => true,
            '--doi' => ['10.5880/issue.1199.command'],
        ])->expectsOutputToContain('Exact subject duplicate cleanup applied.')
            ->assertExitCode(Command::SUCCESS);

        expect(Subject::query()->pluck('id')->all())->toBe([$survivor->id]);
    } finally {
        File::delete($reportPath);
    }
});

it('invalidates complete IGSN landing-page families after applying changes', function (): void {
    $resource = Resource::factory()->create(['doi' => '10.60510/issue1199']);
    IgsnMetadata::query()->create([
        'resource_id' => $resource->id,
        'upload_status' => IgsnMetadata::STATUS_REGISTERED,
    ]);
    issue1199Subject($resource);
    issue1199Subject($resource);

    $keywordCache = Mockery::mock(PortalKeywordCacheInvalidationService::class);
    $keywordCache->shouldReceive('scheduleAfterCommit')->once();
    $landingPageCache = Mockery::mock(LandingPageRenderDataCacheService::class);
    $landingPageCache->shouldReceive('forgetForIgsnFamilies')->once()->with([$resource->id]);

    $result = (new SubjectDuplicateCleanupService($keywordCache, $landingPageCache))->run(apply: true);

    expect($result)->toMatchArray([
        'duplicate_groups' => 1,
        'duplicate_subjects' => 1,
        'errors' => 0,
    ]);
});

it('returns a failing exit code when post-commit cache invalidation fails', function (): void {
    $resource = Resource::factory()->create();
    issue1199Subject($resource);
    issue1199Subject($resource);

    $keywordCache = Mockery::mock(PortalKeywordCacheInvalidationService::class);
    $keywordCache->shouldReceive('scheduleAfterCommit')->once()->andThrow(new RuntimeException('Cache unavailable'));
    $landingPageCache = Mockery::mock(LandingPageRenderDataCacheService::class);
    app()->instance(
        SubjectDuplicateCleanupService::class,
        new SubjectDuplicateCleanupService($keywordCache, $landingPageCache),
    );

    $this->artisan('subjects:deduplicate', ['--apply' => true])
        ->assertExitCode(Command::FAILURE);

    expect(Subject::query()->where('resource_id', $resource->id)->count())->toBe(1);
});
