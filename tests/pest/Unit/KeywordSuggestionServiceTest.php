<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Subject;
use App\Services\KeywordSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

covers(KeywordSuggestionService::class);

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    $this->service = app(KeywordSuggestionService::class);

    $this->datasetType = ResourceType::factory()->create([
        'name' => 'Dataset',
        'slug' => 'dataset',
    ]);
});

/**
 * Create a published resource with subjects for testing.
 *
 * @param  array<int, array{value: string, subject_scheme?: string|null}>  $subjects
 */
function createResourceWithSubjects(ResourceType $type, array $subjects): Resource
{
    $resource = Resource::factory()->create([
        'resource_type_id' => $type->id,
    ]);

    LandingPage::factory()->published()->create([
        'resource_id' => $resource->id,
    ]);

    foreach ($subjects as $subjectData) {
        Subject::factory()->create([
            'resource_id' => $resource->id,
            'value' => $subjectData['value'],
            'subject_scheme' => $subjectData['subject_scheme'] ?? null,
        ]);
    }

    return $resource;
}

it('returns empty array when no published resources exist', function () {
    $suggestions = $this->service->getSuggestions();

    expect($suggestions)->toBeEmpty();
});

it('returns keywords from published resources', function () {
    createResourceWithSubjects($this->datasetType, [
        ['value' => 'Seismology'],
        ['value' => 'GNSS', 'subject_scheme' => 'Science Keywords'],
    ]);

    $suggestions = $this->service->getSuggestions();

    expect($suggestions)->toHaveCount(2);
    expect(array_column($suggestions, 'value'))->toContain('Seismology', 'GNSS');
});

it('excludes keywords from unpublished resources', function () {
    // Published resource
    createResourceWithSubjects($this->datasetType, [
        ['value' => 'PublishedKeyword'],
    ]);

    // Unpublished resource
    $draftResource = Resource::factory()->create([
        'resource_type_id' => $this->datasetType->id,
    ]);
    LandingPage::factory()->draft()->create([
        'resource_id' => $draftResource->id,
    ]);
    Subject::factory()->create([
        'resource_id' => $draftResource->id,
        'value' => 'DraftKeyword',
    ]);

    $suggestions = $this->service->getSuggestions();

    expect($suggestions)->toHaveCount(1);
    expect($suggestions[0]['value'])->toBe('PublishedKeyword');
});

it('deduplicates keywords and counts usage', function () {
    // Same keyword on two different published resources
    createResourceWithSubjects($this->datasetType, [
        ['value' => 'Seismology'],
    ]);

    createResourceWithSubjects($this->datasetType, [
        ['value' => 'Seismology'],
    ]);

    $suggestions = $this->service->getSuggestions();

    expect($suggestions)->toHaveCount(1);
    expect($suggestions[0]['value'])->toBe('Seismology');
    expect($suggestions[0]['count'])->toBe(2);
});

it('includes subject scheme in suggestions', function () {
    createResourceWithSubjects($this->datasetType, [
        ['value' => 'Free Keyword'],
        ['value' => 'GNSS', 'subject_scheme' => 'Science Keywords'],
        ['value' => 'Geochemistry', 'subject_scheme' => 'EPOS MSL vocabulary'],
    ]);

    $suggestions = $this->service->getSuggestions();

    expect($suggestions)->toHaveCount(3);

    $freeKeyword = collect($suggestions)->firstWhere('value', 'Free Keyword');
    expect($freeKeyword['scheme'])->toBeNull();

    $gcmdKeyword = collect($suggestions)->firstWhere('value', 'GNSS');
    expect($gcmdKeyword['scheme'])->toBe('Science Keywords');

    $mslKeyword = collect($suggestions)->firstWhere('value', 'Geochemistry');
    expect($mslKeyword['scheme'])->toBe('EPOS MSL vocabulary');
});

it('sorts suggestions alphabetically by value', function () {
    createResourceWithSubjects($this->datasetType, [
        ['value' => 'Zircon'],
        ['value' => 'Alpine'],
        ['value' => 'Magnetism'],
    ]);

    $suggestions = $this->service->getSuggestions();

    expect($suggestions[0]['value'])->toBe('Alpine');
    expect($suggestions[1]['value'])->toBe('Magnetism');
    expect($suggestions[2]['value'])->toBe('Zircon');
});

it('distinguishes same keyword with different schemes', function () {
    createResourceWithSubjects($this->datasetType, [
        ['value' => 'Geochemistry', 'subject_scheme' => null],
        ['value' => 'Geochemistry', 'subject_scheme' => 'EPOS MSL vocabulary'],
    ]);

    $suggestions = $this->service->getSuggestions();

    // Same keyword value with different schemes = two separate suggestions
    expect($suggestions)->toHaveCount(2);
});
