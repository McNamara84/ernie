<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AssistantSuggestion;
use App\Models\Resource;
use App\Models\Subject;
use App\Models\User;
use App\Services\SubjectEnrichment\SubjectEnrichmentDiscoveryService;
use App\Services\SubjectEnrichment\SubjectEnrichmentMatcher;
use App\Services\SubjectEnrichment\SubjectEnrichmentMatchInputProvider;
use App\Services\SubjectEnrichment\SubjectVocabularyLookupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(RefreshDatabase::class)->group('assistant', 'browser', 'subject-metadata-enrichment');

beforeEach(function (): void {
    app(Vite::class)
        ->useHotFile(storage_path('framework/testing-vite.hot'))
        ->useBuildDirectory('build');

    Storage::fake('local');
});

/**
 * @return array<string, mixed>
 */
function subjectBrowserMslData(): array
{
    return [
        'lastUpdated' => '2026-07-04T00:00:00Z',
        'data' => [
            [
                'id' => 'https://epos-msl.uu.nl/voc/multi-scale-laboratories',
                'text' => 'multi-scale laboratories',
                'language' => 'en',
                'scheme' => 'EPOS MSL vocabulary',
                'schemeURI' => 'https://epos-msl.uu.nl/voc',
                'children' => [],
            ],
        ],
    ];
}

function subjectBrowserDiscoveryService(): SubjectEnrichmentDiscoveryService
{
    $lookup = new SubjectVocabularyLookupService;

    return new SubjectEnrichmentDiscoveryService(
        inputProvider: new SubjectEnrichmentMatchInputProvider,
        matcher: new SubjectEnrichmentMatcher($lookup),
    );
}

function subjectBrowserSuggestionFor(Subject $subject): AssistantSuggestion
{
    $storedSuggestions = [];

    subjectBrowserDiscoveryService()->discover(
        storeSuggestion: function (
            int $resourceId,
            string $targetType,
            int $targetId,
            string $suggestedValue,
            string $suggestedLabel,
            ?float $similarityScore,
            ?array $metadata,
        ) use (&$storedSuggestions): bool {
            $storedSuggestions[] = compact(
                'resourceId',
                'targetType',
                'targetId',
                'suggestedValue',
                'suggestedLabel',
                'similarityScore',
                'metadata',
            );

            return true;
        },
        onProgress: function (string $message): void {},
    );

    $stored = collect($storedSuggestions)
        ->first(fn (array $candidate): bool => $candidate['targetId'] === $subject->id);

    if (! is_array($stored)) {
        throw new RuntimeException('Expected a browser subject enrichment suggestion.');
    }

    return AssistantSuggestion::create([
        'assistant_id' => SubjectEnrichmentDiscoveryService::ASSISTANT_ID,
        'resource_id' => $stored['resourceId'],
        'target_type' => $stored['targetType'],
        'target_id' => $stored['targetId'],
        'suggested_value' => $stored['suggestedValue'],
        'suggested_label' => $stored['suggestedLabel'],
        'similarity_score' => $stored['similarityScore'],
        'metadata' => $stored['metadata'],
        'discovered_at' => now(),
    ]);
}

it('reviews and accepts a subject metadata enrichment suggestion from assistance', function (): void {
    /** @var TestCase $this */
    Storage::disk('local')->put('msl-vocabulary.json', json_encode(subjectBrowserMslData(), JSON_THROW_ON_ERROR));

    $admin = User::factory()->create([
        'role' => UserRole::ADMIN,
    ]);

    $resource = Resource::factory()->withDoi('10.5880/browser.subject-enrichment')->create();
    $subject = Subject::forceCreate([
        'resource_id' => $resource->id,
        'value' => 'multi-scale laboratories',
        'language' => 'en',
        'subject_scheme' => null,
        'scheme_uri' => null,
        'value_uri' => null,
        'classification_code' => null,
        'breadcrumb_path' => null,
    ]);

    $suggestion = subjectBrowserSuggestionFor($subject);

    $this->actingAs($admin);

    visit('/assistance')
        ->assertNoSmoke()
        ->assertSee('Subject Metadata Enrichment')
        ->assertSee('Current Subject metadata')
        ->assertSee('Will update DataCite Subject fields')
        ->assertSee('multi-scale laboratories')
        ->assertSee('EPOS MSL vocabulary')
        ->assertSee('Preserved fields: value, resource_id.')
        ->assertSee('This Free Keyword could be transferred into a Thesaurus Keyword if you accept this suggestion.')
        ->click("[data-testid=\"subject-metadata-enrichment-accept-{$suggestion->id}\"]")
        ->assertSee('Subject metadata enrichment applied.');

    $subject->refresh();

    expect($subject->value)->toBe('multi-scale laboratories')
        ->and($subject->subject_scheme)->toBe('EPOS MSL vocabulary')
        ->and($subject->scheme_uri)->toBe('https://epos-msl.uu.nl/voc')
        ->and($subject->value_uri)->toBe('https://epos-msl.uu.nl/voc/multi-scale-laboratories')
        ->and($subject->breadcrumb_path)->toBe('multi-scale laboratories')
        ->and(AssistantSuggestion::find($suggestion->id))->toBeNull();
});
