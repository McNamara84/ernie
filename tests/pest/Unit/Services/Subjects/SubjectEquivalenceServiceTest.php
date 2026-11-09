<?php

declare(strict_types=1);

use App\Models\Subject;
use App\Services\Subjects\SubjectEquivalenceService;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SubjectDuplicateFixtures as F;

covers(SubjectEquivalenceService::class);

beforeEach(fn () => F::fake());

it('keeps duplicate fixtures on the local fake when another disk is the default', function (): void {
    Storage::fake('subject-fixture-default');
    config(['filesystems.default' => 'subject-fixture-default']);

    F::fake();

    expect(config('filesystems.default'))->toBe('subject-fixture-default')
        ->and(Storage::disk('subject-fixture-default')->allFiles())->toBeEmpty();
    Storage::disk('local')->assertExists(['gcmd-science-keywords.json', 'gcmd-instruments.json']);
    $service = new SubjectEquivalenceService;
    expect($service->analyze(F::rows())['rows'])->toHaveCount(1)
        ->and($service->analyze(F::rows('instruments'))['rows'])->toHaveCount(1);
});

it('merges both reported cases using local evidence and remains idempotent', function (string $case): void {
    $service = new SubjectEquivalenceService;
    $rows = F::rows($case);
    $result = $service->analyze($rows);
    expect($result['rows'])->toHaveCount(1)
        ->and($result['groups'])->toHaveCount(1)
        ->and($result['groups'][0]['reason'])->toBe('local_vocabulary')
        ->and($result['rows'][0]['value_uri'])->toBe($case === 'aquifers' ? F::AQUIFER : F::INSTRUMENT)
        ->and($service->analyze($result['rows'])['rows'])->toBe($result['rows'])
        ->and($service->analyze(array_reverse($rows))['rows'])->toBe($result['rows']);
})->with(['aquifers', 'instruments']);

it('preserves each incompatible field rather than merging by URI or badge text', function (string $field, mixed $value): void {
    $rows = F::rows();
    $rows[1][$field] = $value;
    expect((new SubjectEquivalenceService)->analyze($rows)['rows'])->toBe($rows);
})->with([
    'language' => ['language', 'de'], 'unknown language' => ['language', null],
    'concept' => ['value_uri', F::INSTRUMENT], 'foreign UUID' => ['value_uri', str_replace('gcmd.earthdata.nasa.gov', 'example.org', F::AQUIFER)],
    'scheme' => ['subject_scheme', 'Custom vocabulary'], 'scheme URI' => ['scheme_uri', 'https://example.org/schema'],
    'classification' => ['classification_code', 'conflict'], 'path' => ['breadcrumb_path', 'EARTH SCIENCE > UNRELATED > AQUIFERS'],
    'text' => ['value', 'Unrelated concept'],
]);

it('keeps unresolved pairs when the vocabulary is absent malformed or ambiguous', function (string $state): void {
    if ($state === 'absent') {
        Storage::disk('local')->delete('gcmd-instruments.json');
    } elseif ($state === 'malformed') {
        Storage::disk('local')->put('gcmd-instruments.json', '{invalid');
    } else {
        $data = json_decode(Storage::disk('local')->get('gcmd-instruments.json'), true);
        $other = $data['data'][0];
        $other['id'] = F::AQUIFER;
        $data['data'][] = $other;
        Storage::disk('local')->put('gcmd-instruments.json', json_encode($data));
    }
    $result = (new SubjectEquivalenceService)->analyze(F::rows('instruments'));
    expect($result['rows'])->toHaveCount(2)->and($result['diagnostics'])->toHaveCount(1);
})->with(['absent', 'malformed', 'ambiguous']);

it('does not bridge conflicting partial identities and preserves multilingual and free keywords', function (): void {
    $rows = F::rows('instruments');
    $conflict = array_replace($rows[1], ['value_uri' => F::AQUIFER]);
    $german = array_replace($rows[1], ['language' => 'de']);
    $free = array_replace($rows[0], ['subject_scheme' => null, 'scheme_uri' => null, 'breadcrumb_path' => null]);
    $result = (new SubjectEquivalenceService)->analyze([...$rows, $conflict, $german, $free]);
    expect($result['rows'])->toHaveCount(4)->and($result['rows'])->toContain($conflict, $german, $free);
});

it('preserves exact metadata and the oldest id even without a vocabulary', function (): void {
    Storage::disk('local')->delete('gcmd-science-keywords.json');
    $row = F::rows()[0];
    $rows = [['id' => 8, ...$row], ['id' => 3, ...$row]];
    $result = (new SubjectEquivalenceService)->analyze($rows);
    expect($result['rows'])->toBe([$rows[1]])->and($result['groups'][0]['reason'])->toBe('exact');
});

it('keeps an isolated subject unchanged and preserves list position for merged groups', function (): void {
    $rows = F::rows();
    $free = ['value' => 'heat storage', 'subject_scheme' => null];
    $service = new SubjectEquivalenceService;
    expect($service->analyze([$rows[0]])['rows'])->toBe([$rows[0]])
        ->and($service->analyze([$rows[0], $free, $rows[1]])['rows'][1])->toBe($free);
});

it('returns detached display models without mutating the stored models', function (): void {
    $subjects = collect(F::rows('instruments'))->map(fn (array $row) => new Subject($row));
    $original = $subjects->map->getAttributes()->all();
    $display = (new SubjectEquivalenceService)->forDisplay($subjects);
    expect($display)->toHaveCount(1)->and($display->first()->value_uri)->toBe(F::INSTRUMENT)
        ->and($subjects->map->getAttributes()->all())->toBe($original);
});

it('canonicalizes supported historical GCMD URIs but never arbitrary UUID suffixes', function (): void {
    $rows = F::rows();
    $rows[0]['value_uri'] = 'http://gcmdservices.gsfc.nasa.gov/kms/concepts/concept_scheme/sciencekeywords/a957363b-2f2c-4169-a656-c2f24933eb72';
    expect((new SubjectEquivalenceService)->analyze($rows)['rows'])->toHaveCount(1);
});

it('does not reuse stale vocabulary indexes between calls', function (): void {
    $service = new SubjectEquivalenceService;
    expect($service->analyze(F::rows())['rows'])->toHaveCount(1);
    Storage::disk('local')->delete('gcmd-science-keywords.json');
    expect($service->analyze(F::rows())['rows'])->toHaveCount(2);
});
