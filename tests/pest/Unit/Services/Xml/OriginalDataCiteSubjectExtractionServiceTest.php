<?php

declare(strict_types=1);

use App\Services\Xml\OriginalDataCiteSubjectExtractionService;
use App\Services\Xml\OriginalDataCiteXmlDecoderService;
use Illuminate\Support\Facades\Log;

covers(OriginalDataCiteSubjectExtractionService::class, OriginalDataCiteXmlDecoderService::class);

beforeEach(function (): void {
    $this->service = app(OriginalDataCiteSubjectExtractionService::class);
});

it('extracts subjects and their DataCite attributes from original XML', function (): void {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<resource xmlns="http://datacite.org/schema/kernel-4">
  <subjects>
    <subject subjectScheme="GEMET - INSPIRE themes, version 1.0" schemeURI="http://www.eionet.europa.eu/gemet/" valueURI="http://www.eionet.europa.eu/gemet/concept/3638" classificationCode="3638" xml:lang="en"> geodesy </subject>
    <subject xml:lang="de"> Hydrologie </subject>
    <subject> </subject>
  </subjects>
</resource>
XML;

    expect($this->service->extract($xml, 'issue-1115'))->toBe([
        [
            'subject' => 'geodesy',
            'subjectScheme' => 'GEMET - INSPIRE themes, version 1.0',
            'schemeUri' => 'http://www.eionet.europa.eu/gemet/',
            'valueUri' => 'http://www.eionet.europa.eu/gemet/concept/3638',
            'classificationCode' => '3638',
            'lang' => 'en',
        ],
        [
            'subject' => 'Hydrologie',
            'lang' => 'de',
        ],
    ]);
});

it('prefers base64 encoded original XML subjects over REST subjects', function (): void {
    $xml = <<<'XML'
<resource xmlns="http://datacite.org/schema/kernel-4">
  <subjects>
    <subject>Biology</subject>
    <subject subjectScheme="GEMET">geophysics</subject>
  </subjects>
</resource>
XML;
    $record = [
        'id' => '10.5880/TRR228DB.398',
        'attributes' => [
            'xml' => base64_encode($xml),
            'subjects' => [
                ['subject' => 'Biology'],
                ['subject' => 'FOS: Biological sciences'],
            ],
        ],
    ];

    $result = $this->service->preferOriginalSubjects($record, $record['id']);

    expect($result['attributes']['subjects'])->toBe([
        ['subject' => 'Biology'],
        ['subject' => 'geophysics', 'subjectScheme' => 'GEMET'],
    ]);
});

it('supports flat records and treats a valid empty subjects section as authoritative', function (): void {
    $record = [
        'xml' => '<resource xmlns="http://datacite.org/schema/kernel-4"><subjects /></resource>',
        'subjects' => [['subject' => 'REST only']],
    ];

    expect($this->service->preferOriginalSubjects($record)['subjects'])->toBe([]);
});

it('keeps REST subjects when original XML is absent or malformed', function (): void {
    Log::spy();

    $record = [
        'attributes' => [
            'subjects' => [['subject' => 'REST fallback']],
        ],
    ];
    $malformedRecord = [
        'attributes' => [
            'xml' => base64_encode('<resource><subjects><subject>broken'),
            'subjects' => [['subject' => 'REST fallback']],
        ],
    ];

    expect($this->service->preferOriginalSubjects($record))->toBe($record)
        ->and($this->service->preferOriginalSubjects($malformedRecord, 'broken-doi'))->toBe($malformedRecord);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Could not read XML subjects.'
            && $context['context'] === 'broken-doi')
        ->once();
});
