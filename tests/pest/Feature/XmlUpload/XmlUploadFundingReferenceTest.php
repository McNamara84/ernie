<?php

declare(strict_types=1);

use App\Http\Controllers\UploadXmlController;
use App\Models\User;
use Illuminate\Http\UploadedFile;

covers(UploadXmlController::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

describe('Single funding reference', function () {
    it('extracts a single funding reference from XML', function () {
        $xmlContent = <<<'XML'
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
            ->post(route('dashboard.upload-xml'), ['file' => $file]);

        $response->assertOk();

        $data = $response->json();

        expect($data)->toHaveKey('fundingReferences')
            ->and($data['fundingReferences'])->toBeArray()->toHaveCount(1);

        $fundingRef = $data['fundingReferences'][0];

        expect($fundingRef['funderName'])->toBe('European Research Council')
            ->and($fundingRef['funderIdentifier'])->toBe('https://doi.org/10.13039/501100000780')
            ->and($fundingRef['funderIdentifierType'])->toBe('Crossref Funder ID')
            ->and($fundingRef['awardNumber'])->toBe('ERC-2021-STG-123456')
            ->and($fundingRef['awardUri'])->toBe('https://cordis.europa.eu/project/id/123456')
            ->and($fundingRef['awardTitle'])->toBe('Innovative Research in AI');
    });
});

describe('Multiple funding references', function () {
    it('extracts multiple funding references from XML', function () {
        $xmlContent = <<<'XML'
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
            ->post(route('dashboard.upload-xml'), ['file' => $file]);

        $response->assertOk();

        $data = $response->json();

        expect($data)->toHaveKey('fundingReferences')
            ->and($data['fundingReferences'])->toHaveCount(3);

        // First funding reference
        expect($data['fundingReferences'][0]['funderName'])->toBe('Deutsche Forschungsgemeinschaft')
            ->and($data['fundingReferences'][0]['funderIdentifierType'])->toBe('ROR')
            ->and($data['fundingReferences'][0]['awardNumber'])->toBe('DFG-2024-456');

        // Second funding reference
        expect($data['fundingReferences'][1]['funderName'])->toBe('National Science Foundation')
            ->and($data['fundingReferences'][1]['funderIdentifierType'])->toBe('Crossref Funder ID')
            ->and($data['fundingReferences'][1]['awardUri'])->toBe('https://www.nsf.gov/awardsearch/showAward?AWD_ID=123456')
            ->and($data['fundingReferences'][1]['awardTitle'])->toBe('Advanced Computing Research');

        // Third funding reference
        expect($data['fundingReferences'][2]['funderName'])->toBe('European Commission')
            ->and($data['fundingReferences'][2]['funderIdentifierType'])->toBe('ISNI');
    });
});

describe('Minimal funding reference', function () {
    it('extracts a funding reference with only funderName', function () {
        $xmlContent = <<<'XML'
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
            ->post(route('dashboard.upload-xml'), ['file' => $file]);

        $response->assertOk();

        $data = $response->json();

        expect($data['fundingReferences'])->toHaveCount(1);

        $fundingRef = $data['fundingReferences'][0];

        expect($fundingRef['funderName'])->toBe('Example Funder Without Details')
            ->and($fundingRef['funderIdentifier'])->toBeNull()
            ->and($fundingRef['funderIdentifierType'])->toBeNull()
            ->and($fundingRef['awardNumber'])->toBeNull()
            ->and($fundingRef['awardUri'])->toBeNull()
            ->and($fundingRef['awardTitle'])->toBeNull();
    });
});

describe('Missing funding references', function () {
    it('returns an empty array when XML has no funding references', function () {
        $xmlContent = <<<'XML'
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
            ->post(route('dashboard.upload-xml'), ['file' => $file]);

        $response->assertOk();

        $data = $response->json();

        expect($data)->toHaveKey('fundingReferences')
            ->and($data['fundingReferences'])->toBeArray()->toBeEmpty();
    });
});

describe('Funder identifier types', function () {
    it('supports all funder identifier types', function () {
        $xmlContent = <<<'XML'
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
            ->post(route('dashboard.upload-xml'), ['file' => $file]);

        $response->assertOk();

        $data = $response->json();

        expect($data['fundingReferences'])->toHaveCount(5);

        $types = array_column($data['fundingReferences'], 'funderIdentifierType');

        expect($types)->toContain('ROR')
            ->toContain('Crossref Funder ID')
            ->toContain('ISNI')
            ->toContain('GRID')
            ->toContain('Other');
    });
});
