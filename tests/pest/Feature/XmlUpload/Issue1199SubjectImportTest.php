<?php

declare(strict_types=1);

use App\Models\Resource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

it('imports one GEMET and one MSL subject exactly once from XML', function (): void {
    $this->actingAs(User::factory()->create());
    $fixture = base_path('tests/pest/dataset-examples/issue-1199-subjects.xml');
    $file = UploadedFile::fake()->createWithContent('issue-1199.xml', (string) file_get_contents($fixture));

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])->assertOk();
    $resource = Resource::query()->findOrFail((int) $response->json('resourceId'));
    $sessionData = getXmlUploadData($response);

    expect($sessionData['controlledKeywords'])->toHaveCount(2)
        ->and($sessionData['gcmdKeywords'])->toHaveCount(1)
        ->and($sessionData['mslKeywords'])->toHaveCount(1)
        ->and($sessionData['gemetKeywords'])->toHaveCount(1)
        ->and($resource->subjects)->toHaveCount(2)
        ->and($resource->subjects->where('subject_scheme', 'GEMET - GEneral Multilingual Environmental Thesaurus'))->toHaveCount(1)
        ->and($resource->subjects->where('subject_scheme', 'EPOS MSL vocabulary'))->toHaveCount(1)
        ->and($resource->subjects->where('value', 'geophysics')->first()?->language)->toBe('de')
        ->and($resource->subjects->where('value', 'Mineralogy')->first()?->breadcrumb_path)->toBe('Materials > Mineralogy');

    loadExistingResourceInEditor($this, $resource->id)->assertOk();
});

it('normalizes equivalent XML and DataCite JSON subjects identically', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    $fixture = base_path('tests/pest/dataset-examples/issue-1199-subjects.xml');

    $xmlResponse = $this->postJson('/dashboard/upload-xml', [
        'file' => UploadedFile::fake()->createWithContent('issue-1199.xml', (string) file_get_contents($fixture)),
    ])->assertOk();

    $json = dataCiteJson(minimalAttributes([
        'subjects' => [
            [
                'subject' => 'geophysics',
                'subjectScheme' => 'GEMET - GEneral Multilingual Environmental Thesaurus',
                'schemeUri' => 'http://www.eionet.europa.eu/gemet/concept/',
                'valueUri' => 'http://www.eionet.europa.eu/gemet/concept/3650',
                'lang' => 'de',
            ],
            [
                'subject' => 'Materials > Mineralogy',
                'subjectScheme' => 'EPOS MSL vocabulary',
                'schemeUri' => 'https://epos-msl.uu.nl/voc',
                'valueUri' => 'https://epos-msl.uu.nl/voc/materials/mineralogy',
                'lang' => 'en',
            ],
        ],
    ]));
    $jsonResponse = $this->postJson('/dashboard/upload-json', [
        'file' => UploadedFile::fake()->createWithContent('issue-1199.json', $json),
    ])->assertOk();

    $subjectFields = static fn (Resource $resource): array => $resource->subjects()
        ->orderBy('subject_scheme')
        ->get([
            'value',
            'language',
            'subject_scheme',
            'scheme_uri',
            'value_uri',
            'classification_code',
            'breadcrumb_path',
        ])
        ->map(static fn ($subject): array => $subject->attributesToArray())
        ->all();

    $xmlResource = Resource::query()->findOrFail((int) $xmlResponse->json('resourceId'));
    $jsonResource = Resource::query()->findOrFail((int) $jsonResponse->json('resourceId'));

    expect($subjectFields($xmlResource))->toBe($subjectFields($jsonResource));
});
