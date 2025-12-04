<?php

namespace App\Services;

use App\Models\Institution;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceAuthor;
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
     * Fixed publisher information for all exports
     */
    private const PUBLISHER_NAME = 'GFZ Helmholtz Centre for Geosciences';

    private const PUBLISHER_ROR_ID = 'https://ror.org/04z8jg394';

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
            'titles.titleType',
            'dataciteCreators.authorable',
            'dataciteCreators.roles',
            'dataciteCreators.affiliations',
            'dataciteContributors.authorable',
            'dataciteContributors.roles',
            'dataciteContributors.affiliations',
            'descriptions',
            'dates.dateType',
            'keywords',
            'controlledKeywords',
            'coverages',
            'licenses',
            'relatedIdentifiers',
            'fundingReferences',
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
        foreach ($resource->dataciteCreators as $author) {
            $creator = null;

            if ($author->authorable_type === Person::class) {
                $creator = $this->buildPersonCreator($author);
            } elseif ($author->authorable_type === Institution::class) {
                $creator = $this->buildInstitutionCreator($author);
            }

            if ($creator) {
                $creators->appendChild($creator);
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
    private function buildPersonCreator(ResourceAuthor $author): ?DOMElement
    {
        /** @var Person|null $person */
        $person = $author->authorable;

        if (! $person instanceof Person) {
            return null;
        }

        $creator = $this->dom->createElement('creator');

        // Creator name (required)
        $creatorName = $this->dom->createElement('creatorName');

        if ($person->last_name && $person->first_name) {
            $creatorName->nodeValue = htmlspecialchars("{$person->last_name}, {$person->first_name}");
        } elseif ($person->last_name) {
            $creatorName->nodeValue = htmlspecialchars($person->last_name);
        } elseif ($person->first_name) {
            $creatorName->nodeValue = htmlspecialchars($person->first_name);
        } else {
            $creatorName->nodeValue = 'Unknown';
        }

        $creatorName->setAttribute('nameType', 'Personal');
        $creator->appendChild($creatorName);

        // Given name (optional)
        if ($person->first_name) {
            $givenName = $this->dom->createElement('givenName', htmlspecialchars($person->first_name));
            $creator->appendChild($givenName);
        }

        // Family name (optional)
        if ($person->last_name) {
            $familyName = $this->dom->createElement('familyName', htmlspecialchars($person->last_name));
            $creator->appendChild($familyName);
        }

        // Name identifiers (ORCID)
        if ($person->orcid) {
            $nameIdentifier = $this->dom->createElement('nameIdentifier', htmlspecialchars($person->orcid));
            $nameIdentifier->setAttribute('nameIdentifierScheme', 'ORCID');
            $nameIdentifier->setAttribute('schemeURI', 'https://orcid.org');
            $creator->appendChild($nameIdentifier);
        }

        // Affiliations
        foreach ($author->affiliations as $affiliation) {
            $affiliationElement = $this->dom->createElement('affiliation', htmlspecialchars($affiliation->value));

            if ($affiliation->ror_id) {
                $affiliationElement->setAttribute('affiliationIdentifier', htmlspecialchars($affiliation->ror_id));
                $affiliationElement->setAttribute('affiliationIdentifierScheme', 'ROR');
                $affiliationElement->setAttribute('schemeURI', 'https://ror.org');
            }

            $creator->appendChild($affiliationElement);
        }

        return $creator;
    }

    /**
     * Build an institution creator element
     */
    private function buildInstitutionCreator(ResourceAuthor $author): ?DOMElement
    {
        /** @var Institution|null $institution */
        $institution = $author->authorable;

        if (! $institution instanceof Institution) {
            return null;
        }

        $creator = $this->dom->createElement('creator');

        // Creator name (required)
        $creatorName = $this->dom->createElement(
            'creatorName',
            htmlspecialchars($institution->name ?? 'Unknown Institution')
        );
        $creatorName->setAttribute('nameType', 'Organizational');
        $creator->appendChild($creatorName);

        // Name identifiers (ROR)
        if ($institution->identifier_type === 'ROR' && $institution->identifier) {
            $nameIdentifier = $this->dom->createElement('nameIdentifier', htmlspecialchars($institution->identifier));
            $nameIdentifier->setAttribute('nameIdentifierScheme', 'ROR');
            $nameIdentifier->setAttribute('schemeURI', 'https://ror.org');
            $creator->appendChild($nameIdentifier);
        } elseif ($institution->ror_id) {
            // Legacy ROR field
            $nameIdentifier = $this->dom->createElement('nameIdentifier', htmlspecialchars($institution->ror_id));
            $nameIdentifier->setAttribute('nameIdentifierScheme', 'ROR');
            $nameIdentifier->setAttribute('schemeURI', 'https://ror.org');
            $creator->appendChild($nameIdentifier);
        }

        // Affiliations
        foreach ($author->affiliations as $affiliation) {
            $affiliationElement = $this->dom->createElement('affiliation', htmlspecialchars($affiliation->value));

            if ($affiliation->ror_id) {
                $affiliationElement->setAttribute('affiliationIdentifier', htmlspecialchars($affiliation->ror_id));
                $affiliationElement->setAttribute('affiliationIdentifierScheme', 'ROR');
                $affiliationElement->setAttribute('schemeURI', 'https://ror.org');
            }

            $creator->appendChild($affiliationElement);
        }

        return $creator;
    }

    /**
     * Build titles element (required)
     */
    private function buildTitles(Resource $resource): void
    {
        $titles = $this->dom->createElement('titles');

        foreach ($resource->titles as $title) {
            $titleElement = $this->dom->createElement('title', htmlspecialchars($title->title));

            // Add title type if not main title
            $titleType = $title->titleType?->slug;
            if ($titleType && $titleType !== 'main-title') {
                $titleElement->setAttribute('titleType', $this->convertTitleType($titleType));
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
     * Convert title type slug to DataCite format
     */
    private function convertTitleType(string $slug): string
    {
        $mapping = [
            'subtitle' => 'Subtitle',
            'alternative-title' => 'AlternativeTitle',
            'translated-title' => 'TranslatedTitle',
            'other' => 'Other',
        ];

        return $mapping[$slug] ?? 'Other';
    }

    /**
     * Build publisher element (required)
     */
    private function buildPublisher(Resource $resource): void
    {
        $publisher = $this->dom->createElement('publisher', htmlspecialchars(self::PUBLISHER_NAME));
        $publisher->setAttribute('publisherIdentifier', self::PUBLISHER_ROR_ID);
        $publisher->setAttribute('publisherIdentifierScheme', 'ROR');
        $publisher->setAttribute('schemeURI', 'https://ror.org/');

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
            htmlspecialchars((string) $resource->year)
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
        if ($resource->keywords->isEmpty() && $resource->controlledKeywords->isEmpty()) {
            return;
        }

        $subjects = $this->dom->createElement('subjects');

        // Free keywords
        foreach ($resource->keywords as $keyword) {
            $subject = $this->dom->createElement('subject', htmlspecialchars($keyword->keyword));
            $subjects->appendChild($subject);
        }

        // Controlled keywords (GCMD)
        foreach ($resource->controlledKeywords as $keyword) {
            $subject = $this->dom->createElement('subject', htmlspecialchars($keyword->text));
            $subject->setAttribute('subjectScheme', htmlspecialchars($keyword->scheme));

            if ($keyword->scheme_uri) {
                $subject->setAttribute('schemeURI', htmlspecialchars($keyword->scheme_uri));
            }

            if ($keyword->keyword_id) {
                $subject->setAttribute('valueURI', htmlspecialchars($keyword->keyword_id));
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
        if ($resource->dataciteContributors->isEmpty()) {
            return;
        }

        $contributors = $this->dom->createElement('contributors');
        $hasContributors = false;

        foreach ($resource->dataciteContributors as $contributor) {
            $contributorElement = null;

            // Check if this is an MSL Laboratory
            if ($contributor->authorable_type === Institution::class) {
                /** @var Institution|null $institution */
                $institution = $contributor->authorable;
                if ($institution instanceof Institution && $institution->isLaboratory()) {
                    $contributorElement = $this->buildMslLaboratoryContributor($contributor);
                    if ($contributorElement) {
                        $contributors->appendChild($contributorElement);
                        $hasContributors = true;
                    }

                    continue;
                }
            }

            // Regular contributor
            $contributorType = $this->determineContributorType($contributor);

            if ($contributor->authorable_type === Person::class) {
                $contributorElement = $this->buildPersonContributor($contributor, $contributorType);
            } elseif ($contributor->authorable_type === Institution::class) {
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
    private function buildMslLaboratoryContributor(ResourceAuthor $contributor): ?DOMElement
    {
        /** @var Institution|null $institution */
        $institution = $contributor->authorable;

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
        if ($institution->identifier) {
            $nameIdentifier = $this->dom->createElement('nameIdentifier', htmlspecialchars($institution->identifier));
            $nameIdentifier->setAttribute('nameIdentifierScheme', 'labid');
            $contributorElement->appendChild($nameIdentifier);
        }

        // Affiliations
        foreach ($contributor->affiliations as $affiliation) {
            $affiliationElement = $this->dom->createElement('affiliation', htmlspecialchars($affiliation->value));

            if ($affiliation->ror_id) {
                $affiliationElement->setAttribute('affiliationIdentifier', htmlspecialchars($affiliation->ror_id));
                $affiliationElement->setAttribute('affiliationIdentifierScheme', 'ROR');
                $affiliationElement->setAttribute('schemeURI', 'https://ror.org');
            }

            $contributorElement->appendChild($affiliationElement);
        }

        return $contributorElement;
    }

    /**
     * Determine contributor type from roles
     */
    private function determineContributorType(ResourceAuthor $contributor): string
    {
        $role = $contributor->roles->first();

        if (! $role) {
            return 'Other';
        }

        // Map ERNIE roles to DataCite contributor types
        $roleMapping = [
            'Contact Person' => 'ContactPerson',
            'Data Collector' => 'DataCollector',
            'Data Curator' => 'DataCurator',
            'Data Manager' => 'DataManager',
            'Distributor' => 'Distributor',
            'Editor' => 'Editor',
            'Hosting Institution' => 'HostingInstitution',
            'Producer' => 'Producer',
            'Project Leader' => 'ProjectLeader',
            'Project Manager' => 'ProjectManager',
            'Project Member' => 'ProjectMember',
            'Registration Agency' => 'RegistrationAgency',
            'Registration Authority' => 'RegistrationAuthority',
            'Related Person' => 'RelatedPerson',
            'Researcher' => 'Researcher',
            'Research Group' => 'ResearchGroup',
            'Rights Holder' => 'RightsHolder',
            'Sponsor' => 'Sponsor',
            'Supervisor' => 'Supervisor',
            'Work Package Leader' => 'WorkPackageLeader',
            'Translator' => 'Translator',
            'Other' => 'Other',
        ];

        return $roleMapping[$role->name] ?? 'Other';
    }

    /**
     * Build a person contributor element
     */
    private function buildPersonContributor(ResourceAuthor $contributor, string $contributorType): ?DOMElement
    {
        /** @var Person|null $person */
        $person = $contributor->authorable;

        if (! $person instanceof Person) {
            return null;
        }

        $contributorElement = $this->dom->createElement('contributor');
        $contributorElement->setAttribute('contributorType', $contributorType);

        // Contributor name (required)
        $contributorName = $this->dom->createElement('contributorName');

        if ($person->last_name && $person->first_name) {
            $contributorName->nodeValue = htmlspecialchars("{$person->last_name}, {$person->first_name}");
        } elseif ($person->last_name) {
            $contributorName->nodeValue = htmlspecialchars($person->last_name);
        } elseif ($person->first_name) {
            $contributorName->nodeValue = htmlspecialchars($person->first_name);
        } else {
            $contributorName->nodeValue = 'Unknown';
        }

        $contributorName->setAttribute('nameType', 'Personal');
        $contributorElement->appendChild($contributorName);

        // Given name (optional)
        if ($person->first_name) {
            $givenName = $this->dom->createElement('givenName', htmlspecialchars($person->first_name));
            $contributorElement->appendChild($givenName);
        }

        // Family name (optional)
        if ($person->last_name) {
            $familyName = $this->dom->createElement('familyName', htmlspecialchars($person->last_name));
            $contributorElement->appendChild($familyName);
        }

        // Name identifiers (ORCID)
        if ($person->orcid) {
            $nameIdentifier = $this->dom->createElement('nameIdentifier', htmlspecialchars($person->orcid));
            $nameIdentifier->setAttribute('nameIdentifierScheme', 'ORCID');
            $nameIdentifier->setAttribute('schemeURI', 'https://orcid.org');
            $contributorElement->appendChild($nameIdentifier);
        }

        // Affiliations
        foreach ($contributor->affiliations as $affiliation) {
            $affiliationElement = $this->dom->createElement('affiliation', htmlspecialchars($affiliation->value));

            if ($affiliation->ror_id) {
                $affiliationElement->setAttribute('affiliationIdentifier', htmlspecialchars($affiliation->ror_id));
                $affiliationElement->setAttribute('affiliationIdentifierScheme', 'ROR');
                $affiliationElement->setAttribute('schemeURI', 'https://ror.org');
            }

            $contributorElement->appendChild($affiliationElement);
        }

        return $contributorElement;
    }

    /**
     * Build an institution contributor element
     */
    private function buildInstitutionContributor(ResourceAuthor $contributor, string $contributorType): ?DOMElement
    {
        /** @var Institution|null $institution */
        $institution = $contributor->authorable;

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

        // Name identifiers (ROR)
        if ($institution->identifier_type === 'ROR' && $institution->identifier) {
            $nameIdentifier = $this->dom->createElement('nameIdentifier', htmlspecialchars($institution->identifier));
            $nameIdentifier->setAttribute('nameIdentifierScheme', 'ROR');
            $nameIdentifier->setAttribute('schemeURI', 'https://ror.org');
            $contributorElement->appendChild($nameIdentifier);
        } elseif ($institution->ror_id) {
            $nameIdentifier = $this->dom->createElement('nameIdentifier', htmlspecialchars($institution->ror_id));
            $nameIdentifier->setAttribute('nameIdentifierScheme', 'ROR');
            $nameIdentifier->setAttribute('schemeURI', 'https://ror.org');
            $contributorElement->appendChild($nameIdentifier);
        }

        // Affiliations
        foreach ($contributor->affiliations as $affiliation) {
            $affiliationElement = $this->dom->createElement('affiliation', htmlspecialchars($affiliation->value));

            if ($affiliation->ror_id) {
                $affiliationElement->setAttribute('affiliationIdentifier', htmlspecialchars($affiliation->ror_id));
                $affiliationElement->setAttribute('affiliationIdentifierScheme', 'ROR');
                $affiliationElement->setAttribute('schemeURI', 'https://ror.org');
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
            if (! $date->dateType) {
                continue;
            }

            // Build date string
            $dateString = '';
            if ($date->start_date && $date->end_date) {
                $dateString = "{$date->start_date}/{$date->end_date}";
            } elseif ($date->start_date) {
                $dateString = $date->start_date;
            } elseif ($date->end_date) {
                $dateString = $date->end_date;
            } else {
                continue; // Skip if no date
            }

            $dateElement = $this->dom->createElement('date', htmlspecialchars($dateString));
            $dateElement->setAttribute('dateType', $this->convertDateType($date->dateType->slug));

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
     * Convert date type to DataCite format
     */
    private function convertDateType(string $type): string
    {
        $mapping = [
            'accepted' => 'Accepted',
            'available' => 'Available',
            'copyrighted' => 'Copyrighted',
            'collected' => 'Collected',
            'created' => 'Created',
            'issued' => 'Issued',
            'submitted' => 'Submitted',
            'updated' => 'Updated',
            'valid' => 'Valid',
            'withdrawn' => 'Withdrawn',
            'coverage' => 'Coverage', // DataCite 4.6 addition
            'other' => 'Other',
        ];

        return $mapping[$type] ?? 'Other';
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
            $relatedElement->setAttribute('relatedIdentifierType', htmlspecialchars($relatedIdentifier->identifier_type));
            $relatedElement->setAttribute('relationType', htmlspecialchars($relatedIdentifier->relation_type));

            // Add resourceTypeGeneral if available
            if (isset($relatedIdentifier->related_metadata['resourceTypeGeneral'])) {
                $relatedElement->setAttribute(
                    'resourceTypeGeneral',
                    htmlspecialchars($relatedIdentifier->related_metadata['resourceTypeGeneral'])
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
        // Currently not implemented in ERNIE data model
        // Placeholder for future implementation
    }

    /**
     * Build formats element (optional)
     */
    private function buildFormats(Resource $resource): void
    {
        // Currently not implemented in ERNIE data model
        // Placeholder for future implementation
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
        if ($resource->licenses->isEmpty()) {
            return;
        }

        $rightsList = $this->dom->createElement('rightsList');

        foreach ($resource->licenses as $license) {
            $rights = $this->dom->createElement('rights', htmlspecialchars($license->name));

            if ($license->reference) {
                $rights->setAttribute('rightsURI', htmlspecialchars($license->reference));
            }

            if ($license->spdx_id) {
                $rights->setAttribute('rightsIdentifier', htmlspecialchars($license->spdx_id));
                $rights->setAttribute('rightsIdentifierScheme', 'SPDX');
                $rights->setAttribute('schemeURI', 'https://spdx.org/licenses/');
            }

            if ($resource->language) {
                $rights->setAttributeNS(
                    self::XML_NAMESPACE,
                    'xml:lang',
                    $resource->language->iso_code ?? 'en'
                );
            }

            $rightsList->appendChild($rights);
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
            $descriptionElement->setAttribute('descriptionType', $this->convertDescriptionType($description->description_type));

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
     * Convert description type to DataCite format
     */
    private function convertDescriptionType(string $type): string
    {
        $mapping = [
            'abstract' => 'Abstract',
            'methods' => 'Methods',
            'series-information' => 'SeriesInformation',
            'table-of-contents' => 'TableOfContents',
            'technical-info' => 'TechnicalInfo',
            'other' => 'Other',
        ];

        return $mapping[$type] ?? 'Other';
    }

    /**
     * Build geoLocations element (optional)
     */
    private function buildGeoLocations(Resource $resource): void
    {
        if ($resource->coverages->isEmpty()) {
            return;
        }

        $geoLocations = $this->dom->createElement('geoLocations');
        $hasGeoLocations = false;

        foreach ($resource->coverages as $coverage) {
            $geoLocation = $this->dom->createElement('geoLocation');
            $hasContent = false;

            // Add description/place
            if ($coverage->description) {
                $geoLocationPlace = $this->dom->createElement(
                    'geoLocationPlace',
                    htmlspecialchars($coverage->description)
                );
                $geoLocation->appendChild($geoLocationPlace);
                $hasContent = true;
            }

            // Add polygon if polygon_points exist (highest priority)
            if ($coverage->type === 'polygon' && ! empty($coverage->polygon_points)) {
                $geoLocationPolygon = $this->dom->createElement('geoLocationPolygon');

                foreach ($coverage->polygon_points as $point) {
                    $polygonPoint = $this->dom->createElement('polygonPoint');

                    $pointLatitude = $this->dom->createElement(
                        'pointLatitude',
                        htmlspecialchars((string) $point['lat'])
                    );
                    $polygonPoint->appendChild($pointLatitude);

                    $pointLongitude = $this->dom->createElement(
                        'pointLongitude',
                        htmlspecialchars((string) $point['lon'])
                    );
                    $polygonPoint->appendChild($pointLongitude);

                    $geoLocationPolygon->appendChild($polygonPoint);
                }

                $geoLocation->appendChild($geoLocationPolygon);
                $hasContent = true;
            }
            // Add bounding box if spatial data exists (fallback if not polygon)
            elseif ($coverage->lat_min !== null || $coverage->lat_max !== null ||
                $coverage->lon_min !== null || $coverage->lon_max !== null) {

                $geoLocationBox = $this->dom->createElement('geoLocationBox');

                $westBoundLongitude = $this->dom->createElement(
                    'westBoundLongitude',
                    htmlspecialchars((string) $coverage->lon_min)
                );
                $geoLocationBox->appendChild($westBoundLongitude);

                $eastBoundLongitude = $this->dom->createElement(
                    'eastBoundLongitude',
                    htmlspecialchars((string) $coverage->lon_max)
                );
                $geoLocationBox->appendChild($eastBoundLongitude);

                $southBoundLatitude = $this->dom->createElement(
                    'southBoundLatitude',
                    htmlspecialchars((string) $coverage->lat_min)
                );
                $geoLocationBox->appendChild($southBoundLatitude);

                $northBoundLatitude = $this->dom->createElement(
                    'northBoundLatitude',
                    htmlspecialchars((string) $coverage->lat_max)
                );
                $geoLocationBox->appendChild($northBoundLatitude);

                $geoLocation->appendChild($geoLocationBox);
                $hasContent = true;
            }

            if ($hasContent) {
                $geoLocations->appendChild($geoLocation);
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
                    htmlspecialchars($funding->funder_identifier_type ?? 'Other')
                );
                $fundingReference->appendChild($funderIdentifier);
            }

            if ($funding->award_number) {
                $awardNumber = $this->dom->createElement('awardNumber', htmlspecialchars($funding->award_number));
                $fundingReference->appendChild($awardNumber);
            }

            if ($funding->award_title) {
                $awardTitle = $this->dom->createElement('awardTitle', htmlspecialchars($funding->award_title));
                $fundingReference->appendChild($awardTitle);
            }

            if ($funding->award_uri) {
                $awardURI = $this->dom->createElement('awardURI', htmlspecialchars($funding->award_uri));
                $fundingReference->appendChild($awardURI);
            }

            $fundingReferences->appendChild($fundingReference);
        }

        $this->root->appendChild($fundingReferences);
    }
}
