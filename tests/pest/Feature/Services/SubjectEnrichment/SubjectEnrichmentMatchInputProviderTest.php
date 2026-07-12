<?php

declare(strict_types=1);

use App\Models\Resource;
use App\Models\Subject;
use App\Services\SubjectEnrichment\SubjectEnrichmentMatchInput;
use App\Services\SubjectEnrichment\SubjectEnrichmentMatchInputProvider;

/**
 * @param  array<string, mixed>  $attributes
 */
function subjectEnrichmentCreateSubject(Resource $resource, array $attributes): Subject
{
    return Subject::forceCreate(array_replace([
        'resource_id' => $resource->id,
        'value' => 'EPOS',
        'language' => 'en',
        'subject_scheme' => null,
        'scheme_uri' => null,
        'value_uri' => null,
        'classification_code' => null,
        'breadcrumb_path' => null,
    ], $attributes));
}

it('reads controlled and free subject rows as enrichment inputs and skips empty values', function (): void {
    $resource = Resource::factory()->withDoi('10.5880/GFZ.2026.813')->create();

    $controlled = subjectEnrichmentCreateSubject($resource, [
        'value' => 'Science Keywords > EARTH SCIENCE > ATMOSPHERE > AEROSOLS > PARTICULATE MATTER',
        'subject_scheme' => 'NASA/GCMD Earth Science Keywords',
        'language' => 'de',
    ]);
    $free = subjectEnrichmentCreateSubject($resource, [
        'value' => 'multi-scale laboratories',
    ]);
    subjectEnrichmentCreateSubject($resource, [
        'value' => '',
    ]);

    $inputs = (new SubjectEnrichmentMatchInputProvider)->pendingInputs();

    expect($inputs)->toHaveCount(2)
        ->and($inputs->pluck('targetId')->all())->toEqualCanonicalizing([$controlled->id, $free->id]);

    $controlledInput = $inputs->first(fn (object $input): bool => $input->targetId === $controlled->id);
    $freeInput = $inputs->first(fn (object $input): bool => $input->targetId === $free->id);

    if (! $controlledInput instanceof SubjectEnrichmentMatchInput || ! $freeInput instanceof SubjectEnrichmentMatchInput) {
        throw new RuntimeException('Expected controlled and free subject enrichment inputs.');
    }

    expect($controlledInput->isControlled)->toBeTrue()
        ->and($controlledInput->subjectScheme)->toBe('NASA/GCMD Earth Science Keywords')
        ->and($controlledInput->normalizedSubjectScheme)->toBe('Science Keywords')
        ->and($controlledInput->language)->toBe('de')
        ->and($freeInput->isControlled)->toBeFalse()
        ->and($freeInput->subjectScheme)->toBeNull()
        ->and($freeInput->language)->toBe('en');
});
