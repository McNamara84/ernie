<?php

declare(strict_types=1);

use App\Models\ResourceType;
use App\Models\User;
use Database\Seeders\ResourceTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

const RELATED_ITEMS_XML = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4"
          xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
          xsi:schemaLocation="http://datacite.org/schema/kernel-4 http://schema.datacite.org/meta/kernel-4.7/metadata.xsd">
  <identifier identifierType="DOI">10.5880/pik.2021.001</identifier>
  <creators>
    <creator><creatorName>Doe, Jane</creatorName></creator>
  </creators>
  <titles>
    <title>Test Dataset</title>
  </titles>
  <publisher>GFZ Data Services</publisher>
  <publicationYear>2021</publicationYear>
  <resourceType resourceTypeGeneral="Dataset">Dataset</resourceType>
  <relatedItems>
    <relatedItem relatedItemType="JournalArticle" relationType="IsCitedBy">
      <relatedItemIdentifier relatedItemIdentifierType="DOI">10.1234/example</relatedItemIdentifier>
      <creators>
        <creator>
          <creatorName nameType="Personal">Smith, John</creatorName>
          <givenName>John</givenName>
          <familyName>Smith</familyName>
          <affiliation affiliationIdentifier="https://ror.org/04z8jg394"
                       affiliationIdentifierScheme="ROR">GFZ Helmholtz Centre</affiliation>
        </creator>
      </creators>
      <titles>
        <title>Main Article Title</title>
        <title titleType="Subtitle">A descriptive subtitle</title>
      </titles>
      <publicationYear>2020</publicationYear>
      <volume>12</volume>
      <issue>3</issue>
      <number numberType="Article">42</number>
      <firstPage>101</firstPage>
      <lastPage>115</lastPage>
      <publisher>Journal of Science</publisher>
      <edition>2nd</edition>
      <contributors>
        <contributor contributorType="Editor">
          <contributorName nameType="Personal">Roe, Alex</contributorName>
        </contributor>
      </contributors>
    </relatedItem>
    <relatedItem relatedItemType="Book" relationType="IsSupplementTo">
      <titles>
        <title>A Supporting Book</title>
      </titles>
      <publicationYear>2019</publicationYear>
    </relatedItem>
    <relatedItem relatedItemType="Book" relationType="Cites">
      <!-- Missing titles on purpose — must be skipped -->
      <publicationYear>2018</publicationYear>
    </relatedItem>
  </relatedItems>
</resource>
XML;

test('extracts DataCite 4.7 <relatedItems> from uploaded XML', function () {
    $this->actingAs(User::factory()->create());

    $file = UploadedFile::fake()->createWithContent('with-related-items.xml', RELATED_ITEMS_XML);

    $response = $this->postJson('/dashboard/upload-xml', ['file' => $file])->assertOk();

    $payload = session()->get($response->json('sessionKey'));
    expect($payload)->toHaveKey('relatedItems');

    $items = $payload['relatedItems'];
    expect($items)->toHaveCount(2);

    // First item — rich metadata.
    expect($items[0])->toMatchArray([
        'related_item_type' => 'journal-article',
        'relation_type_slug' => 'IsCitedBy',
        'publication_year' => 2020,
        'volume' => '12',
        'issue' => '3',
        'first_page' => '101',
        'last_page' => '115',
        'publisher' => 'Journal of Science',
        'edition' => '2nd',
        'identifier' => '10.1234/example',
        'identifier_type' => 'DOI',
        'number' => '42',
        'number_type' => 'Article',
    ]);

    expect($items[0]['titles'])->toHaveCount(2);
    expect($items[0]['titles'][0])->toMatchArray([
        'title' => 'Main Article Title',
        'title_type' => 'MainTitle',
    ]);
    expect($items[0]['titles'][1])->toMatchArray([
        'title' => 'A descriptive subtitle',
        'title_type' => 'Subtitle',
    ]);

    expect($items[0]['creators'])->toHaveCount(1);
    expect($items[0]['creators'][0])->toMatchArray([
        'name_type' => 'Personal',
        'name' => 'Smith, John',
        'given_name' => 'John',
        'family_name' => 'Smith',
    ]);
    expect($items[0]['creators'][0]['affiliations'][0])->toMatchArray([
        'name' => 'GFZ Helmholtz Centre',
        'affiliation_identifier' => 'https://ror.org/04z8jg394',
        'scheme' => 'ROR',
    ]);

    expect($items[0]['contributors'])->toHaveCount(1);
    expect($items[0]['contributors'][0])->toMatchArray([
        'contributor_type' => 'Editor',
        'name' => 'Roe, Alex',
    ]);

    // Second item — minimal, title promoted to MainTitle.
    expect($items[1])->toMatchArray([
        'related_item_type' => 'book',
        'relation_type_slug' => 'IsSupplementTo',
        'publication_year' => 2019,
    ]);
    expect($items[1]['titles'][0])->toMatchArray([
        'title' => 'A Supporting Book',
        'title_type' => 'MainTitle',
    ]);
});

/**
 * Helper: wrap arbitrary <relatedItem> XML in a minimal valid DataCite resource
 * and upload it, returning the parsed `relatedItems` array from the session.
 *
 * @return array<int, array<string, mixed>>
 */
function uploadRelatedItemsXml(string $relatedItemXml): array
{
    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4"
          xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
          xsi:schemaLocation="http://datacite.org/schema/kernel-4 http://schema.datacite.org/meta/kernel-4.7/metadata.xsd">
  <identifier identifierType="DOI">10.5880/pik.2021.999</identifier>
  <creators><creator><creatorName>Doe, Jane</creatorName></creator></creators>
  <titles><title>Test Dataset</title></titles>
  <publisher>GFZ</publisher>
  <publicationYear>2021</publicationYear>
  <resourceType resourceTypeGeneral="Dataset">Dataset</resourceType>
  <relatedItems>
{$relatedItemXml}
  </relatedItems>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('ri.xml', $xml);
    $response = test()->postJson('/dashboard/upload-xml', ['file' => $file])->assertOk();
    $payload = session()->get($response->json('sessionKey'));

    return $payload['relatedItems'] ?? [];
}

describe('extractRelatedItems edge cases', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->create());
    });

    test('skips relatedItem missing relationType attribute', function () {
        $items = uploadRelatedItemsXml(<<<'XML'
    <relatedItem relatedItemType="Book">
      <titles><title>No relationType</title></titles>
    </relatedItem>
XML);

        expect($items)->toBeEmpty();
    });

    test('skips relatedItem missing relatedItemType attribute', function () {
        $items = uploadRelatedItemsXml(<<<'XML'
    <relatedItem relationType="Cites">
      <titles><title>No relatedItemType</title></titles>
    </relatedItem>
XML);

        expect($items)->toBeEmpty();
    });

    test('accepts <number> without numberType attribute', function () {
        $items = uploadRelatedItemsXml(<<<'XML'
    <relatedItem relatedItemType="Book" relationType="Cites">
      <titles><title>With bare number</title></titles>
      <number>17</number>
    </relatedItem>
XML);

        expect($items)->toHaveCount(1);
        expect($items[0]['number'])->toBe('17');
        expect($items[0]['number_type'])->toBeNull();
    });

    test('captures all optional identifier scheme attributes', function () {
        $items = uploadRelatedItemsXml(<<<'XML'
    <relatedItem relatedItemType="Dataset" relationType="Cites">
      <titles><title>Full identifier</title></titles>
      <relatedItemIdentifier relatedItemIdentifierType="URL"
                             relatedMetadataScheme="DataCite"
                             schemeURI="https://schema.datacite.org/"
                             schemeType="XSD">https://example.org/data</relatedItemIdentifier>
    </relatedItem>
XML);

        expect($items)->toHaveCount(1);
        expect($items[0])->toMatchArray([
            'identifier' => 'https://example.org/data',
            'identifier_type' => 'URL',
            'related_metadata_scheme' => 'DataCite',
            'scheme_uri' => 'https://schema.datacite.org/',
            'scheme_type' => 'XSD',
        ]);
    });

    test('skips empty-string identifier element', function () {
        $items = uploadRelatedItemsXml(<<<'XML'
    <relatedItem relatedItemType="Book" relationType="Cites">
      <titles><title>Empty id</title></titles>
      <relatedItemIdentifier relatedItemIdentifierType="DOI">   </relatedItemIdentifier>
    </relatedItem>
XML);

        expect($items)->toHaveCount(1);
        expect($items[0])->not->toHaveKey('identifier');
        expect($items[0])->not->toHaveKey('identifier_type');
    });

    test('accepts creator nameIdentifier without scheme attribute', function () {
        $items = uploadRelatedItemsXml(<<<'XML'
    <relatedItem relatedItemType="Book" relationType="Cites">
      <titles><title>Bare identifier</title></titles>
      <creators>
        <creator>
          <creatorName>Smith, J</creatorName>
          <nameIdentifier>X-123</nameIdentifier>
        </creator>
      </creators>
    </relatedItem>
XML);

        expect($items[0]['creators'][0])->toMatchArray([
            'name' => 'Smith, J',
            'name_identifier' => 'X-123',
        ]);
        expect($items[0]['creators'][0])->not->toHaveKey('name_identifier_scheme');
    });

    test('drops contributor without contributorType attribute', function () {
        $items = uploadRelatedItemsXml(<<<'XML'
    <relatedItem relatedItemType="Book" relationType="Cites">
      <titles><title>Bad contributor</title></titles>
      <contributors>
        <contributor>
          <contributorName>No Type, Person</contributorName>
        </contributor>
        <contributor contributorType="Editor">
          <contributorName>Editor, One</contributorName>
        </contributor>
      </contributors>
    </relatedItem>
XML);

        expect($items[0]['contributors'])->toHaveCount(1);
        expect($items[0]['contributors'][0]['name'])->toBe('Editor, One');
    });

    test('ignores non-numeric publicationYear', function () {
        $items = uploadRelatedItemsXml(<<<'XML'
    <relatedItem relatedItemType="Book" relationType="Cites">
      <titles><title>Bad year</title></titles>
      <publicationYear>not-a-year</publicationYear>
    </relatedItem>
XML);

        expect($items[0]['publication_year'])->toBeNull();
    });

    test('defaults creator nameType to Personal when absent', function () {
        $items = uploadRelatedItemsXml(<<<'XML'
    <relatedItem relatedItemType="Book" relationType="Cites">
      <titles><title>No nameType</title></titles>
      <creators>
        <creator><creatorName>Anonymous</creatorName></creator>
      </creators>
    </relatedItem>
XML);

        expect($items[0]['creators'][0]['name_type'])->toBe('Personal');
    });

    test('normalises DataCite PascalCase relatedItemType to the kebab-case slug stored by ResourceTypeSeeder (Issue: PR #679 review)', function () {
        // ResourceTypeSeeder seeds slugs via Str::slug($name), e.g.
        //   "Journal Article" → "journal-article"
        //   "Conference Paper" → "conference-paper"
        //   "Book Chapter" → "book-chapter"
        // DataCite XML, however, carries the PascalCase form ("JournalArticle",
        // "ConferencePaper", "BookChapter"). Without normalisation
        // `StoreResourceRequest`'s `Rule::exists('resource_types', 'slug')`
        // would reject every imported related item in production.
        $items = uploadRelatedItemsXml(<<<'XML'
    <relatedItem relatedItemType="JournalArticle" relationType="Cites">
      <titles><title>JA</title></titles>
    </relatedItem>
    <relatedItem relatedItemType="ConferencePaper" relationType="Cites">
      <titles><title>CP</title></titles>
    </relatedItem>
    <relatedItem relatedItemType="BookChapter" relationType="Cites">
      <titles><title>BC</title></titles>
    </relatedItem>
    <relatedItem relatedItemType="Book" relationType="Cites">
      <titles><title>B</title></titles>
    </relatedItem>
XML);

        expect($items)->toHaveCount(4);
        expect($items[0]['related_item_type'])->toBe('journal-article');
        expect($items[1]['related_item_type'])->toBe('conference-paper');
        expect($items[2]['related_item_type'])->toBe('book-chapter');
        expect($items[3]['related_item_type'])->toBe('book');

        // Sanity: every value must exist in the production-seeded
        // resource_types.slug column.
        $this->seed(ResourceTypeSeeder::class);
        $existing = ResourceType::query()->pluck('slug')->all();
        foreach ($items as $item) {
            expect($existing)->toContain($item['related_item_type']);
        }
    });
});
