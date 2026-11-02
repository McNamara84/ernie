<?php

declare(strict_types=1);

use App\Models\ContributorType;
use App\Models\Person;
use App\Models\Resource;
use App\Models\User;
use App\Services\DataCiteJsonExporter;
use App\Services\DataCiteXmlExporter;
use App\Services\Editor\EditorDataTransformer;
use App\Services\ResourceStorageService;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => 'curator']));
    ContributorType::firstOrCreate(['slug' => 'ContactPerson'], ['name' => 'Contact Person', 'category' => 'person']);
});

function issue1496File(string $format): UploadedFile
{
    $contents = $format === 'xml'
        ? file_get_contents(base_path('tests/pest/dataset-examples/issue-1496-contact-persons.xml'))
        : dataCiteJson(minimalAttributes([
            'creators' => [['name' => 'Author, Anna', 'nameType' => 'Personal', 'givenName' => 'Anna', 'familyName' => 'Author', 'affiliation' => ['Author Institute']]],
            'contributors' => [
                ['name' => 'Author, Anna', 'nameType' => 'Personal', 'givenName' => 'Anna', 'familyName' => 'Author', 'contributorType' => 'ContactPerson', 'affiliation' => ['Contact Institute']],
                ['name' => 'Contact, Chris', 'nameType' => 'Personal', 'givenName' => 'Chris', 'familyName' => 'Contact', 'contributorType' => 'ContactPerson', 'affiliation' => ['Contributor Institute']],
            ],
        ]));

    return UploadedFile::fake()->createWithContent('issue-1496.'.$format, $contents);
}

it('keeps standalone contacts out of authors in the editor preview', function (string $format) {
    $response = $this->postJson('/editor/upload-'.$format.'/preview', ['file' => issue1496File($format)])->assertOk();
    $data = $response->json('metadata');

    expect($data['authors'])->toHaveCount(1)
        ->and($data['authors'][0]['firstName'])->toBe('Anna')
        ->and($data['authors'][0]['isContact'])->toBeTrue()
        ->and(array_column($data['authors'][0]['affiliations'], 'value'))->toBe(['Author Institute', 'Contact Institute'])
        ->and($data['contributors'])->toHaveCount(1)
        ->and($data['contributors'][0]['firstName'])->toBe('Chris')
        ->and($data['contributors'][0]['roles'])->toBe(['Contact Person']);

    if ($format === 'xml') {
        expect($data['authors'][0]['email'])->toBe('anna@example.org')
            ->and($data['contributors'][0]['email'])->toBe('chris@example.org')
            ->and($data['contributors'][0]['website'])->toBe('https://example.org/chris');
    }
    expect(Resource::count())->toBe(0);
})->with(['xml', 'json']);

/** @param list<array<string, mixed>> $authors
 * @param  list<array<string, mixed>>  $contributors
 */
function issue1496PartiesFile(string $format, array $authors, array $contributors): UploadedFile
{
    $attributes = minimalAttributes(['creators' => $authors, 'contributors' => $contributors]);
    if ($format === 'json') {
        return UploadedFile::fake()->createWithContent('contacts.json', dataCiteJson($attributes));
    }
    $xml = new SimpleXMLElement('<resource xmlns="http://datacite.org/schema/kernel-4"/>');
    $xml->addChild('titles')->addChild('title', 'Contact regression');
    foreach (['creators' => 'creator', 'contributors' => 'contributor'] as $group => $element) {
        $parent = $xml->addChild($group);
        foreach ($attributes[$group] as $party) {
            $entry = $parent->addChild($element);
            if ($element === 'contributor') {
                $entry->addAttribute('contributorType', $party['contributorType']);
            }
            $entry->addChild($element.'Name', $party['name'])->addAttribute('nameType', $party['nameType'] ?? 'Personal');
            foreach (['givenName', 'familyName'] as $field) {
                if (isset($party[$field])) {
                    $entry->addChild($field, $party[$field]);
                }
            }
            foreach ($party['nameIdentifiers'] ?? [] as $identifier) {
                $entry->addChild('nameIdentifier', $identifier['nameIdentifier'])->addAttribute('nameIdentifierScheme', 'ORCID');
            }
            foreach ($party['affiliation'] ?? [] as $affiliation) {
                $affiliationElement = $entry->addChild('affiliation', $affiliation['name']);
                $affiliationElement->addAttribute('affiliationIdentifier', $affiliation['affiliationIdentifier']);
                $affiliationElement->addAttribute('affiliationIdentifierScheme', 'ROR');
            }
        }
    }

    return UploadedFile::fake()->createWithContent('contacts.xml', $xml->asXML());
}

it('keeps different institutional contributors sharing a parent ROR after upload save and reload', function (string $format) {
    $authors = [['name' => 'Author, Anna', 'givenName' => 'Anna', 'familyName' => 'Author']];
    $contributors = array_map(fn (string $name): array => [
        'name' => $name, 'nameType' => 'Organizational', 'contributorType' => 'ContactPerson',
        'affiliation' => [['name' => 'Parent Institute', 'affiliationIdentifier' => 'https://ror.org/04z8jg394', 'affiliationIdentifierScheme' => 'ROR']],
    ], ['Laboratory A', 'Laboratory B']);
    $preview = $this->postJson('/editor/upload-'.$format.'/preview', [
        'file' => issue1496PartiesFile($format, $authors, $contributors),
    ])->assertOk()->json('metadata');
    expect(array_column($preview['contributors'], 'institutionName'))->toBe(['Laboratory A', 'Laboratory B']);

    $response = $this->postJson('/dashboard/upload-'.$format, [
        'file' => issue1496PartiesFile($format, $authors, $contributors),
    ])->assertOk();
    $resource = Resource::findOrFail($response->json('resourceId'));
    for ($cycle = 0; $cycle < 3; $cycle++) {
        $editor = app(EditorDataTransformer::class)->transformResource($resource->fresh());
        expect(array_column($editor['contributors'], 'institutionName'))->toBe(['Laboratory A', 'Laboratory B']);
        foreach ($editor['contributors'] as $contributor) {
            expect($contributor['affiliations'][0]['rorId'])->toBe('https://ror.org/04z8jg394');
        }
        if ($cycle < 2) {
            $this->postJson('/editor/resources/draft', [
                'resourceId' => $resource->id, 'authors' => $editor['authors'], 'contributors' => $editor['contributors'],
                'titles' => [['title' => 'Institution contact regression', 'titleType' => 'MainTitle']],
            ])->assertOk();
        }
    }
})->with(['xml', 'json']);

it('preserves unresolved surname-only contacts through upload draft saves and editor reconstruction', function (string $format, bool $markAuthorAsContact) {
    $person = ['name' => 'Doe', 'familyName' => 'Doe', 'nameType' => 'Personal'];
    $contact = $person + ['contributorType' => 'ContactPerson'];
    $preview = $this->postJson('/editor/upload-'.$format.'/preview', [
        'file' => issue1496PartiesFile($format, [$person], [$contact]),
    ])->assertOk()->json('metadata');
    expect($preview['authors'][0]['isContact'] ?? false)->toBeFalse()
        ->and($preview['contributors'])->toHaveCount(1);

    $response = $this->postJson('/dashboard/upload-'.$format, [
        'file' => issue1496PartiesFile($format, [$person], [$contact]),
    ])->assertOk();
    $resource = Resource::findOrFail($response->json('resourceId'));
    $creatorPersonId = $resource->creators()->sole()->creatorable_id;
    $contributorPersonId = $resource->contributors()->sole()->contributorable_id;
    expect($contributorPersonId)->not->toBe($creatorPersonId);

    for ($cycle = 0; $cycle < 3; $cycle++) {
        $editor = app(EditorDataTransformer::class)->transformResource($resource->fresh());
        expect($editor['authors'])->toHaveCount(1)
            ->and($editor['authors'][0]['isContact'])->toBe($cycle > 0 && $markAuthorAsContact)
            ->and($editor['contributors'])->toHaveCount(1)
            ->and($editor['contributors'][0]['lastName'])->toBe('Doe')
            ->and($editor['contributors'][0]['firstName'])->toBe('')
            ->and($editor['contributors'][0]['roles'])->toBe(['Contact Person']);
        expect($resource->contributors()->where('contributorable_id', $contributorPersonId)->count())->toBe(1)
            ->and($resource->creators()->sole()->creatorable_id)->toBe($creatorPersonId)
            ->and(Person::count())->toBe(2);
        if ($cycle > 0) {
            expect($editor['contributors'][0]['email'])->toBe('standalone@example.org')
                ->and($editor['authors'][0]['email'] ?? null)->toBe($markAuthorAsContact ? 'author@example.org' : null)
                ->and($resource->contributors()->count())->toBe($markAuthorAsContact ? 2 : 1);
        }
        if ($cycle < 2) {
            $editor['authors'][0]['isContact'] = $markAuthorAsContact;
            $editor['authors'][0]['email'] = $markAuthorAsContact ? 'author@example.org' : '';
            $editor['contributors'][0]['email'] = 'standalone@example.org';
            $this->postJson('/editor/resources/draft', [
                'resourceId' => $resource->id, 'authors' => $editor['authors'], 'contributors' => $editor['contributors'],
                'titles' => [['title' => 'Surname contact regression', 'titleType' => 'MainTitle']],
            ])->assertOk();
        }
    }
})->with(['xml', 'json'])->with([false, true]);

it('keeps an unidentified contact separate from conflicting same-name authors after saving twice', function (string $format) {
    $person = ['name' => 'Shared, Morgan', 'givenName' => 'Morgan', 'familyName' => 'Shared', 'nameType' => 'Personal'];
    $authors = array_map(fn (string $orcid): array => $person + ['nameIdentifiers' => [
        ['nameIdentifier' => $orcid, 'nameIdentifierScheme' => 'ORCID'],
    ]], ['0000-0002-1825-0097', '0000-0002-1694-233X']);
    $response = $this->postJson('/dashboard/upload-'.$format, [
        'file' => issue1496PartiesFile($format, $authors, [$person + ['contributorType' => 'ContactPerson']]),
    ])->assertOk();
    $resource = Resource::findOrFail($response->json('resourceId'));
    for ($cycle = 0; $cycle < 2; $cycle++) {
        $editor = app(EditorDataTransformer::class)->transformResource($resource->fresh());
        expect($editor['authors'])->toHaveCount(2)
            ->and(array_column($editor['authors'], 'isContact'))->toBe([false, false])
            ->and($editor['contributors'])->toHaveCount(1)
            ->and($editor['contributors'][0]['orcid'])->toBe('');
        [$resource] = app(ResourceStorageService::class)->store([
            'resourceId' => $resource->id, 'authors' => $editor['authors'], 'contributors' => $editor['contributors'],
            'titles' => [['title' => 'Contact regression', 'titleType' => 'MainTitle']],
        ], auth()->id());
    }
})->with(['xml', 'json']);

it('preserves a contact with a conflicting ORCID and organizational contacts', function (string $format) {
    $person = ['name' => 'Shared, Morgan', 'givenName' => 'Morgan', 'familyName' => 'Shared', 'nameType' => 'Personal'];
    $author = $person + ['nameIdentifiers' => [['nameIdentifier' => '0000-0002-1825-0097', 'nameIdentifierScheme' => 'ORCID']]];
    $contact = $person + ['contributorType' => 'ContactPerson', 'nameIdentifiers' => [['nameIdentifier' => '0000-0002-1694-233X', 'nameIdentifierScheme' => 'ORCID']]];
    $institution = ['name' => 'Contact Institute', 'nameType' => 'Organizational', 'contributorType' => 'ContactPerson'];
    $response = $this->postJson('/dashboard/upload-'.$format, [
        'file' => issue1496PartiesFile($format, [$author], [$contact, $institution]),
    ])->assertOk();
    $resource = Resource::findOrFail($response->json('resourceId'));
    $editor = app(EditorDataTransformer::class)->transformResource($resource);
    expect($editor['authors'])->toHaveCount(1)
        ->and($editor['authors'][0]['isContact'])->toBeFalse()
        ->and($editor['contributors'])->toHaveCount(2)
        ->and($editor['contributors'][0]['roles'])->toBe(['Contact Person'])
        ->and($editor['contributors'][1]['type'])->toBe('institution')
        ->and($editor['contributors'][1]['institutionName'])->toBe('Contact Institute')
        ->and($editor['contributors'][1]['roles'])->toBe(['Contact Person']);
})->with(['xml', 'json']);

it('preserves all roles when a contact is also a data collector', function (string $format, bool $reverse, bool $isAuthor) {
    ContributorType::firstOrCreate(['slug' => 'DataCollector'], ['name' => 'Data Collector', 'category' => 'person']);
    $person = ['name' => 'Contact, Chris', 'givenName' => 'Chris', 'familyName' => 'Contact', 'nameType' => 'Personal'];
    $contributors = [$person + ['contributorType' => 'ContactPerson'], $person + ['contributorType' => 'DataCollector']];
    $response = $this->postJson('/dashboard/upload-'.$format, [
        'file' => issue1496PartiesFile($format, [$isAuthor ? $person : ['name' => 'Author, Anna', 'givenName' => 'Anna', 'familyName' => 'Author']], $reverse ? array_reverse($contributors) : $contributors),
    ])->assertOk();
    $resource = Resource::findOrFail($response->json('resourceId'));
    $editor = app(EditorDataTransformer::class)->transformResource($resource);
    expect($editor['authors'])->toHaveCount(1)
        ->and($editor['authors'][0]['isContact'])->toBe($isAuthor)
        ->and($editor['contributors'])->toHaveCount(1)
        ->and($editor['contributors'][0]['roles'])->toContain('Data Collector');
    expect(in_array('Contact Person', $editor['contributors'][0]['roles'], true))->toBe(! $isAuthor);
    $export = app(DataCiteJsonExporter::class)->export($resource)['data']['attributes'];
    expect($export['creators'])->toHaveCount(1)
        ->and(array_column($export['contributors'], 'contributorType'))->toContain('ContactPerson', 'DataCollector');
})->with(['xml', 'json'])->with([false, true])->with([false, true]);

it('does not use conflicting ISO contact addresses for the same name', function (bool $reverse) {
    $xml = file_get_contents(base_path('tests/pest/dataset-examples/issue-1496-contact-persons.xml'));
    preg_match('~<pointOfContact><CI_ResponsibleParty>\s*<individualName><gco:CharacterString>Contact, Chris.*?</pointOfContact>~s', $xml, $match);
    $original = $match[0];
    $conflicting = str_replace('chris@example.org', 'different@example.org', $original);
    $xml = str_replace($original, $reverse ? $conflicting.$original : $original.$conflicting, $xml);
    $metadata = $this->postJson('/editor/upload-xml/preview', [
        'file' => UploadedFile::fake()->createWithContent('conflicting-iso.xml', $xml),
    ])->assertOk()->json('metadata');
    expect($metadata['authors'][0]['email'])->toBe('anna@example.org')
        ->and($metadata['contributors'][0]['email'] ?? '')->toBe('')
        ->and($metadata['contributors'][0]['website'] ?? '')->toBe('');
})->with([false, true]);

it('retains safe ISO contact websites and rejects unsafe schemes', function (string $website, ?string $expected) {
    $xml = str_replace('example.org/chris', $website, file_get_contents(base_path('tests/pest/dataset-examples/issue-1496-contact-persons.xml')));
    $response = $this->postJson('/dashboard/upload-xml', [
        'file' => UploadedFile::fake()->createWithContent('contact-website.xml', $xml),
    ])->assertOk();
    $resource = Resource::findOrFail($response->json('resourceId'));
    $contact = $resource->contributors()->where('email', 'chris@example.org')->sole();
    expect($contact->website)->toBe($expected);
})->with([
    ['//example.org/chris', 'https://example.org/chris'],
    ['http://example.org/chris', 'http://example.org/chris'],
    ['javascript:alert(1)', null],
    ['', null],
]);

it('preserves author and contributor roles through upload storage editor save and export', function (string $format) {
    $response = $this->postJson('/dashboard/upload-'.$format, ['file' => issue1496File($format)])->assertOk();
    $resource = Resource::findOrFail($response->json('resourceId'));
    $editor = app(EditorDataTransformer::class)->transformResource($resource);

    expect($editor['authors'])->toHaveCount(1)
        ->and($editor['authors'][0]['firstName'])->toBe('Anna')
        ->and($editor['authors'][0]['isContact'])->toBeTrue()
        ->and($editor['contributors'])->toHaveCount(1)
        ->and($editor['contributors'][0]['firstName'])->toBe('Chris')
        ->and($editor['contributors'][0]['roles'])->toBe(['Contact Person']);

    [$saved] = app(ResourceStorageService::class)->store([
        'resourceId' => $resource->id,
        'authors' => $editor['authors'],
        'contributors' => $editor['contributors'],
        'titles' => [['title' => 'Issue 1496', 'titleType' => 'MainTitle']],
        'year' => '2026',
    ], auth()->id());

    expect($saved->creators()->count())->toBe(1)
        ->and($saved->contributors()->count())->toBe(2);
    if ($format === 'xml') {
        expect($editor['authors'][0]['email'])->toBe('anna@example.org')
            ->and($editor['contributors'][0]['email'])->toBe('chris@example.org')
            ->and($editor['contributors'][0]['website'])->toBe('https://example.org/chris');
    }

    $json = app(DataCiteJsonExporter::class)->export($saved->fresh())['data']['attributes'];
    expect($json['creators'])->toHaveCount(1)
        ->and($json['creators'][0]['givenName'])->toBe('Anna')
        ->and($json['contributors'])->toHaveCount(2)
        ->and(array_column($json['contributors'], 'contributorType'))->toBe(['ContactPerson', 'ContactPerson']);
    $xml = app(DataCiteXmlExporter::class)->export($saved->fresh());
    $reimport = $this->postJson('/editor/upload-xml/preview', [
        'file' => UploadedFile::fake()->createWithContent('export.xml', $xml),
    ])->assertOk()->json('metadata');
    expect($reimport['authors'])->toHaveCount(1)
        ->and($reimport['contributors'])->toHaveCount(1)
        ->and($reimport['contributors'][0]['firstName'])->toBe('Chris');
})->with(['xml', 'json']);
