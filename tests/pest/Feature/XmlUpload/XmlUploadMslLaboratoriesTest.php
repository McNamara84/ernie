<?php

declare(strict_types=1);

use App\Http\Controllers\UploadXmlController;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

covers(UploadXmlController::class);

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->create();

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
});

describe('Single MSL laboratory extraction', function () {
    it('extracts an MSL laboratory from DataCite XML', function () {
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

        $data = getXmlUploadData($response);
        $mslLabs = $data['mslLaboratories'] ?? [];

        expect($mslLabs)->toHaveCount(1)
            ->and($mslLabs[0]['identifier'])->toBe('9ba34c109b827b177aab36e0266b1643')
            ->and($mslLabs[0]['name'])->toBe('HelTec - Helmholtz Laboratory');
    });
});

describe('Multiple MSL laboratories', function () {
    it('extracts multiple MSL laboratories from XML', function () {
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

        $data = getXmlUploadData($response);
        $mslLabs = $data['mslLaboratories'] ?? [];

        expect($mslLabs)->toHaveCount(2)
            ->and($mslLabs[0]['identifier'])->toBe('lab1id123')
            ->and($mslLabs[1]['identifier'])->toBe('lab2id456');
    });
});

describe('MSL laboratory separation from contributors', function () {
    it('keeps MSL laboratories separate from regular contributors', function () {
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

        $data = getXmlUploadData($response);

        // MSL lab should be in mslLaboratories
        $mslLabs = $data['mslLaboratories'] ?? [];
        expect($mslLabs)->toHaveCount(1)
            ->and($mslLabs[0]['identifier'])->toBe('msllab123');

        // MSL lab should NOT be in regular contributors
        $contributors = $data['contributors'] ?? [];
        foreach ($contributors as $contributor) {
            expect($contributor['institutionName'] ?? '')->not->toBe('MSL Laboratory');
        }

        // Regular contributor should be in contributors list
        $found = false;
        foreach ($contributors as $contributor) {
            if (($contributor['lastName'] ?? '') === 'Regular Contributor') {
                $found = true;
                break;
            }
        }
        expect($found)->toBeTrue('Regular Contributor should be in contributors list');
    });
});

describe('Edge cases', function () {
    it('handles MSL laboratory without affiliation', function () {
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

        $data = getXmlUploadData($response);
        $mslLabs = $data['mslLaboratories'] ?? [];
        expect($mslLabs)->toHaveCount(1)
            ->and($mslLabs[0]['affiliation_name'])->toBe('')
            ->and($mslLabs[0]['affiliation_ror'])->toBe('');
    });

    it('ignores hosting institution without labid', function () {
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

        $data = getXmlUploadData($response);
        $mslLabs = $data['mslLaboratories'] ?? [];
        expect($mslLabs)->toBeEmpty();

        // Should be in regular contributors
        $contributors = $data['contributors'] ?? [];
        $contributorNames = array_column($contributors, 'institutionName');
        expect($contributorNames)->toContain('Regular Hosting Institution');
    });

    it('returns empty array when XML has no MSL laboratories', function () {
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

        $data = getXmlUploadData($response);
        $mslLabs = $data['mslLaboratories'] ?? [];
        expect($mslLabs)->toBeArray()->toBeEmpty();
    });

    it('extracts MSL laboratory with ROR but without scheme attribute', function () {
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

        $data = getXmlUploadData($response);
        $mslLabs = $data['mslLaboratories'] ?? [];
        expect($mslLabs)->toHaveCount(1)
            ->and($mslLabs[0]['identifier'])->toBe('testlab123')
            ->and($mslLabs[0]['affiliation_ror'])->toBe('https://ror.org/04z8jg394');
    });
});

describe('Vocabulary enrichment', function () {
    it('enriches MSL laboratory data from vocabulary service', function () {
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

        $data = getXmlUploadData($response);
        $mslLabs = $data['mslLaboratories'] ?? [];
        expect($mslLabs)->toHaveCount(1)
            ->and($mslLabs[0]['name'])->toBe('HelTec - Helmholtz Laboratory')
            ->and($mslLabs[0]['affiliation_name'])->toBe('GFZ German Research Centre')
            ->and($mslLabs[0]['affiliation_ror'])->toBe('https://ror.org/04z8jg394');
    });
});
