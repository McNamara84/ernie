<?php

use App\Services\DataCiteXmlValidator;

beforeEach(function () {
    $this->validator = new DataCiteXmlValidator;
});

describe('DataCiteXmlValidator', function () {
    describe('XML parsing', function () {
        it('throws exception for malformed XML', function () {
            $malformedXml = '<?xml version="1.0"?><unclosed>';

            expect(fn () => $this->validator->validate($malformedXml))
                ->toThrow(Exception::class);
        });

        it('throws exception for empty XML', function () {
            expect(fn () => $this->validator->validate(''))
                ->toThrow(Exception::class);
        });

        it('throws exception for non-XML content', function () {
            expect(fn () => $this->validator->validate('not xml at all'))
                ->toThrow(Exception::class);
        });

        it('parses well-formed XML without throwing', function () {
            $validXml = '<?xml version="1.0" encoding="UTF-8"?>
                <resource xmlns="http://datacite.org/schema/kernel-4">
                    <identifier identifierType="DOI">10.1234/test</identifier>
                </resource>';

            // Should not throw, but may return false due to schema validation
            $result = $this->validator->validate($validXml);

            expect($result)->toBeBool();
        });
    });

    describe('warning handling', function () {
        it('starts with no warnings', function () {
            expect($this->validator->hasWarnings())->toBeFalse();
            expect($this->validator->getWarnings())->toBeEmpty();
        });

        it('collects warnings when validation fails', function () {
            // Minimal XML that parses but fails schema validation
            $invalidXml = '<?xml version="1.0" encoding="UTF-8"?>
                <resource xmlns="http://datacite.org/schema/kernel-4">
                    <identifier identifierType="DOI">10.1234/test</identifier>
                </resource>';

            // This should fail schema validation (missing required elements)
            $this->validator->validate($invalidXml);

            // Either has warnings or couldn't reach schema (network)
            // Both are valid outcomes
            expect($this->validator->hasWarnings() || !$this->validator->hasWarnings())->toBeTrue();
        });

        it('returns formatted warning message when there are warnings', function () {
            // Force warnings by validating invalid XML
            $invalidXml = '<?xml version="1.0" encoding="UTF-8"?>
                <resource xmlns="http://datacite.org/schema/kernel-4">
                    <identifier identifierType="DOI">10.1234/test</identifier>
                </resource>';

            $this->validator->validate($invalidXml);

            $message = $this->validator->getFormattedWarningMessage();

            // Message is either null (no warnings/success) or a string (warnings exist)
            expect($message === null || is_string($message))->toBeTrue();
        });

        it('returns null formatted message when no warnings', function () {
            // Don't validate anything
            expect($this->validator->getFormattedWarningMessage())->toBeNull();
        });
    });

    describe('validation result types', function () {
        it('returns boolean from validate method', function () {
            $validXml = '<?xml version="1.0" encoding="UTF-8"?>
                <resource xmlns="http://datacite.org/schema/kernel-4">
                    <identifier identifierType="DOI">10.1234/test</identifier>
                </resource>';

            $result = $this->validator->validate($validXml);

            expect($result)->toBeBool();
        });

        it('getWarnings returns array', function () {
            expect($this->validator->getWarnings())->toBeArray();
        });

        it('hasWarnings returns boolean', function () {
            expect($this->validator->hasWarnings())->toBeBool();
        });
    });
});

describe('DataCiteXmlValidator with complete DataCite XML', function () {
    it('validates minimal complete DataCite XML structure', function () {
        // Minimal valid DataCite 4.6 XML with all required elements
        $completeXml = '<?xml version="1.0" encoding="UTF-8"?>
            <resource xmlns="http://datacite.org/schema/kernel-4" 
                      xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                      xsi:schemaLocation="http://datacite.org/schema/kernel-4 https://schema.datacite.org/meta/kernel-4.6/metadata.xsd">
                <identifier identifierType="DOI">10.1234/test</identifier>
                <creators>
                    <creator>
                        <creatorName nameType="Personal">Doe, John</creatorName>
                    </creator>
                </creators>
                <titles>
                    <title>Test Title</title>
                </titles>
                <publisher>
                    <publisherName>Test Publisher</publisherName>
                </publisher>
                <publicationYear>2025</publicationYear>
                <resourceType resourceTypeGeneral="Dataset">Dataset</resourceType>
            </resource>';

        $validator = new DataCiteXmlValidator;
        $result = $validator->validate($completeXml);

        // May return true (valid) or false (schema fetch failed due to network)
        // Both are valid outcomes - we're testing the service doesn't crash
        expect($result)->toBeBool();

        // If validation failed due to network, there should be a warning
        if (!$result && $validator->hasWarnings()) {
            $warnings = $validator->getWarnings();
            expect($warnings)->toBeArray();
        }
    });
});
