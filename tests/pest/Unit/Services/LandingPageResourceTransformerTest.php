<?php

declare(strict_types=1);

use App\Enums\Igsn\IgsnClassificationType;
use App\Models\AlternateIdentifier;
use App\Models\ContributorType;
use App\Models\DateType;
use App\Models\Description;
use App\Models\DescriptionType;
use App\Models\GeoLocation;
use App\Models\IdentifierType;
use App\Models\IgsnClassification;
use App\Models\IgsnMetadata;
use App\Models\LandingPage;
use App\Models\Person;
use App\Models\Publisher;
use App\Models\RelatedIdentifier;
use App\Models\RelatedItem;
use App\Models\RelatedItemContributor;
use App\Models\RelatedItemContributorAffiliation;
use App\Models\RelatedItemCreator;
use App\Models\RelatedItemCreatorAffiliation;
use App\Models\RelatedItemTitle;
use App\Models\RelationType;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\ResourceDate;
use App\Models\ResourceRight;
use App\Models\Right;
use App\Models\Subject;
use App\Models\Title;
use App\Services\LandingPageResourceTransformer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

covers(LandingPageResourceTransformer::class);

function legacyLandingPerson(
    int $id,
    string $givenName,
    string $familyName,
    ?string $orcid = null,
    ?string $scheme = null,
): Person {
    $person = new Person;
    $person->forceFill([
        'id' => $id,
        'given_name' => $givenName,
        'family_name' => $familyName,
        'name_identifier' => $orcid,
        'name_identifier_scheme' => $scheme,
    ]);

    return $person;
}

function legacyLandingCreator(
    int $id,
    Person $person,
    int $position = 1,
    bool $isContact = false,
    ?string $email = null,
): ResourceCreator {
    $creator = new ResourceCreator;
    $creator->forceFill([
        'id' => $id,
        'position' => $position,
        'creatorable_type' => Person::class,
        'creatorable_id' => $person->id,
        'is_contact' => $isContact,
        'email' => $email,
        'website' => null,
    ]);
    $creator->setRelation('creatorable', $person);
    $creator->setRelation('affiliations', new EloquentCollection);

    return $creator;
}

function legacyLandingContributor(
    int $id,
    Person $person,
    ?string $email = null,
    ?string $website = null,
): ResourceContributor {
    $contactType = new ContributorType;
    $contactType->forceFill(['id' => 1, 'name' => 'Contact Person', 'slug' => 'ContactPerson']);

    $contributor = new ResourceContributor;
    $contributor->forceFill([
        'id' => $id,
        'position' => 1,
        'contributorable_type' => Person::class,
        'contributorable_id' => $person->id,
        'email' => $email,
        'website' => $website,
    ]);
    $contributor->setRelation('contributorable', $person);
    $contributor->setRelation('contributorTypes', new EloquentCollection([$contactType]));
    $contributor->setRelation('affiliations', new EloquentCollection);

    return $contributor;
}

/**
 * @param  list<ResourceCreator>  $creators
 * @param  list<ResourceContributor>  $contributors
 */
function legacyLandingResource(array $creators, array $contributors): Resource
{
    $resource = new Resource;
    $resource->setRelation('creators', new EloquentCollection($creators));
    $resource->setRelation('contributors', new EloquentCollection($contributors));
    $resource->setRelation('titles', new EloquentCollection);
    $resource->setRelation('relatedIdentifiers', new EloquentCollection);
    $resource->setRelation('descriptions', new EloquentCollection);
    $resource->setRelation('fundingReferences', new EloquentCollection);
    $resource->setRelation('subjects', new EloquentCollection);
    $resource->setRelation('geoLocations', new EloquentCollection);
    $resource->setRelation('rights', new EloquentCollection);

    return $resource;
}

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

    $publisher = new Publisher;
    $publisher->forceFill(['id' => 42, 'name' => 'Actual Publisher']);

    $resource->setRelation('titles', new EloquentCollection([$title]));
    $resource->setRelation('creators', new EloquentCollection([$creator]));
    $resource->setRelation('publisher', $publisher);
    $resource->setRelation('contributors', new EloquentCollection);
    $resource->setRelation('relatedIdentifiers', new EloquentCollection);
    $resource->setRelation('descriptions', new EloquentCollection);
    $resource->setRelation('fundingReferences', new EloquentCollection);
    $resource->setRelation('subjects', new EloquentCollection);
    $resource->setRelation('geoLocations', new EloquentCollection);
    $resource->setRelation('rights', new EloquentCollection);

    $data = $transformer->transform($resource);

    expect($transformer->requiredRelations())->toContain('publisher');

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
        ->toHaveKeys(['id', 'position', 'affiliations', 'creatorable'])
        ->and($data)
        ->not->toHaveKey('publisher');
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
        'landing_page_html' => '<p>Some <strong>description</strong></p>',
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
            'landing_page_html' => '<p>Some <strong>description</strong></p>',
            'description_type' => null,
            'language' => null,
        ]);

    expect($data['contributors'][0])
        ->toHaveKey('contributor_types')
        ->and($data['contributors'][0]['contributor_types'])->toBeArray();
});

test('transforms landing page html alongside plain text descriptions', function () {
    $transformer = new LandingPageResourceTransformer;

    $resource = new Resource;

    $descriptionType = new DescriptionType;
    $descriptionType->forceFill([
        'id' => 1,
        'name' => 'Abstract',
        'slug' => 'Abstract',
    ]);

    $description = new Description;
    $description->forceFill([
        'id' => 1,
        'value' => 'Formatted abstract',
        'landing_page_html' => '<p>Formatted <strong>abstract</strong></p>',
        'language' => 'de',
    ]);
    $description->setRelation('descriptionType', $descriptionType);

    $resource->setRelation('titles', new EloquentCollection);
    $resource->setRelation('creators', new EloquentCollection);
    $resource->setRelation('contributors', new EloquentCollection);
    $resource->setRelation('relatedIdentifiers', new EloquentCollection);
    $resource->setRelation('descriptions', new EloquentCollection([$description]));
    $resource->setRelation('fundingReferences', new EloquentCollection);
    $resource->setRelation('subjects', new EloquentCollection);
    $resource->setRelation('geoLocations', new EloquentCollection);
    $resource->setRelation('rights', new EloquentCollection);

    $data = $transformer->transform($resource);

    expect($data['descriptions'][0])->toMatchArray([
        'value' => 'Formatted abstract',
        'landing_page_html' => '<p>Formatted <strong>abstract</strong></p>',
        'description_type' => 'Abstract',
        'language' => 'de',
    ]);
});

test('sanitizes landing page html before exposing it to the client', function () {
    $transformer = new LandingPageResourceTransformer;

    $resource = new Resource;

    $descriptionType = new DescriptionType;
    $descriptionType->forceFill([
        'id' => 1,
        'name' => 'Abstract',
        'slug' => 'Abstract',
    ]);

    $description = new Description;
    $description->forceFill([
        'id' => 1,
        'value' => 'Safe abstract text',
        'landing_page_html' => '<script>alert(1)</script><p>Safe <strong>abstract</strong></p><span> extra</span>',
    ]);
    $description->setRelation('descriptionType', $descriptionType);

    $resource->setRelation('titles', new EloquentCollection);
    $resource->setRelation('creators', new EloquentCollection);
    $resource->setRelation('contributors', new EloquentCollection);
    $resource->setRelation('relatedIdentifiers', new EloquentCollection);
    $resource->setRelation('descriptions', new EloquentCollection([$description]));
    $resource->setRelation('fundingReferences', new EloquentCollection);
    $resource->setRelation('subjects', new EloquentCollection);
    $resource->setRelation('geoLocations', new EloquentCollection);
    $resource->setRelation('rights', new EloquentCollection);

    $data = $transformer->transform($resource);

    expect($data['descriptions'][0])->toMatchArray([
        'value' => 'Safe abstract text',
        'landing_page_html' => '<p>Safe <strong>abstract</strong></p> extra',
        'description_type' => 'Abstract',
    ]);
});

test('transforms controlled subjects with breadcrumb_path and narrow labels for landing pages', function () {
    $transformer = new LandingPageResourceTransformer;

    $resource = new Resource;

    $subject = new Subject;
    $subject->forceFill([
        'id' => 1,
        'value' => 'Seismology',
        'subject_scheme' => 'Science Keywords',
        'scheme_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
        'value_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concept/uuid-seismology',
        'classification_code' => null,
        'breadcrumb_path' => 'EARTH SCIENCE > SOLID EARTH > SEISMOLOGY',
    ]);

    $resource->setRelation('titles', new EloquentCollection);
    $resource->setRelation('creators', new EloquentCollection);
    $resource->setRelation('contributors', new EloquentCollection);
    $resource->setRelation('relatedIdentifiers', new EloquentCollection);
    $resource->setRelation('descriptions', new EloquentCollection);
    $resource->setRelation('fundingReferences', new EloquentCollection);
    $resource->setRelation('subjects', new EloquentCollection([$subject]));
    $resource->setRelation('geoLocations', new EloquentCollection);
    $resource->setRelation('rights', new EloquentCollection);

    $data = $transformer->transform($resource);

    expect($data['subjects'][0])->toMatchArray([
        'subject' => 'SEISMOLOGY',
        'subject_scheme' => 'Science Keywords',
        'breadcrumb_path' => 'EARTH SCIENCE > SOLID EARTH > SEISMOLOGY',
    ]);
});

test('normalizes subject scheme aliases in landing page subject payloads', function () {
    $transformer = new LandingPageResourceTransformer;

    $resource = new Resource;

    $subject = new Subject;
    $subject->forceFill([
        'id' => 1,
        'value' => 'Seismology',
        'subject_scheme' => 'NASA/GCMD Earth Science Keywords',
        'scheme_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
        'value_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concept/uuid-seismology',
        'classification_code' => null,
        'breadcrumb_path' => 'EARTH SCIENCE > SOLID EARTH > SEISMOLOGY',
    ]);

    $resource->setRelation('titles', new EloquentCollection);
    $resource->setRelation('creators', new EloquentCollection);
    $resource->setRelation('contributors', new EloquentCollection);
    $resource->setRelation('relatedIdentifiers', new EloquentCollection);
    $resource->setRelation('descriptions', new EloquentCollection);
    $resource->setRelation('fundingReferences', new EloquentCollection);
    $resource->setRelation('subjects', new EloquentCollection([$subject]));
    $resource->setRelation('geoLocations', new EloquentCollection);
    $resource->setRelation('rights', new EloquentCollection);

    $data = $transformer->transform($resource);

    expect($data['subjects'][0])->toMatchArray([
        'subject' => 'SEISMOLOGY',
        'subject_scheme' => 'Science Keywords',
    ]);
});

test('derives landing page breadcrumb_path from legacy full-path subject values', function () {
    $transformer = new LandingPageResourceTransformer;

    $resource = new Resource;

    $subject = new Subject;
    $subject->forceFill([
        'id' => 1,
        'value' => 'EARTH SCIENCE > SOLID EARTH > SEISMOLOGY',
        'subject_scheme' => 'Science Keywords',
        'scheme_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
        'value_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concept/uuid-seismology',
        'classification_code' => null,
        'breadcrumb_path' => null,
    ]);

    $resource->setRelation('titles', new EloquentCollection);
    $resource->setRelation('creators', new EloquentCollection);
    $resource->setRelation('contributors', new EloquentCollection);
    $resource->setRelation('relatedIdentifiers', new EloquentCollection);
    $resource->setRelation('descriptions', new EloquentCollection);
    $resource->setRelation('fundingReferences', new EloquentCollection);
    $resource->setRelation('subjects', new EloquentCollection([$subject]));
    $resource->setRelation('geoLocations', new EloquentCollection);
    $resource->setRelation('rights', new EloquentCollection);

    $data = $transformer->transform($resource);

    expect($data['subjects'][0])->toMatchArray([
        'subject' => 'SEISMOLOGY',
        'breadcrumb_path' => 'EARTH SCIENCE > SOLID EARTH > SEISMOLOGY',
    ]);
});

test('derives landing page breadcrumb_path from legacy entity-encoded subject values', function () {
    $transformer = new LandingPageResourceTransformer;

    $resource = new Resource;

    $subject = new Subject;
    $subject->forceFill([
        'id' => 1,
        'value' => 'EARTH SCIENCE &gt; SOLID EARTH &gt SEISMOLOGY',
        'subject_scheme' => 'Science Keywords',
        'scheme_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
        'value_uri' => 'https://gcmd.earthdata.nasa.gov/kms/concept/uuid-seismology',
        'classification_code' => null,
        'breadcrumb_path' => null,
    ]);

    $resource->setRelation('titles', new EloquentCollection);
    $resource->setRelation('creators', new EloquentCollection);
    $resource->setRelation('contributors', new EloquentCollection);
    $resource->setRelation('relatedIdentifiers', new EloquentCollection);
    $resource->setRelation('descriptions', new EloquentCollection);
    $resource->setRelation('fundingReferences', new EloquentCollection);
    $resource->setRelation('subjects', new EloquentCollection([$subject]));
    $resource->setRelation('geoLocations', new EloquentCollection);
    $resource->setRelation('rights', new EloquentCollection);

    $data = $transformer->transform($resource);

    expect($data['subjects'][0])->toMatchArray([
        'subject' => 'SEISMOLOGY',
        'breadcrumb_path' => 'EARTH SCIENCE > SOLID EARTH > SEISMOLOGY',
    ]);
});

test('transforms related identifier slugs and citation label for landing pages', function () {
    $transformer = new LandingPageResourceTransformer;

    $resource = new Resource;

    $identifierType = new IdentifierType;
    $identifierType->forceFill([
        'id' => 1,
        'name' => 'DOI',
        'slug' => 'DOI',
    ]);

    $relationType = new RelationType;
    $relationType->forceFill([
        'id' => 1,
        'name' => 'Is Supplement To',
        'slug' => 'IsSupplementTo',
    ]);

    $related = new RelatedIdentifier;
    $related->forceFill([
        'id' => 1,
        'identifier' => '10.1234/example',
        'citation_label' => 'Doe, J. (2026): Example. GFZ.',
        'source' => RelatedIdentifier::SOURCE_RELATION_SUGGESTION_ASSISTANT,
        'position' => 1,
    ]);
    $related->setRelation('identifierType', $identifierType);
    $related->setRelation('relationType', $relationType);

    $resource->setRelation('titles', new EloquentCollection);
    $resource->setRelation('creators', new EloquentCollection);
    $resource->setRelation('contributors', new EloquentCollection);
    $resource->setRelation('relatedIdentifiers', new EloquentCollection([$related]));
    $resource->setRelation('descriptions', new EloquentCollection);
    $resource->setRelation('fundingReferences', new EloquentCollection);
    $resource->setRelation('subjects', new EloquentCollection);
    $resource->setRelation('geoLocations', new EloquentCollection);
    $resource->setRelation('rights', new EloquentCollection);

    $data = $transformer->transform($resource);

    expect($data['related_identifiers'][0])->toMatchArray([
        'identifier' => '10.1234/example',
        'identifier_type' => 'DOI',
        'relation_type' => 'IsSupplementTo',
        'citation_label' => 'Doe, J. (2026): Example. GFZ.',
        'source' => RelatedIdentifier::SOURCE_RELATION_SUGGESTION_ASSISTANT,
        'is_repository_curation' => true,
    ]);
});

test('transforms rights to licenses with correct field mapping', function () {
    $transformer = new LandingPageResourceTransformer;

    $resource = new Resource;

    $right = new Right;
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

test('transforms custom licenses without SPDX identifiers', function () {
    $transformer = new LandingPageResourceTransformer;

    $resource = new Resource;

    $customRight = new Right;
    $customRight->forceFill([
        'id' => 7,
        'identifier' => 'CUSTOM-COMMUNITY-123456789ABC',
        'name' => 'Community Data License',
        'uri' => 'https://example.test/licenses/community-data',
        'scheme_uri' => null,
    ]);

    $resource->setRelation('rights', new EloquentCollection([$customRight]));
    $resource->setRelation('titles', new EloquentCollection);
    $resource->setRelation('creators', new EloquentCollection);
    $resource->setRelation('contributors', new EloquentCollection);
    $resource->setRelation('relatedIdentifiers', new EloquentCollection);
    $resource->setRelation('descriptions', new EloquentCollection);
    $resource->setRelation('fundingReferences', new EloquentCollection);
    $resource->setRelation('subjects', new EloquentCollection);
    $resource->setRelation('geoLocations', new EloquentCollection);

    $data = $transformer->transform($resource);

    expect($data['licenses'][0])->toMatchArray([
        'id' => 7,
        'name' => 'Community Data License',
        'spdx_id' => null,
        'reference' => 'https://example.test/licenses/community-data',
        'scheme_uri' => null,
    ]);
});

test('transforms resolved and unresolved resource rights exactly once', function () {
    $transformer = new LandingPageResourceTransformer;
    $resource = Resource::factory()->create();
    $right = Right::factory()->create([
        'identifier' => 'CC-BY-4.0',
        'name' => 'Creative Commons Attribution 4.0 International',
        'uri' => 'https://creativecommons.org/licenses/by/4.0/',
        'scheme_uri' => 'https://spdx.org/licenses/',
    ]);
    $linked = ResourceRight::create([
        'resource_id' => $resource->id,
        'rights_id' => $right->id,
        'rights_text' => 'CC BY 4.0',
        'rights_uri' => 'https://creativecommons.org/licenses/by/4.0/',
    ]);
    $raw = ResourceRight::create([
        'resource_id' => $resource->id,
        'rights_text' => 'Use requires individual permission.',
        'rights_uri' => 'https://example.test/rights/permission',
        'scheme_uri' => 'https://example.test/rights/',
    ]);
    $identifierOnly = ResourceRight::create([
        'resource_id' => $resource->id,
        'rights_identifier' => 'Community-Data-Terms',
    ]);
    ResourceRight::create(['resource_id' => $resource->id]);

    $resource->load($transformer->requiredRelations());
    $data = $transformer->transform($resource);

    expect($transformer->requiredRelations())
        ->toContain('resourceRights.right')
        ->not->toContain('rights')
        ->and($resource->relationLoaded('resourceRights'))->toBeTrue()
        ->and($resource->relationLoaded('rights'))->toBeFalse()
        ->and($data)->not->toHaveKeys(['rights', 'resource_rights'])
        ->and($data['licenses'])->toHaveCount(3)
        ->and($data['licenses'][0])->toMatchArray([
            'id' => $right->id,
            'resource_right_id' => $linked->id,
            'name' => 'Creative Commons Attribution 4.0 International',
            'spdx_id' => 'CC-BY-4.0',
            'reference' => 'https://creativecommons.org/licenses/by/4.0/',
            'source' => 'catalog',
        ])
        ->and($data['licenses'][1])->toMatchArray([
            'id' => null,
            'resource_right_id' => $raw->id,
            'name' => 'Use requires individual permission.',
            'spdx_id' => null,
            'reference' => 'https://example.test/rights/permission',
            'scheme_uri' => 'https://example.test/rights/',
            'source' => 'raw',
        ])
        ->and($data['licenses'][2])->toMatchArray([
            'resource_right_id' => $identifierOnly->id,
            'name' => 'Community-Data-Terms',
            'reference' => null,
            'source' => 'raw',
        ]);
});

test('falls back to an unresolved rights URI when text and identifier are missing', function () {
    $transformer = new LandingPageResourceTransformer;
    $resource = Resource::factory()->create();
    $raw = ResourceRight::create([
        'resource_id' => $resource->id,
        'rights_uri' => 'info:eu-repo/semantics/restrictedAccess',
    ]);

    $resource->load($transformer->requiredRelations());
    $data = $transformer->transform($resource);

    expect($data['licenses'])->toHaveCount(1)
        ->and($data['licenses'][0])->toMatchArray([
            'resource_right_id' => $raw->id,
            'name' => 'info:eu-repo/semantics/restrictedAccess',
            'reference' => 'info:eu-repo/semantics/restrictedAccess',
            'source' => 'raw',
        ]);
});

test('transforms multiple licenses correctly', function () {
    $transformer = new LandingPageResourceTransformer;

    $resource = new Resource;

    $ccBy = new Right;
    $ccBy->forceFill([
        'id' => 1,
        'identifier' => 'CC-BY-4.0',
        'name' => 'Creative Commons Attribution 4.0 International',
        'uri' => 'https://creativecommons.org/licenses/by/4.0/',
        'scheme_uri' => 'https://spdx.org/licenses/',
    ]);

    $mit = new Right;
    $mit->forceFill([
        'id' => 2,
        'identifier' => 'MIT',
        'name' => 'MIT License',
        'uri' => 'https://opensource.org/licenses/MIT',
        'scheme_uri' => 'https://spdx.org/licenses/',
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

test('resolves reordered legacy contact names consistently across credits and contacts', function () {
    $creatorPerson = legacyLandingPerson(101, 'Juan Camilo', 'Gomez-Zapata');
    $contributorPerson = legacyLandingPerson(202, 'Gomez Zapata Juan', 'Camilo');
    $creator = legacyLandingCreator(11, $creatorPerson, isContact: true, email: 'juan.creator@example.com');
    $contributor = legacyLandingContributor(22, $contributorPerson, 'juan.contributor@example.com');
    $resource = legacyLandingResource([$creator], [$contributor]);

    $data = (new LandingPageResourceTransformer)->transform($resource);

    expect($data['creators'][0]['display_identity_key'])
        ->toBe($data['contributors'][0]['display_identity_key'])
        ->and($data['contributors'][0]['contributor_types'])->toBe(['Contact Person'])
        ->and($data['contact_persons'])->toHaveCount(1)
        ->and($data['contact_persons'][0]['source'])->toBe('creator')
        ->and($data['contact_persons'][0]['name'])->toBe('Juan Camilo Gomez-Zapata')
        ->and($creatorPerson->given_name)->toBe('Juan Camilo')
        ->and($contributorPerson->given_name)->toBe('Gomez Zapata Juan');
});

test('keeps a contributor contact route while using the matched creator identity for display', function () {
    $creatorPerson = legacyLandingPerson(
        101,
        'Juan Camilo',
        'Gomez-Zapata',
        '0000-0002-1825-0097',
        'ORCID',
    );
    $contributorPerson = legacyLandingPerson(202, 'Gomez Zapata Juan', 'Camilo');
    $creator = legacyLandingCreator(11, $creatorPerson);
    $contributor = legacyLandingContributor(
        22,
        $contributorPerson,
        'juan@example.com',
        'https://example.com/juan',
    );
    $resource = legacyLandingResource([$creator], [$contributor]);

    $data = (new LandingPageResourceTransformer)->transform($resource);

    expect($data['contact_persons'])->toHaveCount(1)
        ->and($data['contact_persons'][0])->toMatchArray([
            'id' => $contributor->id,
            'source' => 'contributor',
            'name' => 'Juan Camilo Gomez-Zapata',
            'given_name' => 'Juan Camilo',
            'family_name' => 'Gomez-Zapata',
            'orcid' => '0000-0002-1825-0097',
            'website' => 'https://example.com/juan',
            'has_email' => true,
        ]);
});

test('deduplicates contributor-only contacts by valid ORCID', function () {
    $firstPerson = legacyLandingPerson(
        201,
        'Alex',
        'First',
        '0000-0002-1825-0097',
        'ORCID',
    );
    $secondPerson = legacyLandingPerson(
        202,
        'Alexandra',
        'Second',
        'https://orcid.org/0000-0002-1825-0097',
        null,
    );
    $firstContributor = legacyLandingContributor(21, $firstPerson, 'first@example.com');
    $secondContributor = legacyLandingContributor(22, $secondPerson, 'second@example.com');
    $resource = legacyLandingResource([], [$firstContributor, $secondContributor]);

    $data = (new LandingPageResourceTransformer)->transform($resource);

    expect($data['contributors'][0]['display_identity_key'])
        ->toBe($data['contributors'][1]['display_identity_key'])
        ->and($data['contact_persons'])->toHaveCount(1)
        ->and($data['contact_persons'][0]['id'])->toBe($firstContributor->id)
        ->and($data['contact_persons'][0]['source'])->toBe('contributor');
});

test('keeps ambiguous same-name legacy contacts separate', function () {
    $firstPerson = legacyLandingPerson(101, 'Alex Maria', 'Example');
    $secondPerson = legacyLandingPerson(102, 'Alex Maria', 'Example');
    $contributorPerson = legacyLandingPerson(203, 'Example Alex', 'Maria');
    $firstCreator = legacyLandingCreator(11, $firstPerson, 1, true, 'first@example.com');
    $secondCreator = legacyLandingCreator(12, $secondPerson, 2, true, 'second@example.com');
    $contributor = legacyLandingContributor(22, $contributorPerson, 'third@example.com');
    $resource = legacyLandingResource([$firstCreator, $secondCreator], [$contributor]);

    $data = (new LandingPageResourceTransformer)->transform($resource);

    expect($data['contact_persons'])->toHaveCount(3)
        ->and($data['contributors'][0]['display_identity_key'])
        ->not->toBe($data['creators'][0]['display_identity_key'])
        ->not->toBe($data['creators'][1]['display_identity_key']);
});

test('transforms inline relatedItems with titles, creators and contributors', function () {
    $transformer = new LandingPageResourceTransformer;

    $resource = new Resource;

    $relationType = new RelationType;
    $relationType->forceFill([
        'id' => 1,
        'name' => 'IsCitedBy',
        'slug' => 'IsCitedBy',
    ]);

    $title = new RelatedItemTitle;
    $title->forceFill([
        'id' => 1,
        'title' => 'A Related Journal Article',
        'title_type' => 'MainTitle',
        'language' => 'en',
    ]);

    $affiliation = new RelatedItemCreatorAffiliation;
    $affiliation->forceFill([
        'id' => 1,
        'name' => 'GFZ Potsdam',
        'affiliation_identifier' => 'https://ror.org/04z8jg394',
        'scheme' => 'ROR',
    ]);

    $creator = new RelatedItemCreator;
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

    $contribAff = new RelatedItemContributorAffiliation;
    $contribAff->forceFill([
        'id' => 2,
        'name' => 'ETH Zurich',
        'affiliation_identifier' => null,
        'scheme' => null,
    ]);

    $contributor = new RelatedItemContributor;
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

    $relatedItem = new RelatedItem;
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
        'relation_type_slug' => 'IsCitedBy',
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

test('exposes igsn_metadata, igsn_classifications and dates for IGSN resources', function () {
    $transformer = new LandingPageResourceTransformer;

    $resource = new Resource;
    $resource->forceFill(['id' => 1, 'doi' => '10.60510/igsn-child']);

    // dates
    $dateType = new DateType;
    $dateType->forceFill(['id' => 1, 'name' => 'Available', 'slug' => 'Available']);

    $date = new ResourceDate;
    $date->forceFill([
        'id' => 1,
        'date_value' => '2024-01-15',
        'start_date' => null,
        'end_date' => null,
        'date_information' => null,
    ]);
    $date->setRelation('dateType', $dateType);

    // parent landing page (published)
    $parentLandingPage = new LandingPage;
    $parentLandingPage->forceFill([
        'id' => 7,
        'slug' => 'parent-slug',
        'is_published' => true,
    ]);

    $parent = new Resource;
    $parent->forceFill(['id' => 99, 'doi' => '10.60510/igsn-parent']);
    $parent->setRelation('landingPage', $parentLandingPage);

    $igsn = new IgsnMetadata;
    $igsn->forceFill([
        'id' => 1,
        'sample_type' => 'Rock',
        'material' => 'Granite',
        'cruise_field_program' => 'Alpine 2023',
        'sample_purpose' => 'Tectonic study',
        'collection_method' => 'Drilling',
        'collection_method_description' => null,
        'platform_type' => 'Drill Rig',
        'platform_name' => 'MSR Punto',
        'platform_description' => 'UDR',
        'current_archive' => 'BGR Berlin',
        'current_archive_contact' => 'Tina Kollaske <Tina.Kollaske@bgr.de>',
        'description_json' => [
            'description_groups' => [['entries' => [
                ['value' => 'Fine-grained basalt', 'scheme' => 'Rock Type'],
            ]]],
            'material_descriptions' => ['Fine-grained basalt'],
            'comments' => ['Stored frozen'],
            'original_archive' => 'Legacy Core Archive',
            'original_archive_contact' => 'archive@example.org',
        ],
    ]);
    $igsn->setRelation('parentResource', $parent);

    $classificationA = new IgsnClassification;
    $classificationA->forceFill([
        'id' => 1,
        'value' => 'Igneous',
        'classification_type' => IgsnClassificationType::ROCK,
        'position' => 1,
    ]);
    $classificationB = new IgsnClassification;
    $classificationB->forceFill([
        'id' => 2,
        'value' => 'Plutonic',
        'classification_type' => IgsnClassificationType::ROCK,
        'position' => 2,
    ]);

    $resource->setRelation('titles', new EloquentCollection);
    $resource->setRelation('creators', new EloquentCollection);
    $resource->setRelation('contributors', new EloquentCollection);
    $resource->setRelation('relatedIdentifiers', new EloquentCollection);
    $resource->setRelation('descriptions', new EloquentCollection);
    $resource->setRelation('fundingReferences', new EloquentCollection);
    $resource->setRelation('subjects', new EloquentCollection);
    $resource->setRelation('geoLocations', new EloquentCollection);
    $resource->setRelation('rights', new EloquentCollection);
    $resource->setRelation('dates', new EloquentCollection([$date]));
    $resource->setRelation('igsnMetadata', $igsn);
    $resource->setRelation('igsnClassifications', new EloquentCollection([$classificationB, $classificationA]));

    $data = $transformer->transform($resource);

    expect($data)->toHaveKey('dates')
        ->and($data['dates'])->toHaveCount(1)
        ->and($data['dates'][0])->toMatchArray([
            'date_type' => 'Available',
            'date_type_slug' => 'Available',
            'date_value' => '2024-01-15',
        ]);

    expect($data)->toHaveKey('igsn_metadata')
        ->and($data['igsn_metadata'])->toMatchArray([
            'sample_type' => 'Rock',
            'material' => 'Granite',
            'cruise_field_program' => 'Alpine 2023',
            'sample_purpose' => 'Tectonic study',
            'collection_method' => 'Drilling',
            'description_groups' => [['entries' => [
                ['value' => 'Fine-grained basalt', 'scheme' => 'Rock Type'],
            ]]],
            'material_descriptions' => ['Fine-grained basalt'],
            'comments' => ['Stored frozen'],
            'platform_type' => 'Drill Rig',
            'platform_name' => 'MSR Punto',
            'platform_description' => 'UDR',
            'current_archive_contact' => 'Tina Kollaske',
            'original_archive_contact' => 'Legacy Core Archive contact',
            'repository_contacts' => [
                ['type' => 'current', 'label' => 'Tina Kollaske', 'has_email' => true],
                ['type' => 'original', 'label' => 'Legacy Core Archive contact', 'has_email' => true],
            ],
        ])
        ->and($data['igsn_metadata']['parent'])->toMatchArray([
            'doi' => '10.60510/igsn-parent',
            'igsn' => 'IGSN-PARENT',
        ])
        ->and($data['igsn_metadata']['parent']['landing_page'])->toBeArray()
        ->and($data['igsn_metadata']['parent']['landing_page'])->toHaveKey('public_url')
        ->and(json_encode($data['igsn_metadata']))->not->toContain('Tina.Kollaske@bgr.de')
        ->not->toContain('archive@example.org');

    // sorted by position ascending
    expect($data)->toHaveKey('igsn_classifications')
        ->and($data['igsn_classifications'])->toHaveCount(2)
        ->and($data['igsn_classifications'][0]['value'])->toBe('Igneous')
        ->and($data['igsn_classifications'][0]['classification_type'])->toBe('rock')
        ->and($data['igsn_classifications'][1]['value'])->toBe('Plutonic');
});

test('provides an unschemed description group for legacy flat IGSN descriptions', function () {
    $transformer = new LandingPageResourceTransformer;
    $resource = new Resource;
    $resource->forceFill(['id' => 1, 'doi' => '10.60510/legacy-description']);
    $igsn = new IgsnMetadata;
    $igsn->forceFill([
        'description_json' => ['material_descriptions' => ['Legacy value']],
    ]);
    $igsn->setRelation('parentResource', null);

    foreach (['titles', 'creators', 'contributors', 'relatedIdentifiers', 'descriptions', 'fundingReferences', 'subjects', 'geoLocations', 'rights', 'dates', 'igsnClassifications', 'igsnGeologicalUnits', 'alternateIdentifiers', 'sizes'] as $relation) {
        $resource->setRelation($relation, new EloquentCollection);
    }
    $resource->setRelation('igsnMetadata', $igsn);

    expect($transformer->transform($resource)['igsn_metadata']['description_groups'])->toBe([
        ['entries' => [['value' => 'Legacy value', 'scheme' => null]]],
    ]);
});

test('exposes locality description separately from location description', function () {
    $transformer = new LandingPageResourceTransformer;
    $resource = new Resource;
    $geo = new GeoLocation;
    $geo->forceFill([
        'id' => 1,
        'location_description' => 'General location',
        'locality_description' => 'Detailed locality',
    ]);

    foreach (['titles', 'creators', 'contributors', 'relatedIdentifiers', 'descriptions', 'fundingReferences', 'subjects', 'rights'] as $relation) {
        $resource->setRelation($relation, new EloquentCollection);
    }
    $resource->setRelation('geoLocations', new EloquentCollection([$geo]));

    expect($transformer->transform($resource)['geo_locations'][0])->toMatchArray([
        'location_description' => 'General location',
        'locality_description' => 'Detailed locality',
    ]);
});

test('omits parent landing_page when parent is unpublished', function () {
    $transformer = new LandingPageResourceTransformer;

    $resource = new Resource;
    $resource->forceFill(['id' => 1]);

    $parentLandingPage = new LandingPage;
    $parentLandingPage->forceFill(['id' => 7, 'slug' => 'parent-slug', 'is_published' => false]);

    $parent = new Resource;
    $parent->forceFill(['id' => 99, 'doi' => '10.60510/igsn-parent']);
    $parent->setRelation('landingPage', $parentLandingPage);

    $igsn = new IgsnMetadata;
    $igsn->forceFill(['id' => 1, 'sample_type' => 'Rock']);
    $igsn->setRelation('parentResource', $parent);

    $resource->setRelation('titles', new EloquentCollection);
    $resource->setRelation('creators', new EloquentCollection);
    $resource->setRelation('contributors', new EloquentCollection);
    $resource->setRelation('relatedIdentifiers', new EloquentCollection);
    $resource->setRelation('descriptions', new EloquentCollection);
    $resource->setRelation('fundingReferences', new EloquentCollection);
    $resource->setRelation('subjects', new EloquentCollection);
    $resource->setRelation('geoLocations', new EloquentCollection);
    $resource->setRelation('rights', new EloquentCollection);
    $resource->setRelation('dates', new EloquentCollection);
    $resource->setRelation('igsnMetadata', $igsn);
    $resource->setRelation('igsnClassifications', new EloquentCollection);

    $data = $transformer->transform($resource);

    expect($data['igsn_metadata']['parent']['doi'])->toBe('10.60510/igsn-parent');
    expect($data['igsn_metadata']['parent']['landing_page'])->toBeNull();
});

test('exposes the complete persisted IGSN sample family in the frontend contract', function (): void {
    $root = Resource::factory()->create([
        'doi' => '10.60510/transformer-root',
        'identifier_type' => 'IGSN',
    ]);
    $child = Resource::factory()->create([
        'doi' => '10.60510/transformer-child',
        'identifier_type' => 'IGSN',
    ]);
    IgsnMetadata::query()->create([
        'resource_id' => $root->id,
        'sample_type' => 'Hole',
        'upload_status' => IgsnMetadata::STATUS_REGISTERED,
    ]);
    IgsnMetadata::query()->create([
        'resource_id' => $child->id,
        'parent_resource_id' => $root->id,
        'sample_type' => 'Core',
        'upload_status' => IgsnMetadata::STATUS_REGISTERED,
    ]);
    AlternateIdentifier::query()->create([
        'resource_id' => $root->id,
        'value' => 'Root sample',
        'type' => 'Local accession number',
        'position' => 0,
    ]);
    AlternateIdentifier::query()->create([
        'resource_id' => $child->id,
        'value' => 'Child sample',
        'type' => 'Local accession number',
        'position' => 0,
    ]);
    LandingPage::factory()->published()->create([
        'resource_id' => $root->id,
        'doi_prefix' => $root->doi,
        'slug' => 'transformer-root',
    ]);
    LandingPage::factory()->draft()->create([
        'resource_id' => $child->id,
        'doi_prefix' => $child->doi,
        'slug' => 'transformer-child',
    ]);

    $transformer = new LandingPageResourceTransformer;
    $child->load($transformer->requiredRelations());
    $data = $transformer->transform($child);

    expect($data['igsn_sample_family']['member_count'])->toBe(2)
        ->and($data['igsn_sample_family']['root']['resource_id'])->toBe($root->id)
        ->and($data['igsn_sample_family']['root']['name'])->toBe('Root sample')
        ->and($data['igsn_sample_family']['root']['igsn'])->toBe('TRANSFORMER-ROOT')
        ->and($data['igsn_sample_family']['root']['sample_type'])->toBe('Hole')
        ->and($data['igsn_sample_family']['root']['landing_page'])->toHaveKey('public_url')
        ->and($data['igsn_sample_family']['root']['children'][0])->toMatchArray([
            'resource_id' => $child->id,
            'name' => 'Child sample',
            'igsn' => 'TRANSFORMER-CHILD',
            'sample_type' => 'Core',
            'landing_page' => null,
        ]);
});
