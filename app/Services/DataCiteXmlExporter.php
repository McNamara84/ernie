<?php

namespace App\Services;

use App\Models\Institution;
use App\Models\Person;
use App\Models\Publisher;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Services\Traits\DataCiteExporterHelpers;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Facades\Log;

/**
 * Service for exporting Resource data to DataCite XML format (v4.6)
 *
 * Implements the DataCite Metadata Schema v4.6 XML format.
 * Schema: https://schema.datacite.org/meta/kernel-4.6/metadata.xsd
 * Documentation: https://datacite-metadata-schema.readthedocs.io/en/4.6/
 *
 * DataCite 4.6 additions over 4.5:
 * - resourceTypeGeneral: Award, Project
 * - relatedIdentifierType: CSTR, RRID
 * - contributorType: Translator
 * - relationType: HasTranslation, IsTranslationOf
 * - dateType: Coverage
 */
class DataCiteXmlExporter
{
    use DataCiteExporterHelpers;
    /**
     * DataCite namespace constants
     */
    private const DATACITE_NAMESPACE = 'http://datacite.org/schema/kernel-4';

    private const XSI_NAMESPACE = 'http://www.w3.org/2001/XMLSchema-instance';

    private const XML_NAMESPACE = 'http://www.w3.org/XML/1998/namespace';

    private const SCHEMA_LOCATION = 'http://datacite.org/schema/kernel-4 https://schema.datacite.org/meta/kernel-4.6/metadata.xsd';

    private DOMDocument $dom;

    private DOMElement $root;

    /**
     * Export a Resource to DataCite XML format
     *
     * @param  resource  $resource  The resource to export
     * @return string The DataCite XML string
     */
    #[\NoDiscard('Exported XML string must be used')]
    public function export(Resource $resource): string
    {
        // Load all necessary relationships
        $resource->load($this->getRequiredRelations());

        // Initialize DOM
        $this->dom = new DOMDocument('1.0', 'UTF-8');
        $this->dom->formatOutput = true;
        $this->dom->preserveWhiteSpace = false;

        // Create root element with namespaces
        $this->root = $this->dom->createElementNS(self::DATACITE_NAMESPACE, 'resource');
        $this->root->setAttributeNS(
            'http://www.w3.org/2000/xmlns/',
            'xmlns:xsi',
            self::XSI_NAMESPACE
        );
        $this->root->setAttributeNS(
            self::XSI_NAMESPACE,
            'xsi:schemaLocation',
            self::SCHEMA_LOCATION
        );
        $this->dom->appendChild($this->root);

        // Build XML structure
        $this->buildIdentifier($resource);
        $this->buildCreators($resource);
        $this->buildTitles($resource);
        $this->buildPublisher($resource);
        $this->buildPublicationYear($resource);
        $this->buildResourceType($resource);

        // Optional elements
        $this->buildSubjects($resource);
        $this->buildContributors($resource);
        $this->buildDates($resource);
        $this->buildLanguage($resource);
        $this->buildAlternateIdentifiers($resource);
        $this->buildRelatedIdentifiers($resource);
        $this->buildSizes($resource);
        $this->buildFormats($resource);
        $this->buildVersion($resource);
        $this->buildRightsList($resource);
        $this->buildDescriptions($resource);
        $this->buildGeoLocations($resource);
        $this->buildFundingReferences($resource);

        $xml = $this->dom->saveXML();

        if ($xml === false) {
            throw new \RuntimeException('Failed to generate XML from DOM document');
        }

        return $xml;
    }

    /**
     * Build identifier element (required)
     */
    private function buildIdentifier(Resource $resource): void
    {
        $identifier = $this->dom->createElement('identifier', htmlspecialchars($resource->doi ?? ''));
        $identifier->setAttribute('identifierType', 'DOI');
        $this->root->appendChild($identifier);
    }

    /**
     * Build creators element (required)
     */
    private function buildCreators(Resource $resource): void
    {
        $creators = $this->dom->createElement('creators');

        $hasCreators = false;
        foreach ($resource->creators as $creator) {
            $creatorElement = null;

            if ($creator->creatorable_type === Person::class) {
                $creatorElement = $this->buildPersonCreator($creator);
            } elseif ($creator->creatorable_type === Institution::class) {
                $creatorElement = $this->buildInstitutionCreator($creator);
            }

            if ($creatorElement) {
                $creators->appendChild($creatorElement);
                $hasCreators = true;
            }
        }

        // If no creators, add a default one (required by schema)
        if (! $hasCreators) {
            $creator = $this->dom->createElement('creator');
            $creatorName = $this->dom->createElement('creatorName', 'Unknown');
            $creatorName->setAttribute('nameType', 'Personal');
            $creator->appendChild($creatorName);
            $creators->appendChild($creator);
        }

        $this->root->appendChild($creators);
    }

    /**
     * Build a person creator element
     */
    private function buildPersonCreator(ResourceCreator $creator): ?DOMElement
    {
        /** @var Person|null $person */
        $person = $creator->creatorable;

        if (! $person instanceof Person) {
            return null;
        }

        $creatorElement = $this->dom->createElement('creator');

        // Creator name (required) - use shared helper
        $creatorName = $this->dom->createElement('creatorName', htmlspecialchars($this->formatPersonName($person)));
        $creatorName->setAttribute('nameType', 'Personal');
        $creatorElement->appendChild($creatorName);

        // Given name (optional)
        if ($person->given_name) {
            $givenName = $this->dom->createElement('givenName', htmlspecialchars($person->given_name));
            $creatorElement->appendChild($givenName);
        }

        // Family name (optional)
        if ($person->family_name) {
            $familyName = $this->dom->createElement('familyName', htmlspecialchars($person->family_name));
            $creatorElement->appendChild($familyName);
        }

        // Name identifiers (ORCID or other) - use shared helper
        if ($nameIdData = $this->buildPersonNameIdentifier($person)) {
            $nameIdentifier = $this->dom->createElement('nameIdentifier', htmlspecialchars($nameIdData['nameIdentifier']));
            $nameIdentifier->setAttribute('nameIdentifierScheme', $nameIdData['nameIdentifierScheme']);
            $nameIdentifier->setAttribute('schemeURI', $nameIdData['schemeUri']);
            $creatorElement->appendChild($nameIdentifier);
        }

        // Affiliations - use shared helper
        foreach ($this->transformAffiliations($creator) as $affiliationData) {
            $affiliationElement = $this->dom->createElement('affiliation', htmlspecialchars($affiliationData['name'] ?? ''));

            if (isset($affiliationData['affiliationIdentifier'])) {
                $affiliationElement->setAttribute('affiliationIdentifier', htmlspecialchars($affiliationData['affiliationIdentifier']));
                $affiliationElement->setAttribute('affiliationIdentifierScheme', $affiliationData['affiliationIdentifierScheme'] ?? 'ROR');
                if (isset($affiliationData['schemeURI'])) {
                    $affiliationElement->setAttribute('schemeURI', htmlspecialchars($affiliationData['schemeURI']));
                }
            }

            $creatorElement->appendChild($affiliationElement);
        }

        return $creatorElement;
    }

    /**
     * Build an institution creator element
     */
    private function buildInstitutionCreator(ResourceCreator $creator): ?DOMElement
    {
        /** @var Institution|null $institution */
        $institution = $creator->creatorable;

        if (! $institution instanceof Institution) {
            return null;
        }

        $creatorElement = $this->dom->createElement('creator');

        // Creator name (required) - use shared helper
        $creatorName = $this->dom->createElement(
            'creatorName',
            htmlspecialchars($this->formatInstitutionName($institution))
        );
        $creatorName->setAttribute('nameType', 'Organizational');
        $creatorElement->appendChild($creatorName);

        // Name identifiers (ROR or other) - use shared helper
        if ($nameIdData = $this->buildInstitutionNameIdentifier($institution)) {
            $nameIdentifier = $this->dom->createElement('nameIdentifier', htmlspecialchars($nameIdData['nameIdentifier']));
            $nameIdentifier->setAttribute('nameIdentifierScheme', $nameIdData['nameIdentifierScheme']);
            $nameIdentifier->setAttribute('schemeURI', $nameIdData['schemeUri']);
            $creatorElement->appendChild($nameIdentifier);
        }

        // Affiliations - use shared helper
        foreach ($this->transformAffiliations($creator) as $affiliationData) {
            $affiliationElement = $this->dom->createElement('affiliation', htmlspecialchars($affiliationData['name'] ?? ''));

            if (isset($affiliationData['affiliationIdentifier'])) {
                $affiliationElement->setAttribute('affiliationIdentifier', htmlspecialchars($affiliationData['affiliationIdentifier']));
                $affiliationElement->setAttribute('affiliationIdentifierScheme', $affiliationData['affiliationIdentifierScheme'] ?? 'ROR');
                if (isset($affiliationData['schemeURI'])) {
                    $affiliationElement->setAttribute('schemeURI', htmlspecialchars($affiliationData['schemeURI']));
                }
            }

            $creatorElement->appendChild($affiliationElement);
        }

        return $creatorElement;
    }

    /**
     * Build titles element (required)
     */
    private function buildTitles(Resource $resource): void
    {
        $titles = $this->dom->createElement('titles');

        foreach ($resource->titles as $title) {
            $titleElement = $this->dom->createElement('title', htmlspecialchars($title->value));

            // Add title type if not main title (DataCite convention: MainTitle has no titleType attribute)
            if (! $title->isMainTitle()) {
                // Use null-safe operator for legacy data where titleType may be null
                /** @phpstan-ignore nullsafe.neverNull (titleType may be null in legacy data before migration) */
                $slug = $title->titleType?->slug ?? 'Other';
                $titleElement->setAttribute('titleType', $slug);
            }

            // Add language
            if ($resource->language) {
                $titleElement->setAttributeNS(
                    self::XML_NAMESPACE,
                    'xml:lang',
                    $resource->language->iso_code ?? 'en'
                );
            }

            $titles->appendChild($titleElement);
        }

        // If no titles, add a default one (required by schema)
        if ($resource->titles->isEmpty()) {
            $titleElement = $this->dom->createElement('title', 'Untitled');
            $titles->appendChild($titleElement);
        }

        $this->root->appendChild($titles);
    }

    /**
     * Build publisher element (required) according to DataCite Schema 4.6.
     *
     * Uses the resource's publisher if available, otherwise falls back
     * to the default publisher (GFZ Data Services).
     *
     * @see https://datacite-metadata-schema.readthedocs.io/en/4.6/properties/publisher/
     */
    private function buildPublisher(Resource $resource): void
    {
        $publisherModel = $resource->publisher ?? Publisher::getDefault();

        if (! $publisherModel) {
            // Ultimate fallback if no default publisher exists in database
            $publisher = $this->dom->createElement('publisher', htmlspecialchars('GFZ Data Services'));
            $this->root->appendChild($publisher);

            return;
        }

        $publisher = $this->dom->createElement('publisher', htmlspecialchars($publisherModel->name));

        if ($publisherModel->identifier) {
            $publisher->setAttribute('publisherIdentifier', htmlspecialchars($publisherModel->identifier));
            if ($publisherModel->identifier_scheme) {
                $publisher->setAttribute('publisherIdentifierScheme', htmlspecialchars($publisherModel->identifier_scheme));
            }
            if ($publisherModel->scheme_uri) {
                $publisher->setAttribute('schemeURI', htmlspecialchars($publisherModel->scheme_uri));
            }
        }

        // Use publisher's language attribute (DataCite 4.6 semantics)
        // The lang attribute refers to the language of the publisher name, not the resource
        if ($publisherModel->language) {
            $publisher->setAttributeNS(
                self::XML_NAMESPACE,
                'xml:lang',
                $publisherModel->language
            );
        }

        $this->root->appendChild($publisher);
    }

    /**
     * Build publicationYear element (required)
     */
    private function buildPublicationYear(Resource $resource): void
    {
        $publicationYear = $this->dom->createElement(
            'publicationYear',
            htmlspecialchars((string) $resource->publication_year)
        );
        $this->root->appendChild($publicationYear);
    }

    /**
     * Build resourceType element (required).
     *
     * For IGSN resources (PhysicalObject), uses sample_type and/or material
     * from IGSN metadata as the specific resourceType value.
     */
    private function buildResourceType(Resource $resource): void
    {
        $resourceType = $resource->resourceType;
        $typeName = $resourceType->name ?? 'Other';

        // Map to DataCite resourceTypeGeneral format
        $resourceTypeGeneral = match ($typeName) {
            'Physical Object' => 'PhysicalObject',
            'Book Chapter' => 'BookChapter',
            'Conference Paper' => 'ConferencePaper',
            'Conference Proceeding' => 'ConferenceProceeding',
            'Computational Notebook' => 'ComputationalNotebook',
            'Data Paper' => 'DataPaper',
            'Interactive Resource' => 'InteractiveResource',
            'Journal Article' => 'JournalArticle',
            'Output Management Plan' => 'OutputManagementPlan',
            'Peer Review' => 'PeerReview',
            'Study Registration' => 'StudyRegistration',
            default => str_replace(' ', '', $typeName),
        };

        // For PhysicalObject (IGSN), build specific resourceType from sample_type and material
        $specificType = $typeName;
        if ($resourceTypeGeneral === 'PhysicalObject' && $resource->igsnMetadata) {
            $specificType = $this->buildIgsnResourceType($resource->igsnMetadata);
        }

        $resourceTypeElement = $this->dom->createElement(
            'resourceType',
            htmlspecialchars($specificType)
        );
        $resourceTypeElement->setAttribute('resourceTypeGeneral', $resourceTypeGeneral);
        $this->root->appendChild($resourceTypeElement);
    }

    /**
     * Build specific resourceType value for IGSN from sample_type and material.
     *
     * Combines sample_type and material with a colon separator when both are available.
     * Returns "Physical Object" as fallback when neither is set.
     */
    private function buildIgsnResourceType(\App\Models\IgsnMetadata $igsnMetadata): string
    {
        $parts = array_filter([
            $igsnMetadata->sample_type,
            $igsnMetadata->material,
        ]);

        if (empty($parts)) {
            return 'Physical Object';
        }

        return implode(': ', $parts);
    }

    /**
     * Build subjects element (optional)
     */
    private function buildSubjects(Resource $resource): void
    {
        if ($resource->subjects->isEmpty()) {
            return;
        }

        $subjects = $this->dom->createElement('subjects');

        foreach ($resource->subjects as $subjectModel) {
            $subject = $this->dom->createElement('subject', htmlspecialchars($subjectModel->value));

            if ($subjectModel->subject_scheme) {
                $subject->setAttribute('subjectScheme', htmlspecialchars($subjectModel->subject_scheme));
            }

            if ($subjectModel->scheme_uri) {
                $subject->setAttribute('schemeURI', htmlspecialchars($subjectModel->scheme_uri));
            }

            if ($subjectModel->value_uri) {
                $subject->setAttribute('valueURI', htmlspecialchars($subjectModel->value_uri));
            }

            if ($subjectModel->classification_code) {
                $subject->setAttribute('classificationCode', htmlspecialchars($subjectModel->classification_code));
            }

            $subjects->appendChild($subject);
        }

        $this->root->appendChild($subjects);
    }

    /**
     * Build contributors element (optional)
     */
    private function buildContributors(Resource $resource): void
    {
        if ($resource->contributors->isEmpty()) {
            return;
        }

        $contributors = $this->dom->createElement('contributors');
        $hasContributors = false;

        foreach ($resource->contributors as $contributor) {
            $contributorElement = null;

            // Check if this is an MSL Laboratory
            if ($contributor->contributorable_type === Institution::class) {
                /** @var Institution|null $institution */
                $institution = $contributor->contributorable;
                if ($institution instanceof Institution && $institution->isLaboratory()) {
                    $contributorElement = $this->buildMslLaboratoryContributor($contributor);
                    if ($contributorElement) {
                        $contributors->appendChild($contributorElement);
                        $hasContributors = true;
                    }

                    continue;
                }
            }

            // Regular contributor - get type from contributorType relation
            $contributorType = $contributor->contributorType->slug ?? 'Other';

            if ($contributor->contributorable_type === Person::class) {
                $contributorElement = $this->buildPersonContributor($contributor, $contributorType);
            } elseif ($contributor->contributorable_type === Institution::class) {
                $contributorElement = $this->buildInstitutionContributor($contributor, $contributorType);
            }

            if ($contributorElement) {
                $contributors->appendChild($contributorElement);
                $hasContributors = true;
            }
        }

        if ($hasContributors) {
            $this->root->appendChild($contributors);
        }
    }

    /**
     * Build MSL Laboratory contributor element
     */
    private function buildMslLaboratoryContributor(ResourceContributor $contributor): ?DOMElement
    {
        /** @var Institution|null $institution */
        $institution = $contributor->contributorable;

        if (! $institution instanceof Institution) {
            return null;
        }

        $contributorElement = $this->dom->createElement('contributor');
        $contributorElement->setAttribute('contributorType', 'HostingInstitution');

        // Contributor name (required)
        $contributorName = $this->dom->createElement(
            'contributorName',
            htmlspecialchars($institution->name ?? 'Unknown Laboratory')
        );
        $contributorName->setAttribute('nameType', 'Organizational');
        $contributorElement->appendChild($contributorName);

        // Laboratory identifier
        if ($institution->name_identifier) {
            $nameIdentifier = $this->dom->createElement('nameIdentifier', htmlspecialchars($institution->name_identifier));
            $nameIdentifier->setAttribute('nameIdentifierScheme', $institution->name_identifier_scheme ?? 'labid');
            $contributorElement->appendChild($nameIdentifier);
        }

        // Affiliations
        foreach ($contributor->affiliations as $affiliation) {
            $affiliationElement = $this->dom->createElement('affiliation', htmlspecialchars($affiliation->name));

            if ($affiliation->identifier) {
                $affiliationElement->setAttribute('affiliationIdentifier', htmlspecialchars($affiliation->identifier));
                $affiliationElement->setAttribute('affiliationIdentifierScheme', $affiliation->identifier_scheme ?? 'ROR');
                if ($affiliation->scheme_uri) {
                    $affiliationElement->setAttribute('schemeURI', htmlspecialchars($affiliation->scheme_uri));
                }
            }

            $contributorElement->appendChild($affiliationElement);
        }

        return $contributorElement;
    }

    /**
     * Build a person contributor element
     */
    private function buildPersonContributor(ResourceContributor $contributor, string $contributorType): ?DOMElement
    {
        /** @var Person|null $person */
        $person = $contributor->contributorable;

        if (! $person instanceof Person) {
            return null;
        }

        $contributorElement = $this->dom->createElement('contributor');
        $contributorElement->setAttribute('contributorType', $contributorType);

        // Contributor name (required) - use shared helper
        $contributorName = $this->dom->createElement('contributorName', htmlspecialchars($this->formatPersonName($person)));
        $contributorName->setAttribute('nameType', 'Personal');
        $contributorElement->appendChild($contributorName);

        // Given name (optional)
        if ($person->given_name) {
            $givenName = $this->dom->createElement('givenName', htmlspecialchars($person->given_name));
            $contributorElement->appendChild($givenName);
        }

        // Family name (optional)
        if ($person->family_name) {
            $familyName = $this->dom->createElement('familyName', htmlspecialchars($person->family_name));
            $contributorElement->appendChild($familyName);
        }

        // Name identifiers (ORCID or other) - use shared helper
        if ($nameIdData = $this->buildPersonNameIdentifier($person)) {
            $nameIdentifier = $this->dom->createElement('nameIdentifier', htmlspecialchars($nameIdData['nameIdentifier']));
            $nameIdentifier->setAttribute('nameIdentifierScheme', $nameIdData['nameIdentifierScheme']);
            $nameIdentifier->setAttribute('schemeURI', $nameIdData['schemeUri']);
            $contributorElement->appendChild($nameIdentifier);
        }

        // Affiliations - use shared helper
        foreach ($this->transformAffiliations($contributor) as $affiliationData) {
            $affiliationElement = $this->dom->createElement('affiliation', htmlspecialchars($affiliationData['name'] ?? ''));

            if (isset($affiliationData['affiliationIdentifier'])) {
                $affiliationElement->setAttribute('affiliationIdentifier', htmlspecialchars($affiliationData['affiliationIdentifier']));
                $affiliationElement->setAttribute('affiliationIdentifierScheme', $affiliationData['affiliationIdentifierScheme'] ?? 'ROR');
                if (isset($affiliationData['schemeURI'])) {
                    $affiliationElement->setAttribute('schemeURI', htmlspecialchars($affiliationData['schemeURI']));
                }
            }

            $contributorElement->appendChild($affiliationElement);
        }

        return $contributorElement;
    }

    /**
     * Build an institution contributor element
     */
    private function buildInstitutionContributor(ResourceContributor $contributor, string $contributorType): ?DOMElement
    {
        /** @var Institution|null $institution */
        $institution = $contributor->contributorable;

        if (! $institution instanceof Institution) {
            return null;
        }

        $contributorElement = $this->dom->createElement('contributor');
        $contributorElement->setAttribute('contributorType', $contributorType);

        // Contributor name (required) - use shared helper
        $contributorName = $this->dom->createElement(
            'contributorName',
            htmlspecialchars($this->formatInstitutionName($institution))
        );
        $contributorName->setAttribute('nameType', 'Organizational');
        $contributorElement->appendChild($contributorName);

        // Name identifiers (ROR or other) - use shared helper
        if ($nameIdData = $this->buildInstitutionNameIdentifier($institution)) {
            $nameIdentifier = $this->dom->createElement('nameIdentifier', htmlspecialchars($nameIdData['nameIdentifier']));
            $nameIdentifier->setAttribute('nameIdentifierScheme', $nameIdData['nameIdentifierScheme']);
            $nameIdentifier->setAttribute('schemeURI', $nameIdData['schemeUri']);
            $contributorElement->appendChild($nameIdentifier);
        }

        // Affiliations - use shared helper
        foreach ($this->transformAffiliations($contributor) as $affiliationData) {
            $affiliationElement = $this->dom->createElement('affiliation', htmlspecialchars($affiliationData['name'] ?? ''));

            if (isset($affiliationData['affiliationIdentifier'])) {
                $affiliationElement->setAttribute('affiliationIdentifier', htmlspecialchars($affiliationData['affiliationIdentifier']));
                $affiliationElement->setAttribute('affiliationIdentifierScheme', $affiliationData['affiliationIdentifierScheme'] ?? 'ROR');
                if (isset($affiliationData['schemeURI'])) {
                    $affiliationElement->setAttribute('schemeURI', htmlspecialchars($affiliationData['schemeURI']));
                }
            }

            $contributorElement->appendChild($affiliationElement);
        }

        return $contributorElement;
    }

    /**
     * Build dates element (optional).
     *
     * Note: This method requires the dateType relation to be eager loaded on ResourceDate
     * objects. The ResourceController's baseQuery() does this, but if using this exporter
     * in other contexts, ensure dates are loaded with: ->with(['dates.dateType']).
     */
    private function buildDates(Resource $resource): void
    {
        if ($resource->dates->isEmpty()) {
            return;
        }

        $dates = $this->dom->createElement('dates');
        $hasDates = false;

        foreach ($resource->dates as $date) {
            // Skip if no date type - this could happen if:
            // 1. The dateType relation isn't eager loaded (N+1 query will occur)
            // 2. The date type was deleted (orphaned data)
            // @phpstan-ignore booleanNot.alwaysFalse
            if (! $date->dateType) {
                Log::warning('DataCite export: Date without dateType relation', [
                    'date_id' => $date->id,
                    'resource_id' => $resource->id,
                ]);

                continue;
            }

            // Format date value using shared helper
            $dateValue = $this->formatDateValue($date);

            // Skip dates where no value could be determined to avoid empty XML elements
            if ($dateValue === null) {
                continue;
            }

            $dateElement = $this->dom->createElement('date', htmlspecialchars($dateValue));
            $dateElement->setAttribute('dateType', $date->dateType->slug);

            if ($date->date_information) {
                $dateElement->setAttribute('dateInformation', htmlspecialchars($date->date_information));
            }

            $dates->appendChild($dateElement);
            $hasDates = true;
        }

        if ($hasDates) {
            $this->root->appendChild($dates);
        }
    }

    /**
     * Build language element (optional)
     */
    private function buildLanguage(Resource $resource): void
    {
        if ($resource->language) {
            $language = $this->dom->createElement(
                'language',
                htmlspecialchars($resource->language->iso_code ?? 'en')
            );
            $this->root->appendChild($language);
        }
    }

    /**
     * Build alternateIdentifiers element (optional)
     */
    private function buildAlternateIdentifiers(Resource $resource): void
    {
        // Currently not implemented in ERNIE data model
        // Placeholder for future implementation
    }

    /**
     * Build relatedIdentifiers element (optional)
     */
    private function buildRelatedIdentifiers(Resource $resource): void
    {
        if ($resource->relatedIdentifiers->isEmpty()) {
            return;
        }

        $relatedIdentifiers = $this->dom->createElement('relatedIdentifiers');

        foreach ($resource->relatedIdentifiers as $relatedIdentifier) {
            $relatedElement = $this->dom->createElement(
                'relatedIdentifier',
                htmlspecialchars($relatedIdentifier->identifier)
            );
            $relatedElement->setAttribute('relatedIdentifierType', $relatedIdentifier->identifierType->slug ?? 'DOI');
            $relatedElement->setAttribute('relationType', $relatedIdentifier->relationType->slug ?? 'References');

            // Add resourceTypeGeneral if available
            if ($relatedIdentifier->resource_type_general) {
                $relatedElement->setAttribute(
                    'resourceTypeGeneral',
                    htmlspecialchars($relatedIdentifier->resource_type_general)
                );
            }

            $relatedIdentifiers->appendChild($relatedElement);
        }

        $this->root->appendChild($relatedIdentifiers);
    }

    /**
     * Build sizes element (optional)
     */
    private function buildSizes(Resource $resource): void
    {
        if ($resource->sizes->isEmpty()) {
            return;
        }

        $sizes = $this->dom->createElement('sizes');

        foreach ($resource->sizes as $size) {
            $sizeElement = $this->dom->createElement('size', htmlspecialchars($size->value));
            $sizes->appendChild($sizeElement);
        }

        $this->root->appendChild($sizes);
    }

    /**
     * Build formats element (optional)
     */
    private function buildFormats(Resource $resource): void
    {
        if ($resource->formats->isEmpty()) {
            return;
        }

        $formats = $this->dom->createElement('formats');

        foreach ($resource->formats as $format) {
            $formatElement = $this->dom->createElement('format', htmlspecialchars($format->value));
            $formats->appendChild($formatElement);
        }

        $this->root->appendChild($formats);
    }

    /**
     * Build version element (optional)
     */
    private function buildVersion(Resource $resource): void
    {
        if ($resource->version) {
            $version = $this->dom->createElement('version', htmlspecialchars($resource->version));
            $this->root->appendChild($version);
        }
    }

    /**
     * Build rightsList element (optional)
     */
    private function buildRightsList(Resource $resource): void
    {
        if ($resource->rights->isEmpty()) {
            return;
        }

        $rightsList = $this->dom->createElement('rightsList');

        foreach ($resource->rights as $right) {
            $rightsElement = $this->dom->createElement('rights', htmlspecialchars($right->name));

            if ($right->uri) {
                $rightsElement->setAttribute('rightsURI', htmlspecialchars($right->uri));
            }

            if ($right->identifier) {
                $rightsElement->setAttribute('rightsIdentifier', htmlspecialchars($right->identifier));
                $rightsElement->setAttribute('rightsIdentifierScheme', 'SPDX');
                if ($right->scheme_uri) {
                    $rightsElement->setAttribute('schemeURI', htmlspecialchars($right->scheme_uri));
                }
            }

            if ($resource->language) {
                $rightsElement->setAttributeNS(
                    self::XML_NAMESPACE,
                    'xml:lang',
                    $resource->language->iso_code ?? 'en'
                );
            }

            $rightsList->appendChild($rightsElement);
        }

        $this->root->appendChild($rightsList);
    }

    /**
     * Build descriptions element (optional)
     */
    private function buildDescriptions(Resource $resource): void
    {
        if ($resource->descriptions->isEmpty()) {
            return;
        }

        $descriptions = $this->dom->createElement('descriptions');

        foreach ($resource->descriptions as $description) {
            $descriptionElement = $this->dom->createElement('description', htmlspecialchars($description->value));
            $descriptionElement->setAttribute('descriptionType', $description->descriptionType->slug ?? 'Abstract');

            if ($resource->language) {
                $descriptionElement->setAttributeNS(
                    self::XML_NAMESPACE,
                    'xml:lang',
                    $resource->language->iso_code ?? 'en'
                );
            }

            $descriptions->appendChild($descriptionElement);
        }

        $this->root->appendChild($descriptions);
    }

    /**
     * Build geoLocations element (optional)
     */
    private function buildGeoLocations(Resource $resource): void
    {
        if ($resource->geoLocations->isEmpty()) {
            return;
        }

        $geoLocations = $this->dom->createElement('geoLocations');
        $hasGeoLocations = false;

        foreach ($resource->geoLocations as $geoLocation) {
            $geoLocationElement = $this->dom->createElement('geoLocation');
            $hasContent = false;

            // Add description/place
            if ($geoLocation->place) {
                $geoLocationPlace = $this->dom->createElement(
                    'geoLocationPlace',
                    htmlspecialchars($geoLocation->place)
                );
                $geoLocationElement->appendChild($geoLocationPlace);
                $hasContent = true;
            }

            // Add point if coordinates exist
            if ($geoLocation->hasPoint()) {
                $geoLocationPoint = $this->dom->createElement('geoLocationPoint');

                $pointLongitude = $this->dom->createElement(
                    'pointLongitude',
                    htmlspecialchars((string) $geoLocation->point_longitude)
                );
                $geoLocationPoint->appendChild($pointLongitude);

                $pointLatitude = $this->dom->createElement(
                    'pointLatitude',
                    htmlspecialchars((string) $geoLocation->point_latitude)
                );
                $geoLocationPoint->appendChild($pointLatitude);

                $geoLocationElement->appendChild($geoLocationPoint);
                $hasContent = true;
            }

            // Add bounding box if coordinates exist
            if ($geoLocation->hasBox()) {
                $geoLocationBox = $this->dom->createElement('geoLocationBox');

                $westBoundLongitude = $this->dom->createElement(
                    'westBoundLongitude',
                    htmlspecialchars((string) $geoLocation->west_bound_longitude)
                );
                $geoLocationBox->appendChild($westBoundLongitude);

                $eastBoundLongitude = $this->dom->createElement(
                    'eastBoundLongitude',
                    htmlspecialchars((string) $geoLocation->east_bound_longitude)
                );
                $geoLocationBox->appendChild($eastBoundLongitude);

                $southBoundLatitude = $this->dom->createElement(
                    'southBoundLatitude',
                    htmlspecialchars((string) $geoLocation->south_bound_latitude)
                );
                $geoLocationBox->appendChild($southBoundLatitude);

                $northBoundLatitude = $this->dom->createElement(
                    'northBoundLatitude',
                    htmlspecialchars((string) $geoLocation->north_bound_latitude)
                );
                $geoLocationBox->appendChild($northBoundLatitude);

                $geoLocationElement->appendChild($geoLocationBox);
                $hasContent = true;
            }

            // Add polygons if they exist (from polygon_points JSON column)
            if (! empty($geoLocation->polygon_points) && count($geoLocation->polygon_points) >= 3) {
                $geoLocationPolygon = $this->dom->createElement('geoLocationPolygon');

                // Add regular polygon points
                foreach ($geoLocation->polygon_points as $point) {
                    $polygonPointElement = $this->dom->createElement('polygonPoint');

                    $pointLongitude = $this->dom->createElement(
                        'pointLongitude',
                        htmlspecialchars((string) $point['longitude'])
                    );
                    $polygonPointElement->appendChild($pointLongitude);

                    $pointLatitude = $this->dom->createElement(
                        'pointLatitude',
                        htmlspecialchars((string) $point['latitude'])
                    );
                    $polygonPointElement->appendChild($pointLatitude);

                    $geoLocationPolygon->appendChild($polygonPointElement);
                }

                // Add inPolygonPoint if defined (from geo_locations columns)
                if ($geoLocation->in_polygon_point_longitude !== null && $geoLocation->in_polygon_point_latitude !== null) {
                    $inPolygonPoint = $this->dom->createElement('inPolygonPoint');

                    $pointLongitude = $this->dom->createElement(
                        'pointLongitude',
                        htmlspecialchars((string) $geoLocation->in_polygon_point_longitude)
                    );
                    $inPolygonPoint->appendChild($pointLongitude);

                    $pointLatitude = $this->dom->createElement(
                        'pointLatitude',
                        htmlspecialchars((string) $geoLocation->in_polygon_point_latitude)
                    );
                    $inPolygonPoint->appendChild($pointLatitude);

                    $geoLocationPolygon->appendChild($inPolygonPoint);
                }

                $geoLocationElement->appendChild($geoLocationPolygon);
                $hasContent = true;
            }

            if ($hasContent) {
                $geoLocations->appendChild($geoLocationElement);
                $hasGeoLocations = true;
            }
        }

        if ($hasGeoLocations) {
            $this->root->appendChild($geoLocations);
        }
    }

    /**
     * Build fundingReferences element (optional)
     */
    private function buildFundingReferences(Resource $resource): void
    {
        if ($resource->fundingReferences->isEmpty()) {
            return;
        }

        $fundingReferences = $this->dom->createElement('fundingReferences');

        foreach ($resource->fundingReferences as $funding) {
            $fundingReference = $this->dom->createElement('fundingReference');

            $funderName = $this->dom->createElement('funderName', htmlspecialchars($funding->funder_name));
            $fundingReference->appendChild($funderName);

            if ($funding->funder_identifier) {
                $funderIdentifier = $this->dom->createElement(
                    'funderIdentifier',
                    htmlspecialchars($funding->funder_identifier)
                );
                $funderIdentifier->setAttribute(
                    'funderIdentifierType',
                    $funding->funderIdentifierType->slug ?? 'Other'
                );
                if ($funding->scheme_uri) {
                    $funderIdentifier->setAttribute(
                        'schemeURI',
                        htmlspecialchars($funding->scheme_uri)
                    );
                }
                $fundingReference->appendChild($funderIdentifier);
            }

            if ($funding->award_number) {
                $awardNumber = $this->dom->createElement('awardNumber', htmlspecialchars($funding->award_number));
                if ($funding->award_uri) {
                    $awardNumber->setAttribute('awardURI', htmlspecialchars($funding->award_uri));
                }
                $fundingReference->appendChild($awardNumber);
            }

            if ($funding->award_title) {
                $awardTitle = $this->dom->createElement('awardTitle', htmlspecialchars($funding->award_title));
                $fundingReference->appendChild($awardTitle);
            }

            $fundingReferences->appendChild($fundingReference);
        }

        $this->root->appendChild($fundingReferences);
    }
}
