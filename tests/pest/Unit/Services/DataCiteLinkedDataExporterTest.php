<?php

declare(strict_types=1);

use App\Models\DescriptionType;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\TitleType;
use App\Services\DataCiteLinkedDataExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('db:seed', ['--class' => 'TitleTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'ResourceTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'DateTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'DescriptionTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'ContributorTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'IdentifierTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'RelationTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'LanguageSeeder']);
    $this->artisan('db:seed', ['--class' => 'PublisherSeeder']);

    $this->exporter = new DataCiteLinkedDataExporter;
});

covers(DataCiteLinkedDataExporter::class);

describe('export basics', function () {
    it('includes @context from config', function () {
        $resource = createResourceWithTitle();

        $result = $this->exporter->export($resource);

        expect($result)->toHaveKey('@context');
        expect($result['@context'])->toBe(config('datacite.linked_data.context_url'));
    });

    it('includes @id when DOI is present', function () {
        $resource = createResourceWithTitle('10.5880/test.2025.001');

        $result = $this->exporter->export($resource);

        expect($result)->toHaveKey('@id');
        expect($result['@id'])->toBe('https://doi.org/10.5880/test.2025.001');
    });

    it('omits @id when DOI is null', function () {
        $resource = createResourceWithTitle(null);

        $result = $this->exporter->export($resource);

        expect($result)->not->toHaveKey('@id');
    });

    it('includes publicationYear as value wrapper', function () {
        $resource = createResourceWithTitle();

        $result = $this->exporter->export($resource);

        expect($result)->toHaveKey('publicationYear');
        expect($result['publicationYear'])->toHaveKey('value');
    });

    it('includes version as value wrapper', function () {
        $resource = createResourceWithTitle();

        $result = $this->exporter->export($resource);

        expect($result)->toHaveKey('version');
        expect($result['version'])->toBe(['value' => '1.0']);
    });

    it('includes language as value wrapper', function () {
        $resource = createResourceWithTitle();

        $result = $this->exporter->export($resource);

        expect($result)->toHaveKey('language');
        expect($result['language'])->toBe(['value' => 'en']);
    });
});

describe('identifiers', function () {
    it('transforms a single identifier with attrs/value pattern', function () {
        $resource = createResourceWithTitle('10.5880/test.2025.001');

        $result = $this->exporter->export($resource);

        expect($result)->toHaveKey('identifier');
        expect($result['identifier'])->toHaveKey('attrs');
        expect($result['identifier'])->toHaveKey('value');
        expect($result['identifier']['attrs']['identifierType'])->toBe('DOI');
        expect($result['identifier']['value'])->toBe('10.5880/test.2025.001');
    });
});

describe('creators', function () {
    it('transforms a single creator with creatorName attrs/value', function () {
        $resource = createResourceWithTitle();

        $person = Person::factory()->create([
            'family_name' => 'Doe',
            'given_name' => 'John',
        ]);

        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => Person::class,
            'creatorable_id' => $person->id,
            'position' => 1,
        ]);

        $result = $this->exporter->export($resource->fresh());

        expect($result)->toHaveKey('creators');
        expect($result['creators'])->toHaveKey('creator');

        $creator = $result['creators']['creator'];
        expect($creator)->toHaveKey('creatorName');
        expect($creator['creatorName'])->toHaveKey('value');
        expect($creator['creatorName']['value'])->toBe('Doe, John');
        expect($creator)->toHaveKey('givenName');
        expect($creator['givenName'])->toBe(['value' => 'John']);
        expect($creator)->toHaveKey('familyName');
        expect($creator['familyName'])->toBe(['value' => 'Doe']);
    });

    it('wraps multiple creators in an array', function () {
        $resource = createResourceWithTitle();

        $person1 = Person::factory()->create(['family_name' => 'Smith', 'given_name' => 'Alice']);
        $person2 = Person::factory()->create(['family_name' => 'Jones', 'given_name' => 'Bob']);

        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => Person::class,
            'creatorable_id' => $person1->id,
            'position' => 1,
        ]);
        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => Person::class,
            'creatorable_id' => $person2->id,
            'position' => 2,
        ]);

        $result = $this->exporter->export($resource->fresh());

        expect($result['creators']['creator'])->toBeArray();
        expect($result['creators']['creator'])->toHaveCount(2);
    });
});

describe('titles', function () {
    it('transforms a single title with value wrapper', function () {
        $resource = createResourceWithTitle();

        $result = $this->exporter->export($resource);

        expect($result)->toHaveKey('titles');
        expect($result['titles'])->toHaveKey('title');
        expect($result['titles']['title'])->toHaveKey('value');
        expect($result['titles']['title']['value'])->toBe('Test Resource Title');
    });

    it('wraps multiple titles in an array', function () {
        $resource = createResourceWithTitle();
        $subtitleType = TitleType::where('slug', 'Subtitle')->first();

        $resource->titles()->create([
            'value' => 'A Subtitle',
            'title_type_id' => $subtitleType?->id,
        ]);

        $result = $this->exporter->export($resource->fresh());

        expect($result['titles']['title'])->toBeArray();
        expect(count($result['titles']['title']))->toBeGreaterThanOrEqual(2);
    });
});

describe('publisher', function () {
    it('transforms publisher with value wrapper', function () {
        $resource = createResourceWithTitle();

        $result = $this->exporter->export($resource);

        expect($result)->toHaveKey('publisher');
        expect($result['publisher'])->toHaveKey('value');
        expect($result['publisher']['value'])->toBe('GFZ Data Services');
    });
});

describe('resourceType', function () {
    it('transforms resource type with attrs/value pattern', function () {
        $resource = createResourceWithTitle();

        $result = $this->exporter->export($resource);

        expect($result)->toHaveKey('resourceType');
        expect($result['resourceType'])->toHaveKey('attrs');
        expect($result['resourceType']['attrs'])->toHaveKey('resourceTypeGeneral');
        expect($result['resourceType'])->toHaveKey('value');
    });
});

describe('descriptions', function () {
    it('transforms descriptions with attrs/value pattern', function () {
        $resource = createResourceWithTitle();

        $abstractType = DescriptionType::where('slug', 'Abstract')->first();
        $resource->descriptions()->create([
            'value' => 'A test abstract',
            'description_type_id' => $abstractType?->id,
        ]);

        $result = $this->exporter->export($resource->fresh());

        expect($result)->toHaveKey('descriptions');
        expect($result['descriptions'])->toHaveKey('description');

        $desc = $result['descriptions']['description'];
        expect($desc)->toHaveKey('value');
        expect($desc['value'])->toBe('A test abstract');
        expect($desc)->toHaveKey('attrs');
        expect($desc['attrs']['descriptionType'])->toBe('Abstract');
    });
});

describe('geoLocations', function () {
    it('transforms geo location point with nested value wrappers', function () {
        $resource = createResourceWithTitle();

        $resource->geoLocations()->create([
            'place' => 'Potsdam',
            'point_longitude' => 13.0,
            'point_latitude' => 52.4,
        ]);

        $result = $this->exporter->export($resource->fresh());

        expect($result)->toHaveKey('geoLocations');
        $geoLocation = $result['geoLocations']['geoLocation'];
        expect($geoLocation)->toHaveKey('geoLocationPlace');
        expect($geoLocation['geoLocationPlace'])->toBe(['value' => 'Potsdam']);
        expect($geoLocation)->toHaveKey('geoLocationPoint');
        expect($geoLocation['geoLocationPoint'])->toHaveKey('pointLongitude');
        expect($geoLocation['geoLocationPoint']['pointLongitude'])->toHaveKey('value');
    });

    it('transforms geo location box with nested value wrappers', function () {
        $resource = createResourceWithTitle();

        $resource->geoLocations()->create([
            'west_bound_longitude' => 12.0,
            'east_bound_longitude' => 14.0,
            'south_bound_latitude' => 51.0,
            'north_bound_latitude' => 53.0,
        ]);

        $result = $this->exporter->export($resource->fresh());

        $geoLocation = $result['geoLocations']['geoLocation'];
        expect($geoLocation)->toHaveKey('geoLocationBox');
        expect($geoLocation['geoLocationBox'])->toHaveKey('westBoundLongitude');
        expect($geoLocation['geoLocationBox']['westBoundLongitude'])->toHaveKey('value');
    });
});

describe('funding references', function () {
    it('transforms funding reference with funderName and awardNumber', function () {
        $resource = createResourceWithTitle();

        $resource->fundingReferences()->create([
            'funder_name' => 'Deutsche Forschungsgemeinschaft',
            'funder_identifier' => 'https://doi.org/10.13039/501100001659',
            'funder_identifier_type_id' => null,
            'award_number' => 'ABC-123',
            'award_title' => 'Test Grant',
            'award_uri' => 'https://example.com/grant',
        ]);

        $result = $this->exporter->export($resource->fresh());

        expect($result)->toHaveKey('fundingReferences');
        $funding = $result['fundingReferences']['fundingReference'];
        expect($funding)->toHaveKey('funderName');
        expect($funding['funderName'])->toBe(['value' => 'Deutsche Forschungsgemeinschaft']);
        expect($funding)->toHaveKey('awardNumber');
        expect($funding['awardNumber']['value'])->toBe('ABC-123');
        expect($funding)->toHaveKey('awardTitle');
        expect($funding['awardTitle'])->toBe(['value' => 'Test Grant']);
    });
});

describe('rights', function () {
    it('transforms rights with attrs/value pattern', function () {
        $resource = createResourceWithTitle();

        $right = \App\Models\Right::firstOrCreate(
            ['identifier' => 'CC-BY-4.0'],
            [
                'name' => 'Creative Commons Attribution 4.0 International',
                'uri' => 'https://creativecommons.org/licenses/by/4.0/',
                'scheme_uri' => 'https://spdx.org/licenses/',
            ]
        );
        $resource->rights()->attach($right->id);

        $result = $this->exporter->export($resource->fresh());

        expect($result)->toHaveKey('rightsList');
        $rights = $result['rightsList']['rights'];
        expect($rights)->toHaveKey('value');
        expect($rights['value'])->toBe('Creative Commons Attribution 4.0 International');
        expect($rights)->toHaveKey('attrs');
        expect($rights['attrs'])->toHaveKey('rightsIdentifier');
    });
});

describe('output is valid JSON-LD', function () {
    it('produces JSON-encodable output', function () {
        $resource = createResourceWithTitle();

        $result = $this->exporter->export($resource);

        $json = json_encode($result, JSON_PRETTY_PRINT);
        expect($json)->not->toBeFalse();
        expect(json_decode($json, true))->toBe($result);
    });
});

// --- Helper ---

function createResourceWithTitle(?string $doi = '10.5880/test.2025.001'): Resource
{
    $mainTitleType = TitleType::where('slug', 'MainTitle')->first();

    $resource = Resource::factory()->create([
        'doi' => $doi,
        'publication_year' => 2025,
    ]);

    $resource->titles()->create([
        'value' => 'Test Resource Title',
        'title_type_id' => $mainTitleType?->id,
    ]);

    return $resource;
}
