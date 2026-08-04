<?php

declare(strict_types=1);

use App\Services\Xml\OriginalDataCiteRelatedIdentifierExtractionService;
use Illuminate\Support\Facades\Log;

covers(OriginalDataCiteRelatedIdentifierExtractionService::class);

beforeEach(function (): void {
    $this->service = app(OriginalDataCiteRelatedIdentifierExtractionService::class);
});

it('decodes raw and base64 encoded XML and rejects non XML values', function (): void {
    $xml = '<resource xmlns="http://datacite.org/schema/kernel-4" />';
    $lineWrappedBase64 = chunk_split(base64_encode($xml), 12, "\r\n");

    expect($this->service->decode($xml))->toBe($xml)
        ->and($this->service->decode(base64_encode($xml)))->toBe($xml)
        ->and($this->service->decode($lineWrappedBase64))->toBe($xml)
        ->and($this->service->decode(''))
        ->toBeNull()
        ->and($this->service->decode('not XML or base64 XML'))->toBeNull()
        ->and($this->service->decode(null))->toBeNull();
});

it('extracts canonical related identifiers and all supported optional attributes', function (): void {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <relatedIdentifiers>
    <relatedIdentifier
      relatedIdentifierType="doi"
      relationType="Is Supplemented By"
      resourceTypeGeneral="Dataset"
      relationTypeInformation="Additional measurements"
      relatedMetadataScheme="DDI"
      schemeURI="https://example.org/scheme"
      schemeType="XSD"> 10.5880/CRC1211DB.86 </relatedIdentifier>
  </relatedIdentifiers>
</resource>
XML;

    expect($this->service->extract($xml, 'issue-1077'))->toBe([[
        'relatedIdentifier' => '10.5880/CRC1211DB.86',
        'relatedIdentifierType' => 'DOI',
        'relationType' => 'IsSupplementedBy',
        'resourceTypeGeneral' => 'Dataset',
        'relationTypeInformation' => 'Additional measurements',
        'relatedMetadataScheme' => 'DDI',
        'schemeUri' => 'https://example.org/scheme',
        'schemeType' => 'XSD',
    ]]);
});

it('extracts all three related works from the issue 1077 XML fixture', function (): void {
    $xml = <<<'XML'
<resource xmlns="http://datacite.org/schema/kernel-4">
  <relatedIdentifiers>
    <relatedIdentifier relatedIdentifierType="DOI" relationType="IsSupplementedBy" resourceTypeGeneral="Dataset">10.5880/CRC1211DB.86</relatedIdentifier>
    <relatedIdentifier relatedIdentifierType="DOI" relationType="IsSupplementedBy" resourceTypeGeneral="Dataset">10.5880/CRC1211DB.87</relatedIdentifier>
    <relatedIdentifier relatedIdentifierType="DOI" relationType="IsSupplementedBy" resourceTypeGeneral="Dataset">10.5880/CRC1211DB.89</relatedIdentifier>
  </relatedIdentifiers>
</resource>
XML;

    $relatedIdentifiers = $this->service->extract($xml, '10.5880/crc1211db.88');

    expect(array_column($relatedIdentifiers, 'relatedIdentifier'))->toBe([
        '10.5880/CRC1211DB.86',
        '10.5880/CRC1211DB.87',
        '10.5880/CRC1211DB.89',
    ]);
});

it('skips invalid entries and fails open for malformed XML', function (): void {
    Log::spy();

    $xml = <<<'XML'
<resource xmlns="http://datacite.org/schema/kernel-4">
  <relatedIdentifiers>
    <relatedIdentifier relatedIdentifierType="DOI" relationType="Cites"></relatedIdentifier>
    <relatedIdentifier relatedIdentifierType="unsupported" relationType="Cites">10.5880/unsupported</relatedIdentifier>
    <relatedIdentifier relatedIdentifierType="URL" relationType="References">https://example.org/valid</relatedIdentifier>
  </relatedIdentifiers>
</resource>
XML;

    expect($this->service->extract($xml, 'upload.xml'))->toBe([[
        'relatedIdentifier' => 'https://example.org/valid',
        'relatedIdentifierType' => 'URL',
        'relationType' => 'References',
    ]])->and($this->service->extract('<resource><broken>', 'upload.xml'))->toBe([]);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Skipping invalid XML related identifier.'
            && $context['context'] === 'upload.xml')
        ->twice();
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Could not read XML related identifiers.'
            && $context['context'] === 'upload.xml')
        ->once();
});
