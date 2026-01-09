<?php

declare(strict_types=1);

use App\Models\Description;
use App\Models\Person;
use App\Models\RelatedIdentifier;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\Title;
use App\Services\LandingPageResourceTransformer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

uses()->group('landing-pages');

test('transforms a resource into landing page payload structure', function () {
    $transformer = new LandingPageResourceTransformer;

    $resource = new Resource;

    $title = new Title;
    $title->forceFill([
        'id' => 1,
        'value' => 'Test Title',
        'language' => null,
    ]);
    $title->setRelation('titleType', null);

    $person = new Person;
    $person->forceFill([
        'id' => 1,
        'given_name' => 'Jane',
        'family_name' => 'Doe',
        'name_identifier' => null,
        'name_identifier_scheme' => null,
    ]);

    $creator = new ResourceCreator;
    $creator->forceFill([
        'id' => 1,
        'position' => 1,
        'creatorable_type' => Person::class,
        'creatorable_id' => $person->id,
        'is_contact' => false,
        'email' => null,
        'website' => null,
    ]);
    $creator->setRelation('creatorable', $person);
    $creator->setRelation('affiliations', new EloquentCollection);

    $resource->setRelation('titles', new EloquentCollection([$title]));
    $resource->setRelation('creators', new EloquentCollection([$creator]));
    $resource->setRelation('contributors', new EloquentCollection);
    $resource->setRelation('relatedIdentifiers', new EloquentCollection);
    $resource->setRelation('descriptions', new EloquentCollection);
    $resource->setRelation('fundingReferences', new EloquentCollection);
    $resource->setRelation('subjects', new EloquentCollection);
    $resource->setRelation('geoLocations', new EloquentCollection);
    $resource->setRelation('rights', new EloquentCollection);

    $data = $transformer->transform($resource);

    expect($data)
        ->toHaveKey('titles')
        ->and($data['titles'][0])
        ->toMatchArray([
            'id' => $title->id,
            'title' => 'Test Title',
        ])
        ->and($data)
        ->toHaveKey('creators')
        ->and($data['creators'][0])
        ->toHaveKeys(['id', 'position', 'affiliations', 'creatorable']);
});

test('transformation is null-safe for optional relationships', function () {
    $transformer = new LandingPageResourceTransformer;

    $resource = new Resource;

    $related = new RelatedIdentifier;
    $related->forceFill([
        'id' => 1,
        'identifier' => '10.1234/related',
        'position' => 1,
    ]);
    $related->setRelation('identifierType', null);
    $related->setRelation('relationType', null);

    $description = new Description;
    $description->forceFill([
        'id' => 1,
        'value' => 'Some description',
    ]);
    $description->setRelation('descriptionType', null);

    $person = new Person;
    $person->forceFill([
        'id' => 1,
        'given_name' => 'Jane',
        'family_name' => 'Doe',
        'name_identifier' => null,
        'name_identifier_scheme' => null,
    ]);

    $contributor = new ResourceContributor;
    $contributor->forceFill([
        'id' => 1,
        'position' => 1,
        'contributorable_type' => Person::class,
        'contributorable_id' => $person->id,
    ]);
    $contributor->setRelation('contributorType', null);
    $contributor->setRelation('contributorable', $person);
    $contributor->setRelation('affiliations', new EloquentCollection);

    $resource->setRelation('relatedIdentifiers', new EloquentCollection([$related]));
    $resource->setRelation('descriptions', new EloquentCollection([$description]));
    $resource->setRelation('contributors', new EloquentCollection([$contributor]));
    $resource->setRelation('titles', new EloquentCollection);
    $resource->setRelation('creators', new EloquentCollection);
    $resource->setRelation('fundingReferences', new EloquentCollection);
    $resource->setRelation('subjects', new EloquentCollection);
    $resource->setRelation('geoLocations', new EloquentCollection);
    $resource->setRelation('rights', new EloquentCollection);

    $data = $transformer->transform($resource);

    expect($data['related_identifiers'][0])
        ->toMatchArray([
            'identifier' => '10.1234/related',
            'identifier_type' => null,
            'relation_type' => null,
        ]);

    expect($data['descriptions'][0])
        ->toMatchArray([
            'value' => 'Some description',
            'description_type' => null,
        ]);

    expect($data['contributors'][0])
        ->toMatchArray([
            'contributor_type' => null,
        ]);
});

test('transforms rights to licenses with correct field mapping', function () {
    $transformer = new LandingPageResourceTransformer;

    $resource = new Resource;

    $right = new \App\Models\Right;
    $right->forceFill([
        'id' => 1,
        'identifier' => 'CC-BY-4.0',
        'name' => 'Creative Commons Attribution 4.0 International',
        'uri' => 'https://creativecommons.org/licenses/by/4.0/',
        'scheme_uri' => 'https://spdx.org/licenses/',
    ]);

    $resource->setRelation('rights', new EloquentCollection([$right]));
    $resource->setRelation('titles', new EloquentCollection);
    $resource->setRelation('creators', new EloquentCollection);
    $resource->setRelation('contributors', new EloquentCollection);
    $resource->setRelation('relatedIdentifiers', new EloquentCollection);
    $resource->setRelation('descriptions', new EloquentCollection);
    $resource->setRelation('fundingReferences', new EloquentCollection);
    $resource->setRelation('subjects', new EloquentCollection);
    $resource->setRelation('geoLocations', new EloquentCollection);

    $data = $transformer->transform($resource);

    expect($data)
        ->toHaveKey('licenses')
        ->and($data['licenses'])
        ->toHaveCount(1)
        ->and($data['licenses'][0])
        ->toMatchArray([
            'id' => 1,
            'name' => 'Creative Commons Attribution 4.0 International',
            'spdx_id' => 'CC-BY-4.0',
            'reference' => 'https://creativecommons.org/licenses/by/4.0/',
        ]);
});

test('transforms multiple licenses correctly', function () {
    $transformer = new LandingPageResourceTransformer;

    $resource = new Resource;

    $ccBy = new \App\Models\Right;
    $ccBy->forceFill([
        'id' => 1,
        'identifier' => 'CC-BY-4.0',
        'name' => 'Creative Commons Attribution 4.0 International',
        'uri' => 'https://creativecommons.org/licenses/by/4.0/',
    ]);

    $mit = new \App\Models\Right;
    $mit->forceFill([
        'id' => 2,
        'identifier' => 'MIT',
        'name' => 'MIT License',
        'uri' => 'https://opensource.org/licenses/MIT',
    ]);

    $resource->setRelation('rights', new EloquentCollection([$ccBy, $mit]));
    $resource->setRelation('titles', new EloquentCollection);
    $resource->setRelation('creators', new EloquentCollection);
    $resource->setRelation('contributors', new EloquentCollection);
    $resource->setRelation('relatedIdentifiers', new EloquentCollection);
    $resource->setRelation('descriptions', new EloquentCollection);
    $resource->setRelation('fundingReferences', new EloquentCollection);
    $resource->setRelation('subjects', new EloquentCollection);
    $resource->setRelation('geoLocations', new EloquentCollection);

    $data = $transformer->transform($resource);

    expect($data['licenses'])
        ->toHaveCount(2)
        ->and($data['licenses'][0]['spdx_id'])->toBe('CC-BY-4.0')
        ->and($data['licenses'][0]['name'])->toBe('Creative Commons Attribution 4.0 International')
        ->and($data['licenses'][1]['spdx_id'])->toBe('MIT')
        ->and($data['licenses'][1]['name'])->toBe('MIT License');
});

test('handles empty licenses collection', function () {
    $transformer = new LandingPageResourceTransformer;

    $resource = new Resource;

    $resource->setRelation('rights', new EloquentCollection);
    $resource->setRelation('titles', new EloquentCollection);
    $resource->setRelation('creators', new EloquentCollection);
    $resource->setRelation('contributors', new EloquentCollection);
    $resource->setRelation('relatedIdentifiers', new EloquentCollection);
    $resource->setRelation('descriptions', new EloquentCollection);
    $resource->setRelation('fundingReferences', new EloquentCollection);
    $resource->setRelation('subjects', new EloquentCollection);
    $resource->setRelation('geoLocations', new EloquentCollection);

    $data = $transformer->transform($resource);

    expect($data)
        ->toHaveKey('licenses')
        ->and($data['licenses'])
        ->toBeArray()
        ->toBeEmpty();
});
