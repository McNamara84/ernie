<?php

use App\Models\ResourceType;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('extracts doi, publication year, version, language, resource type and titles from uploaded xml, ignoring related item titles', function () {
    $this->actingAs(User::factory()->create());
    $type = ResourceType::create(['name' => 'Dataset', 'slug' => 'dataset']);

    $xml = <<<'XML'
<resource>
    <identifier identifierType="DOI">10.1234/xyz</identifier>
    <publicationYear>2024</publicationYear>
    <version>1.0</version>
    <language>de</language>
    <rightsList>
        <rights rightsURI="https://creativecommons.org/licenses/by/4.0/legalcode" rightsIdentifier="CC-BY-4.0" rightsIdentifierScheme="SPDX" schemeURI="https://spdx.org/licenses/">Creative Commons Attribution 4.0 International</rights>
        <rights rightsIdentifier="MIT" rightsIdentifierScheme="SPDX" schemeURI="https://spdx.org/licenses/">MIT License</rights>
    </rightsList>
    <titles>
        <title>Example Title</title>
        <title titleType="Subtitle">Example Subtitle</title>
        <title titleType="TranslatedTitle">Example TranslatedTitle</title>
        <title titleType="AlternativeTitle">Example AlternativeTitle</title>
    </titles>
    <relatedItem>
        <titles>
            <title>Example RelatedItem Title</title>
            <title titleType="TranslatedTitle">Example RelatedItem TranslatedTitle</title>
        </titles>
    </relatedItem>
    <resourceType resourceTypeGeneral="Dataset">Dataset</resourceType>
</resource>
XML;
    $file = UploadedFile::fake()->createWithContent('test.xml', $xml);

    $response = $this->post(route('dashboard.upload-xml'), [
        'file' => $file,
        '_token' => csrf_token(),
    ]);

    $response->assertSessionData([
        'doi' => '10.1234/xyz',
        'year' => '2024',
        'version' => '1.0',
        'language' => 'de',
        'resourceType' => (string) $type->id,
        'titles' => [
            ['title' => 'Example Title', 'titleType' => 'main-title'],
            ['title' => 'Example Subtitle', 'titleType' => 'subtitle'],
            ['title' => 'Example TranslatedTitle', 'titleType' => 'translated-title'],
            ['title' => 'Example AlternativeTitle', 'titleType' => 'alternative-title'],
        ],
        'licenses' => ['CC-BY-4.0', 'MIT'],
        'authors' => [],
    ]);
});

it('returns null when doi, publication year, version, language and resource type are missing', function () {
    $this->actingAs(User::factory()->create());

    $xml = '<resource></resource>';
    $file = UploadedFile::fake()->createWithContent('test.xml', $xml);

    $response = $this->post(route('dashboard.upload-xml'), [
        'file' => $file,
        '_token' => csrf_token(),
    ]);

    $response->assertSessionData(['doi' => null, 'year' => null, 'version' => null, 'language' => null, 'resourceType' => null, 'titles' => [], 'licenses' => [], 'authors' => []]);
});

it('handles xml with a single main title', function () {
    $this->actingAs(User::factory()->create());

    $xml = '<resource><titles><title xml:lang="en">A mandatory Event</title></titles></resource>';
    $file = UploadedFile::fake()->createWithContent('test.xml', $xml);

    $response = $this->post(route('dashboard.upload-xml'), [
        'file' => $file,
        '_token' => csrf_token(),
    ]);

    $response->assertSessionData([
        'titles' => [
            ['title' => 'A mandatory Event', 'titleType' => 'main-title'],
        ],
        'licenses' => [],
    ]);
});

it('extracts main title from namespaced DataCite xml wrapped in an envelope element', function () {
    // This test verifies that XPath queries using local-name() correctly extract data
    // when the DataCite resource element is nested inside a parent envelope element,
    // which is common when XML is exported from systems that bundle multiple schemas.
    $this->actingAs(User::factory()->create());
    $type = ResourceType::create(['name' => 'Book', 'slug' => 'book']);

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<envelope>
    
<resource xmlns="http://datacite.org/schema/kernel-4" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://datacite.org/schema/kernel-4 file:///C:/xampp/htdocs/msl-mde/schemas/DataCite/DataCiteSchema45.xsd">
  <identifier identifierType="DOI"/>
  <creators>
    <creator>
      <creatorName nameType="Personal">Hernandez, Sofia</creatorName>
      <givenName>Sofia</givenName>
      <familyName>Hernandez</familyName>
      <nameIdentifier nameIdentifierScheme="ORCID" schemeURI="https://orcid.org/">0000-0002-2771-9344</nameIdentifier>
    </creator>
  </creators>
  <titles>
    <title xml:lang="en">A mandatory Event</title>
  </titles>
  <publisher xml:lang="en">GFZ Data Services</publisher>
  <publicationYear>1956</publicationYear>
  <resourceType resourceTypeGeneral="Book">Dataset</resourceType>
  <subjects/>
  <dates>
    <date dateType="Created">2025-02-10</date>
  </dates>
  <language>en</language>
  <rightsList>
    <rights rightsURI="https://creativecommons.org/licenses/by/4.0/legalcode" rightsIdentifier="CC-BY-4.0" rightsIdentifierScheme="SPDX" schemeURI="https://spdx.org/licenses/" xml:lang="en">Creative Commons Attribution 4.0 International</rights>
  </rightsList>
  <descriptions>
    <description descriptionType="Abstract" xml:lang="en">dsfdf</description>
  </descriptions>
  <geoLocations/>
</resource>


    
<MD_Metadata xmlns="http://www.isotc211.org/2005/gmd" xmlns:gco="http://www.isotc211.org/2005/gco" xmlns:gsr="http://www.isotc211.org/2005/gsr" xmlns:gss="http://www.isotc211.org/2005/gss" xmlns:gts="http://www.isotc211.org/2005/gts" xmlns:gml="http://www.opengis.net/gml" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.isotc211.org/2005/gmd file:///C:/xampp/htdocs/msl-mde/schemas/ISO/gmd.xsd">
  <fileIdentifier/>
  <language>
    <LanguageCode codeList="http://www.loc.gov/standards/iso639-1/" codeListValue="en">en</LanguageCode>
  </language>
  <characterSet>
    <MD_CharacterSetCode codeList="http://www.isotc211.org/2005/resources/codeList.xml#MD_CharacterSetCode" codeListValue="utf8"/>
  </characterSet>
  <hierarchyLevel>
    <MD_ScopeCode codeList="http://www.isotc211.org/2005/resources/Codelist/gmxCodelists.xml#MD_ScopeCode" codeListValue="dataset"/>
  </hierarchyLevel>
  <contact>
    <CI_ResponsibleParty>
      <organisationName>
        <gco:CharacterString>GFZ German Research Center for Geosciences</gco:CharacterString>
      </organisationName>
      <contactInfo>
        <CI_Contact>
          <onlineResource>
            <CI_OnlineResource>
              <linkage>
                <URL>https://www.gfz-potsdam.de/</URL>
              </linkage>
              <function>
                <CI_OnLineFunctionCode codeList="http://www.isotc211.org/2005/resources/Codelist/gmxCodelists.xml#CI_OnLineFunctionCode" codeListValue="information">information</CI_OnLineFunctionCode>
              </function>
            </CI_OnlineResource>
          </onlineResource>
        </CI_Contact>
      </contactInfo>
      <role>
        <CI_RoleCode codeList="http://www.isotc211.org/2005/resources/Codelist/gmxCodelists.xml#CI_RoleCode" codeListValue="pointOfContact">pointOfContact</CI_RoleCode>
      </role>
    </CI_ResponsibleParty>
  </contact>
  <dateStamp>
    <gco:Date>2025-02-10</gco:Date>
  </dateStamp>
  <referenceSystemInfo>
    <MD_ReferenceSystem>
      <referenceSystemIdentifier>
        <RS_Identifier>
          <code>
            <gco:CharacterString>urn:ogc:def:crs:EPSG:4326</gco:CharacterString>
          </code>
        </RS_Identifier>
      </referenceSystemIdentifier>
    </MD_ReferenceSystem>
  </referenceSystemInfo>
  <identificationInfo>
    <MD_DataIdentification>
      <citation>
        <CI_Citation>
          <title>
            <gco:CharacterString>A mandatory Event</gco:CharacterString>
          </title>
          <date>
            <CI_Date>
              <date>
                <gco:Date>2025-02-10</gco:Date>
              </date>
              <dateType>
                <CI_DateTypeCode codeList="http://www.isotc211.org/2005/resources/Codelist/gmxCodelists.xml#CI_DateTypeCode" codeListValue="creation">creation</CI_DateTypeCode>
              </dateType>
            </CI_Date>
          </date>
          <citedResponsibleParty xlink:href="http://orcid.org/0000-0002-2771-9344">
            <CI_ResponsibleParty>
              <individualName>
                <gco:CharacterString>Hernandez, Sofia</gco:CharacterString>
              </individualName>
              <role>
                <CI_RoleCode codeList="http://www.isotc211.org/2005/resources/Codelist/gmxCodelists.xml#CI_RoleCode" codeListValue="author">author</CI_RoleCode>
              </role>
            </CI_ResponsibleParty>
          </citedResponsibleParty>
        </CI_Citation>
      </citation>
      <abstract>
        <gco:CharacterString>dsfdf</gco:CharacterString>
      </abstract>
      <status>
        <MD_ProgressCode codeList="http://www.isotc211.org/2005/resources/Codelist/gmxCodelists.xml#MD_ProgressCode" codeListValue="Complete">Complete</MD_ProgressCode>
      </status>
      <pointOfContact>
        <CI_ResponsibleParty>
          <individualName>
            <gco:CharacterString>Ehrmann, Holger</gco:CharacterString>
          </individualName>
          <positionName>
            <gco:CharacterString/>
          </positionName>
          <contactInfo>
            <CI_Contact>
              <address>
                <CI_Address>
                  <electronicMailAddress>
                    <gco:CharacterString>holger.ehrmann@gfz-potsdam.de</gco:CharacterString>
                  </electronicMailAddress>
                </CI_Address>
              </address>
            </CI_Contact>
          </contactInfo>
          <role>
            <CI_RoleCode codeList="http://www.isotc211.org/2005/resources/Codelist/gmxCodelists.xml#CI_RoleCode" codeListValue="pointOfContact">pointOfContact</CI_RoleCode>
          </role>
        </CI_ResponsibleParty>
      </pointOfContact>
      <descriptiveKeywords/>
      <descriptiveKeywords>
        <MD_Keywords>
          <thesaurusName>
            <CI_Citation>
              <title>
                <gco:CharacterString>NASA/GCMD Earth Science Keywords</gco:CharacterString>
              </title>
            </CI_Citation>
          </thesaurusName>
        </MD_Keywords>
      </descriptiveKeywords>
      <resourceConstraints>
        <MD_Constraints>
          <useLimitation>
            <gco:CharacterString>Creative Commons Attribution 4.0 International</gco:CharacterString>
          </useLimitation>
        </MD_Constraints>
      </resourceConstraints>
      <resourceConstraints>
        <MD_LegalConstraints>
          <accessConstraints>
            <MD_RestrictionCode codeList="http://www.isotc211.org/2005/resources/codeList.xml#MD_RestrictionCode" codeListValue="otherRestrictions"/>
          </accessConstraints>
          <otherConstraints>
            <gco:CharacterString>Creative Commons Attribution 4.0 International</gco:CharacterString>
          </otherConstraints>
        </MD_LegalConstraints>
      </resourceConstraints>
      <resourceConstraints>
        <MD_SecurityConstraints>
          <classification>
            <MD_ClassificationCode codeList="http://www.isotc211.org/2005/resources/codeList.xml#MD_ClassificationCode" codeListValue="unclassified"/>
          </classification>
        </MD_SecurityConstraints>
      </resourceConstraints>
      <language>
        <gco:CharacterString>en</gco:CharacterString>
      </language>
    </MD_DataIdentification>
  </identificationInfo>
  <distributionInfo>
    <MD_Distribution>
      <transferOptions>
        <MD_DigitalTransferOptions>
          <onLine>
            <CI_OnlineResource>
              <linkage/>
              <protocol>
                <gco:CharacterString>WWW:LINK-1.0-http--link</gco:CharacterString>
              </protocol>
              <name>
                <gco:CharacterString>Download</gco:CharacterString>
              </name>
              <description>
                <gco:CharacterString>Download</gco:CharacterString>
              </description>
              <function>
                <CI_OnLineFunctionCode codeList="http://www.isotc211.org/2005/resources/Codelist/gmxCodelists.xml#CI_OnLineFunctionCode" codeListValue="information">download</CI_OnLineFunctionCode>
              </function>
            </CI_OnlineResource>
          </onLine>
        </MD_DigitalTransferOptions>
      </transferOptions>
    </MD_Distribution>
  </distributionInfo>
</MD_Metadata>


    
<DIF xmlns="http://gcmd.gsfc.nasa.gov/Aboutus/xml/dif/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://gcmd.gsfc.nasa.gov/Aboutus/xml/dif/&#10;&#9;&#9;&#9;&#9;file:///C:/xampp2/htdocs/mde-msl/schemas/GCMD/DIF.xsd">
  <Entry_ID/>
  <Entry_Title>A mandatory Event</Entry_Title>
  <Data_Set_Citation>
    <Dataset_Creator>Hernandez, Sofia</Dataset_Creator>
    <Dataset_Title>A mandatory Event</Dataset_Title>
    <Dataset_Release_Date>1956</Dataset_Release_Date>
  </Data_Set_Citation>
  <Data_Center>
    <Data_Center_Name>
      <Short_Name>Deutsches GeoForschungsZentrum GFZ</Short_Name>
      <Long_Name>GFZ</Long_Name>
    </Data_Center_Name>
    <Personnel>
      <Role>DATA CENTER CONTACT</Role>
      <Last_Name>Deutsches GeoForschungsZentrum GFZ</Last_Name>
    </Personnel>
  </Data_Center>
  <Summary>
    <Abstract>dsfdf</Abstract>
  </Summary>
  <Metadata_Name>DIF</Metadata_Name>
  <Metadata_Version>9.9.3</Metadata_Version>
</DIF>

</envelope>
XML;
    $file = UploadedFile::fake()->createWithContent('test.xml', $xml);

    $response = $this->post(route('dashboard.upload-xml'), [
        'file' => $file,
        '_token' => csrf_token(),
    ]);

    $response->assertSessionData([
        'year' => '1956',
        'language' => 'en',
        'resourceType' => (string) $type->id,
        'titles' => [
            ['title' => 'A mandatory Event', 'titleType' => 'main-title'],
        ],
        'licenses' => ['CC-BY-4.0'],
    ]);
});

it('extracts authors and resolves affiliations from uploaded xml', function () {
    $this->actingAs(User::factory()->create());
    Storage::fake('local');

    $rorData = [
        [
            'prefLabel' => 'GFZ Data Services',
            'rorId' => 'https://ror.org/04wxnsj81',
            'otherLabel' => ['GFZ'],
        ],
    ];

    Storage::disk('local')->put(
        'ror/ror-affiliations.json',
        json_encode($rorData, JSON_THROW_ON_ERROR),
    );

    $xml = <<<'XML'
<resource>
    <creators>
        <creator>
            <creatorName nameType="Personal">ExampleFamilyName, ExampleGivenName</creatorName>
            <givenName>ExampleGivenName</givenName>
            <familyName>ExampleFamilyName</familyName>
            <nameIdentifier nameIdentifierScheme="ORCID" schemeURI="https://orcid.org">https://orcid.org/0000-0001-5727-2427</nameIdentifier>
            <affiliation affiliationIdentifier="https://ror.org/04wxnsj81" affiliationIdentifierScheme="ROR" schemeURI="https://ror.org">ExampleAffiliation</affiliation>
        </creator>
        <creator>
            <creatorName xml:lang="en" nameType="Organizational">ExampleOrganization</creatorName>
            <affiliation>Independent Collaboration</affiliation>
        </creator>
    </creators>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('test.xml', $xml);

    $response = $this->post(route('dashboard.upload-xml'), [
        'file' => $file,
        '_token' => csrf_token(),
    ]);

    $response->assertSessionData([
        'authors' => [
            [
                'type' => 'person',
                'firstName' => 'ExampleGivenName',
                'lastName' => 'ExampleFamilyName',
                'orcid' => '0000-0001-5727-2427',
                'affiliations' => [
                    [
                        'value' => 'GFZ Data Services',
                        'rorId' => 'https://ror.org/04wxnsj81',
                    ],
                ],
            ],
            [
                'type' => 'institution',
                'institutionName' => 'ExampleOrganization',
                'affiliations' => [
                    [
                        'value' => 'Independent Collaboration',
                        'rorId' => null,
                    ],
                ],
            ],
        ],
    ]);
});

it('ignores related item creators when extracting authors', function () {
    $this->actingAs(User::factory()->create());
    Storage::fake('local');

    Storage::disk('local')->put(
        'ror/ror-affiliations.json',
        json_encode([
            [
                'prefLabel' => 'DataCite',
                'rorId' => 'https://ror.org/04wxnsj81',
            ],
        ], JSON_THROW_ON_ERROR),
    );

    $xmlContents = file_get_contents(base_path('tests/pest/dataset-examples/datacite-xml-example-full-v4.xml'));

    $this->assertIsString($xmlContents);

    $file = UploadedFile::fake()->createWithContent('datacite-xml-example-full-v4.xml', $xmlContents);

    $response = $this->post(route('dashboard.upload-xml'), [
        'file' => $file,
        '_token' => csrf_token(),
    ]);

    $response->assertOk();

    expect($response->sessionData('authors'))->toHaveCount(3);

    $response->assertSessionData([
        'authors' => [
            [
                'type' => 'person',
                'firstName' => 'Holger',
                'lastName' => 'Ehrmann',
                'orcid' => '0009-0000-1235-6950',
                'affiliations' => [
                    [
                        'value' => 'Helmholtz Centre Potsdam - GFZ German Research Centre for Geosciences',
                        'rorId' => 'https://ror.org/04z8jg394',
                    ],
                    [
                        'value' => 'Fachhochschule Potsdam University of Applied Sciences',
                        'rorId' => 'https://ror.org/012m9bp23',
                    ],
                    [
                        'value' => 'Bundeswehr',
                        'rorId' => 'https://ror.org/00nmgny79',
                    ],
                ],
            ],
            [
                'type' => 'person',
                'firstName' => 'Sofia',
                'lastName' => 'Garcia',
                'orcid' => '0000-0001-5727-2427',
                'affiliations' => [
                    [
                        'value' => 'ORCID',
                        'rorId' => 'https://ror.org/04fa4r544',
                    ],
                ],
            ],
            [
                'type' => 'institution',
                'institutionName' => 'Library and Information Services',
                'affiliations' => [
                    [
                        'value' => 'GFZ Helmholtz Centre for Geosciences',
                        'rorId' => 'https://ror.org/04z8jg394',
                    ],
                ],
            ],
        ],
    ]);
});

it('validates xml file type and size', function () {
    $this->actingAs(User::factory()->create());

    $file = UploadedFile::fake()->create('test.txt', 10, 'text/plain');

    $response = $this->post(route('dashboard.upload-xml'), [
        'file' => $file,
        '_token' => csrf_token(),
    ], ['Accept' => 'application/json']);

    $response->assertStatus(422)->assertJsonValidationErrors('file');
});

it('extracts GCMD keywords from uploaded xml', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<resource>
    <subjects>
        <subject subjectScheme="NASA/GCMD Earth Science Keywords" 
                 schemeURI="https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords" 
                 valueURI="https://gcmd.earthdata.nasa.gov/kms/concept/b1ce822a-139b-4e11-8bbe-453f19501c36" 
                 xml:lang="en">Science Keywords &gt; EARTH SCIENCE &gt; CRYOSPHERE &gt; FROZEN GROUND &gt; ROCK GLACIERS</subject>
        <subject subjectScheme="NASA/GCMD Earth Platforms Keywords" 
                 schemeURI="https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/platforms" 
                 valueURI="https://gcmd.earthdata.nasa.gov/kms/concept/6438d343-2e1f-4a89-97a9-b032e651163f" 
                 xml:lang="en">Platforms &gt; Living Organism-based Platforms &gt; Living Organism</subject>
        <subject subjectScheme="NASA/GCMD Instruments" 
                 schemeURI="https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/instruments" 
                 valueURI="https://gcmd.earthdata.nasa.gov/kms/concept/aac79253-3894-408b-a20b-51e7101c36e3" 
                 xml:lang="en">Instruments &gt; Solar/Space Observing Instruments &gt; Radio Wave Detectors &gt; RIOMETER</subject>
    </subjects>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('test.xml', $xml);

    $response = $this->post(route('dashboard.upload-xml'), [
        'file' => $file,
        '_token' => csrf_token(),
    ]);

    $response->assertSessionData([
        'gcmdKeywords' => [
            [
                'uuid' => 'b1ce822a-139b-4e11-8bbe-453f19501c36',
                'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/b1ce822a-139b-4e11-8bbe-453f19501c36',
                'scheme' => 'Science Keywords',
                'text' => 'ROCK GLACIERS',
                'path' => 'EARTH SCIENCE > CRYOSPHERE > FROZEN GROUND > ROCK GLACIERS',
            ],
            [
                'uuid' => '6438d343-2e1f-4a89-97a9-b032e651163f',
                'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/6438d343-2e1f-4a89-97a9-b032e651163f',
                'scheme' => 'Platforms',
                'text' => 'Living Organism',
                'path' => 'Living Organism-based Platforms > Living Organism',
            ],
            [
                'uuid' => 'aac79253-3894-408b-a20b-51e7101c36e3',
                'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/aac79253-3894-408b-a20b-51e7101c36e3',
                'scheme' => 'Instruments',
                'text' => 'RIOMETER',
                'path' => 'Solar/Space Observing Instruments > Radio Wave Detectors > RIOMETER',
            ],
        ],
    ]);
});

it('extracts free keywords from uploaded xml', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<resource>
    <subjects>
        <subject>climate change</subject>
        <subject>temperature</subject>
        <subject>precipitation</subject>
        <subject subjectScheme="NASA/GCMD Earth Science Keywords" 
                 schemeURI="https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords" 
                 valueURI="https://gcmd.earthdata.nasa.gov/kms/concept/b1ce822a-139b-4e11-8bbe-453f19501c36" 
                 xml:lang="en">Science Keywords &gt; EARTH SCIENCE &gt; CRYOSPHERE &gt; FROZEN GROUND &gt; ROCK GLACIERS</subject>
    </subjects>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('test.xml', $xml);

    $response = $this->post(route('dashboard.upload-xml'), [
        'file' => $file,
        '_token' => csrf_token(),
    ]);

    $response->assertSessionData([
        'freeKeywords' => [
            'climate change',
            'temperature',
            'precipitation',
        ],
        'gcmdKeywords' => [
            [
                'uuid' => 'b1ce822a-139b-4e11-8bbe-453f19501c36',
                'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/b1ce822a-139b-4e11-8bbe-453f19501c36',
                'scheme' => 'Science Keywords',
                'text' => 'ROCK GLACIERS',
                'path' => 'EARTH SCIENCE > CRYOSPHERE > FROZEN GROUND > ROCK GLACIERS',
            ],
        ],
    ]);
});

it('only extracts subjects without schema attributes as free keywords', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<resource>
    <subjects>
        <subject>free keyword 1</subject>
        <subject schemeURI="http://some-scheme.org">controlled keyword</subject>
        <subject>free keyword 2</subject>
        <subject valueURI="http://some-uri.org/value">another controlled keyword</subject>
        <subject>freekeyword3</subject>
    </subjects>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('test.xml', $xml);

    $response = $this->post(route('dashboard.upload-xml'), [
        'file' => $file,
        '_token' => csrf_token(),
    ]);

    $response->assertSessionData([
        'freeKeywords' => [
            'free keyword 1',
            'free keyword 2',
            'freekeyword3',
        ],
    ]);

    // Verify that keywords with schema attributes are NOT in freeKeywords
    $data = $response->sessionData();
    expect($data['freeKeywords'])->not->toContain('controlled keyword');
    expect($data['freeKeywords'])->not->toContain('another controlled keyword');
});

it('handles empty and whitespace-only free keywords', function () {
    $this->actingAs(User::factory()->create());

    $xml = <<<'XML'
<resource>
    <subjects>
        <subject>valid keyword</subject>
        <subject>   </subject>
        <subject></subject>
        <subject>another valid keyword</subject>
    </subjects>
</resource>
XML;

    $file = UploadedFile::fake()->createWithContent('test.xml', $xml);

    $response = $this->post(route('dashboard.upload-xml'), [
        'file' => $file,
        '_token' => csrf_token(),
    ]);

    $response->assertSessionData([
        'freeKeywords' => [
            'valid keyword',
            'another valid keyword',
        ],
    ]);
});
