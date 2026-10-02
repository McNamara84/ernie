<?php

declare(strict_types=1);

use App\Enums\PortalCacheArea;
use App\Models\AssistantDismissed;
use App\Models\AssistantSuggestion;
use App\Models\Resource;
use App\Models\Subject;
use App\Models\Title;
use App\Models\User;
use App\Services\Assistance\AssistantRegistrar;
use App\Services\DataCiteSyncResult;
use App\Services\DataCiteSyncService;
use App\Services\PortalCacheInvalidationService;
use App\Services\SubjectEnrichment\SubjectVocabularyLookupService;
use App\Services\SubjectHierarchy\SubjectHierarchyAcceptanceService;
use App\Services\SubjectHierarchy\SubjectHierarchyCacheService;
use App\Services\SubjectHierarchy\SubjectHierarchyDiscoveryService;
use App\Services\SubjectHierarchy\SubjectHierarchyVocabularyService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Assistants\SubjectHierarchyCorrection\Assistant;

covers(SubjectHierarchyAcceptanceService::class, SubjectHierarchyDiscoveryService::class, SubjectHierarchyVocabularyService::class);

function hierarchyConcepts(): array
{
    return [
        ['id' => 'https://example.org/root', 'text' => 'Root', 'broaderIds' => []],
        ['id' => 'https://example.org/branch', 'text' => 'Branch', 'broaderIds' => ['https://example.org/root']],
        ['id' => 'https://example.org/alpha', 'text' => 'Alpha', 'notation' => 'A', 'description' => 'Alpha definition', 'broaderIds' => ['https://example.org/branch']],
        ['id' => 'https://example.org/beta', 'text' => 'Beta', 'broaderIds' => ['https://example.org/root', 'https://example.org/branch']],
        ['id' => 'https://example.org/other', 'text' => 'Other', 'broaderIds' => []],
    ];
}

function publishHierarchy(array $concepts = [], string $scheme = 'Platforms'): void
{
    $file = app(SubjectVocabularyLookupService::class)->localCacheFile($scheme);
    (new SubjectHierarchyCacheService)->publishFlat($file, '{"data":[],"lastUpdated":"fixture"}', $concepts ?: hierarchyConcepts(), $scheme, 'https://example.org/scheme');
}

function hierarchySubject(Resource $resource, string $name = 'Root', array $attributes = []): Subject
{
    return Subject::create([
        'resource_id' => $resource->id, 'value' => $name, 'subject_scheme' => 'GCMD Platforms',
        'scheme_uri' => 'https://example.org/scheme', 'value_uri' => 'https://example.org/'.strtolower($name),
        'language' => 'en', ...$attributes,
    ]);
}

function discoverHierarchy(): int
{
    return app(Assistant::class)->runDiscovery(static function (string $message): void {});
}

function hierarchyProposal(Resource $resource): AssistantSuggestion
{
    discoverHierarchy();

    return AssistantSuggestion::where('assistant_id', 'subject-hierarchy-correction')->where('resource_id', $resource->id)
        ->where('metadata->broader_id', 'https://example.org/root')->firstOrFail();
}

function hierarchyInput(AssistantSuggestion $suggestion, array $selected = ['https://example.org/alpha']): array
{
    return ['selected_leaf_ids' => $selected, 'subject_hierarchy_fingerprint' => $suggestion->metadata['fingerprint']];
}

beforeEach(function (): void {
    Storage::fake('local');
    Queue::fake();
    $this->mock(DataCiteSyncService::class)->shouldReceive('syncIfRegistered')->andReturn(DataCiteSyncResult::notRequired());
    publishHierarchy();
});

it('registers the assistant and presents its resource-scoped review contract', function (): void {
    $resource = Resource::factory()->create();
    hierarchySubject($resource);
    $proposal = hierarchyProposal($resource);
    $assistant = app(AssistantRegistrar::class)->get('subject-hierarchy-correction');
    $presented = $assistant->getSuggestionForReview($proposal->id);

    expect($presented['review'])->toMatchArray(['can_accept' => true, 'can_decline' => true,
        'exclusive_target_key' => 'subject-hierarchy-correction:'.$resource->id.':Platforms'])
        ->and($proposal->metadata['leaf_ids'])->toBe(['https://example.org/alpha', 'https://example.org/beta'])
        ->and($proposal->metadata['nodes'])->toHaveCount(4)
        ->and(discoverHierarchy())->toBe(0)
        ->and(AssistantSuggestion::count())->toBe(1);
});

it('supports every configured hierarchical vocabulary', function (string $scheme): void {
    publishHierarchy(scheme: $scheme);
    $resource = Resource::factory()->create();
    hierarchySubject($resource, attributes: ['subject_scheme' => $scheme]);
    expect(discoverHierarchy())->toBe(1);
})->with(['Science Keywords', 'Platforms', 'Instruments', 'EPOS MSL vocabulary', 'GEMET',
    'International Chronostratigraphic Chart', 'Analytical Methods for Geochemistry and Cosmochemistry',
    'European Science Vocabulary (EuroSciVoc)', 'CGI Simple Lithology']);

it('detects partial tagging but excludes a fully covered broader term and an implicit breadcrumb', function (): void {
    $partial = Resource::factory()->create();
    hierarchySubject($partial);
    hierarchySubject($partial, 'Alpha');
    $full = Resource::factory()->create();
    hierarchySubject($full);
    hierarchySubject($full, 'Alpha');
    hierarchySubject($full, 'Beta');
    $leafOnly = Resource::factory()->create();
    hierarchySubject($leafOnly, 'Alpha', ['value' => 'Root > Branch > Alpha', 'breadcrumb_path' => 'Root > Branch > Alpha']);

    expect(discoverHierarchy())->toBe(1)
        ->and(AssistantSuggestion::first()->resource_id)->toBe($partial->id)
        ->and(AssistantSuggestion::first()->metadata['existing_leaf_ids'])->toBe(['https://example.org/alpha']);
});

it('saves trusted metadata and removes only explicit broader variants for a subset', function (): void {
    $resource = Resource::factory()->create();
    $broader = hierarchySubject($resource);
    $duplicate = hierarchySubject($resource, attributes: ['value_uri' => null, 'value' => 'Root', 'scheme_uri' => null]);
    $other = hierarchySubject($resource, 'Other');
    $free = hierarchySubject($resource, 'Root', ['subject_scheme' => null]);
    $proposal = hierarchyProposal($resource);
    $proposal->update(['metadata' => [...$proposal->metadata, 'nodes' => [['id' => 'https://attacker.invalid', 'label' => 'Forged']]]]);
    $result = app(Assistant::class)->acceptSuggestion($proposal->id, hierarchyInput($proposal));

    expect($result['success'])->toBeTrue()
        ->and(Subject::whereKey([$broader->id, $duplicate->id])->exists())->toBeFalse()
        ->and(Subject::whereKey([$other->id, $free->id])->count())->toBe(2)
        ->and(AssistantSuggestion::whereKey($proposal->id)->exists())->toBeFalse();
    $alpha = Subject::where('resource_id', $resource->id)->where('value_uri', 'https://example.org/alpha')->sole();
    expect($alpha->value)->toBe('Alpha')->and($alpha->language)->toBe('en')
        ->and($alpha->subject_scheme)->toBe('GCMD Platforms')->and($alpha->scheme_uri)->toBe('https://example.org/scheme')
        ->and($alpha->classification_code)->toBe('A')->and($alpha->breadcrumb_path)->toBe('Root > Branch > Alpha');
    expect(app(Assistant::class)->acceptSuggestion($proposal->id, hierarchyInput($proposal))['success'])->toBeFalse()
        ->and(Subject::where('resource_id', $resource->id)->count())->toBe(3);
});

it('retains a true broader term for the complete deduplicated leaf selection', function (): void {
    $resource = Resource::factory()->create();
    $root = hierarchySubject($resource);
    $alpha = hierarchySubject($resource, 'Alpha', ['value_uri' => null]);
    $proposal = hierarchyProposal($resource);
    expect(app(Assistant::class)->acceptSuggestion($proposal->id, hierarchyInput($proposal, $proposal->metadata['leaf_ids']))['success'])->toBeTrue()
        ->and(Subject::whereKey([$root->id, $alpha->id])->count())->toBe(2)
        ->and(Subject::where('resource_id', $resource->id)->count())->toBe(3)
        ->and(discoverHierarchy())->toBe(0);
});

it('rejects invalid selections without changing any subjects', function (array $selection): void {
    $resource = Resource::factory()->create();
    hierarchySubject($resource);
    $proposal = hierarchyProposal($resource);
    expect(app(Assistant::class)->acceptSuggestion($proposal->id, hierarchyInput($proposal, $selection))['success'])->toBeFalse()
        ->and($resource->subjects()->count())->toBe(1)
        ->and(AssistantSuggestion::whereKey($proposal->id)->exists())->toBeTrue();
})->with(['empty' => [[]], 'unknown' => [['https://example.org/foreign']], 'intermediate' => [['https://example.org/branch']],
    'broader' => [['https://example.org/root']], 'duplicate' => [['https://example.org/alpha', 'https://example.org/alpha']], 'invalid type' => [[123]]]);

it('requires preserving already assigned leaf subjects', function (): void {
    $resource = Resource::factory()->create();
    hierarchySubject($resource);
    hierarchySubject($resource, 'Beta');
    $proposal = hierarchyProposal($resource);
    expect(app(Assistant::class)->acceptSuggestion($proposal->id, hierarchyInput($proposal))['success'])->toBeFalse()
        ->and($resource->subjects()->count())->toBe(2);
});

it('rejects stale cases after relevant subject or source changes', function (string $change): void {
    $resource = Resource::factory()->create();
    $root = hierarchySubject($resource);
    $proposal = hierarchyProposal($resource);
    match ($change) {
        'subject' => hierarchySubject($resource, 'Beta'),
        'removed' => $root->delete(),
        'source' => publishHierarchy([...hierarchyConcepts(), ['id' => 'https://example.org/gamma', 'text' => 'Gamma', 'broaderIds' => ['https://example.org/root']]]),
        'missing cache' => Storage::delete('subject-hierarchies/gcmd-platforms.json'),
        'different source' => Storage::put('gcmd-platforms.json', '{"data":[]}'),
    };
    $before = $resource->subjects()->get()->toArray();
    expect(app(Assistant::class)->acceptSuggestion($proposal->id, hierarchyInput($proposal))['success'])->toBeFalse()
        ->and($resource->subjects()->get()->toArray())->toBe($before);
})->with(['subject', 'removed', 'source', 'missing cache', 'different source']);

it('requires a matching client fingerprint and a valid resource target', function (string $change): void {
    $resource = Resource::factory()->create();
    hierarchySubject($resource);
    $proposal = hierarchyProposal($resource);
    $input = hierarchyInput($proposal);
    if ($change === 'fingerprint') {
        $input['subject_hierarchy_fingerprint'] = str_repeat('0', 64);
    }
    if ($change === 'target') {
        $proposal->update(['target_id' => $resource->id + 1000]);
    }
    if ($change === 'metadata') {
        $proposal->update(['metadata' => []]);
    }
    expect(app(Assistant::class)->acceptSuggestion($proposal->id, $input)['success'])->toBeFalse()
        ->and($resource->subjects()->count())->toBe(1);
})->with(['fingerprint', 'target', 'metadata']);

it('rolls back all changes and retains the proposal if saving a leaf fails', function (): void {
    $resource = Resource::factory()->create();
    hierarchySubject($resource);
    $proposal = hierarchyProposal($resource);
    Event::listen('eloquent.created: '.Subject::class, static function (Subject $subject): void {
        if ($subject->value === 'Beta') {
            throw new RuntimeException('Simulated persistence failure');
        }
    });
    expect(fn () => app(Assistant::class)->acceptSuggestion($proposal->id, hierarchyInput($proposal, $proposal->metadata['leaf_ids'])))->toThrow(RuntimeException::class)
        ->and($resource->subjects()->count())->toBe(1)
        ->and(AssistantSuggestion::whereKey($proposal->id)->exists())->toBeTrue();
});

it('refreshes affected overlapping proposals and rejects their previous selection', function (): void {
    $resource = Resource::factory()->create();
    hierarchySubject($resource);
    hierarchySubject($resource, 'Branch');
    $proposal = hierarchyProposal($resource);
    $branch = AssistantSuggestion::where('metadata->broader_id', 'https://example.org/branch')->sole();
    $oldInput = hierarchyInput($branch);
    expect(AssistantSuggestion::count())->toBe(2);
    app(Assistant::class)->acceptSuggestion($proposal->id, hierarchyInput($proposal));
    expect(AssistantSuggestion::count())->toBe(1)->and(discoverHierarchy())->toBe(0)
        ->and($branch->fresh()->metadata['existing_leaf_ids'])->toBe(['https://example.org/alpha'])
        ->and(app(Assistant::class)->acceptSuggestion($branch->id, $oldInput)['success'])->toBeFalse();
});

it('requires a reason and suppresses only the unchanged semantic case', function (): void {
    $resource = Resource::factory()->create();
    $root = hierarchySubject($resource);
    $proposal = hierarchyProposal($resource);
    $user = User::factory()->admin()->create();
    expect(fn () => app(Assistant::class)->declineSuggestion($proposal->id, $user, '  '))->toThrow(ValidationException::class);
    app(Assistant::class)->declineSuggestion($proposal->id, $user, '  The broader scope describes the resource.  ');
    expect(AssistantDismissed::sole()->reason)->toBe('The broader scope describes the resource.')
        ->and($resource->subjects()->count())->toBe(1)->and(discoverHierarchy())->toBe(0);
    $root->touch();
    hierarchySubject($resource, 'Other');
    publishHierarchy();
    expect(discoverHierarchy())->toBe(0);
    hierarchySubject($resource, 'Beta');
    expect(discoverHierarchy())->toBe(1)->and(AssistantDismissed::count())->toBe(1);
});

it('reports unavailable sources and preserves pending suggestions until the source is restored', function (): void {
    $resource = Resource::factory()->create();
    hierarchySubject($resource);
    $proposal = hierarchyProposal($resource);
    Storage::delete('subject-hierarchies/gcmd-platforms.json');
    $assistant = app(Assistant::class);
    expect($assistant->runDiscovery(static function (string $message): void {}))->toBe(0)
        ->and($assistant->discoveryDetails()['unavailable_vocabularies'])->toContain('Platforms: The local hierarchy is missing')
        ->and(AssistantSuggestion::whereKey($proposal->id)->exists())->toBeTrue();
    publishHierarchy();
    hierarchySubject($resource, 'Alpha');
    hierarchySubject($resource, 'Beta');
    expect($assistant->runDiscovery(static function (string $message): void {}))->toBe(0)->and(AssistantSuggestion::count())->toBe(0)
        ->and($assistant->discoveryDetails()['stale_suggestions_removed'])->toBe(1);
});

it('reconciles proposals after the last controlled subject was removed', function (): void {
    $resource = Resource::factory()->create();
    $subject = hierarchySubject($resource);
    hierarchyProposal($resource);
    $subject->delete();
    expect(discoverHierarchy())->toBe(0)->and(AssistantSuggestion::count())->toBe(0);
});

it('does not correct ambiguous or contradictory controlled subjects', function (array $attributes): void {
    $resource = Resource::factory()->create();
    hierarchySubject($resource, attributes: $attributes);
    expect(discoverHierarchy())->toBe(0);
})->with([
    'unknown uri' => [['value_uri' => 'https://example.org/missing']],
    'label conflicts with uri' => [['value' => 'Alpha']],
    'notation conflicts with label' => [['classification_code' => 'A']],
    'unsupported directory' => [['subject_scheme' => 'MSL Laboratories']],
    'free text' => [['subject_scheme' => null]],
]);

it('presents navigation groups as editor hints without selectable descendants', function (): void {
    publishHierarchy([...hierarchyConcepts(), ['id' => 'https://example.org/group', 'text' => 'Group', 'broaderIds' => [], 'selectable' => false]]);
    $resource = Resource::factory()->create();
    hierarchySubject($resource, 'Group');
    expect(discoverHierarchy())->toBe(1);
    $proposal = AssistantSuggestion::sole();
    expect(app(Assistant::class)->getSuggestionForReview($proposal->id)['review']['can_accept'])->toBeFalse()
        ->and($proposal->metadata['suggestion_kind'])->toBe('hint')
        ->and(app(Assistant::class)->acceptSuggestion($proposal->id, hierarchyInput($proposal))['success'])->toBeFalse();
});

it('allows admin and group leader acceptance through the validated single route', function (string $role): void {
    $resource = Resource::factory()->create();
    hierarchySubject($resource);
    $proposal = hierarchyProposal($resource);
    $this->actingAs(User::factory()->create(['role' => $role]))
        ->postJson('/assistance/subject-hierarchy-correction/'.$proposal->id.'/accept', hierarchyInput($proposal))
        ->assertOk()->assertJsonPath('success', true);
    expect($resource->subjects()->sole()->value)->toBe('Alpha');
})->with(['admin', 'group_leader']);

it('prevents curator and beginner access to hierarchy actions', function (string $role): void {
    $resource = Resource::factory()->create();
    hierarchySubject($resource);
    $proposal = hierarchyProposal($resource);
    $this->actingAs(User::factory()->create(['role' => $role]))
        ->postJson('/assistance/subject-hierarchy-correction/'.$proposal->id.'/accept', hierarchyInput($proposal))->assertForbidden();
    expect($resource->subjects()->count())->toBe(1);
})->with(['curator', 'beginner']);

it('validates duplicate selections and required decline reasons through HTTP', function (): void {
    $resource = Resource::factory()->create();
    hierarchySubject($resource);
    $proposal = hierarchyProposal($resource);
    $this->actingAs(User::factory()->admin()->create())
        ->postJson('/assistance/subject-hierarchy-correction/'.$proposal->id.'/accept', hierarchyInput($proposal, ['https://example.org/alpha', 'https://example.org/alpha']))
        ->assertUnprocessable()->assertJsonValidationErrors('selected_leaf_ids.0');
    $this->postJson('/assistance/subject-hierarchy-correction/'.$proposal->id.'/decline', [])
        ->assertUnprocessable()->assertJsonValidationErrors('reason');
});

it('transports the selected leaves through a batch and synchronizes once after persistence', function (): void {
    $resource = Resource::factory()->withDoi('10.5880/hierarchy')->create();
    hierarchySubject($resource);
    $proposal = hierarchyProposal($resource);
    $this->mock(DataCiteSyncService::class)->shouldReceive('syncIfRegistered')->once()->withArgs(function (Resource $fresh) use ($resource): bool {
        return $fresh->id === $resource->id && $fresh->subjects()->sole()->value === 'Alpha';
    })->andReturn(DataCiteSyncResult::succeeded($resource->doi));
    $this->actingAs(User::factory()->admin()->create())->postJson('/assistance/suggestions/batch/accept', [
        'resource_id' => $resource->id,
        'suggestions' => [['assistant_id' => 'subject-hierarchy-correction', 'suggestion_id' => $proposal->id, ...hierarchyInput($proposal)]],
    ])->assertOk()->assertJsonPath('success_count', 1)->assertJsonPath('synced_dois.0', $resource->doi);
});

it('rejects missing batch input, reasons and mismatched resources before mutation', function (string $variant): void {
    $resource = Resource::factory()->create();
    hierarchySubject($resource);
    $proposal = hierarchyProposal($resource);
    $this->actingAs(User::factory()->admin()->create())->postJson('/assistance/suggestions/batch/'.($variant === 'reason' ? 'decline' : 'accept'), [
        'resource_id' => $variant === 'resource' ? Resource::factory()->create()->id : $resource->id,
        'suggestions' => [['assistant_id' => 'subject-hierarchy-correction', 'suggestion_id' => $proposal->id,
            ...($variant === 'input' ? [] : hierarchyInput($proposal))]],
    ])->assertUnprocessable();
    expect($resource->subjects()->count())->toBe(1)->and(AssistantSuggestion::whereKey($proposal->id)->exists())->toBeTrue();
})->with(['input', 'reason', 'resource']);

it('records a mixed batch decline reason only for hierarchy suggestions', function (bool $hierarchyFirst): void {
    $resource = Resource::factory()->create();
    hierarchySubject($resource);
    $hierarchy = hierarchyProposal($resource);
    $title = Title::factory()->for($resource)->create(['language' => null]);
    $other = AssistantSuggestion::create([
        'assistant_id' => 'title-language-suggestion', 'resource_id' => $resource->id,
        'target_type' => 'title', 'target_id' => $title->id, 'suggested_value' => 'en',
        'suggested_label' => 'English', 'metadata' => [], 'discovered_at' => now(),
    ]);
    $items = array_map(static fn (AssistantSuggestion $proposal): array => [
        'assistant_id' => $proposal->assistant_id, 'suggestion_id' => $proposal->id,
    ], $hierarchyFirst ? [$hierarchy, $other] : [$other, $hierarchy]);
    $this->actingAs(User::factory()->admin()->create())->postJson('/assistance/suggestions/batch/decline', [
        'resource_id' => $resource->id, 'suggestions' => $items, 'reason' => 'The broader scope describes the resource.',
    ])->assertOk()->assertJsonPath('success_count', 2);
    expect(AssistantDismissed::where('assistant_id', 'subject-hierarchy-correction')->sole()->reason)
        ->toBe('The broader scope describes the resource.')
        ->and(AssistantDismissed::where('assistant_id', 'title-language-suggestion')->sole()->reason)->toBeNull()
        ->and(AssistantSuggestion::count())->toBe(0)
        ->and($resource->subjects()->sole()->value)->toBe('Root')
        ->and($title->fresh()->language)->toBeNull();
})->with([true, false]);

it('keeps local changes when DataCite fails and exposes the existing retry action', function (): void {
    $resource = Resource::factory()->withDoi('10.5880/hierarchy-failure')->create();
    hierarchySubject($resource);
    $proposal = hierarchyProposal($resource);
    $this->mock(DataCiteSyncService::class)->shouldReceive('syncIfRegistered')->once()->andReturn(DataCiteSyncResult::failed($resource->doi, 'Unavailable'));
    $result = app(Assistant::class)->acceptSuggestion($proposal->id, hierarchyInput($proposal));
    expect($result['success'])->toBeTrue()->and($result['datacite_sync']['success'])->toBeFalse()
        ->and($result['datacite_sync_retry_url'])->toContain('/assistance/resources/'.$resource->id.'/retry-datacite-sync')
        ->and($resource->subjects()->sole()->value)->toBe('Alpha');
});

it('scans in resource chunks without one subjects query per resource', function (): void {
    foreach (Resource::factory()->count(105)->create() as $resource) {
        hierarchySubject($resource);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    $assistant = app(Assistant::class);
    expect($assistant->runDiscovery(static function (string $message): void {}))->toBe(105);
    $subjectReads = collect(DB::getQueryLog())->filter(fn (array $query): bool => preg_match('/select \* from ["`]subjects["`] where ["`]subjects["`]\.["`]resource_id["`] in/', $query['query']) === 1);
    DB::disableQueryLog();
    expect($subjectReads)->toHaveCount(2)->and($assistant->discoveryDetails()['checked_resources'])->toBe(105);
});

it('preserves SubjectObserver invalidation for additions and broader removals', function (): void {
    $resource = Resource::factory()->create();
    hierarchySubject($resource);
    $proposal = hierarchyProposal($resource);
    $this->mock(PortalCacheInvalidationService::class)->shouldReceive('scheduleForResourceId')->twice()
        ->with($resource->id, Mockery::on(fn (array $areas): bool => in_array(PortalCacheArea::KEYWORDS, $areas, true)));
    app(Assistant::class)->acceptSuggestion($proposal->id, hierarchyInput($proposal));
});

it('isolates scheme identities in a batch and synchronizes a shared resource once', function (): void {
    publishHierarchy(scheme: 'Instruments');
    $resource = Resource::factory()->create();
    hierarchySubject($resource);
    hierarchySubject($resource, attributes: ['subject_scheme' => 'GCMD Instruments']);
    discoverHierarchy();
    $proposals = AssistantSuggestion::all();
    $this->mock(DataCiteSyncService::class)->shouldReceive('syncIfRegistered')->once()->andReturn(DataCiteSyncResult::notRequired());
    $this->actingAs(User::factory()->admin()->create())->postJson('/assistance/suggestions/batch/accept', [
        'resource_id' => $resource->id,
        'suggestions' => $proposals->map(fn (AssistantSuggestion $proposal): array => ['assistant_id' => 'subject-hierarchy-correction',
            'suggestion_id' => $proposal->id, ...hierarchyInput($proposal)])->all(),
    ])->assertOk()->assertJsonPath('success_count', 2);
    expect($resource->subjects()->count())->toBe(2)
        ->and($resource->subjects()->pluck('subject_scheme')->sort()->values()->all())->toBe(['GCMD Instruments', 'GCMD Platforms']);
});

it('rejects two overlapping corrections in one batch before applying either', function (): void {
    $resource = Resource::factory()->create();
    hierarchySubject($resource);
    hierarchySubject($resource, 'Branch');
    discoverHierarchy();
    $this->actingAs(User::factory()->admin()->create())->postJson('/assistance/suggestions/batch/accept', [
        'resource_id' => $resource->id,
        'suggestions' => AssistantSuggestion::all()->map(fn (AssistantSuggestion $proposal): array => [
            'assistant_id' => 'subject-hierarchy-correction', 'suggestion_id' => $proposal->id, ...hierarchyInput($proposal),
        ])->all(),
    ])->assertUnprocessable();
    expect($resource->subjects()->count())->toBe(2)->and(AssistantSuggestion::count())->toBe(2);
});

it('serializes competing MySQL writers on the resource before creating any subjects', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Run the dedicated MySQL 9.7 hierarchy slice.');
    }
    $resource = Resource::factory()->create();
    hierarchySubject($resource);
    $proposal = hierarchyProposal($resource);
    // Commit fixture creation so the competing connection observes the service's lock,
    // not a lock retained by RefreshDatabase's fixture inserts.
    DB::commit();
    DB::beginTransaction();
    config(['database.connections.hierarchy_contender' => config('database.connections.mysql')]);
    $contender = DB::connection('hierarchy_contender');
    $contender->statement('SET SESSION innodb_lock_wait_timeout = 1');
    $observed = false;
    Event::listen('eloquent.creating: '.Subject::class, function (Subject $subject) use ($resource, $contender, &$observed): void {
        if ($subject->value !== 'Alpha') {
            return;
        }
        $contender->beginTransaction();
        try {
            $contender->table('resources')->where('id', $resource->id)->lockForUpdate()->first();
            throw new RuntimeException('The resource lock did not serialize the competing writer.');
        } catch (QueryException $exception) {
            expect($exception->errorInfo[1])->toBe(1205);
            $observed = true;
        } finally {
            $contender->rollBack();
        }
    });
    try {
        expect(app(Assistant::class)->acceptSuggestion($proposal->id, hierarchyInput($proposal))['success'])->toBeTrue()
            ->and($observed)->toBeTrue()->and($resource->subjects()->count())->toBe(1);
        DB::commit();
        expect($contender->table('resources')->where('id', $resource->id)->first())->not->toBeNull();
    } finally {
        if (DB::transactionLevel() === 0) {
            DB::beginTransaction();
        }
        DB::disconnect('hierarchy_contender');
    }
});
