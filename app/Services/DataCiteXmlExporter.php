<?php

namespace App\Services;

use App\Models\Institution;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use DOMDocument;
use DOMElement;

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
    public function export(Resource $resource): string
    {
        // Load all necessary relationships
        $resource->load([
            'resourceType',
            'language',
            'publisher',
            'titles.titleType',
            'creators.creatorable',
            'creators.affiliations',
            'contributors.contributorable',
            'contributors.contributorType',
            'contributors.affiliations',
            'descriptions.descriptionType',
            'dates.dateType',
            'subjects',
            'geoLocations.polygons',
            'rights',
            'relatedIdentifiers.relatedIdentifierType',
            'relatedIdentifiers.relationType',
            'fundingReferences.funderIdentifierType',
            'sizes',
            'formats',
        ]);

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

        // Creator name (required)
        $creatorName = $this->dom->createElement('creatorName');

        if ($person->family_name && $person->given_name) {
            $creatorName->nodeValue = htmlspecialchars("{$person->family_name}, {$person->given_name}");
        } elseif ($person->family_name) {
            $creatorName->nodeValue = htmlspecialchars($person->family_name);
        } elseif ($person->given_name) {
            $creatorName->nodeValue = htmlspecialchars($person->given_name);
        } else {
            $creatorName->nodeValue = 'Unknown';
        }

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

        // Name identifiers (ORCID or other)
        if ($person->name_identifier) {
            $nameIdentifier = $this->dom->createElement('nameIdentifier', htmlspecialchars($person->name_identifier));
            $nameIdentifier->setAttribute('nameIdentifierScheme', $person->name_identifier_scheme ?? 'ORCID');
            if ($person->name_identifier_scheme_uri) {
                $nameIdentifier->setAttribute('schemeURI', htmlspecialchars($person->name_identifier_scheme_uri));
            } elseif ($person->name_identifier_scheme === 'ORCID') {
                $nameIdentifier->setAttribute('schemeURI', 'https://orcid.org');
            }
            $creatorElement->appendChild($nameIdentifier);
        }

        // Affiliations
        foreach ($creator->affiliations as $affiliation) {
            $affiliationElement = $this->dom->createElement('affiliation', htmlspecialchars($affiliation->name));

            if ($affiliation->identifier) {
                $affiliationElement->setAttribute('affiliationIdentifier', htmlspecialchars($affiliation->identifier));
                $affiliationElement->setAttribute('affiliationIdentifierScheme', $affiliation->identifier_scheme ?? 'ROR');
                if ($affiliation->scheme_uri) {
                    $affiliationElement->setAttribute('schemeURI', htmlspecialchars($affiliation->scheme_uri));
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

        // Creator name (required)
        $creatorName = $this->dom->createElement(
            'creatorName',
            htmlspecialchars($institution->name ?? 'Unknown Institution')
        );
        $creatorName->setAttribute('nameType', 'Organizational');
        $creatorElement->appendChild($creatorName);

        // Name identifiers (ROR or other)
        if ($institution->name_identifier) {
            $nameIdentifier = $this->dom->createElement('nameIdentifier', htmlspecialchars($institution->name_identifier));
            $nameIdentifier->setAttribute('nameIdentifierScheme', $institution->name_identifier_scheme ?? 'ROR');
            if ($institution->name_identifier_scheme_uri) {
                $nameIdentifier->setAttribute('schemeURI', htmlspecialchars($institution->name_identifier_scheme_uri));
            } elseif ($institution->name_identifier_scheme === 'ROR') {
                $nameIdentifier->setAttribute('schemeURI', 'https://ror.org');
            }
            $creatorElement->appendChild($nameIdentifier);
        }

        // Affiliations
        foreach ($creator->affiliations as $affiliation) {
            $affiliationElement = $this->dom->createElement('affiliation', htmlspecialchars($affiliation->name));

            if ($affiliation->identifier) {
                $affiliationElement->setAttribute('affiliationIdentifier', htmlspecialchars($affiliation->identifier));
                $affiliationElement->setAttribute('affiliationIdentifierScheme', $affiliation->identifier_scheme ?? 'ROR');
                if ($affiliation->scheme_uri) {
                    $affiliationElement->setAttribute('schemeURI', htmlspecialchars($affiliation->scheme_uri));
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

            // Add title type if not main title
            $titleType = $title->titleType;
            if ($titleType && ! $title->isMainTitle()) {
                $titleElement->setAttribute('titleType', $titleType->slug);
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
     * Build publisher element (required)
     */
    private function buildPublisher(Resource $resource): void
    {
        $publisherModel = $resource->publisher;

        if (! $publisherModel) {
            $publisher = $this->dom->createElement('publisher', htmlspecialchars('GFZ Data Services'));
        } else {
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
        }

        if ($resource->language) {
            $publisher->setAttributeNS(
                self::XML_NAMESPACE,
                'xml:lang',
                $resource->language->iso_code ?? 'en'
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
     * Build resourceType element (required)
     */
    private function buildResourceType(Resource $resource): void
    {
        $resourceType = $resource->resourceType;
        $resourceTypeElement = $this->dom->createElement(
            'resourceType',
            htmlspecialchars($resourceType->name ?? 'Other')
        );
        $resourceTypeElement->setAttribute('resourceTypeGeneral', $resourceType->name ?? 'Other');
        $this->root->appendChild($resourceTypeElement);
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

        // Contributor name (required)
        $contributorName = $this->dom->createElement('contributorName');

        if ($person->family_name && $person->given_name) {
            $contributorName->nodeValue = htmlspecialchars("{$person->family_name}, {$person->given_name}");
        } elseif ($person->family_name) {
            $contributorName->nodeValue = htmlspecialchars($person->family_name);
        } elseif ($person->given_name) {
            $contributorName->nodeValue = htmlspecialchars($person->given_name);
        } else {
            $contributorName->nodeValue = 'Unknown';
        }

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

        // Name identifiers (ORCID or other)
        if ($person->name_identifier) {
            $nameIdentifier = $this->dom->createElement('nameIdentifier', htmlspecialchars($person->name_identifier));
            $nameIdentifier->setAttribute('nameIdentifierScheme', $person->name_identifier_scheme ?? 'ORCID');
            if ($person->name_identifier_scheme_uri) {
                $nameIdentifier->setAttribute('schemeURI', htmlspecialchars($person->name_identifier_scheme_uri));
            } elseif ($person->name_identifier_scheme === 'ORCID') {
                $nameIdentifier->setAttribute('schemeURI', 'https://orcid.org');
            }
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

        // Contributor name (required)
        $contributorName = $this->dom->createElement(
            'contributorName',
            htmlspecialchars($institution->name ?? 'Unknown Institution')
        );
        $contributorName->setAttribute('nameType', 'Organizational');
        $contributorElement->appendChild($contributorName);

        // Name identifiers (ROR or other)
        if ($institution->name_identifier) {
            $nameIdentifier = $this->dom->createElement('nameIdentifier', htmlspecialchars($institution->name_identifier));
            $nameIdentifier->setAttribute('nameIdentifierScheme', $institution->name_identifier_scheme ?? 'ROR');
            if ($institution->name_identifier_scheme_uri) {
                $nameIdentifier->setAttribute('schemeURI', htmlspecialchars($institution->name_identifier_scheme_uri));
            } elseif ($institution->name_identifier_scheme === 'ROR') {
                $nameIdentifier->setAttribute('schemeURI', 'https://ror.org');
            }
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
     * Build dates element (optional)
     */
    private function buildDates(Resource $resource): void
    {
        if ($resource->dates->isEmpty()) {
            return;
        }

        $dates = $this->dom->createElement('dates');
        $hasDates = false;

        foreach ($resource->dates as $date) {
            // Skip if no date type (should not happen in normal usage)
            // @phpstan-ignore booleanNot.alwaysFalse
            if (! $date->dateType) {
                continue;
            }

            $dateElement = $this->dom->createElement('date', htmlspecialchars($date->date));
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
            $relatedElement->setAttribute('relatedIdentifierType', $relatedIdentifier->relatedIdentifierType->slug ?? 'DOI');
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
            $sizeElement = $this->dom->createElement('size', htmlspecialchars($size->size));
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
            $formatElement = $this->dom->createElement('format', htmlspecialchars($format->format));
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
            $descriptionElement = $this->dom->createElement('description', htmlspecialchars($description->description));
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

            // Add polygons if they exist
            if ($geoLocation->polygons->isNotEmpty()) {
                $geoLocationPolygon = $this->dom->createElement('geoLocationPolygon');

                foreach ($geoLocation->polygons->sortBy('position') as $polygonPoint) {
                    if ($polygonPoint->is_in_polygon_point) {
                        // This is the inPolygonPoint
                        $inPolygonPoint = $this->dom->createElement('inPolygonPoint');

                        $pointLongitude = $this->dom->createElement(
                            'pointLongitude',
                            htmlspecialchars((string) $polygonPoint->point_longitude)
                        );
                        $inPolygonPoint->appendChild($pointLongitude);

                        $pointLatitude = $this->dom->createElement(
                            'pointLatitude',
                            htmlspecialchars((string) $polygonPoint->point_latitude)
                        );
                        $inPolygonPoint->appendChild($pointLatitude);

                        $geoLocationPolygon->appendChild($inPolygonPoint);
                    } else {
                        // Regular polygon point
                        $polygonPointElement = $this->dom->createElement('polygonPoint');

                        $pointLongitude = $this->dom->createElement(
                            'pointLongitude',
                            htmlspecialchars((string) $polygonPoint->point_longitude)
                        );
                        $polygonPointElement->appendChild($pointLongitude);

                        $pointLatitude = $this->dom->createElement(
                            'pointLatitude',
                            htmlspecialchars((string) $polygonPoint->point_latitude)
                        );
                        $polygonPointElement->appendChild($pointLatitude);

                        $geoLocationPolygon->appendChild($polygonPointElement);
                    }
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
