<?php

declare(strict_types=1);

use App\Models\User;
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
        'related_item_type' => 'JournalArticle',
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
        'related_item_type' => 'Book',
        'relation_type_slug' => 'IsSupplementTo',
        'publication_year' => 2019,
    ]);
    expect($items[1]['titles'][0])->toMatchArray([
        'title' => 'A Supporting Book',
        'title_type' => 'MainTitle',
    ]);
});
