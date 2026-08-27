<?php

declare(strict_types=1);

namespace App\Services\Iso19115;

use App\Models\Affiliation;
use App\Models\GeoLocation;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Resource;
use App\Services\TemporalCoverageValueService;
use App\Support\SubjectBreadcrumbPath;
use App\Support\UriHelper;
use DOMDocument;
use DOMElement;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Deterministic DataCite-to-ISO 19115-3:2023 crosswalk.
 *
 * The exporter only emits values present in ERNIE, plus the configured metadata
 * contact and standard citations. Missing mandatory character-string properties
 * use ISO's gco:nilReason instead of fabricated placeholder text.
 */
class Iso19115XmlExporter
{
    public const MDB_NAMESPACE = 'https://schemas.isotc211.org/19115/-1/mdb/1.3';

    public const MCC_NAMESPACE = 'https://schemas.isotc211.org/19115/-1/mcc/1.3';

    public const CIT_NAMESPACE = 'https://schemas.isotc211.org/19115/-1/cit/1.3';

    public const MRI_NAMESPACE = 'https://schemas.isotc211.org/19115/-1/mri/1.3';

    public const GEX_NAMESPACE = 'https://schemas.isotc211.org/19115/-1/gex/1.3';

    public const MCO_NAMESPACE = 'https://schemas.isotc211.org/19115/-1/mco/1.3';

    public const MRD_NAMESPACE = 'https://schemas.isotc211.org/19115/-1/mrd/1.3';

    public const GML_NAMESPACE = 'http://www.opengis.net/gml/3.2';

    public const LAN_NAMESPACE = 'https://schemas.isotc211.org/19115/-1/lan/1.3';

    public const GCO_NAMESPACE = 'https://schemas.isotc211.org/19103/-/gco/1.2';

    public const GCX_NAMESPACE = 'https://schemas.isotc211.org/19103/-/gcx/1.2';

    public const XSI_NAMESPACE = 'http://www.w3.org/2001/XMLSchema-instance';

    public const XLINK_NAMESPACE = 'http://www.w3.org/1999/xlink';

    private const XMLNS_NAMESPACE = 'http://www.w3.org/2000/xmlns/';

    /**
     * @var array<string, string>
     */
    private const NAMESPACES = [
        'mdb' => self::MDB_NAMESPACE,
        'mcc' => self::MCC_NAMESPACE,
        'cit' => self::CIT_NAMESPACE,
        'mri' => self::MRI_NAMESPACE,
        'gex' => self::GEX_NAMESPACE,
        'mco' => self::MCO_NAMESPACE,
        'mrd' => self::MRD_NAMESPACE,
        'gml' => self::GML_NAMESPACE,
        'lan' => self::LAN_NAMESPACE,
        'gco' => self::GCO_NAMESPACE,
        'gcx' => self::GCX_NAMESPACE,
        'xlink' => self::XLINK_NAMESPACE,
        'xsi' => self::XSI_NAMESPACE,
    ];

    /**
     * Deliberately conservative DataCite contributor-to-ISO role crosswalk.
     *
     * @var array<string, string>
     */
    private const CONTRIBUTOR_ROLE_MAP = [
        'DataCollector' => 'contributor',
        'DataCurator' => 'custodian',
        'DataManager' => 'custodian',
        'Distributor' => 'distributor',
        'Editor' => 'editor',
        'HostingInstitution' => 'resourceProvider',
        'Producer' => 'originator',
        'ProjectLeader' => 'principalInvestigator',
        'ProjectManager' => 'principalInvestigator',
        'ProjectMember' => 'collaborator',
        'Researcher' => 'contributor',
        'ResearchGroup' => 'collaborator',
        'RightsHolder' => 'rightsHolder',
        'Sponsor' => 'sponsor',
        'Supervisor' => 'collaborator',
        'WorkPackageLeader' => 'principalInvestigator',
    ];

    private const RESOURCE_DATE_TYPE_MAP = [
        'Created' => 'creation',
        'Available' => 'released',
        'Withdrawn' => 'unavailable',
    ];

    /**
     * DataCite relations with an unambiguous ISO association type.
     *
     * @var array<string, string>
     */
    private const ASSOCIATION_TYPE_MAP = [
        'IsPartOf' => 'largerWorkCitation',
        'HasPart' => 'isComposedOf',
        'IsVersionOf' => 'revisionOf',
        'IsNewVersionOf' => 'revisionOf',
        'IsPreviousVersionOf' => 'revisionOf',
        'Requires' => 'dependency',
        'IsRequiredBy' => 'dependency',
        'IsDerivedFrom' => 'source',
    ];

    private DOMDocument $dom;

    private DOMElement $root;

    public function __construct(
        private readonly Iso19115ResourceProfileService $profile,
    ) {}

    #[\NoDiscard('Exported XML string must be used')]
    public function export(Resource $resource): string
    {
        if (! $this->profile->supports($resource)) {
            throw new RuntimeException('The resource type is not supported by the ISO 19115-3 profile.');
        }

        $resource->loadMissing($this->profile->requiredRelations());

        $this->dom = new DOMDocument('1.0', 'UTF-8');
        $this->dom->formatOutput = true;
        $this->dom->preserveWhiteSpace = false;

        $this->root = $this->dom->createElementNS(self::MDB_NAMESPACE, 'mdb:MD_Metadata');
        foreach (self::NAMESPACES as $prefix => $namespace) {
            if ($prefix === 'mdb') {
                continue;
            }
            $this->root->setAttributeNS(self::XMLNS_NAMESPACE, "xmlns:{$prefix}", $namespace);
        }
        $this->root->setAttributeNS(
            self::XSI_NAMESPACE,
            'xsi:schemaLocation',
            self::MDB_NAMESPACE.' '.(string) config('iso19115.schema'),
        );
        $this->dom->appendChild($this->root);

        // MD_Metadata sequence from ISO 19115-1 mdb 1.3.0.
        $this->buildMetadataIdentifier($resource);
        $this->buildLocale($this->root, 'mdb:defaultLocale', $this->languageCode($resource));
        $this->buildMetadataContact();
        $this->buildMetadataCreationDate($resource);
        $this->buildMetadataStandards();
        $this->buildMetadataLinkage($resource);
        $this->buildIdentification($resource);
        $this->buildDistributionInfo($resource);
        $this->buildMetadataScope($resource);

        $xml = $this->dom->saveXML();
        if ($xml === false) {
            throw new RuntimeException('Failed to serialize ISO 19115-3 XML.');
        }

        return $xml;
    }

    private function buildMetadataIdentifier(Resource $resource): void
    {
        $property = $this->append($this->root, self::MDB_NAMESPACE, 'mdb:metadataIdentifier');
        $metadataUrl = $this->metadataUrl($resource);
        $identifier = $this->append($property, self::MCC_NAMESPACE, 'mcc:MD_Identifier');
        $this->characterString($identifier, self::MCC_NAMESPACE, 'mcc:code', $metadataUrl ?? $resource->doi);
        $this->characterString($identifier, self::MCC_NAMESPACE, 'mcc:codeSpace', $metadataUrl !== null ? 'URL' : 'https://doi.org/');
    }

    private function buildMetadataContact(): void
    {
        $contact = config('iso19115.metadata_contact', []);
        $name = is_string($contact['name'] ?? null) ? trim($contact['name']) : '';
        $email = is_string($contact['email'] ?? null) ? trim($contact['email']) : null;
        $website = is_string($contact['website'] ?? null) ? trim($contact['website']) : null;

        $property = $this->append($this->root, self::MDB_NAMESPACE, 'mdb:contact');
        $this->responsibility(
            $property,
            role: 'pointOfContact',
            partyName: $name !== '' ? $name : 'GFZ Data Services',
            organizational: true,
            email: $email,
            website: $website,
        );
    }

    private function buildMetadataCreationDate(Resource $resource): void
    {
        $timestamp = $resource->created_at ?? $resource->updated_at ?? now();
        $property = $this->append($this->root, self::MDB_NAMESPACE, 'mdb:dateInfo');
        $this->typedDate($property, $timestamp->utc()->format('Y-m-d\TH:i:s\Z'), 'creation', dateTime: true);

        if ($resource->updated_at !== null && ! $resource->updated_at->equalTo($timestamp)) {
            $revision = $this->append($this->root, self::MDB_NAMESPACE, 'mdb:dateInfo');
            $this->typedDate(
                $revision,
                $resource->updated_at->utc()->format('Y-m-d\TH:i:s\Z'),
                'revision',
                dateTime: true,
            );
        }
    }

    private function buildMetadataStandards(): void
    {
        $this->standardCitation(
            'ISO 19115-1:2014 Geographic information — Metadata — Part 1: Fundamentals',
            '2014',
        );
        $this->standardCitation(
            'ISO 19115-3:2023 Geographic information — Metadata — Part 3: XML schema implementation for fundamental concepts',
            '2023',
        );
    }

    private function standardCitation(string $title, string $edition): void
    {
        $property = $this->append($this->root, self::MDB_NAMESPACE, 'mdb:metadataStandard');
        $citation = $this->append($property, self::CIT_NAMESPACE, 'cit:CI_Citation');
        $this->characterString($citation, self::CIT_NAMESPACE, 'cit:title', $title);
        $this->characterString($citation, self::CIT_NAMESPACE, 'cit:edition', $edition);
    }

    private function buildMetadataLinkage(Resource $resource): void
    {
        $url = $this->metadataUrl($resource);
        if ($url === null) {
            return;
        }

        $property = $this->append($this->root, self::MDB_NAMESPACE, 'mdb:metadataLinkage');
        $online = $this->append($property, self::CIT_NAMESPACE, 'cit:CI_OnlineResource');
        $this->characterString($online, self::CIT_NAMESPACE, 'cit:linkage', $url);
        $this->characterString($online, self::CIT_NAMESPACE, 'cit:protocol', 'HTTPS');
        $this->characterString($online, self::CIT_NAMESPACE, 'cit:name', 'ISO 19115-3 metadata');
        $this->code(
            $online,
            self::CIT_NAMESPACE,
            'cit:function',
            self::CIT_NAMESPACE,
            'cit:CI_OnLineFunctionCode',
            'CI_OnLineFunctionCode',
            'download',
        );
    }

    private function buildIdentification(Resource $resource): void
    {
        $property = $this->append($this->root, self::MDB_NAMESPACE, 'mdb:identificationInfo');
        $identification = $this->append($property, self::MRI_NAMESPACE, 'mri:MD_DataIdentification');

        $this->buildResourceCitation($identification, $resource);

        $abstract = $resource->descriptions->first(
            fn ($description): bool => $description->descriptionType->slug === 'Abstract'
                && trim((string) $description->value) !== '',
        );
        $this->characterString(
            $identification,
            self::MRI_NAMESPACE,
            'mri:abstract',
            $abstract?->value,
            mandatory: true,
        );

        $purpose = $resource->descriptions->first(
            fn ($description): bool => $description->descriptionType->slug === 'Methods'
                && trim((string) $description->value) !== '',
        );
        if ($purpose !== null) {
            $this->characterString($identification, self::MRI_NAMESPACE, 'mri:purpose', $purpose->value);
        } elseif ($resource->igsnMetadata?->sample_purpose) {
            $this->characterString(
                $identification,
                self::MRI_NAMESPACE,
                'mri:purpose',
                $resource->igsnMetadata->sample_purpose,
            );
        }

        $this->code(
            $identification,
            self::MRI_NAMESPACE,
            'mri:status',
            self::MCC_NAMESPACE,
            'mcc:MD_ProgressCode',
            'MD_ProgressCode',
            'completed',
        );
        $this->buildResourceContacts($identification, $resource);
        $this->buildExtents($identification, $resource);
        $this->buildResourceFormats($identification, $resource);
        $this->buildKeywords($identification, $resource);
        $this->buildRights($identification, $resource);
        $this->buildAssociatedResources($identification, $resource);
        $this->buildLocale($identification, 'mri:defaultLocale', $this->languageCode($resource));
        $this->buildSupplementalInformation($identification, $resource);
    }

    private function buildResourceCitation(DOMElement $identification, Resource $resource): void
    {
        $property = $this->append($identification, self::MRI_NAMESPACE, 'mri:citation');
        $citation = $this->append($property, self::CIT_NAMESPACE, 'cit:CI_Citation');

        $mainTitle = $resource->titles->first(
            fn ($title): bool => $title->isMainTitle() && trim((string) $title->value) !== '',
        );
        $this->characterString(
            $citation,
            self::CIT_NAMESPACE,
            'cit:title',
            $mainTitle?->value,
            mandatory: true,
        );

        foreach ($resource->titles as $title) {
            if ($mainTitle !== null && $title->is($mainTitle)) {
                continue;
            }
            if (trim((string) $title->value) !== '') {
                $this->characterString($citation, self::CIT_NAMESPACE, 'cit:alternateTitle', $title->value);
            }
        }

        if ($resource->publication_year !== null) {
            $date = $this->append($citation, self::CIT_NAMESPACE, 'cit:date');

            $this->typedDate($date, (string) $resource->publication_year, 'publication');
        }

        $this->buildCitationDates($citation, $resource);

        if (is_string($resource->version) && trim($resource->version) !== '') {
            $this->characterString($citation, self::CIT_NAMESPACE, 'cit:edition', $resource->version);
        }

        if (is_string($resource->doi) && trim($resource->doi) !== '') {
            $identifierProperty = $this->append($citation, self::CIT_NAMESPACE, 'cit:identifier');
            $identifier = $this->append($identifierProperty, self::MCC_NAMESPACE, 'mcc:MD_Identifier');
            $this->characterString($identifier, self::MCC_NAMESPACE, 'mcc:code', $resource->doi);
            $this->characterString($identifier, self::MCC_NAMESPACE, 'mcc:codeSpace', 'DOI');
        }

        foreach ($resource->alternateIdentifiers as $alternateIdentifier) {
            if (trim($alternateIdentifier->value) === '') {
                continue;
            }

            $identifierProperty = $this->append($citation, self::CIT_NAMESPACE, 'cit:identifier');
            $identifier = $this->append($identifierProperty, self::MCC_NAMESPACE, 'mcc:MD_Identifier');
            $this->characterString($identifier, self::MCC_NAMESPACE, 'mcc:code', $alternateIdentifier->value);
            $this->characterString($identifier, self::MCC_NAMESPACE, 'mcc:codeSpace', $alternateIdentifier->type);
        }

        foreach ($resource->creators as $creator) {
            $party = $this->partyData($creator->creatorable);
            if ($party === null) {
                continue;
            }

            $responsibilityProperty = $this->append(
                $citation,
                self::CIT_NAMESPACE,
                'cit:citedResponsibleParty',
            );
            $this->responsibility(
                $responsibilityProperty,
                role: 'originator',
                partyName: $party['name'],
                organizational: $party['organizational'],
                identifier: $party['identifier'],
                identifierScheme: $party['identifierScheme'],
                email: $creator->email,
                website: $creator->website,
                affiliations: array_values($creator->affiliations->all()),
            );
        }

        $this->buildPublisherResponsibility($citation, $resource);
        $this->buildContributorResponsibilities($citation, $resource);
        $this->buildFunderResponsibilities($citation, $resource);
        $this->buildSeriesInformation($citation, $resource);

        $landingUrl = $this->landingPageUrl($resource);
        if ($landingUrl !== null) {
            $onlineProperty = $this->append($citation, self::CIT_NAMESPACE, 'cit:onlineResource');
            $online = $this->append($onlineProperty, self::CIT_NAMESPACE, 'cit:CI_OnlineResource');
            $this->characterString($online, self::CIT_NAMESPACE, 'cit:linkage', $landingUrl);
            $this->characterString($online, self::CIT_NAMESPACE, 'cit:protocol', 'HTTPS');
            $this->characterString($online, self::CIT_NAMESPACE, 'cit:name', 'Resource landing page');
            $this->code(
                $online,
                self::CIT_NAMESPACE,
                'cit:function',
                self::CIT_NAMESPACE,
                'cit:CI_OnLineFunctionCode',
                'CI_OnLineFunctionCode',
                'information',
            );
        }
    }

    private function buildCitationDates(DOMElement $citation, Resource $resource): void
    {
        foreach ($resource->dates as $resourceDate) {
            $dateType = self::RESOURCE_DATE_TYPE_MAP[$resourceDate->dateType->slug] ?? null;
            $value = trim((string) $resourceDate->date_value);
            if ($dateType === null
                || $value === ''
                || preg_match('/^\d{4}(?:-\d{2}(?:-\d{2})?)?$/', $value) !== 1) {
                continue;
            }

            $property = $this->append($citation, self::CIT_NAMESPACE, 'cit:date');
            $this->typedDate($property, $value, $dateType);
        }
    }

    private function buildPublisherResponsibility(DOMElement $citation, Resource $resource): void
    {
        $publisher = $resource->publisher;
        if ($publisher === null || trim($publisher->name) === '') {
            return;
        }

        $property = $this->append($citation, self::CIT_NAMESPACE, 'cit:citedResponsibleParty');
        $this->responsibility(
            $property,
            role: 'publisher',
            partyName: $publisher->name,
            organizational: true,
            identifier: $publisher->identifier,
            identifierScheme: $publisher->identifier_scheme,
        );
    }

    private function buildContributorResponsibilities(DOMElement $citation, Resource $resource): void
    {
        foreach ($resource->contributors as $contributor) {
            $role = null;
            foreach ($contributor->contributorTypes as $contributorType) {
                if (array_key_exists($contributorType->slug, self::CONTRIBUTOR_ROLE_MAP)) {
                    $role = self::CONTRIBUTOR_ROLE_MAP[$contributorType->slug];

                    break;
                }
            }

            if ($role === null) {
                continue;
            }

            $party = $this->partyData($contributor->contributorable);
            if ($party === null) {
                continue;
            }

            $property = $this->append($citation, self::CIT_NAMESPACE, 'cit:citedResponsibleParty');
            $this->responsibility(
                $property,
                role: $role,
                partyName: $party['name'],
                organizational: $party['organizational'],
                identifier: $party['identifier'],
                identifierScheme: $party['identifierScheme'],
                email: $contributor->email,
                website: $contributor->website,
                affiliations: array_values($contributor->affiliations->all()),
            );
        }
    }

    private function buildFunderResponsibilities(DOMElement $citation, Resource $resource): void
    {
        foreach ($resource->fundingReferences as $fundingReference) {
            $name = trim($fundingReference->funder_name);
            if ($name === '') {
                continue;
            }

            $property = $this->append($citation, self::CIT_NAMESPACE, 'cit:citedResponsibleParty');
            $this->responsibility(
                $property,
                role: 'funder',
                partyName: $name,
                organizational: true,
                identifier: $fundingReference->funder_identifier,
                identifierScheme: $fundingReference->funderIdentifierType?->slug,
            );
        }
    }

    private function buildSeriesInformation(DOMElement $citation, Resource $resource): void
    {
        $seriesInformation = $resource->descriptions->first(
            fn ($description): bool => $description->descriptionType->slug === 'SeriesInformation'
                && trim((string) $description->value) !== '',
        );
        if ($seriesInformation === null) {
            return;
        }

        $property = $this->append($citation, self::CIT_NAMESPACE, 'cit:series');
        $series = $this->append($property, self::CIT_NAMESPACE, 'cit:CI_Series');
        $this->characterString(
            $series,
            self::CIT_NAMESPACE,
            'cit:name',
            $seriesInformation->value,
        );
    }

    private function buildResourceContacts(DOMElement $identification, Resource $resource): void
    {
        foreach ($resource->creators->where('is_contact', true) as $creator) {
            $this->appendResourceContact(
                $identification,
                $creator->creatorable,
                $creator->email,
                $creator->website,
                array_values($creator->affiliations->all()),
            );
        }

        foreach ($resource->contributors as $contributor) {
            $isContact = $contributor->contributorTypes->contains(
                fn ($type): bool => in_array($type->slug, ['ContactPerson', 'contact-person'], true),
            );
            if ($isContact) {
                $this->appendResourceContact(
                    $identification,
                    $contributor->contributorable,
                    $contributor->email,
                    $contributor->website,
                    array_values($contributor->affiliations->all()),
                );
            }
        }
    }

    /**
     * @param  list<Affiliation>  $affiliations
     */
    private function appendResourceContact(
        DOMElement $identification,
        ?Model $partyModel,
        ?string $email,
        ?string $website,
        array $affiliations,
    ): void {
        $party = $this->partyData($partyModel);
        if ($party === null) {
            return;
        }

        $property = $this->append($identification, self::MRI_NAMESPACE, 'mri:pointOfContact');
        $this->responsibility(
            $property,
            role: 'pointOfContact',
            partyName: $party['name'],
            organizational: $party['organizational'],
            identifier: $party['identifier'],
            identifierScheme: $party['identifierScheme'],
            email: $email,
            website: $website,
            affiliations: $affiliations,
        );
    }

    private function buildExtents(DOMElement $identification, Resource $resource): void
    {
        foreach ($resource->geoLocations as $coverageIndex => $geoLocation) {
            $bounds = $this->bounds($geoLocation);
            if ($bounds === null && ! $geoLocation->hasPlace() && ! $geoLocation->hasTemporalCoverage()) {
                continue;
            }

            $extentProperty = $this->append($identification, self::MRI_NAMESPACE, 'mri:extent');
            $extent = $this->append($extentProperty, self::GEX_NAMESPACE, 'gex:EX_Extent');

            if ($geoLocation->hasPlace()) {
                $this->characterString($extent, self::GEX_NAMESPACE, 'gex:description', $geoLocation->place);

                $geoProperty = $this->append($extent, self::GEX_NAMESPACE, 'gex:geographicElement');
                $description = $this->append(
                    $geoProperty,
                    self::GEX_NAMESPACE,
                    'gex:EX_GeographicDescription',
                );
                $this->boolean($description, 'gex:extentTypeCode', true);
                $identifierProperty = $this->append(
                    $description,
                    self::GEX_NAMESPACE,
                    'gex:geographicIdentifier',
                );
                $identifier = $this->append(
                    $identifierProperty,
                    self::MCC_NAMESPACE,
                    'mcc:MD_Identifier',
                );
                $this->characterString(
                    $identifier,
                    self::MCC_NAMESPACE,
                    'mcc:code',
                    $geoLocation->place,
                );
            }

            $this->buildSourceGeometry($extent, $geoLocation);

            if ($bounds !== null) {
                $geoProperty = $this->append($extent, self::GEX_NAMESPACE, 'gex:geographicElement');
                $box = $this->append($geoProperty, self::GEX_NAMESPACE, 'gex:EX_GeographicBoundingBox');
                $this->decimal($box, 'gex:westBoundLongitude', $bounds['west']);
                $this->decimal($box, 'gex:eastBoundLongitude', $bounds['east']);
                $this->decimal($box, 'gex:southBoundLatitude', $bounds['south']);
                $this->decimal($box, 'gex:northBoundLatitude', $bounds['north']);
            }

            $this->buildCoverageTemporalElement($extent, $geoLocation, $coverageIndex + 1);
        }

        $this->buildTemporalExtents($identification, $resource);
    }

    private function buildCoverageTemporalElement(DOMElement $extent, GeoLocation $geoLocation, int $position): void
    {
        if (! $geoLocation->hasTemporalCoverage()) {
            return;
        }

        $endpoints = app(TemporalCoverageValueService::class)->toIsoEndpoints([
            'startDate' => $geoLocation->start_date,
            'endDate' => $geoLocation->end_date,
            'startTime' => $geoLocation->start_time,
            'endTime' => $geoLocation->end_time,
            'timezone' => $geoLocation->timezone,
            'temporalMode' => $geoLocation->temporal_mode,
        ]);

        if ($endpoints['start'] === '' && $endpoints['end'] === '') {
            return;
        }

        $temporalProperty = $this->append($extent, self::GEX_NAMESPACE, 'gex:temporalElement');
        $temporal = $this->append($temporalProperty, self::GEX_NAMESPACE, 'gex:EX_TemporalExtent');
        $timeProperty = $this->append($temporal, self::GEX_NAMESPACE, 'gex:extent');

        if ($endpoints['mode'] === 'instant' && $endpoints['start'] !== '') {
            $instant = $this->append($timeProperty, self::GML_NAMESPACE, 'gml:TimeInstant');
            $instant->setAttributeNS(self::GML_NAMESPACE, 'gml:id', 'coverage-temporal-'.$position);
            $timePosition = $this->append($instant, self::GML_NAMESPACE, 'gml:timePosition');
            $timePosition->appendChild($this->dom->createTextNode($endpoints['start']));

            return;
        }

        $period = $this->append($timeProperty, self::GML_NAMESPACE, 'gml:TimePeriod');
        $period->setAttributeNS(self::GML_NAMESPACE, 'gml:id', 'coverage-temporal-'.$position);

        $begin = $this->append($period, self::GML_NAMESPACE, 'gml:beginPosition');
        if ($endpoints['start'] === '') {
            $begin->setAttribute('indeterminatePosition', 'unknown');
        } else {
            $begin->appendChild($this->dom->createTextNode($endpoints['start']));
        }

        $end = $this->append($period, self::GML_NAMESPACE, 'gml:endPosition');
        if ($endpoints['end'] === '') {
            $end->setAttribute('indeterminatePosition', 'unknown');
        } else {
            $end->appendChild($this->dom->createTextNode($endpoints['end']));
        }
    }

    private function buildSourceGeometry(DOMElement $extent, GeoLocation $geoLocation): void
    {
        $geometryName = null;
        $positionText = null;
        if ($geoLocation->hasPoint()) {
            $geometryName = 'gml:Point';
            $positionText = $this->formatDecimal((float) $geoLocation->point_longitude)
                .' '.$this->formatDecimal((float) $geoLocation->point_latitude);
        } elseif ($geoLocation->hasPolygon()) {
            $points = $this->usablePoints($geoLocation);
            if (count($points) >= 3) {
                if ($points[0] !== $points[array_key_last($points)]) {
                    $points[] = $points[0];
                }
                $geometryName = 'gml:Polygon';
                $positionText = implode(
                    ' ',
                    array_map(
                        fn (array $point): string => $this->formatDecimal($point['longitude'])
                            .' '.$this->formatDecimal($point['latitude']),
                        $points,
                    ),
                );
            }
        }

        if ($geometryName === null || $positionText === null) {
            return;
        }

        $property = $this->append($extent, self::GEX_NAMESPACE, 'gex:geographicElement');
        $boundingPolygon = $this->append(
            $property,
            self::GEX_NAMESPACE,
            'gex:EX_BoundingPolygon',
        );
        $this->boolean($boundingPolygon, 'gex:extentTypeCode', true);
        $polygonProperty = $this->append($boundingPolygon, self::GEX_NAMESPACE, 'gex:polygon');
        $geometry = $this->append($polygonProperty, self::GML_NAMESPACE, $geometryName);
        $geometry->setAttributeNS(
            self::GML_NAMESPACE,
            'gml:id',
            'geometry-'.$geoLocation->id,
        );
        $geometry->setAttribute('srsName', 'urn:ogc:def:crs:OGC::CRS84');
        $geometry->setAttribute('srsDimension', '2');

        if ($geometryName === 'gml:Point') {
            $position = $this->append($geometry, self::GML_NAMESPACE, 'gml:pos');
            $position->appendChild($this->dom->createTextNode($positionText));

            return;
        }

        $exterior = $this->append($geometry, self::GML_NAMESPACE, 'gml:exterior');
        $ring = $this->append($exterior, self::GML_NAMESPACE, 'gml:LinearRing');
        $positions = $this->append($ring, self::GML_NAMESPACE, 'gml:posList');
        $positions->setAttribute('srsDimension', '2');
        $positions->appendChild($this->dom->createTextNode($positionText));
    }

    private function buildTemporalExtents(DOMElement $identification, Resource $resource): void
    {
        $position = 0;
        foreach ($resource->dates as $resourceDate) {
            if (! in_array($resourceDate->dateType->slug, ['Collected', 'Valid'], true)) {
                continue;
            }

            $start = $this->temporalValue($resourceDate->getStartDate());
            $end = $this->temporalValue($resourceDate->getEndDate());
            if ($start === null) {
                continue;
            }

            $position++;
            $extentProperty = $this->append($identification, self::MRI_NAMESPACE, 'mri:extent');
            $extent = $this->append($extentProperty, self::GEX_NAMESPACE, 'gex:EX_Extent');
            $temporalProperty = $this->append($extent, self::GEX_NAMESPACE, 'gex:temporalElement');
            $temporal = $this->append(
                $temporalProperty,
                self::GEX_NAMESPACE,
                'gex:EX_TemporalExtent',
            );
            $timeProperty = $this->append($temporal, self::GEX_NAMESPACE, 'gex:extent');

            if ($end === null && ! $resourceDate->isOpenEndedRange()) {
                $instant = $this->append($timeProperty, self::GML_NAMESPACE, 'gml:TimeInstant');
                $instant->setAttributeNS(self::GML_NAMESPACE, 'gml:id', "temporal-{$position}");
                $timePosition = $this->append($instant, self::GML_NAMESPACE, 'gml:timePosition');
                $timePosition->appendChild($this->dom->createTextNode($start));

                continue;
            }

            $period = $this->append($timeProperty, self::GML_NAMESPACE, 'gml:TimePeriod');
            $period->setAttributeNS(self::GML_NAMESPACE, 'gml:id', "temporal-{$position}");
            $begin = $this->append($period, self::GML_NAMESPACE, 'gml:beginPosition');
            $begin->appendChild($this->dom->createTextNode($start));
            $endPosition = $this->append($period, self::GML_NAMESPACE, 'gml:endPosition');
            if ($end === null) {
                $endPosition->setAttribute('indeterminatePosition', 'unknown');
            } else {
                $endPosition->appendChild($this->dom->createTextNode($end));
            }
        }
    }

    private function buildResourceFormats(DOMElement $identification, Resource $resource): void
    {
        foreach ($resource->formats as $format) {
            $value = trim($format->value);
            if ($value === '') {
                continue;
            }

            $property = $this->append($identification, self::MRI_NAMESPACE, 'mri:resourceFormat');
            $isoFormat = $this->append($property, self::MRD_NAMESPACE, 'mrd:MD_Format');
            $citationProperty = $this->append(
                $isoFormat,
                self::MRD_NAMESPACE,
                'mrd:formatSpecificationCitation',
            );
            $citation = $this->append(
                $citationProperty,
                self::CIT_NAMESPACE,
                'cit:CI_Citation',
            );
            $this->characterString($citation, self::CIT_NAMESPACE, 'cit:title', $value);
        }
    }

    private function buildAssociatedResources(DOMElement $identification, Resource $resource): void
    {
        foreach ($resource->relatedIdentifiers as $relatedIdentifier) {
            $associationType = self::ASSOCIATION_TYPE_MAP[$relatedIdentifier->relationType->slug] ?? null;
            $identifier = trim($relatedIdentifier->identifier);
            if ($associationType === null || $identifier === '') {
                continue;
            }

            $this->associatedResource(
                $identification,
                $identifier,
                $relatedIdentifier->identifierType->name,
                $associationType,
                $relatedIdentifier->citation_label,
            );
        }

        $parent = $resource->igsnMetadata?->parentResource;
        if ($parent !== null && is_string($parent->doi) && trim($parent->doi) !== '') {
            $title = $parent->titles->first(
                fn ($candidate): bool => $candidate->isMainTitle()
                    && trim((string) $candidate->value) !== '',
            );
            $this->associatedResource(
                $identification,
                $parent->doi,
                'DOI',
                'largerWorkCitation',
                $title?->value,
            );
        }
    }

    private function associatedResource(
        DOMElement $identification,
        string $identifierValue,
        string $identifierType,
        string $associationType,
        ?string $label,
    ): void {
        $property = $this->append($identification, self::MRI_NAMESPACE, 'mri:associatedResource');
        $associated = $this->append($property, self::MRI_NAMESPACE, 'mri:MD_AssociatedResource');
        $nameProperty = $this->append($associated, self::MRI_NAMESPACE, 'mri:name');
        $citation = $this->append($nameProperty, self::CIT_NAMESPACE, 'cit:CI_Citation');
        $this->characterString(
            $citation,
            self::CIT_NAMESPACE,
            'cit:title',
            is_string($label) && trim($label) !== '' ? $label : $identifierValue,
        );
        $identifierProperty = $this->append($citation, self::CIT_NAMESPACE, 'cit:identifier');
        $identifier = $this->append(
            $identifierProperty,
            self::MCC_NAMESPACE,
            'mcc:MD_Identifier',
        );
        $this->characterString($identifier, self::MCC_NAMESPACE, 'mcc:code', $identifierValue);
        $this->characterString($identifier, self::MCC_NAMESPACE, 'mcc:codeSpace', $identifierType);
        $this->code(
            $associated,
            self::MRI_NAMESPACE,
            'mri:associationType',
            self::MRI_NAMESPACE,
            'mri:DS_AssociationTypeCode',
            'DS_AssociationTypeCode',
            $associationType,
        );
    }

    private function buildKeywords(DOMElement $identification, Resource $resource): void
    {
        foreach ($resource->subjects as $subject) {
            $this->keyword(
                $identification,
                (string) $subject->value,
                $subject->subject_scheme,
                $subject->scheme_uri,
                $subject->value_uri,
                $subject->breadcrumb_path,
            );
        }

        $igsn = $resource->igsnMetadata;
        if ($igsn !== null) {
            $this->keyword($identification, (string) $igsn->sample_type, 'IGSN sample type');
            $this->keyword($identification, (string) $igsn->material, 'IGSN material');
        }

        foreach ($resource->igsnClassifications as $classification) {
            $this->keyword(
                $identification,
                $classification->value,
                'IGSN sample classification',
            );
        }
    }

    private function keyword(
        DOMElement $identification,
        string $rawValue,
        ?string $scheme = null,
        ?string $schemeUri = null,
        ?string $valueUri = null,
        ?string $breadcrumbPath = null,
    ): void {
        $value = trim($rawValue);
        if (is_string($scheme)
            && trim($scheme) !== ''
            && (! is_string($valueUri) || trim($valueUri) === '')
        ) {
            $value = SubjectBreadcrumbPath::normalize($breadcrumbPath) ?? $value;
        }
        if ($value === '') {
            return;
        }

        $property = $this->append($identification, self::MRI_NAMESPACE, 'mri:descriptiveKeywords');
        $keywords = $this->append($property, self::MRI_NAMESPACE, 'mri:MD_Keywords');
        $keywordProperty = $this->append($keywords, self::MRI_NAMESPACE, 'mri:keyword');
        if (is_string($valueUri) && trim($valueUri) !== '' && $this->isSafeHttpUrl($valueUri)) {
            $anchor = $this->append($keywordProperty, self::GCX_NAMESPACE, 'gcx:Anchor');
            $anchor->setAttributeNS(self::XLINK_NAMESPACE, 'xlink:href', trim($valueUri));
            $anchor->setAttributeNS(self::XLINK_NAMESPACE, 'xlink:type', 'simple');
            $anchor->appendChild($this->dom->createTextNode($value));
        } else {
            $characterString = $this->append($keywordProperty, self::GCO_NAMESPACE, 'gco:CharacterString');
            $characterString->appendChild($this->dom->createTextNode($value));
        }

        if (is_string($scheme) && trim($scheme) !== '') {
            $thesaurusProperty = $this->append($keywords, self::MRI_NAMESPACE, 'mri:thesaurusName');
            $citation = $this->append($thesaurusProperty, self::CIT_NAMESPACE, 'cit:CI_Citation');
            $this->characterString($citation, self::CIT_NAMESPACE, 'cit:title', $scheme);

            if (is_string($schemeUri) && trim($schemeUri) !== '' && $this->isSafeHttpUrl($schemeUri)) {
                $onlineProperty = $this->append($citation, self::CIT_NAMESPACE, 'cit:onlineResource');
                $online = $this->append($onlineProperty, self::CIT_NAMESPACE, 'cit:CI_OnlineResource');
                $this->characterString($online, self::CIT_NAMESPACE, 'cit:linkage', trim($schemeUri));
            }
        }
    }

    private function buildRights(DOMElement $identification, Resource $resource): void
    {
        foreach ($resource->resourceRights as $resourceRight) {
            $right = $resourceRight->right;
            $name = trim((string) ($right?->name ?: $resourceRight->rights_text));
            $uri = trim((string) ($right?->uri ?: $resourceRight->rights_uri));
            if ($name === '' && $uri === '') {
                continue;
            }

            $property = $this->append($identification, self::MRI_NAMESPACE, 'mri:resourceConstraints');
            $constraints = $this->append($property, self::MCO_NAMESPACE, 'mco:MD_LegalConstraints');
            if ($name !== '') {
                $this->characterString($constraints, self::MCO_NAMESPACE, 'mco:useLimitation', $name);
            }

            if ($uri !== '') {
                $referenceProperty = $this->append($constraints, self::MCO_NAMESPACE, 'mco:reference');
                $citation = $this->append($referenceProperty, self::CIT_NAMESPACE, 'cit:CI_Citation');
                $this->characterString(
                    $citation,
                    self::CIT_NAMESPACE,
                    'cit:title',
                    $name !== '' ? $name : $uri,
                );
                $onlineProperty = $this->append($citation, self::CIT_NAMESPACE, 'cit:onlineResource');
                $online = $this->append($onlineProperty, self::CIT_NAMESPACE, 'cit:CI_OnlineResource');
                $this->characterString($online, self::CIT_NAMESPACE, 'cit:linkage', $uri);
            }
        }
    }

    private function buildDistributionInfo(Resource $resource): void
    {
        $landingPage = $resource->landingPage;
        if ($landingPage === null || ! $landingPage->isPublished()) {
            return;
        }

        $onlineResources = [];
        $landingPageUrl = $this->landingPageUrl($resource);
        if ($landingPageUrl !== null) {
            $onlineResources[] = [
                'url' => $landingPageUrl,
                'name' => 'Resource landing page',
                'function' => 'information',
            ];
        }

        if (! $landingPage->downloads_unavailable) {
            foreach ($landingPage->files as $file) {
                $onlineResources[] = [
                    'url' => $file->url,
                    'name' => $this->downloadName($file->url, 'Resource file'),
                    'function' => 'download',
                ];
            }
            foreach ($landingPage->links as $link) {
                $onlineResources[] = [
                    'url' => $link->url,
                    'name' => trim($link->label) !== '' ? $link->label : 'Related resource',
                    'function' => 'information',
                ];
            }
        }

        $onlineResources = array_values(array_filter(
            $onlineResources,
            fn (array $onlineResource): bool => $this->isSafeHttpUrl($onlineResource['url']),
        ));
        if ($onlineResources === []) {
            return;
        }

        $property = $this->append($this->root, self::MDB_NAMESPACE, 'mdb:distributionInfo');
        $distribution = $this->append($property, self::MRD_NAMESPACE, 'mrd:MD_Distribution');
        $transferProperty = $this->append($distribution, self::MRD_NAMESPACE, 'mrd:transferOptions');
        $transferOptions = $this->append(
            $transferProperty,
            self::MRD_NAMESPACE,
            'mrd:MD_DigitalTransferOptions',
        );

        foreach ($onlineResources as $onlineResource) {
            $onlineProperty = $this->append($transferOptions, self::MRD_NAMESPACE, 'mrd:onLine');
            $online = $this->append($onlineProperty, self::CIT_NAMESPACE, 'cit:CI_OnlineResource');
            $this->characterString($online, self::CIT_NAMESPACE, 'cit:linkage', trim($onlineResource['url']));
            $this->characterString($online, self::CIT_NAMESPACE, 'cit:name', $onlineResource['name']);
            $this->code(
                $online,
                self::CIT_NAMESPACE,
                'cit:function',
                self::CIT_NAMESPACE,
                'cit:CI_OnLineFunctionCode',
                'CI_OnLineFunctionCode',
                $onlineResource['function'],
            );
        }
    }

    private function isSafeHttpUrl(string $url): bool
    {
        return UriHelper::isHttpUrl(trim($url))
            && UriHelper::getHost(trim($url)) !== null
            && preg_match('/[\x00-\x1F\x7F]/', $url) !== 1;
    }

    private function downloadName(string $url, string $fallback): string
    {
        $path = UriHelper::getPath(trim($url));
        $name = is_string($path) ? rawurldecode(basename($path)) : '';

        return trim($name) !== '' ? $name : $fallback;
    }

    private function buildSupplementalInformation(DOMElement $identification, Resource $resource): void
    {
        $parts = [];
        foreach ($resource->descriptions as $description) {
            if (in_array($description->descriptionType->slug, ['Abstract', 'Methods', 'SeriesInformation'], true)) {
                continue;
            }

            $value = trim($description->value);
            if ($value !== '') {
                $parts[] = "{$description->descriptionType->name}: {$value}";
            }
        }

        foreach ($resource->fundingReferences as $fundingReference) {
            $awardParts = [];
            foreach ([
                'Award number' => $fundingReference->award_number,
                'Award title' => $fundingReference->award_title,
                'Award URI' => $fundingReference->award_uri,
            ] as $label => $value) {
                if ($value !== null && trim($value) !== '') {
                    $awardParts[] = "{$label}: {$value}";
                }
            }

            if ($awardParts !== []) {
                $parts[] = "Funding award ({$fundingReference->funder_name}): ".implode(', ', $awardParts);
            }
        }

        $igsn = $resource->igsnMetadata;
        if ($igsn !== null) {
            foreach ([
                'Sample type' => $igsn->sample_type,
                'Material' => $igsn->material,
                'Collection method' => $igsn->collection_method,
                'Collection method description' => $igsn->collection_method_description,
                'Collection date precision' => $igsn->collection_date_precision,
                'Depth minimum' => $igsn->depth_min,
                'Depth maximum' => $igsn->depth_max,
                'Depth scale' => $igsn->depth_scale,
                'Cruise or field programme' => $igsn->cruise_field_program,
                'Platform type' => $igsn->platform_type,
                'Platform' => $igsn->platform_name,
                'Platform description' => $igsn->platform_description,
                'Current archive' => $igsn->current_archive,
                'Current archive contact' => $igsn->current_archive_contact,
                'Sample access' => $igsn->sample_access,
                'Operator' => $igsn->operator,
                'Coordinate system' => $igsn->coordinate_system,
                'User code' => $igsn->user_code,
            ] as $label => $value) {
                if ($value !== null && trim((string) $value) !== '') {
                    $parts[] = "{$label}: {$value}";
                }
            }
        }

        if ($parts === []) {
            return;
        }

        $this->characterString(
            $identification,
            self::MRI_NAMESPACE,
            'mri:supplementalInformation',
            implode('; ', $parts),
        );
    }

    private function buildMetadataScope(Resource $resource): void
    {
        $scopeCode = $this->profile->scopeCode($resource);
        if ($scopeCode === null) {
            return;
        }

        $property = $this->append($this->root, self::MDB_NAMESPACE, 'mdb:metadataScope');
        $scope = $this->append($property, self::MDB_NAMESPACE, 'mdb:MD_MetadataScope');
        $this->code(
            $scope,
            self::MDB_NAMESPACE,
            'mdb:resourceScope',
            self::MCC_NAMESPACE,
            'mcc:MD_ScopeCode',
            'MD_ScopeCode',
            $scopeCode,
        );

        if ($scopeCode !== 'dataset') {
            $name = $resource->resourceType?->name;
            $this->characterString(
                $scope,
                self::MDB_NAMESPACE,
                'mdb:name',
                is_string($name) && trim($name) !== '' ? $name : $scopeCode,
            );
        }
    }

    private function buildLocale(DOMElement $parent, string $propertyName, string $languageCode): void
    {
        $propertyNamespace = str_starts_with($propertyName, 'mdb:')
            ? self::MDB_NAMESPACE
            : self::MRI_NAMESPACE;
        $property = $this->append($parent, $propertyNamespace, $propertyName);
        $locale = $this->append($property, self::LAN_NAMESPACE, 'lan:PT_Locale');

        $language = $this->append($locale, self::LAN_NAMESPACE, 'lan:language');
        $languageValue = $this->append($language, self::LAN_NAMESPACE, 'lan:LanguageCode');
        $languageValue->setAttribute('codeList', 'https://www.loc.gov/standards/iso639-2/');
        $languageValue->setAttribute('codeListValue', $languageCode);
        $languageValue->appendChild($this->dom->createTextNode($languageCode));

        $encoding = $this->append($locale, self::LAN_NAMESPACE, 'lan:characterEncoding');
        $encodingValue = $this->append($encoding, self::LAN_NAMESPACE, 'lan:MD_CharacterSetCode');
        $encodingValue->setAttribute('codeList', 'https://www.iana.org/assignments/character-sets');
        $encodingValue->setAttribute('codeListValue', 'utf8');
        $encodingValue->appendChild($this->dom->createTextNode('UTF-8'));
    }

    /**
     * @param  list<Affiliation>  $affiliations
     */
    private function responsibility(
        DOMElement $property,
        string $role,
        string $partyName,
        bool $organizational,
        ?string $identifier = null,
        ?string $identifierScheme = null,
        ?string $email = null,
        ?string $website = null,
        array $affiliations = [],
    ): void {
        $responsibility = $this->append($property, self::CIT_NAMESPACE, 'cit:CI_Responsibility');
        $this->code(
            $responsibility,
            self::CIT_NAMESPACE,
            'cit:role',
            self::CIT_NAMESPACE,
            'cit:CI_RoleCode',
            'CI_RoleCode',
            $role,
        );

        $partyProperty = $this->append($responsibility, self::CIT_NAMESPACE, 'cit:party');
        $party = $this->append(
            $partyProperty,
            self::CIT_NAMESPACE,
            $organizational ? 'cit:CI_Organisation' : 'cit:CI_Individual',
        );
        $this->characterString($party, self::CIT_NAMESPACE, 'cit:name', $partyName, mandatory: true);

        $email = is_string($email) && trim($email) !== '' ? trim($email) : null;
        $website = is_string($website) && trim($website) !== '' ? trim($website) : null;
        if ($email !== null || $website !== null) {
            $contactProperty = $this->append($party, self::CIT_NAMESPACE, 'cit:contactInfo');
            $contact = $this->append($contactProperty, self::CIT_NAMESPACE, 'cit:CI_Contact');
            if ($email !== null) {
                $addressProperty = $this->append($contact, self::CIT_NAMESPACE, 'cit:address');
                $address = $this->append($addressProperty, self::CIT_NAMESPACE, 'cit:CI_Address');
                $this->characterString(
                    $address,
                    self::CIT_NAMESPACE,
                    'cit:electronicMailAddress',
                    $email,
                );
            }
            if ($website !== null) {
                $onlineProperty = $this->append($contact, self::CIT_NAMESPACE, 'cit:onlineResource');
                $online = $this->append($onlineProperty, self::CIT_NAMESPACE, 'cit:CI_OnlineResource');
                $this->characterString($online, self::CIT_NAMESPACE, 'cit:linkage', $website);
            }
        }

        if (is_string($identifier) && trim($identifier) !== '') {
            $identifierProperty = $this->append($party, self::CIT_NAMESPACE, 'cit:partyIdentifier');
            $mdIdentifier = $this->append($identifierProperty, self::MCC_NAMESPACE, 'mcc:MD_Identifier');
            $this->characterString($mdIdentifier, self::MCC_NAMESPACE, 'mcc:code', $identifier);
            if (is_string($identifierScheme) && trim($identifierScheme) !== '') {
                $this->characterString(
                    $mdIdentifier,
                    self::MCC_NAMESPACE,
                    'mcc:codeSpace',
                    $identifierScheme,
                );
            }
        }

        foreach ($affiliations as $affiliation) {
            $affiliationName = trim($affiliation->name);
            if ($affiliationName === '') {
                continue;
            }

            $affiliationProperty = $this->append($responsibility, self::CIT_NAMESPACE, 'cit:party');
            $organization = $this->append($affiliationProperty, self::CIT_NAMESPACE, 'cit:CI_Organisation');
            $this->characterString($organization, self::CIT_NAMESPACE, 'cit:name', $affiliationName);

            if (is_string($affiliation->identifier) && trim($affiliation->identifier) !== '') {
                $identifierProperty = $this->append($organization, self::CIT_NAMESPACE, 'cit:partyIdentifier');
                $mdIdentifier = $this->append($identifierProperty, self::MCC_NAMESPACE, 'mcc:MD_Identifier');
                $this->characterString($mdIdentifier, self::MCC_NAMESPACE, 'mcc:code', $affiliation->identifier);
                $this->characterString(
                    $mdIdentifier,
                    self::MCC_NAMESPACE,
                    'mcc:codeSpace',
                    $affiliation->identifier_scheme,
                );
            }
        }
    }

    private function typedDate(
        DOMElement $property,
        string $value,
        string $dateType,
        bool $dateTime = false,
    ): void {
        $date = $this->append($property, self::CIT_NAMESPACE, 'cit:CI_Date');
        $dateProperty = $this->append($date, self::CIT_NAMESPACE, 'cit:date');
        $dateValue = $this->append(
            $dateProperty,
            self::GCO_NAMESPACE,
            $dateTime ? 'gco:DateTime' : 'gco:Date',
        );
        $dateValue->appendChild($this->dom->createTextNode($value));

        $this->code(
            $date,
            self::CIT_NAMESPACE,
            'cit:dateType',
            self::CIT_NAMESPACE,
            'cit:CI_DateTypeCode',
            'CI_DateTypeCode',
            $dateType,
        );
    }

    private function characterString(
        DOMElement $parent,
        string $propertyNamespace,
        string $propertyName,
        ?string $value,
        bool $mandatory = false,
    ): ?DOMElement {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '' && ! $mandatory) {
            return null;
        }

        $property = $this->append($parent, $propertyNamespace, $propertyName);
        if ($value === '') {
            $property->setAttributeNS(self::GCO_NAMESPACE, 'gco:nilReason', 'missing');

            return $property;
        }

        $characterString = $this->append($property, self::GCO_NAMESPACE, 'gco:CharacterString');
        $characterString->appendChild($this->dom->createTextNode($value));

        return $property;
    }

    private function code(
        DOMElement $parent,
        string $propertyNamespace,
        string $propertyName,
        string $codeNamespace,
        string $codeElementName,
        string $codeListName,
        string $value,
    ): void {
        $property = $this->append($parent, $propertyNamespace, $propertyName);
        $code = $this->append($property, $codeNamespace, $codeElementName);
        $code->setAttribute(
            'codeList',
            rtrim((string) config('iso19115.codelist'), '#')
                ."#ISO19115-1.1.{$this->prefixForNamespace($codeNamespace)}.{$codeListName}",
        );
        $code->setAttribute('codeListValue', $value);
        $code->appendChild($this->dom->createTextNode($value));
    }

    private function decimal(DOMElement $parent, string $propertyName, float $value): void
    {
        $property = $this->append($parent, self::GEX_NAMESPACE, $propertyName);
        $decimal = $this->append($property, self::GCO_NAMESPACE, 'gco:Decimal');
        $decimal->appendChild($this->dom->createTextNode($this->formatDecimal($value)));
    }

    private function boolean(DOMElement $parent, string $propertyName, bool $value): void
    {
        $property = $this->append($parent, self::GEX_NAMESPACE, $propertyName);
        $boolean = $this->append($property, self::GCO_NAMESPACE, 'gco:Boolean');
        $boolean->appendChild($this->dom->createTextNode($value ? 'true' : 'false'));
    }

    /**
     * @return array{west: float, east: float, south: float, north: float}|null
     */
    private function bounds(GeoLocation $geoLocation): ?array
    {
        if ($geoLocation->hasBox()) {
            return [
                'west' => (float) $geoLocation->west_bound_longitude,
                'east' => (float) $geoLocation->east_bound_longitude,
                'south' => (float) $geoLocation->south_bound_latitude,
                'north' => (float) $geoLocation->north_bound_latitude,
            ];
        }

        if ($geoLocation->hasPoint()) {
            $longitude = (float) $geoLocation->point_longitude;
            $latitude = (float) $geoLocation->point_latitude;

            return [
                'west' => $longitude,
                'east' => $longitude,
                'south' => $latitude,
                'north' => $latitude,
            ];
        }

        $points = $this->usablePoints($geoLocation);

        if ($points === []) {
            return null;
        }

        $longitudes = array_map(
            static fn (array $point): float => $point['longitude'],
            $points,
        );
        $latitudes = array_map(
            static fn (array $point): float => $point['latitude'],
            $points,
        );

        return [
            'west' => min($longitudes),
            'east' => max($longitudes),
            'south' => min($latitudes),
            'north' => max($latitudes),
        ];
    }

    /**
     * @return list<array{longitude: float, latitude: float}>
     */
    private function usablePoints(GeoLocation $geoLocation): array
    {
        $rawPoints = $geoLocation->getAttribute('polygon_points');
        if (! is_array($rawPoints)) {
            return [];
        }

        $points = [];
        foreach ($rawPoints as $point) {
            if (
                ! is_array($point)
                || ! is_numeric($point['longitude'] ?? null)
                || ! is_numeric($point['latitude'] ?? null)
            ) {
                continue;
            }
            $points[] = [
                'longitude' => (float) $point['longitude'],
                'latitude' => (float) $point['latitude'],
            ];
        }

        return $points;
    }

    private function temporalValue(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return preg_match('/^\d{4}(?:-\d{2}(?:-\d{2})?)?$/', $value) === 1
            ? $value
            : null;
    }

    /**
     * @return array{name: string, organizational: bool, identifier: string|null, identifierScheme: string|null}|null
     */
    private function partyData(?Model $party): ?array
    {
        if ($party instanceof Person) {
            $name = trim($party->full_name);
            if ($name === '') {
                return null;
            }

            return [
                'name' => $name,
                'organizational' => false,
                'identifier' => $party->name_identifier,
                'identifierScheme' => $party->name_identifier_scheme,
            ];
        }

        if ($party instanceof Institution) {
            $name = trim($party->name);
            if ($name === '') {
                return null;
            }

            return [
                'name' => $name,
                'organizational' => true,
                'identifier' => $party->name_identifier,
                'identifierScheme' => $party->name_identifier_scheme,
            ];
        }

        return null;
    }

    private function languageCode(Resource $resource): string
    {
        $raw = strtolower(trim((string) ($resource->language?->code ?: 'en')));
        $primary = explode('-', str_replace('_', '-', $raw))[0];

        return [
            'en' => 'eng',
            'de' => 'deu',
            'fr' => 'fra',
            'es' => 'spa',
            'it' => 'ita',
            'pt' => 'por',
            'nl' => 'nld',
            'pl' => 'pol',
            'ru' => 'rus',
            'zh' => 'zho',
            'ja' => 'jpn',
        ][$primary] ?? ($primary !== '' ? $primary : 'eng');
    }

    private function landingPageUrl(Resource $resource): ?string
    {
        $landingPage = $resource->landingPage;
        if ($landingPage === null || ! $landingPage->isPublished()) {
            return is_string($resource->doi) && trim($resource->doi) !== ''
                ? 'https://doi.org/'.trim($resource->doi)
                : null;
        }

        return url($landingPage->getPublicPath());
    }

    private function metadataUrl(Resource $resource): ?string
    {
        $landingPage = $resource->landingPage;
        if ($landingPage === null || ! $landingPage->isPublished() || $landingPage->doi_prefix === null) {
            return null;
        }

        return url($landingPage->getPublicPath().'/metadata/iso-19115-3.xml');
    }

    private function formatDecimal(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 8, '.', ''), '0'), '.');

        return $formatted === '-0' ? '0' : $formatted;
    }

    private function prefixForNamespace(string $namespace): string
    {
        return array_search($namespace, self::NAMESPACES, true) ?: 'mcc';
    }

    private function append(DOMElement $parent, string $namespace, string $qualifiedName): DOMElement
    {
        $element = $this->dom->createElementNS($namespace, $qualifiedName);
        $parent->appendChild($element);

        return $element;
    }
}
