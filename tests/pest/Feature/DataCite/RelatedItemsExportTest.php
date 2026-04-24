<?php

declare(strict_types=1);

use App\Models\RelatedItem;
use App\Models\RelatedItemContributor;
use App\Models\RelatedItemContributorAffiliation;
use App\Models\RelatedItemCreator;
use App\Models\RelatedItemCreatorAffiliation;
use App\Models\RelatedItemTitle;
use App\Models\RelationType;
use App\Models\Resource;
use App\Services\DataCiteJsonExporter;
use App\Services\DataCiteLinkedDataExporter;
use App\Services\DataCiteXmlExporter;
use App\Services\SchemaOrgJsonLdExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makeResourceWithRelatedItem(): Resource
{
    $resource = Resource::factory()->create();
    $relationType = RelationType::firstOrCreate(
        ['slug' => 'Cites'],
        ['name' => 'Cites', 'is_active' => true]
    );
    $item = RelatedItem::factory()->create([
        'resource_id' => $resource->id,
        'related_item_type' => 'JournalArticle',
        'relation_type_id' => $relationType->id,
        'publication_year' => 2023,
        'volume' => '12',
        'issue' => '3',
        'first_page' => '101',
        'last_page' => '115',
        'publisher' => 'Journal of Science',
        'edition' => '1st',
        'identifier' => '10.1234/xyz',
        'identifier_type' => 'DOI',
    ]);
    RelatedItemTitle::factory()->create([
        'related_item_id' => $item->id,
        'title' => 'The Main Title',
        'title_type' => 'MainTitle',
        'position' => 0,
    ]);
    RelatedItemTitle::factory()->create([
        'related_item_id' => $item->id,
        'title' => 'A Subtitle',
        'title_type' => 'Subtitle',
        'position' => 1,
    ]);
    $creator = RelatedItemCreator::factory()->create([
        'related_item_id' => $item->id,
        'name_type' => 'Personal',
        'name' => 'Doe, Jane',
        'given_name' => 'Jane',
        'family_name' => 'Doe',
        'name_identifier' => '0000-0001-0002-0003',
        'name_identifier_scheme' => 'ORCID',
        'position' => 0,
    ]);
    RelatedItemCreatorAffiliation::factory()->create([
        'related_item_creator_id' => $creator->id,
        'name' => 'GFZ Helmholtz Centre',
        'affiliation_identifier' => 'https://ror.org/04z8jg394',
        'scheme' => 'ROR',
        'position' => 0,
    ]);
    $contrib = RelatedItemContributor::factory()->create([
        'related_item_id' => $item->id,
        'contributor_type' => 'Editor',
        'name_type' => 'Personal',
        'name' => 'Smith, John',
        'given_name' => 'John',
        'family_name' => 'Smith',
        'position' => 0,
    ]);
    RelatedItemContributorAffiliation::factory()->create([
        'related_item_contributor_id' => $contrib->id,
        'name' => 'University X',
        'position' => 0,
    ]);

    return $resource->fresh();
}

describe('DataCiteXmlExporter — relatedItems', function () {
    test('emits <relatedItems> with a complete <relatedItem> block', function () {
        $resource = makeResourceWithRelatedItem();
        $xml = (new DataCiteXmlExporter())->export($resource);

        $dom = new DOMDocument();
        expect($dom->loadXML($xml))->toBeTrue();

        expect($xml)->toContain('<relatedItems>')
            ->toContain('<relatedItem relatedItemType="JournalArticle" relationType="Cites">')
            ->toContain('<relatedItemIdentifier relatedItemIdentifierType="DOI">10.1234/xyz</relatedItemIdentifier>')
            ->toContain('<title>The Main Title</title>')
            ->toContain('<title titleType="Subtitle">A Subtitle</title>')
            ->toContain('<publicationYear>2023</publicationYear>')
            ->toContain('<volume>12</volume>')
            ->toContain('<issue>3</issue>')
            ->toContain('<firstPage>101</firstPage>')
            ->toContain('<lastPage>115</lastPage>')
            ->toContain('<publisher>Journal of Science</publisher>')
            ->toContain('<edition>1st</edition>')
            ->toContain('<creatorName nameType="Personal">Doe, Jane</creatorName>')
            ->toContain('<givenName>Jane</givenName>')
            ->toContain('<familyName>Doe</familyName>')
            ->toContain('nameIdentifierScheme="ORCID"')
            ->toContain('<contributor contributorType="Editor">')
            ->toContain('<contributorName nameType="Personal">Smith, John</contributorName>')
            ->toContain('affiliationIdentifier="https://ror.org/04z8jg394"')
            ->toContain('affiliationIdentifierScheme="ROR"');
    });

    test('omits <relatedItems> when resource has none', function () {
        $resource = Resource::factory()->create();
        $xml = (new DataCiteXmlExporter())->export($resource);

        expect($xml)->not->toContain('<relatedItems>');
    });
});

describe('DataCiteJsonExporter — relatedItems', function () {
    test('includes relatedItems in the attributes block', function () {
        $resource = makeResourceWithRelatedItem();
        $json = (new DataCiteJsonExporter())->export($resource);

        $attrs = $json['data']['attributes'];
        expect($attrs)->toHaveKey('relatedItems');
        expect($attrs['relatedItems'])->toHaveCount(1);

        $ri = $attrs['relatedItems'][0];
        expect($ri['relatedItemType'])->toBe('JournalArticle');
        expect($ri['relationType'])->toBe('Cites');
        expect($ri['titles'])->toContain(['title' => 'The Main Title']);
        expect($ri['titles'])->toContain(['title' => 'A Subtitle', 'titleType' => 'Subtitle']);
        expect($ri['relatedItemIdentifier'])->toMatchArray([
            'relatedItemIdentifier' => '10.1234/xyz',
            'relatedItemIdentifierType' => 'DOI',
        ]);
        expect($ri['volume'])->toBe('12');
        expect($ri['issue'])->toBe('3');
        expect($ri['firstPage'])->toBe('101');
        expect($ri['lastPage'])->toBe('115');
        expect($ri['publisher'])->toBe('Journal of Science');
        expect($ri['publicationYear'])->toBe('2023');
        expect($ri['creators'][0])->toMatchArray([
            'name' => 'Doe, Jane',
            'nameType' => 'Personal',
            'givenName' => 'Jane',
            'familyName' => 'Doe',
        ]);
        expect($ri['creators'][0]['nameIdentifiers'][0])->toMatchArray([
            'nameIdentifier' => '0000-0001-0002-0003',
            'nameIdentifierScheme' => 'ORCID',
        ]);
        expect($ri['creators'][0]['affiliation'][0])->toMatchArray([
            'name' => 'GFZ Helmholtz Centre',
            'affiliationIdentifier' => 'https://ror.org/04z8jg394',
            'affiliationIdentifierScheme' => 'ROR',
        ]);
        expect($ri['contributors'][0]['contributorType'])->toBe('Editor');
    });

    test('omits relatedItems when resource has none', function () {
        $resource = Resource::factory()->create();
        $json = (new DataCiteJsonExporter())->export($resource);

        expect($json['data']['attributes'])->not->toHaveKey('relatedItems');
    });
});

describe('DataCiteLinkedDataExporter — relatedItems', function () {
    test('includes relatedItems block with attrs + value', function () {
        $resource = makeResourceWithRelatedItem();
        $ld = (new DataCiteLinkedDataExporter())->export($resource);

        expect($ld)->toHaveKey('relatedItems');
        $ri = $ld['relatedItems']['relatedItem'];
        expect($ri['attrs'])->toMatchArray([
            'relatedItemType' => 'JournalArticle',
            'relationType' => 'Cites',
        ]);
        expect($ri['value'])->toHaveKey('titles');
        expect($ri['value'])->toHaveKey('creators');
        expect($ri['value']['publisher'])->toBe('Journal of Science');
    });
});

describe('SchemaOrgJsonLdExporter — citations', function () {
    test('emits schema:citation CreativeWork for relatedItems', function () {
        $resource = makeResourceWithRelatedItem();
        $schema = (new SchemaOrgJsonLdExporter())->export($resource);

        expect($schema)->toHaveKey('citation');
        $citation = $schema['citation'][0];
        expect($citation['@type'])->toBe('CreativeWork');
        expect($citation['name'])->toBe('The Main Title');
        expect($citation['@id'])->toBe('https://doi.org/10.1234/xyz');
        expect($citation['identifier'])->toMatchArray([
            '@type' => 'PropertyValue',
            'value' => 'doi:10.1234/xyz',
        ]);
        expect($citation['publisher'])->toMatchArray([
            '@type' => 'Organization',
            'name' => 'Journal of Science',
        ]);
        expect($citation['datePublished'])->toBe('2023');
        expect($citation['description'])->toContain('Vol. 12')
            ->toContain('Issue 3')
            ->toContain('pp. 101-115');
        expect($citation['author'])->toMatchArray([
            '@type' => 'Person',
            'name' => 'Doe, Jane',
            'givenName' => 'Jane',
            'familyName' => 'Doe',
        ]);
    });
});

describe('SchemaOrgJsonLdExporter — citation edge cases', function () {
    function makeMinimalRelatedItemResource(array $relatedItemOverrides = [], array $titleOverrides = []): Resource
    {
        $resource = Resource::factory()->create();
        $relationType = RelationType::firstOrCreate(
            ['slug' => 'Cites'],
            ['name' => 'Cites', 'is_active' => true]
        );
        $item = RelatedItem::factory()->create(array_merge([
            'resource_id' => $resource->id,
            'related_item_type' => 'Book',
            'relation_type_id' => $relationType->id,
            'publication_year' => null,
            'volume' => null,
            'issue' => null,
            'first_page' => null,
            'last_page' => null,
            'publisher' => null,
            'edition' => null,
            'identifier' => null,
            'identifier_type' => null,
        ], $relatedItemOverrides));
        RelatedItemTitle::factory()->create(array_merge([
            'related_item_id' => $item->id,
            'title' => 'Some Book',
            'title_type' => 'MainTitle',
            'position' => 0,
        ], $titleOverrides));

        return $resource->fresh();
    }

    test('emits url (not @id) when identifier type is URL', function () {
        $resource = makeMinimalRelatedItemResource([
            'identifier' => 'https://example.org/paper',
            'identifier_type' => 'URL',
        ]);
        $schema = (new SchemaOrgJsonLdExporter())->export($resource);

        $citation = $schema['citation'][0];
        expect($citation['url'])->toBe('https://example.org/paper');
        expect($citation)->not->toHaveKey('@id');
    });

    test('emits PropertyValue for non-DOI/non-URL identifier types', function () {
        $resource = makeMinimalRelatedItemResource([
            'identifier' => 'urn:isbn:9781234567890',
            'identifier_type' => 'ISBN',
        ]);
        $schema = (new SchemaOrgJsonLdExporter())->export($resource);

        $citation = $schema['citation'][0];
        expect($citation['identifier'])->toMatchArray([
            '@type' => 'PropertyValue',
            'propertyID' => 'ISBN',
            'value' => 'urn:isbn:9781234567890',
        ]);
        expect($citation)->not->toHaveKey('@id');
        expect($citation)->not->toHaveKey('url');
    });

    test('emits Organization @type for organizational creators', function () {
        $resource = makeMinimalRelatedItemResource();
        $item = $resource->relatedItems->first();
        RelatedItemCreator::factory()->create([
            'related_item_id' => $item->id,
            'name_type' => 'Organizational',
            'name' => 'GFZ Helmholtz Centre',
            'given_name' => null,
            'family_name' => null,
            'position' => 0,
        ]);
        $schema = (new SchemaOrgJsonLdExporter())->export($resource->fresh());

        $citation = $schema['citation'][0];
        expect($citation['author'])->toMatchArray([
            '@type' => 'Organization',
            'name' => 'GFZ Helmholtz Centre',
        ]);
        expect($citation['author'])->not->toHaveKey('givenName');
        expect($citation['author'])->not->toHaveKey('familyName');
    });

    test('returns author as array when multiple creators exist', function () {
        $resource = makeMinimalRelatedItemResource();
        $item = $resource->relatedItems->first();
        RelatedItemCreator::factory()->create([
            'related_item_id' => $item->id,
            'name_type' => 'Personal',
            'name' => 'Alpha, A',
            'given_name' => 'A',
            'family_name' => 'Alpha',
            'position' => 0,
        ]);
        RelatedItemCreator::factory()->create([
            'related_item_id' => $item->id,
            'name_type' => 'Personal',
            'name' => 'Beta, B',
            'given_name' => 'B',
            'family_name' => 'Beta',
            'position' => 1,
        ]);
        $schema = (new SchemaOrgJsonLdExporter())->export($resource->fresh());

        $authors = $schema['citation'][0]['author'];
        expect($authors)->toBeArray()->toHaveCount(2);
        expect($authors[0]['name'])->toBe('Alpha, A');
        expect($authors[1]['name'])->toBe('Beta, B');
    });

    test('omits publisher when not set', function () {
        $resource = makeMinimalRelatedItemResource();
        $schema = (new SchemaOrgJsonLdExporter())->export($resource);

        $citation = $schema['citation'][0];
        expect($citation)->not->toHaveKey('publisher');
    });

    test('omits datePublished when publicationYear is null', function () {
        $resource = makeMinimalRelatedItemResource();
        $schema = (new SchemaOrgJsonLdExporter())->export($resource);

        $citation = $schema['citation'][0];
        expect($citation)->not->toHaveKey('datePublished');
    });

    test('emits pp. with only firstPage when lastPage missing', function () {
        $resource = makeMinimalRelatedItemResource([
            'first_page' => '42',
            'last_page' => null,
        ]);
        $schema = (new SchemaOrgJsonLdExporter())->export($resource);

        $citation = $schema['citation'][0];
        expect($citation['description'])->toBe('pp. 42');
        expect($citation['description'])->not->toContain('-');
    });

    test('omits citation key entirely when no relatedItems exist', function () {
        $resource = Resource::factory()->create();
        $schema = (new SchemaOrgJsonLdExporter())->export($resource);

        expect($schema)->not->toHaveKey('citation');
    });

    test('uses first title when no MainTitle (untyped) is present', function () {
        $resource = makeMinimalRelatedItemResource([], [
            'title' => 'Only Subtitle',
            'title_type' => 'Subtitle',
        ]);
        $schema = (new SchemaOrgJsonLdExporter())->export($resource);

        $citation = $schema['citation'][0];
        expect($citation['name'])->toBe('Only Subtitle');
    });
});
