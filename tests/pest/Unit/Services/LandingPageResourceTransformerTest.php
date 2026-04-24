<?php

declare(strict_types=1);

use App\Models\ContributorType;
use App\Models\Description;
use App\Models\Person;
use App\Models\RelatedIdentifier;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\Title;
use App\Services\LandingPageResourceTransformer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

covers(LandingPageResourceTransformer::class);

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
    $contributor->setRelation('contributorTypes', new EloquentCollection);
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
        ->toHaveKey('contributor_types')
        ->and($data['contributors'][0]['contributor_types'])->toBeArray();
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

test('includes contributor contact persons with source contributor', function () {
    $transformer = new LandingPageResourceTransformer;

    $resource = new Resource;

    $contactType = new ContributorType;
    $contactType->forceFill(['id' => 1, 'name' => 'ContactPerson', 'slug' => 'ContactPerson']);

    $person = new Person;
    $person->forceFill([
        'id' => 10,
        'given_name' => 'Alice',
        'family_name' => 'Contributor',
        'name_identifier' => null,
        'name_identifier_scheme' => null,
    ]);

    $contributor = new ResourceContributor;
    $contributor->forceFill([
        'id' => 5,
        'position' => 1,
        'contributorable_type' => Person::class,
        'contributorable_id' => $person->id,
        'email' => 'alice@example.com',
        'website' => null,
    ]);
    $contributor->setRelation('contributorable', $person);
    $contributor->setRelation('contributorTypes', new EloquentCollection([$contactType]));
    $contributor->setRelation('affiliations', new EloquentCollection);

    $resource->setRelation('creators', new EloquentCollection);
    $resource->setRelation('contributors', new EloquentCollection([$contributor]));
    $resource->setRelation('titles', new EloquentCollection);
    $resource->setRelation('relatedIdentifiers', new EloquentCollection);
    $resource->setRelation('descriptions', new EloquentCollection);
    $resource->setRelation('fundingReferences', new EloquentCollection);
    $resource->setRelation('subjects', new EloquentCollection);
    $resource->setRelation('geoLocations', new EloquentCollection);
    $resource->setRelation('rights', new EloquentCollection);

    $data = $transformer->transform($resource);

    expect($data['contact_persons'])
        ->toHaveCount(1)
        ->and($data['contact_persons'][0])
        ->toMatchArray([
            'id' => 5,
            'name' => 'Alice Contributor',
            'given_name' => 'Alice',
            'family_name' => 'Contributor',
            'type' => 'Person',
            'source' => 'contributor',
            'has_email' => true,
        ]);
});

test('includes both creator and contributor contact persons', function () {
    $transformer = new LandingPageResourceTransformer;

    $resource = new Resource;

    $creatorPerson = new Person;
    $creatorPerson->forceFill([
        'id' => 1,
        'given_name' => 'Jane',
        'family_name' => 'Creator',
        'name_identifier' => null,
        'name_identifier_scheme' => null,
    ]);

    $creator = new ResourceCreator;
    $creator->forceFill([
        'id' => 1,
        'position' => 1,
        'creatorable_type' => Person::class,
        'creatorable_id' => $creatorPerson->id,
        'is_contact' => true,
        'email' => 'jane@example.com',
        'website' => null,
    ]);
    $creator->setRelation('creatorable', $creatorPerson);
    $creator->setRelation('affiliations', new EloquentCollection);

    $contactType = new ContributorType;
    $contactType->forceFill(['id' => 1, 'name' => 'ContactPerson', 'slug' => 'ContactPerson']);

    $contributorPerson = new Person;
    $contributorPerson->forceFill([
        'id' => 2,
        'given_name' => 'Bob',
        'family_name' => 'Contributor',
        'name_identifier' => null,
        'name_identifier_scheme' => null,
    ]);

    $contributor = new ResourceContributor;
    $contributor->forceFill([
        'id' => 10,
        'position' => 1,
        'contributorable_type' => Person::class,
        'contributorable_id' => $contributorPerson->id,
        'email' => 'bob@example.com',
        'website' => null,
    ]);
    $contributor->setRelation('contributorable', $contributorPerson);
    $contributor->setRelation('contributorTypes', new EloquentCollection([$contactType]));
    $contributor->setRelation('affiliations', new EloquentCollection);

    $resource->setRelation('creators', new EloquentCollection([$creator]));
    $resource->setRelation('contributors', new EloquentCollection([$contributor]));
    $resource->setRelation('titles', new EloquentCollection);
    $resource->setRelation('relatedIdentifiers', new EloquentCollection);
    $resource->setRelation('descriptions', new EloquentCollection);
    $resource->setRelation('fundingReferences', new EloquentCollection);
    $resource->setRelation('subjects', new EloquentCollection);
    $resource->setRelation('geoLocations', new EloquentCollection);
    $resource->setRelation('rights', new EloquentCollection);

    $data = $transformer->transform($resource);

    expect($data['contact_persons'])
        ->toHaveCount(2)
        ->and($data['contact_persons'][0]['source'])->toBe('creator')
        ->and($data['contact_persons'][0]['name'])->toBe('Jane Creator')
        ->and($data['contact_persons'][1]['source'])->toBe('contributor')
        ->and($data['contact_persons'][1]['name'])->toBe('Bob Contributor');
});

test('deduplicates contributor contact persons against creator contact persons', function () {
    $transformer = new LandingPageResourceTransformer;

    $resource = new Resource;

    // Same person is both creator (with is_contact) and contributor (with ContactPerson type)
    $person = new Person;
    $person->forceFill([
        'id' => 1,
        'given_name' => 'Alice',
        'family_name' => 'Duplicate',
        'name_identifier' => null,
        'name_identifier_scheme' => null,
    ]);

    $creator = new ResourceCreator;
    $creator->forceFill([
        'id' => 1,
        'position' => 1,
        'creatorable_type' => Person::class,
        'creatorable_id' => $person->id,
        'is_contact' => true,
        'email' => 'alice@example.com',
        'website' => null,
    ]);
    $creator->setRelation('creatorable', $person);
    $creator->setRelation('affiliations', new EloquentCollection);

    $contactType = new ContributorType;
    $contactType->forceFill(['id' => 1, 'name' => 'ContactPerson', 'slug' => 'ContactPerson']);

    $contributor = new ResourceContributor;
    $contributor->forceFill([
        'id' => 10,
        'position' => 1,
        'contributorable_type' => Person::class,
        'contributorable_id' => $person->id, // Same person!
        'email' => 'alice@example.com',
        'website' => null,
    ]);
    $contributor->setRelation('contributorable', $person);
    $contributor->setRelation('contributorTypes', new EloquentCollection([$contactType]));
    $contributor->setRelation('affiliations', new EloquentCollection);

    $resource->setRelation('creators', new EloquentCollection([$creator]));
    $resource->setRelation('contributors', new EloquentCollection([$contributor]));
    $resource->setRelation('titles', new EloquentCollection);
    $resource->setRelation('relatedIdentifiers', new EloquentCollection);
    $resource->setRelation('descriptions', new EloquentCollection);
    $resource->setRelation('fundingReferences', new EloquentCollection);
    $resource->setRelation('subjects', new EloquentCollection);
    $resource->setRelation('geoLocations', new EloquentCollection);
    $resource->setRelation('rights', new EloquentCollection);

    $data = $transformer->transform($resource);

    // Should only have 1 contact person (creator preferred, contributor deduplicated)
    expect($data['contact_persons'])
        ->toHaveCount(1)
        ->and($data['contact_persons'][0]['source'])->toBe('creator')
        ->and($data['contact_persons'][0]['name'])->toBe('Alice Duplicate');
});

test('transforms inline relatedItems with titles, creators and contributors', function () {
    $transformer = new LandingPageResourceTransformer;

    $resource = new Resource;

    $relationType = new \App\Models\RelationType;
    $relationType->forceFill([
        'id' => 1,
        'name' => 'IsCitedBy',
        'slug' => 'iscitedby',
    ]);

    $title = new \App\Models\RelatedItemTitle;
    $title->forceFill([
        'id' => 1,
        'title' => 'A Related Journal Article',
        'title_type' => 'MainTitle',
        'language' => 'en',
    ]);

    $affiliation = new \App\Models\RelatedItemCreatorAffiliation;
    $affiliation->forceFill([
        'id' => 1,
        'name' => 'GFZ Potsdam',
        'affiliation_identifier' => 'https://ror.org/04z8jg394',
        'scheme' => 'ROR',
    ]);

    $creator = new \App\Models\RelatedItemCreator;
    $creator->forceFill([
        'id' => 1,
        'name_type' => 'Personal',
        'name' => 'Doe, Jane',
        'given_name' => 'Jane',
        'family_name' => 'Doe',
        'name_identifier' => '0000-0001-2345-6789',
        'name_identifier_scheme' => 'ORCID',
        'scheme_uri' => 'https://orcid.org',
        'position' => 1,
    ]);
    $creator->setRelation('affiliations', new EloquentCollection([$affiliation]));

    $contribAff = new \App\Models\RelatedItemContributorAffiliation;
    $contribAff->forceFill([
        'id' => 2,
        'name' => 'ETH Zurich',
        'affiliation_identifier' => null,
        'scheme' => null,
    ]);

    $contributor = new \App\Models\RelatedItemContributor;
    $contributor->forceFill([
        'id' => 1,
        'contributor_type' => 'Editor',
        'name_type' => 'Personal',
        'name' => 'Smith, John',
        'given_name' => 'John',
        'family_name' => 'Smith',
        'name_identifier' => null,
        'name_identifier_scheme' => null,
        'scheme_uri' => null,
        'position' => 1,
    ]);
    $contributor->setRelation('affiliations', new EloquentCollection([$contribAff]));

    $relatedItem = new \App\Models\RelatedItem;
    $relatedItem->forceFill([
        'id' => 1,
        'related_item_type' => 'JournalArticle',
        'publication_year' => 2024,
        'volume' => '42',
        'issue' => '3',
        'number' => null,
        'number_type' => null,
        'first_page' => '101',
        'last_page' => '115',
        'publisher' => 'Acme Publisher',
        'edition' => null,
        'identifier' => '10.1234/abc',
        'identifier_type' => 'DOI',
        'related_metadata_scheme' => null,
        'scheme_uri' => null,
        'scheme_type' => null,
        'position' => 1,
    ]);
    $relatedItem->setRelation('relationType', $relationType);
    $relatedItem->setRelation('titles', new EloquentCollection([$title]));
    $relatedItem->setRelation('creators', new EloquentCollection([$creator]));
    $relatedItem->setRelation('contributors', new EloquentCollection([$contributor]));

    $resource->setRelation('relatedItems', new EloquentCollection([$relatedItem]));
    $resource->setRelation('titles', new EloquentCollection);
    $resource->setRelation('creators', new EloquentCollection);
    $resource->setRelation('contributors', new EloquentCollection);
    $resource->setRelation('relatedIdentifiers', new EloquentCollection);
    $resource->setRelation('descriptions', new EloquentCollection);
    $resource->setRelation('fundingReferences', new EloquentCollection);
    $resource->setRelation('subjects', new EloquentCollection);
    $resource->setRelation('geoLocations', new EloquentCollection);
    $resource->setRelation('rights', new EloquentCollection);

    $data = $transformer->transform($resource);

    expect($data)->toHaveKey('related_items');
    expect($data['related_items'])->toHaveCount(1);

    $item = $data['related_items'][0];
    expect($item)->toMatchArray([
        'id' => 1,
        'related_item_type' => 'JournalArticle',
        'relation_type' => 'IsCitedBy',
        'relation_type_slug' => 'iscitedby',
        'publication_year' => 2024,
        'volume' => '42',
        'issue' => '3',
        'first_page' => '101',
        'last_page' => '115',
        'publisher' => 'Acme Publisher',
        'identifier' => '10.1234/abc',
        'identifier_type' => 'DOI',
        'position' => 1,
    ]);

    expect($item['titles'])->toHaveCount(1);
    expect($item['titles'][0])->toMatchArray([
        'title' => 'A Related Journal Article',
        'title_type' => 'MainTitle',
        'language' => 'en',
    ]);

    expect($item['creators'])->toHaveCount(1);
    expect($item['creators'][0])->toMatchArray([
        'name' => 'Doe, Jane',
        'given_name' => 'Jane',
        'family_name' => 'Doe',
        'name_identifier' => '0000-0001-2345-6789',
        'name_identifier_scheme' => 'ORCID',
    ]);
    expect($item['creators'][0]['affiliations'])->toHaveCount(1);
    expect($item['creators'][0]['affiliations'][0])->toMatchArray([
        'name' => 'GFZ Potsdam',
        'affiliation_identifier' => 'https://ror.org/04z8jg394',
        'scheme' => 'ROR',
    ]);

    expect($item['contributors'])->toHaveCount(1);
    expect($item['contributors'][0])->toMatchArray([
        'contributor_type' => 'Editor',
        'name' => 'Smith, John',
    ]);
    expect($item['contributors'][0]['affiliations'])->toHaveCount(1);
    expect($item['contributors'][0]['affiliations'][0]['name'])->toBe('ETH Zurich');
});

test('related_items defaults to empty array when relation not loaded', function () {
    $transformer = new LandingPageResourceTransformer;

    $resource = new Resource;
    $resource->setRelation('titles', new EloquentCollection);
    $resource->setRelation('creators', new EloquentCollection);
    $resource->setRelation('contributors', new EloquentCollection);
    $resource->setRelation('relatedIdentifiers', new EloquentCollection);
    $resource->setRelation('descriptions', new EloquentCollection);
    $resource->setRelation('fundingReferences', new EloquentCollection);
    $resource->setRelation('subjects', new EloquentCollection);
    $resource->setRelation('geoLocations', new EloquentCollection);
    $resource->setRelation('rights', new EloquentCollection);

    $data = $transformer->transform($resource);

    expect($data)->toHaveKey('related_items')
        ->and($data['related_items'])->toBeArray()->toBeEmpty();
});
