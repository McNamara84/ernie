<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Feature tests for MSL Laboratories in XML Upload
 * Tests extracting MSL labs from DataCite XML files
 */
class XmlUploadMslLaboratoriesTest extends TestCase
{
    use RefreshDatabase;

    private $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        // Create authenticated user
        $this->user = \App\Models\User::factory()->create();

        // Mock MSL Laboratory Service
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response([
                [
                    'identifier' => '9ba34c109b827b177aab36e0266b1643',
                    'name' => 'HelTec - Helmholtz Laboratory',
                    'affiliation_name' => 'GFZ German Research Centre',
                    'affiliation_ror' => 'https://ror.org/04z8jg394',
                ],
            ], 200),
        ]);
    }

    public function test_extracts_msl_laboratory_from_datacite_xml(): void
    {
        $xmlContent = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://datacite.org/schema/kernel-4 http://schema.datacite.org/meta/kernel-4.6/metadata.xsd">
    <identifier identifierType="DOI">10.1234/test</identifier>
    <creators>
        <creator>
            <creatorName>Test Author</creatorName>
        </creator>
    </creators>
    <titles>
        <title>Test Dataset</title>
    </titles>
    <publisher>Test Publisher</publisher>
    <publicationYear>2024</publicationYear>
    <resourceType resourceTypeGeneral="Dataset">Dataset</resourceType>
    <contributors>
        <contributor contributorType="HostingInstitution">
            <contributorName>HelTec - Helmholtz Laboratory</contributorName>
            <nameIdentifier nameIdentifierScheme="labid">9ba34c109b827b177aab36e0266b1643</nameIdentifier>
            <affiliation affiliationIdentifier="https://ror.org/04z8jg394" affiliationIdentifierScheme="ROR">GFZ German Research Centre</affiliation>
        </contributor>
    </contributors>
</resource>
XML;

        $file = UploadedFile::fake()->createWithContent('test.xml', $xmlContent);

        $response = $this->actingAs($this->user)->postJson('/dashboard/upload-xml', [
            'file' => $file,
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'mslLaboratories',
        ]);

        $mslLabs = $response->json('mslLaboratories');
        $this->assertCount(1, $mslLabs);
        $this->assertEquals('9ba34c109b827b177aab36e0266b1643', $mslLabs[0]['identifier']);
        $this->assertEquals('HelTec - Helmholtz Laboratory', $mslLabs[0]['name']);
    }

    public function test_extracts_multiple_msl_laboratories(): void
    {
        $xmlContent = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://datacite.org/schema/kernel-4 http://schema.datacite.org/meta/kernel-4.6/metadata.xsd">
    <identifier identifierType="DOI">10.1234/test</identifier>
    <creators>
        <creator>
            <creatorName>Test Author</creatorName>
        </creator>
    </creators>
    <titles>
        <title>Test Dataset</title>
    </titles>
    <publisher>Test Publisher</publisher>
    <publicationYear>2024</publicationYear>
    <resourceType resourceTypeGeneral="Dataset">Dataset</resourceType>
    <contributors>
        <contributor contributorType="HostingInstitution">
            <contributorName>Lab 1</contributorName>
            <nameIdentifier nameIdentifierScheme="labid">lab1id123</nameIdentifier>
            <affiliation affiliationIdentifier="https://ror.org/uni1" affiliationIdentifierScheme="ROR">University 1</affiliation>
        </contributor>
        <contributor contributorType="HostingInstitution">
            <contributorName>Lab 2</contributorName>
            <nameIdentifier nameIdentifierScheme="labid">lab2id456</nameIdentifier>
            <affiliation affiliationIdentifier="https://ror.org/uni2" affiliationIdentifierScheme="ROR">University 2</affiliation>
        </contributor>
    </contributors>
</resource>
XML;

        $file = UploadedFile::fake()->createWithContent('test.xml', $xmlContent);

        $response = $this->actingAs($this->user)->postJson('/dashboard/upload-xml', [
            'file' => $file,
        ]);

        $response->assertStatus(200);

        $mslLabs = $response->json('mslLaboratories');
        $this->assertCount(2, $mslLabs);
        $this->assertEquals('lab1id123', $mslLabs[0]['identifier']);
        $this->assertEquals('lab2id456', $mslLabs[1]['identifier']);
    }

    public function test_msl_laboratories_not_in_regular_contributors(): void
    {
        $xmlContent = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://datacite.org/schema/kernel-4 http://schema.datacite.org/meta/kernel-4.6/metadata.xsd">
    <identifier identifierType="DOI">10.1234/test</identifier>
    <creators>
        <creator>
            <creatorName>Test Author</creatorName>
        </creator>
    </creators>
    <titles>
        <title>Test Dataset</title>
    </titles>
    <publisher>Test Publisher</publisher>
    <publicationYear>2024</publicationYear>
    <resourceType resourceTypeGeneral="Dataset">Dataset</resourceType>
    <contributors>
        <contributor contributorType="HostingInstitution">
            <contributorName>MSL Laboratory</contributorName>
            <nameIdentifier nameIdentifierScheme="labid">msllab123</nameIdentifier>
            <affiliation>Host University</affiliation>
        </contributor>
        <contributor contributorType="DataCollector">
            <contributorName>Regular Contributor</contributorName>
        </contributor>
    </contributors>
</resource>
XML;

        $file = UploadedFile::fake()->createWithContent('test.xml', $xmlContent);

        $response = $this->actingAs($this->user)->postJson('/dashboard/upload-xml', [
            'file' => $file,
        ]);

        $response->assertStatus(200);

        // MSL lab should be in mslLaboratories
        $mslLabs = $response->json('mslLaboratories');
        $this->assertCount(1, $mslLabs);
        $this->assertEquals('msllab123', $mslLabs[0]['identifier']);

        // MSL lab should NOT be in regular contributors
        $contributors = $response->json('contributors');
        foreach ($contributors as $contributor) {
            $this->assertNotEquals('MSL Laboratory', $contributor['institutionName'] ?? '');
        }

        // Regular contributor should be in contributors
        // DataCollector is parsed as person, so we need to check lastName
        $found = false;
        foreach ($contributors as $contributor) {
            if (($contributor['lastName'] ?? '') === 'Regular Contributor') {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Regular Contributor should be in contributors list');
    }

    public function test_handles_msl_laboratory_without_affiliation(): void
    {
        $xmlContent = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://datacite.org/schema/kernel-4 http://schema.datacite.org/meta/kernel-4.6/metadata.xsd">
    <identifier identifierType="DOI">10.1234/test</identifier>
    <creators>
        <creator>
            <creatorName>Test Author</creatorName>
        </creator>
    </creators>
    <titles>
        <title>Test Dataset</title>
    </titles>
    <publisher>Test Publisher</publisher>
    <publicationYear>2024</publicationYear>
    <resourceType resourceTypeGeneral="Dataset">Dataset</resourceType>
    <contributors>
        <contributor contributorType="HostingInstitution">
            <contributorName>Lab Without Affiliation</contributorName>
            <nameIdentifier nameIdentifierScheme="labid">labnoaff123</nameIdentifier>
        </contributor>
    </contributors>
</resource>
XML;

        $file = UploadedFile::fake()->createWithContent('test.xml', $xmlContent);

        $response = $this->actingAs($this->user)->postJson('/dashboard/upload-xml', [
            'file' => $file,
        ]);

        $response->assertStatus(200);

        $mslLabs = $response->json('mslLaboratories');
        $this->assertCount(1, $mslLabs);
        $this->assertEquals('', $mslLabs[0]['affiliation_name']);
        $this->assertEquals('', $mslLabs[0]['affiliation_ror']);
    }

    public function test_enriches_msl_laboratory_from_vocabulary(): void
    {
        $xmlContent = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://datacite.org/schema/kernel-4 http://schema.datacite.org/meta/kernel-4.6/metadata.xsd">
    <identifier identifierType="DOI">10.1234/test</identifier>
    <creators>
        <creator>
            <creatorName>Test Author</creatorName>
        </creator>
    </creators>
    <titles>
        <title>Test Dataset</title>
    </titles>
    <publisher>Test Publisher</publisher>
    <publicationYear>2024</publicationYear>
    <resourceType resourceTypeGeneral="Dataset">Dataset</resourceType>
    <contributors>
        <contributor contributorType="HostingInstitution">
            <contributorName>Incomplete Lab Name</contributorName>
            <nameIdentifier nameIdentifierScheme="labid">9ba34c109b827b177aab36e0266b1643</nameIdentifier>
        </contributor>
    </contributors>
</resource>
XML;

        $file = UploadedFile::fake()->createWithContent('test.xml', $xmlContent);

        $response = $this->actingAs($this->user)->postJson('/dashboard/upload-xml', [
            'file' => $file,
        ]);

        $response->assertStatus(200);

        $mslLabs = $response->json('mslLaboratories');
        $this->assertCount(1, $mslLabs);

        // Should be enriched from vocabulary (mocked)
        $this->assertEquals('HelTec - Helmholtz Laboratory', $mslLabs[0]['name']);
        $this->assertEquals('GFZ German Research Centre', $mslLabs[0]['affiliation_name']);
        $this->assertEquals('https://ror.org/04z8jg394', $mslLabs[0]['affiliation_ror']);
    }

    public function test_ignores_hosting_institution_without_labid(): void
    {
        $xmlContent = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://datacite.org/schema/kernel-4 http://schema.datacite.org/meta/kernel-4.6/metadata.xsd">
    <identifier identifierType="DOI">10.1234/test</identifier>
    <creators>
        <creator>
            <creatorName>Test Author</creatorName>
        </creator>
    </creators>
    <titles>
        <title>Test Dataset</title>
    </titles>
    <publisher>Test Publisher</publisher>
    <publicationYear>2024</publicationYear>
    <resourceType resourceTypeGeneral="Dataset">Dataset</resourceType>
    <contributors>
        <contributor contributorType="HostingInstitution">
            <contributorName>Regular Hosting Institution</contributorName>
            <!-- No labid identifier -->
        </contributor>
    </contributors>
</resource>
XML;

        $file = UploadedFile::fake()->createWithContent('test.xml', $xmlContent);

        $response = $this->actingAs($this->user)->postJson('/dashboard/upload-xml', [
            'file' => $file,
        ]);

        $response->assertStatus(200);

        // Should not be in mslLaboratories (no labid)
        $mslLabs = $response->json('mslLaboratories');
        $this->assertEmpty($mslLabs);

        // Should be in regular contributors
        $contributors = $response->json('contributors');
        $contributorNames = array_column($contributors, 'institutionName');
        $this->assertContains('Regular Hosting Institution', $contributorNames);
    }

    public function test_handles_empty_xml_with_no_msl_laboratories(): void
    {
        $xmlContent = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://datacite.org/schema/kernel-4 http://schema.datacite.org/meta/kernel-4.6/metadata.xsd">
    <identifier identifierType="DOI">10.1234/test</identifier>
    <creators>
        <creator>
            <creatorName>Test Author</creatorName>
        </creator>
    </creators>
    <titles>
        <title>Test Dataset</title>
    </titles>
    <publisher>Test Publisher</publisher>
    <publicationYear>2024</publicationYear>
    <resourceType resourceTypeGeneral="Dataset">Dataset</resourceType>
</resource>
XML;

        $file = UploadedFile::fake()->createWithContent('test.xml', $xmlContent);

        $response = $this->actingAs($this->user)->postJson('/dashboard/upload-xml', [
            'file' => $file,
        ]);

        $response->assertStatus(200);

        $mslLabs = $response->json('mslLaboratories');
        $this->assertIsArray($mslLabs);
        $this->assertEmpty($mslLabs);
    }

    public function test_extracts_msl_laboratory_with_ror_without_scheme(): void
    {
        $xmlContent = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://datacite.org/schema/kernel-4 http://schema.datacite.org/meta/kernel-4.6/metadata.xsd">
    <identifier identifierType="DOI">10.1234/test</identifier>
    <creators>
        <creator>
            <creatorName>Test Author</creatorName>
        </creator>
    </creators>
    <titles>
        <title>Test Dataset</title>
    </titles>
    <publisher>Test Publisher</publisher>
    <publicationYear>2024</publicationYear>
    <resourceType resourceTypeGeneral="Dataset">Dataset</resourceType>
    <contributors>
        <contributor contributorType="HostingInstitution">
            <contributorName>Test Lab</contributorName>
            <nameIdentifier nameIdentifierScheme="labid">testlab123</nameIdentifier>
            <affiliation affiliationIdentifier="https://ror.org/04z8jg394">GFZ German Research Centre</affiliation>
        </contributor>
    </contributors>
</resource>
XML;

        $file = UploadedFile::fake()->createWithContent('test.xml', $xmlContent);

        Http::fake([
            'raw.githubusercontent.com/*' => Http::response([
                [
                    'identifier' => 'testlab123',
                    'name' => 'Test Laboratory',
                    'affiliation_name' => 'Test University',
                    'affiliation_ror' => 'https://ror.org/test',
                ],
            ], 200),
        ]);

        $response = $this->actingAs($this->user)->postJson('/dashboard/upload-xml', [
            'file' => $file,
        ]);

        $response->assertStatus(200);
        
        $mslLabs = $response->json('mslLaboratories');
        $this->assertCount(1, $mslLabs);
        $this->assertEquals('testlab123', $mslLabs[0]['identifier']);
        // Should recognize ROR even without affiliationIdentifierScheme
        $this->assertEquals('https://ror.org/04z8jg394', $mslLabs[0]['affiliation_ror']);
    }
}

