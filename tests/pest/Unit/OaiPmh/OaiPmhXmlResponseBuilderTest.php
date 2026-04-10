<?php

declare(strict_types=1);

use App\Services\OaiPmh\OaiPmhXmlResponseBuilder;

beforeEach(function () {
    $this->builder = new OaiPmhXmlResponseBuilder;
});

it('handles malformed metadata XML gracefully in addRecord', function () {
    $this->builder->createEnvelope('GetRecord', ['metadataPrefix' => 'oai_dc']);
    $container = $this->builder->beginGetRecord();

    // Pass invalid XML — should not throw or produce warnings
    $this->builder->addRecord(
        $container,
        'oai:ernie.gfz.de:10.5880/test',
        '2024-01-01T00:00:00Z',
        ['resourcetype:dataset'],
        'this is not valid XML <broken>',
    );

    $xml = $this->builder->toXml();

    // Record should still be emitted (with empty metadata)
    expect($xml)->toContain('<record')
        ->and($xml)->toContain('<header')
        ->and($xml)->toContain('<metadata');
});

it('builds Dublin Core XML with proper namespaces', function () {
    $dcElements = [
        'title' => ['Test Title'],
        'creator' => ['Doe, John'],
        'identifier' => ['https://doi.org/10.5880/test'],
    ];

    $xml = $this->builder->buildDublinCoreXml($dcElements);

    expect($xml)->toContain('oai_dc:dc')
        ->and($xml)->toContain('dc:title')
        ->and($xml)->toContain('Test Title')
        ->and($xml)->toContain('dc:creator')
        ->and($xml)->toContain('Doe, John');
});

it('adds error element with correct code and message', function () {
    $this->builder->createEnvelope('ListRecords');
    $this->builder->addError('noRecordsMatch', 'No records match the given criteria');

    $xml = $this->builder->toXml();
    $parsed = simplexml_load_string($xml);

    expect((string) $parsed->error['code'])->toBe('noRecordsMatch')
        ->and((string) $parsed->error)->toBe('No records match the given criteria');
});

it('adds resumption token with all attributes', function () {
    $this->builder->createEnvelope('ListRecords', ['metadataPrefix' => 'oai_dc']);
    $container = $this->builder->beginListRecords();
    $this->builder->addResumptionToken($container, 'test-token-123', 500, 100, '2025-01-01T00:00:00Z');

    $xml = $this->builder->toXml();
    $parsed = simplexml_load_string($xml);

    expect((string) $parsed->ListRecords->resumptionToken)->toBe('test-token-123')
        ->and((string) $parsed->ListRecords->resumptionToken['completeListSize'])->toBe('500')
        ->and((string) $parsed->ListRecords->resumptionToken['cursor'])->toBe('100')
        ->and((string) $parsed->ListRecords->resumptionToken['expirationDate'])->toBe('2025-01-01T00:00:00Z');
});

it('adds empty resumption token to signal end of list', function () {
    $this->builder->createEnvelope('ListRecords', ['metadataPrefix' => 'oai_dc']);
    $container = $this->builder->beginListRecords();
    $this->builder->addResumptionToken($container, null, 100, 100);

    $xml = $this->builder->toXml();
    $parsed = simplexml_load_string($xml);

    expect((string) $parsed->ListRecords->resumptionToken)->toBe('')
        ->and((string) $parsed->ListRecords->resumptionToken['completeListSize'])->toBe('100');
});

it('adds deleted record with status attribute', function () {
    $this->builder->createEnvelope('ListRecords', ['metadataPrefix' => 'oai_dc']);
    $container = $this->builder->beginListRecords();
    $this->builder->addDeletedRecord(
        $container,
        'oai:ernie.gfz.de:10.5880/deleted',
        '2024-06-15T00:00:00Z',
        ['resourcetype:dataset'],
    );

    $xml = $this->builder->toXml();

    expect($xml)->toContain('status="deleted"')
        ->and($xml)->toContain('oai:ernie.gfz.de:10.5880/deleted');
});

it('derives repositoryIdentifier from config in Identify', function () {
    $this->builder->createEnvelope('Identify');
    $this->builder->addIdentifyContent('2000-01-01T00:00:00Z', 'oai:ernie.gfz.de:10.5880/example');

    $xml = $this->builder->toXml();

    $expectedId = str_replace('oai:', '', (string) config('oaipmh.identifier_prefix'));
    expect($xml)->toContain("<repositoryIdentifier>{$expectedId}</repositoryIdentifier>");
});
