<?php

declare(strict_types=1);

namespace App\Services\Iso19115;

use App\Support\Iso19115\Iso19115ValidationResult;
use DOMDocument;
use DOMElement;
use DOMXPath;
use LibXMLError;
use XSLTProcessor;

/**
 * Offline validation for the ERNIE ISO 19115-3 profile.
 *
 * It enforces the schema-mandatory structure used by the crosswalk and the
 * applicable ISO mdb Schematron constraints. No external entity or network
 * access is permitted during validation.
 */
class Iso19115XmlValidator
{
    /**
     * @var array<string, string>
     */
    private const NAMESPACES = [
        'mdb' => Iso19115XmlExporter::MDB_NAMESPACE,
        'mcc' => Iso19115XmlExporter::MCC_NAMESPACE,
        'cit' => Iso19115XmlExporter::CIT_NAMESPACE,
        'mri' => Iso19115XmlExporter::MRI_NAMESPACE,
        'gex' => Iso19115XmlExporter::GEX_NAMESPACE,
        'mco' => Iso19115XmlExporter::MCO_NAMESPACE,
        'mrd' => Iso19115XmlExporter::MRD_NAMESPACE,
        'gml' => Iso19115XmlExporter::GML_NAMESPACE,
        'lan' => Iso19115XmlExporter::LAN_NAMESPACE,
        'gco' => Iso19115XmlExporter::GCO_NAMESPACE,
        'xsi' => Iso19115XmlExporter::XSI_NAMESPACE,
    ];

    public function validate(string $xml): Iso19115ValidationResult
    {
        if (trim($xml) === '') {
            return new Iso19115ValidationResult(['The XML document is empty.']);
        }

        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            return new Iso19115ValidationResult(['DOCTYPE and ENTITY declarations are not permitted.']);
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $document = new DOMDocument;
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT);
            if (! $loaded) {
                return new Iso19115ValidationResult($this->formatLibxmlErrors(libxml_get_errors()));
            }

            $errors = [];
            $warnings = [];
            $root = $document->documentElement;
            if (! $root instanceof DOMElement
                || $root->localName !== 'MD_Metadata'
                || $root->namespaceURI !== Iso19115XmlExporter::MDB_NAMESPACE) {
                return new Iso19115ValidationResult([
                    'The root element must be mdb:MD_Metadata in the ISO 19115-1 mdb/1.3 namespace.',
                ]);
            }

            $this->validateAgainstLocalSchema($document, $errors);
            $this->validateSchematron($document, $errors, $warnings);

            $xpath = new DOMXPath($document);
            foreach (self::NAMESPACES as $prefix => $namespace) {
                $xpath->registerNamespace($prefix, $namespace);
            }

            $expectedSchemaLocation = Iso19115XmlExporter::MDB_NAMESPACE.' '.(string) config('iso19115.schema');
            $actualSchemaLocation = trim($root->getAttributeNS(
                Iso19115XmlExporter::XSI_NAMESPACE,
                'schemaLocation',
            ));
            if ($actualSchemaLocation !== $expectedSchemaLocation) {
                $errors[] = 'xsi:schemaLocation does not reference the configured ISO 19115-3 schema.';
            }

            $this->requireValue(
                $xpath,
                '/mdb:MD_Metadata/mdb:metadataIdentifier/mcc:MD_Identifier/mcc:code/gco:CharacterString',
                'A metadata identifier is required.',
                $errors,
            );
            $this->requireValue(
                $xpath,
                '/mdb:MD_Metadata/mdb:contact/cit:CI_Responsibility/cit:role/cit:CI_RoleCode/@codeListValue',
                'At least one metadata contact role is required.',
                $errors,
            );
            $this->requireValue(
                $xpath,
                '/mdb:MD_Metadata/mdb:contact/cit:CI_Responsibility/cit:party/*/cit:name/gco:CharacterString',
                'At least one named metadata contact is required.',
                $errors,
            );
            $this->requireValue(
                $xpath,
                '/mdb:MD_Metadata/mdb:dateInfo/cit:CI_Date[cit:dateType/cit:CI_DateTypeCode/@codeListValue="creation"]/cit:date/*[self::gco:Date or self::gco:DateTime]',
                'A metadata creation date is required by the mdb Schematron rules.',
                $errors,
            );
            $this->requireValue(
                $xpath,
                '/mdb:MD_Metadata/mdb:identificationInfo/mri:MD_DataIdentification/mri:citation/cit:CI_Citation/cit:title/gco:CharacterString',
                'The resource citation title is required.',
                $errors,
            );

            $abstractCount = $xpath->evaluate(
                'count(/mdb:MD_Metadata/mdb:identificationInfo/mri:MD_DataIdentification/mri:abstract[gco:CharacterString[normalize-space(.) != ""] or @gco:nilReason])',
            );
            if (! is_float($abstractCount) || $abstractCount < 1) {
                $errors[] = 'The resource abstract property requires a value or gco:nilReason.';
            } elseif ((float) $xpath->evaluate(
                'count(/mdb:MD_Metadata/mdb:identificationInfo/mri:MD_DataIdentification/mri:abstract/@gco:nilReason)',
            ) > 0) {
                $warnings[] = 'The resource has no abstract value; ISO nilReason="missing" was emitted.';
            }

            $localeCount = (float) $xpath->evaluate(
                'count(//lan:PT_Locale[not(lan:characterEncoding/lan:MD_CharacterSetCode/@codeListValue = "utf8")])',
            );
            if ($localeCount > 0) {
                $errors[] = 'Every PT_Locale must declare UTF-8 character encoding.';
            }

            $nonDatasetScopeWithoutName = (float) $xpath->evaluate(
                'count(/mdb:MD_Metadata/mdb:metadataScope/mdb:MD_MetadataScope['
                .'not(mdb:resourceScope/mcc:MD_ScopeCode/@codeListValue = "dataset")'
                .' and not(mdb:name/gco:CharacterString[normalize-space(.) != ""] or mdb:name/@gco:nilReason)'
                .'])',
            );
            if ($nonDatasetScopeWithoutName > 0) {
                $errors[] = 'A non-dataset metadata scope requires a name.';
            }

            $validScopeCodes = array_values((array) config('iso19115.resource_scopes', []));
            $validScopeCodes[] = 'coverage';
            foreach ($xpath->query('//mcc:MD_ScopeCode/@codeListValue') ?: [] as $attribute) {
                if (! in_array($attribute->nodeValue, $validScopeCodes, true)) {
                    $errors[] = "Unsupported MD_ScopeCode '{$attribute->nodeValue}'.";
                }
            }

            $this->validateSchemaElementOrder($xpath, $errors);
            $this->validateBoundingBoxes($xpath, $errors);

            if ((float) $xpath->evaluate('count(//mri:pointOfContact)') === 0.0) {
                $warnings[] = 'No resource-level point of contact is available.';
            }

            if ((float) $xpath->evaluate('count(//gex:EX_GeographicBoundingBox)') === 0.0) {
                $warnings[] = 'No geographic bounding box is available.';
            }

            return new Iso19115ValidationResult(
                errors: array_values(array_unique($errors)),
                warnings: array_values(array_unique($warnings)),
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private function validateAgainstLocalSchema(DOMDocument $document, array &$errors): void
    {
        $schemaPath = config('iso19115.validation.schema');
        $catalogPath = config('iso19115.validation.catalog');
        if (! is_string($schemaPath) || ! is_file($schemaPath)) {
            $errors[] = 'The pinned ISO 19115-3 aggregation schema is missing.';

            return;
        }
        if (! is_string($catalogPath) || ! is_file($catalogPath)) {
            $errors[] = 'The pinned ISO 19115-3 XML catalog is missing.';

            return;
        }

        $previousCatalog = getenv('XML_CATALOG_FILES');
        putenv("XML_CATALOG_FILES={$catalogPath}");
        libxml_clear_errors();

        try {
            if (! $document->schemaValidate($schemaPath, LIBXML_NONET)) {
                foreach ($this->formatLibxmlErrors(libxml_get_errors(), 'XSD validation') as $message) {
                    $errors[] = $message;
                }
            }
        } finally {
            libxml_clear_errors();
            if ($previousCatalog === false) {
                putenv('XML_CATALOG_FILES');
            } else {
                putenv("XML_CATALOG_FILES={$previousCatalog}");
            }
        }
    }

    /**
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    private function validateSchematron(
        DOMDocument $document,
        array &$errors,
        array &$warnings,
    ): void {
        $stylesheetPath = config('iso19115.validation.schematron_xslt');
        if (! is_string($stylesheetPath) || ! is_file($stylesheetPath)) {
            $errors[] = 'The pinned ISO 19115-3 Schematron stylesheet is missing.';

            return;
        }
        if (! class_exists(XSLTProcessor::class)) {
            $errors[] = 'The ext-xsl runtime extension is required for ISO Schematron validation.';

            return;
        }

        $stylesheet = new DOMDocument;
        if (! $stylesheet->load($stylesheetPath, LIBXML_NONET | LIBXML_NOBLANKS)) {
            $errors[] = 'The pinned ISO Schematron stylesheet cannot be parsed.';

            return;
        }

        $processor = new XSLTProcessor;
        $processor->setSecurityPrefs(
            XSL_SECPREF_READ_FILE
            | XSL_SECPREF_WRITE_FILE
            | XSL_SECPREF_CREATE_DIRECTORY
            | XSL_SECPREF_READ_NETWORK
            | XSL_SECPREF_WRITE_NETWORK,
        );
        if (! $processor->importStylesheet($stylesheet)) {
            $errors[] = 'The pinned ISO Schematron stylesheet cannot be compiled.';

            return;
        }

        $svrlXml = $processor->transformToXML($document);
        if (! is_string($svrlXml)) {
            $errors[] = 'ISO Schematron validation did not produce an SVRL report.';

            return;
        }

        $svrl = new DOMDocument;
        if (! $svrl->loadXML($svrlXml, LIBXML_NONET | LIBXML_NOBLANKS)) {
            $errors[] = 'The ISO Schematron SVRL report is malformed.';

            return;
        }

        $xpath = new DOMXPath($svrl);
        $xpath->registerNamespace('svrl', 'http://purl.oclc.org/dsdl/svrl');
        foreach ($xpath->query('//svrl:failed-assert') ?: [] as $assertion) {
            if (! $assertion instanceof DOMElement) {
                continue;
            }

            $id = trim($assertion->getAttribute('id'));
            $role = trim($assertion->getAttribute('role'));
            $location = trim($assertion->getAttribute('location'));
            $text = trim((string) $xpath->evaluate('string(svrl:text)', $assertion));
            $message = sprintf(
                'Schematron %s%s: %s',
                $id !== '' ? "[{$id}]" : 'assertion',
                $location !== '' ? " at {$location}" : '',
                $text !== '' ? $text : 'Assertion failed.',
            );

            if ($role === 'warning') {
                $warnings[] = $message;
            } else {
                $errors[] = $message;
            }
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private function requireValue(
        DOMXPath $xpath,
        string $expression,
        string $message,
        array &$errors,
    ): void {
        $count = $xpath->evaluate("count({$expression}[normalize-space(.) != ''])");
        if (! is_float($count) || $count < 1) {
            $errors[] = $message;
        }
    }

    /**
     * Enforce the sequence constraints of the official mdb, mri and cit XSDs.
     *
     * @param  list<string>  $errors
     */
    private function validateSchemaElementOrder(DOMXPath $xpath, array &$errors): void
    {
        $this->validateChildOrder(
            $xpath,
            '/mdb:MD_Metadata',
            [
                'metadataIdentifier',
                'defaultLocale',
                'parentMetadata',
                'contact',
                'dateInfo',
                'metadataStandard',
                'metadataProfile',
                'alternativeMetadataReference',
                'otherLocale',
                'metadataLinkage',
                'spatialRepresentationInfo',
                'referenceSystemInfo',
                'metadataExtensionInfo',
                'identificationInfo',
                'contentInfo',
                'distributionInfo',
                'dataQualityInfo',
                'portrayalCatalogueInfo',
                'metadataConstraints',
                'applicationSchemaInfo',
                'metadataMaintenance',
                'resourceLineage',
                'metadataScope',
                'describes',
                'acquisitionInformation',
            ],
            'mdb:MD_Metadata',
            $errors,
        );
        $this->validateChildOrder(
            $xpath,
            '//mri:MD_DataIdentification',
            [
                'citation',
                'abstract',
                'purpose',
                'credit',
                'status',
                'pointOfContact',
                'spatialRepresentationType',
                'spatialResolution',
                'temporalResolution',
                'topicCategory',
                'extent',
                'additionalDocumentation',
                'processingLevel',
                'resourceMaintenance',
                'graphicOverview',
                'resourceFormat',
                'descriptiveKeywords',
                'resourceSpecificUsage',
                'resourceConstraints',
                'associatedResource',
                'defaultLocale',
                'otherLocale',
                'environmentDescription',
                'supplementalInformation',
            ],
            'mri:MD_DataIdentification',
            $errors,
        );
        $this->validateChildOrder(
            $xpath,
            '//cit:CI_Citation',
            [
                'title',
                'alternateTitle',
                'date',
                'edition',
                'editionDate',
                'identifier',
                'citedResponsibleParty',
                'presentationForm',
                'series',
                'otherCitationDetails',
                'ISBN',
                'ISSN',
                'onlineResource',
                'graphic',
            ],
            'cit:CI_Citation',
            $errors,
        );
    }

    /**
     * @param  list<string>  $orderedNames
     * @param  list<string>  $errors
     */
    private function validateChildOrder(
        DOMXPath $xpath,
        string $parentExpression,
        array $orderedNames,
        string $label,
        array &$errors,
    ): void {
        $positions = array_flip($orderedNames);
        foreach ($xpath->query($parentExpression) ?: [] as $parent) {
            if (! $parent instanceof DOMElement) {
                continue;
            }

            $lastPosition = -1;
            foreach ($parent->childNodes as $child) {
                if (! $child instanceof DOMElement) {
                    continue;
                }

                $localName = $child->localName;
                if (! is_string($localName) || ! isset($positions[$localName])) {
                    continue;
                }

                $position = $positions[$localName];
                if ($position < $lastPosition) {
                    $errors[] = "{$label} child elements do not follow the official XSD sequence.";

                    break;
                }
                $lastPosition = $position;
            }
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private function validateBoundingBoxes(DOMXPath $xpath, array &$errors): void
    {
        foreach ($xpath->query('//gex:EX_GeographicBoundingBox') ?: [] as $box) {
            if (! $box instanceof DOMElement) {
                continue;
            }

            $values = [];
            foreach ([
                'west' => 'gex:westBoundLongitude/gco:Decimal',
                'east' => 'gex:eastBoundLongitude/gco:Decimal',
                'south' => 'gex:southBoundLatitude/gco:Decimal',
                'north' => 'gex:northBoundLatitude/gco:Decimal',
            ] as $key => $expression) {
                $value = $xpath->evaluate("string({$expression})", $box);
                if (! is_string($value) || ! is_numeric($value)) {
                    $errors[] = 'Every geographic bounding-box coordinate must be numeric.';

                    continue 2;
                }
                $values[$key] = (float) $value;
            }

            if ($values['west'] < -180 || $values['west'] > 180
                || $values['east'] < -180 || $values['east'] > 180
                || $values['south'] < -90 || $values['south'] > 90
                || $values['north'] < -90 || $values['north'] > 90) {
                $errors[] = 'Geographic bounding-box coordinates are outside valid longitude/latitude ranges.';
            }
            if ($values['west'] > $values['east'] || $values['south'] > $values['north']) {
                $errors[] = 'Geographic bounding-box minimum coordinates must not exceed maximum coordinates.';
            }
        }
    }

    /**
     * @param  array<int, LibXMLError>  $errors
     * @return list<string>
     */
    private function formatLibxmlErrors(
        array $errors,
        string $context = 'XML parsing',
    ): array {
        $messages = array_map(
            static fn (LibXMLError $error): string => sprintf(
                '%s error at line %d, column %d: %s',
                $context,
                $error->line,
                $error->column,
                trim($error->message),
            ),
            $errors,
        );

        return $messages !== [] ? array_values(array_unique($messages)) : ['The XML document is not well formed.'];
    }
}
