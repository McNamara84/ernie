<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Feature tests for XML Upload with Funding References
 * Tests the complete XML import workflow
 */
class XmlUploadFundingReferenceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
    }

    /**
     * Test uploading XML file with single funding reference
     */
    public function test_can_extract_single_funding_reference_from_xml(): void
    {
        $xmlContent = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
    <identifier identifierType="DOI">10.1234/test</identifier>
    <creators>
        <creator>
            <creatorName>Test Creator</creatorName>
        </creator>
    </creators>
    <titles>
        <title>Test Title</title>
    </titles>
    <publisher>Test Publisher</publisher>
    <publicationYear>2024</publicationYear>
    <resourceType resourceTypeGeneral="Dataset">Dataset</resourceType>
    <fundingReferences>
        <fundingReference>
            <funderName>European Research Council</funderName>
            <funderIdentifier funderIdentifierType="Crossref Funder ID">https://doi.org/10.13039/501100000780</funderIdentifier>
            <awardNumber awardURI="https://cordis.europa.eu/project/id/123456">ERC-2021-STG-123456</awardNumber>
            <awardTitle>Innovative Research in AI</awardTitle>
        </fundingReference>
    </fundingReferences>
</resource>
XML;

        $file = UploadedFile::fake()->createWithContent('test.xml', $xmlContent);

        $response = $this->actingAs($this->user)
            ->post(route('dashboard.upload-xml'), [
                'file' => $file,
            ]);

        $response->assertOk();
        
        $data = $response->json();

        $this->assertArrayHasKey('fundingReferences', $data);
        $this->assertIsArray($data['fundingReferences']);
        $this->assertCount(1, $data['fundingReferences']);

        $fundingRef = $data['fundingReferences'][0];
        
        $this->assertEquals('European Research Council', $fundingRef['funderName']);
        $this->assertEquals('https://doi.org/10.13039/501100000780', $fundingRef['funderIdentifier']);
        $this->assertEquals('Crossref Funder ID', $fundingRef['funderIdentifierType']);
        $this->assertEquals('ERC-2021-STG-123456', $fundingRef['awardNumber']);
        $this->assertEquals('https://cordis.europa.eu/project/id/123456', $fundingRef['awardUri']);
        $this->assertEquals('Innovative Research in AI', $fundingRef['awardTitle']);
    }

    /**
     * Test uploading XML file with multiple funding references
     */
    public function test_can_extract_multiple_funding_references_from_xml(): void
    {
        $xmlContent = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
    <identifier identifierType="DOI">10.1234/test</identifier>
    <creators>
        <creator>
            <creatorName>Test Creator</creatorName>
        </creator>
    </creators>
    <titles>
        <title>Test Title</title>
    </titles>
    <publisher>Test Publisher</publisher>
    <publicationYear>2024</publicationYear>
    <resourceType resourceTypeGeneral="Dataset">Dataset</resourceType>
    <fundingReferences>
        <fundingReference>
            <funderName>Deutsche Forschungsgemeinschaft</funderName>
            <funderIdentifier funderIdentifierType="ROR">https://ror.org/018mejw64</funderIdentifier>
            <awardNumber>DFG-2024-456</awardNumber>
        </fundingReference>
        <fundingReference>
            <funderName>National Science Foundation</funderName>
            <funderIdentifier funderIdentifierType="Crossref Funder ID">https://doi.org/10.13039/100000001</funderIdentifier>
            <awardNumber awardURI="https://www.nsf.gov/awardsearch/showAward?AWD_ID=123456">NSF-123456</awardNumber>
            <awardTitle>Advanced Computing Research</awardTitle>
        </fundingReference>
        <fundingReference>
            <funderName>European Commission</funderName>
            <funderIdentifier funderIdentifierType="ISNI">0000 0001 2162 673X</funderIdentifier>
        </fundingReference>
    </fundingReferences>
</resource>
XML;

        $file = UploadedFile::fake()->createWithContent('test-multiple.xml', $xmlContent);

        $response = $this->actingAs($this->user)
            ->post(route('dashboard.upload-xml'), [
                'file' => $file,
            ]);

        $response->assertOk();
        
        $data = $response->json();

        $this->assertArrayHasKey('fundingReferences', $data);
        $this->assertCount(3, $data['fundingReferences']);

        // Check first funding reference
        $this->assertEquals('Deutsche Forschungsgemeinschaft', $data['fundingReferences'][0]['funderName']);
        $this->assertEquals('ROR', $data['fundingReferences'][0]['funderIdentifierType']);
        $this->assertEquals('DFG-2024-456', $data['fundingReferences'][0]['awardNumber']);

        // Check second funding reference
        $this->assertEquals('National Science Foundation', $data['fundingReferences'][1]['funderName']);
        $this->assertEquals('Crossref Funder ID', $data['fundingReferences'][1]['funderIdentifierType']);
        $this->assertEquals('https://www.nsf.gov/awardsearch/showAward?AWD_ID=123456', $data['fundingReferences'][1]['awardUri']);
        $this->assertEquals('Advanced Computing Research', $data['fundingReferences'][1]['awardTitle']);

        // Check third funding reference
        $this->assertEquals('European Commission', $data['fundingReferences'][2]['funderName']);
        $this->assertEquals('ISNI', $data['fundingReferences'][2]['funderIdentifierType']);
    }

    /**
     * Test uploading XML file with minimal funding reference (only funderName)
     */
    public function test_can_extract_minimal_funding_reference_from_xml(): void
    {
        $xmlContent = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
    <identifier identifierType="DOI">10.1234/test</identifier>
    <creators>
        <creator>
            <creatorName>Test Creator</creatorName>
        </creator>
    </creators>
    <titles>
        <title>Test Title</title>
    </titles>
    <publisher>Test Publisher</publisher>
    <publicationYear>2024</publicationYear>
    <resourceType resourceTypeGeneral="Dataset">Dataset</resourceType>
    <fundingReferences>
        <fundingReference>
            <funderName>Example Funder Without Details</funderName>
        </fundingReference>
    </fundingReferences>
</resource>
XML;

        $file = UploadedFile::fake()->createWithContent('test-minimal.xml', $xmlContent);

        $response = $this->actingAs($this->user)
            ->post(route('dashboard.upload-xml'), [
                'file' => $file,
            ]);

        $response->assertOk();
        
        $data = $response->json();

        $this->assertCount(1, $data['fundingReferences']);
        
        $fundingRef = $data['fundingReferences'][0];
        
        $this->assertEquals('Example Funder Without Details', $fundingRef['funderName']);
        $this->assertNull($fundingRef['funderIdentifier']);
        $this->assertNull($fundingRef['funderIdentifierType']);
        $this->assertNull($fundingRef['awardNumber']);
        $this->assertNull($fundingRef['awardUri']);
        $this->assertNull($fundingRef['awardTitle']);
    }

    /**
     * Test uploading XML file without funding references
     */
    public function test_xml_without_funding_references_returns_empty_array(): void
    {
        $xmlContent = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
    <identifier identifierType="DOI">10.1234/test</identifier>
    <creators>
        <creator>
            <creatorName>Test Creator</creatorName>
        </creator>
    </creators>
    <titles>
        <title>Test Title</title>
    </titles>
    <publisher>Test Publisher</publisher>
    <publicationYear>2024</publicationYear>
    <resourceType resourceTypeGeneral="Dataset">Dataset</resourceType>
</resource>
XML;

        $file = UploadedFile::fake()->createWithContent('test-no-funding.xml', $xmlContent);

        $response = $this->actingAs($this->user)
            ->post(route('dashboard.upload-xml'), [
                'file' => $file,
            ]);

        $response->assertOk();
        
        $data = $response->json();

        $this->assertArrayHasKey('fundingReferences', $data);
        $this->assertIsArray($data['fundingReferences']);
        $this->assertCount(0, $data['fundingReferences']);
    }

    /**
     * Test all supported funder identifier types
     */
    public function test_supports_all_funder_identifier_types(): void
    {
        $xmlContent = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
    <identifier identifierType="DOI">10.1234/test</identifier>
    <creators>
        <creator>
            <creatorName>Test Creator</creatorName>
        </creator>
    </creators>
    <titles>
        <title>Test Title</title>
    </titles>
    <publisher>Test Publisher</publisher>
    <publicationYear>2024</publicationYear>
    <resourceType resourceTypeGeneral="Dataset">Dataset</resourceType>
    <fundingReferences>
        <fundingReference>
            <funderName>Funder with ROR</funderName>
            <funderIdentifier funderIdentifierType="ROR">https://ror.org/example</funderIdentifier>
        </fundingReference>
        <fundingReference>
            <funderName>Funder with Crossref</funderName>
            <funderIdentifier funderIdentifierType="Crossref Funder ID">https://doi.org/10.13039/example</funderIdentifier>
        </fundingReference>
        <fundingReference>
            <funderName>Funder with ISNI</funderName>
            <funderIdentifier funderIdentifierType="ISNI">0000 0001 2162 673X</funderIdentifier>
        </fundingReference>
        <fundingReference>
            <funderName>Funder with GRID</funderName>
            <funderIdentifier funderIdentifierType="GRID">grid.example</funderIdentifier>
        </fundingReference>
        <fundingReference>
            <funderName>Funder with Other</funderName>
            <funderIdentifier funderIdentifierType="Other">other-id-123</funderIdentifier>
        </fundingReference>
    </fundingReferences>
</resource>
XML;

        $file = UploadedFile::fake()->createWithContent('test-all-types.xml', $xmlContent);

        $response = $this->actingAs($this->user)
            ->post(route('dashboard.upload-xml'), [
                'file' => $file,
            ]);

        $response->assertOk();
        
        $data = $response->json();

        $this->assertCount(5, $data['fundingReferences']);

        $types = array_column($data['fundingReferences'], 'funderIdentifierType');
        
        $this->assertContains('ROR', $types);
        $this->assertContains('Crossref Funder ID', $types);
        $this->assertContains('ISNI', $types);
        $this->assertContains('GRID', $types);
        $this->assertContains('Other', $types);
    }
}
