<?php

declare(strict_types=1);

use App\Models\Affiliation;
use App\Models\AlternateIdentifier;
use App\Models\ContributorType;
use App\Models\DateType;
use App\Models\Description;
use App\Models\DescriptionType;
use App\Models\Format;
use App\Models\FundingReference;
use App\Models\GeoLocation;
use App\Models\IdentifierType;
use App\Models\IgsnClassification;
use App\Models\IgsnMetadata;
use App\Models\LandingPage;
use App\Models\Person;
use App\Models\Publisher;
use App\Models\RelatedIdentifier;
use App\Models\RelationType;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\ResourceDate;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\Subject;
use App\Models\Title;
use App\Services\Iso19115\Iso19115ResourceProfileService;
use App\Services\Iso19115\Iso19115XmlExporter;
use App\Services\Iso19115\Iso19115XmlValidator;

covers(Iso19115ResourceProfileService::class, Iso19115XmlExporter::class, Iso19115XmlValidator::class);

/**
 * @return array{0: Resource, 1: Iso19115XmlExporter}
 */
function iso19115Resource(array $attributes = []): array
{
    $resource = Resource::factory()->create($attributes);
    Title::factory()->create([
        'resource_id' => $resource->id,
        'value' => 'Seismic & magnetic observations',
    ]);
    Description::factory()->abstract()->create([
        'resource_id' => $resource->id,
        'value' => 'A reproducible geoscience dataset.',
    ]);

    return [$resource->refresh(), app(Iso19115XmlExporter::class)];
}

/**
 * @return array{0: DOMDocument, 1: DOMXPath}
 */
function parseIso19115(string $xml): array
{
    $document = new DOMDocument;
    expect($document->loadXML($xml))->toBeTrue();

    $xpath = new DOMXPath($document);
    foreach ([
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
        'gcx' => Iso19115XmlExporter::GCX_NAMESPACE,
        'xlink' => Iso19115XmlExporter::XLINK_NAMESPACE,
        'xsi' => Iso19115XmlExporter::XSI_NAMESPACE,
    ] as $prefix => $namespace) {
        $xpath->registerNamespace($prefix, $namespace);
    }

    return [$document, $xpath];
}

/**
 * @return list<string>
 */
function iso19115ReachableSchemaPaths(string $packageRoot): array
{
    $canonicalRoot = realpath($packageRoot);
    if ($canonicalRoot === false) {
        throw new RuntimeException('ISO 19115 schema package root is missing.');
    }

    $pending = [$canonicalRoot.DIRECTORY_SEPARATOR.'ernie-profile.xsd'];
    $seen = [];
    $remotePrefixes = [
        'https://schemas.isotc211.org/' => 'isotc211/',
        'http://schemas.isotc211.org/' => 'isotc211/',
        'https://schemas.opengis.net/' => 'opengis/',
        'http://schemas.opengis.net/' => 'opengis/',
    ];
    $exactRemotePaths = [
        'http://www.w3.org/1999/xlink.xsd' => 'w3c/1999/xlink.xsd',
        'https://www.w3.org/1999/xlink.xsd' => 'w3c/1999/xlink.xsd',
        'http://www.w3.org/2001/xml.xsd' => 'w3c/2001/xml.xsd',
        'https://www.w3.org/2001/xml.xsd' => 'w3c/2001/xml.xsd',
    ];

    while ($pending !== []) {
        $schemaPath = realpath((string) array_pop($pending));
        if ($schemaPath === false) {
            throw new RuntimeException('The ISO 19115 schema dependency closure is incomplete.');
        }
        if (! str_starts_with($schemaPath, $canonicalRoot.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('An ISO 19115 schema dependency resolves outside the pinned package.');
        }

        $relativePath = str_replace('\\', '/', substr($schemaPath, strlen($canonicalRoot) + 1));
        if (isset($seen[$relativePath])) {
            continue;
        }
        $seen[$relativePath] = true;

        $document = new DOMDocument;
        if (! $document->load($schemaPath, LIBXML_NONET)) {
            throw new RuntimeException("Failed to parse pinned schema {$relativePath}.");
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('xs', 'http://www.w3.org/2001/XMLSchema');
        $dependencies = $xpath->query('//xs:import[@schemaLocation] | //xs:include[@schemaLocation] | //xs:redefine[@schemaLocation]');
        if ($dependencies === false) {
            throw new RuntimeException("Failed to inspect pinned schema {$relativePath}.");
        }

        foreach ($dependencies as $dependency) {
            if (! $dependency instanceof DOMElement) {
                continue;
            }
            $location = $dependency->getAttribute('schemaLocation');
            $mappedPath = $exactRemotePaths[$location] ?? null;
            foreach ($remotePrefixes as $remotePrefix => $localPrefix) {
                if (str_starts_with($location, $remotePrefix)) {
                    $mappedPath = $localPrefix.substr($location, strlen($remotePrefix));
                    break;
                }
            }

            if ($mappedPath !== null) {
                $pending[] = $canonicalRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $mappedPath);
            } elseif (preg_match('~^https?://~', $location) === 1) {
                throw new RuntimeException("Unmapped remote schema dependency: {$location}");
            } else {
                $pending[] = dirname($schemaPath).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $location);
            }
        }
    }

    $reachable = array_keys($seen);
    sort($reachable);

    return $reachable;
}

test('exports deterministic ISO 19115-3 XML with current namespaces and schema', function () {
    [$resource, $exporter] = iso19115Resource(['doi' => '10.5880/test.iso.001']);

    $xml = $exporter->export($resource);
    [$document, $xpath] = parseIso19115($xml);

    expect($document->documentElement?->localName)->toBe('MD_Metadata')
        ->and($document->documentElement?->namespaceURI)->toBe(Iso19115XmlExporter::MDB_NAMESPACE)
        ->and($document->documentElement?->getAttributeNS(Iso19115XmlExporter::XSI_NAMESPACE, 'schemaLocation'))
        ->toBe(Iso19115XmlExporter::MDB_NAMESPACE.' '.config('iso19115.schema'))
        ->and($xpath->evaluate('string(/mdb:MD_Metadata/mdb:metadataIdentifier//mcc:code/gco:CharacterString)'))
        ->toBe('10.5880/test.iso.001')
        ->and($xpath->evaluate('string(/mdb:MD_Metadata/mdb:defaultLocale//lan:LanguageCode/@codeListValue)'))
        ->toBe('eng')
        ->and($xpath->evaluate('string(/mdb:MD_Metadata/mdb:defaultLocale//lan:MD_CharacterSetCode/@codeListValue)'))
        ->toBe('utf8')
        ->and((float) $xpath->evaluate('count(/mdb:MD_Metadata/mdb:metadataStandard)'))->toBe(2.0)
        ->and($exporter->export($resource))->toBe($xml);
});

test('exports metadata point of contact, creation date, title, publication date and abstract', function () {
    [$resource, $exporter] = iso19115Resource([
        'doi' => '10.5880/test.iso.002',
        'publication_year' => 2025,
        'version' => '2.1',
    ]);
    $resource->forceFill(['created_at' => '2025-01-02 03:04:05'])->saveQuietly();

    $xml = $exporter->export($resource->fresh());
    [, $xpath] = parseIso19115($xml);

    expect($xpath->evaluate('string(/mdb:MD_Metadata/mdb:contact//cit:CI_RoleCode/@codeListValue)'))->toBe('pointOfContact')
        ->and($xpath->evaluate('string(/mdb:MD_Metadata/mdb:contact//cit:name/gco:CharacterString)'))->toBe('GFZ Data Services')
        ->and($xpath->evaluate('string(/mdb:MD_Metadata/mdb:contact//cit:electronicMailAddress/gco:CharacterString)'))
        ->toBe('datapub@gfz.de')
        ->and($xpath->evaluate('string(/mdb:MD_Metadata/mdb:dateInfo//gco:DateTime)'))->toBe('2025-01-02T03:04:05Z')
        ->and($xpath->evaluate('string(//mri:citation//cit:title/gco:CharacterString)'))->toBe('Seismic & magnetic observations')
        ->and($xpath->evaluate('string(//mri:citation//cit:date//gco:Date)'))->toBe('2025')
        ->and($xpath->evaluate('string(//mri:citation//cit:edition/gco:CharacterString)'))->toBe('2.1')
        ->and($xpath->evaluate('string(//mri:abstract/gco:CharacterString)'))->toBe('A reproducible geoscience dataset.');
});

test('exports creator identity and resource contact details without confusing them with metadata contact', function () {
    [$resource, $exporter] = iso19115Resource();
    $person = Person::factory()->create([
        'given_name' => 'Ada',
        'family_name' => 'Lovelace',
        'name_identifier' => 'https://orcid.org/0000-0001-2345-6789',
        'name_identifier_scheme' => 'ORCID',
    ]);
    $creator = ResourceCreator::create([
        'resource_id' => $resource->id,
        'creatorable_type' => Person::class,
        'creatorable_id' => $person->id,
        'position' => 1,
        'is_contact' => true,
        'email' => 'ada@example.org',
        'website' => 'https://example.org/ada',
    ]);
    Affiliation::create([
        'affiliatable_type' => ResourceCreator::class,
        'affiliatable_id' => $creator->id,
        'name' => 'Analytical Engine Institute',
        'identifier' => 'https://ror.org/03yrm5c26',
        'identifier_scheme' => 'ROR',
        'scheme_uri' => 'https://ror.org/',
    ]);

    $xml = $exporter->export($resource->fresh());
    [, $xpath] = parseIso19115($xml);

    expect($xpath->evaluate('string(//cit:citedResponsibleParty//cit:name/gco:CharacterString)'))->toBe('Lovelace, Ada')
        ->and($xpath->evaluate('string(//cit:citedResponsibleParty//cit:CI_RoleCode/@codeListValue)'))->toBe('originator')
        ->and($xpath->evaluate('string(//cit:citedResponsibleParty//mcc:code/gco:CharacterString)'))
        ->toBe('https://orcid.org/0000-0001-2345-6789')
        ->and($xpath->evaluate('string(//mri:pointOfContact//cit:CI_RoleCode/@codeListValue)'))->toBe('pointOfContact')
        ->and($xpath->evaluate('string(//mri:pointOfContact//cit:electronicMailAddress/gco:CharacterString)'))
        ->toBe('ada@example.org')
        ->and($xpath->evaluate('string(//mri:pointOfContact//cit:onlineResource//cit:linkage/gco:CharacterString)'))
        ->toBe('https://example.org/ada')
        ->and($xpath->evaluate(
            'string(//cit:citedResponsibleParty[.//cit:name/gco:CharacterString="Lovelace, Ada"]//cit:CI_Organisation/cit:name/gco:CharacterString)',
        ))->toBe('Analytical Engine Institute')
        ->and($xpath->evaluate('string(//cit:CI_Organisation//mcc:codeSpace/gco:CharacterString)'))->toBe('ROR');
});

test('exports revision and mapped resource dates, alternate identifiers, publisher, funder and supplemental details', function () {
    $publisher = Publisher::factory()->gfz()->create();
    [$resource, $exporter] = iso19115Resource(['publisher_id' => $publisher->id]);

    AlternateIdentifier::create([
        'resource_id' => $resource->id,
        'value' => 'GFZ-LOCAL-42',
        'type' => 'Local accession number',
        'position' => 0,
    ]);

    $createdType = DateType::firstOrCreate(
        ['slug' => 'Created'],
        ['name' => 'Created', 'is_active' => true],
    );
    $otherType = DateType::firstOrCreate(
        ['slug' => 'Other'],
        ['name' => 'Other', 'is_active' => true],
    );
    ResourceDate::create([
        'resource_id' => $resource->id,
        'date_type_id' => $createdType->id,
        'date_value' => '2024-06',
    ]);
    ResourceDate::create([
        'resource_id' => $resource->id,
        'date_type_id' => $otherType->id,
        'date_value' => '1999-01-01',
    ]);

    FundingReference::create([
        'resource_id' => $resource->id,
        'funder_name' => 'German Research Foundation',
        'funder_identifier' => 'https://ror.org/018mejw64',
        'award_number' => 'DFG-42',
        'award_title' => 'Open geoscience metadata',
        'award_uri' => 'https://example.org/awards/DFG-42',
    ]);
    Description::factory()->technicalInfo()->create([
        'resource_id' => $resource->id,
        'value' => 'NetCDF files use CF conventions.',
    ]);

    $resource->timestamps = false;
    $resource->forceFill([
        'created_at' => '2025-01-02 03:04:05',
        'updated_at' => '2025-02-03 04:05:06',
    ])->saveQuietly();

    [, $xpath] = parseIso19115($exporter->export($resource->fresh()));

    expect($xpath->evaluate(
        'string(/mdb:MD_Metadata/mdb:dateInfo/cit:CI_Date[cit:dateType/cit:CI_DateTypeCode/@codeListValue="revision"]//gco:DateTime)',
    ))->toBe('2025-02-03T04:05:06Z')
        ->and($xpath->evaluate(
            'string(//mri:citation//cit:CI_Date[cit:dateType/cit:CI_DateTypeCode/@codeListValue="creation"]//gco:Date)',
        ))->toBe('2024-06')
        ->and((float) $xpath->evaluate(
            'count(//mri:citation//cit:CI_Date[cit:dateType/cit:CI_DateTypeCode/@codeListValue="other"])',
        ))->toBe(0.0)
        ->and($xpath->evaluate(
            'string(//mri:citation//mcc:MD_Identifier[mcc:codeSpace/gco:CharacterString="Local accession number"]/mcc:code/gco:CharacterString)',
        ))->toBe('GFZ-LOCAL-42')
        ->and($xpath->evaluate(
            'string(//cit:citedResponsibleParty[cit:CI_Responsibility/cit:role/cit:CI_RoleCode/@codeListValue="publisher"]//cit:name/gco:CharacterString)',
        ))->toBe('GFZ Data Services')
        ->and($xpath->evaluate(
            'string(//cit:citedResponsibleParty[cit:CI_Responsibility/cit:role/cit:CI_RoleCode/@codeListValue="funder"]//cit:name/gco:CharacterString)',
        ))->toBe('German Research Foundation')
        ->and($xpath->evaluate('string(//mri:supplementalInformation/gco:CharacterString)'))
        ->toContain('Technical Info: NetCDF files use CF conventions.')
        ->toContain('Award number: DFG-42')
        ->toContain('Award title: Open geoscience metadata')
        ->toContain('Award URI: https://example.org/awards/DFG-42');
});

test('maps every approved DataCite contributor role and omits unmapped roles', function (string $slug, ?string $isoRole) {
    [$resource, $exporter] = iso19115Resource();
    $person = Person::factory()->create([
        'given_name' => 'Grace',
        'family_name' => 'Hopper',
    ]);
    $contributor = ResourceContributor::create([
        'resource_id' => $resource->id,
        'contributorable_type' => Person::class,
        'contributorable_id' => $person->id,
        'position' => 1,
    ]);
    $type = ContributorType::firstOrCreate(
        ['slug' => $slug],
        ['name' => $slug, 'is_active' => true],
    );
    $contributor->contributorTypes()->attach($type);

    [, $xpath] = parseIso19115($exporter->export($resource->fresh()));
    $expression = '//cit:citedResponsibleParty[.//cit:name/gco:CharacterString="Hopper, Grace"]//cit:CI_RoleCode/@codeListValue';

    expect($xpath->evaluate('string('.$expression.')'))->toBe($isoRole ?? '');
})->with([
    ['DataCollector', 'contributor'],
    ['DataCurator', 'custodian'],
    ['DataManager', 'custodian'],
    ['Distributor', 'distributor'],
    ['Editor', 'editor'],
    ['HostingInstitution', 'resourceProvider'],
    ['Producer', 'originator'],
    ['ProjectLeader', 'principalInvestigator'],
    ['ProjectManager', 'principalInvestigator'],
    ['ProjectMember', 'collaborator'],
    ['Researcher', 'contributor'],
    ['ResearchGroup', 'collaborator'],
    ['RightsHolder', 'rightsHolder'],
    ['Sponsor', 'sponsor'],
    ['Supervisor', 'collaborator'],
    ['WorkPackageLeader', 'principalInvestigator'],
    ['Other', null],
]);

test('maps every approved DataCite relation type and omits ambiguous relations', function (
    string $dataCiteRelation,
    ?string $isoAssociation,
) {
    [$resource, $exporter] = iso19115Resource();
    $identifierType = IdentifierType::firstOrCreate(
        ['slug' => 'DOI'],
        ['name' => 'DOI', 'is_active' => true],
    );
    $relationType = RelationType::firstOrCreate(
        ['slug' => $dataCiteRelation],
        ['name' => $dataCiteRelation, 'is_active' => true],
    );
    RelatedIdentifier::create([
        'resource_id' => $resource->id,
        'identifier' => '10.5880/related.resource',
        'identifier_type_id' => $identifierType->id,
        'relation_type_id' => $relationType->id,
        'position' => 0,
    ]);

    [, $xpath] = parseIso19115($exporter->export($resource->fresh()));

    expect($xpath->evaluate(
        'string(//mri:associatedResource//mri:DS_AssociationTypeCode/@codeListValue)',
    ))->toBe($isoAssociation ?? '');
})->with([
    ['IsPartOf', 'largerWorkCitation'],
    ['HasPart', 'isComposedOf'],
    ['IsVersionOf', 'revisionOf'],
    ['IsNewVersionOf', 'revisionOf'],
    ['IsPreviousVersionOf', 'revisionOf'],
    ['Requires', 'dependency'],
    ['IsRequiredBy', 'dependency'],
    ['IsDerivedFrom', 'source'],
    ['Other', null],
]);

test('exports alternate titles, methods, controlled keywords, rights and bounding boxes in schema order', function () {
    [$resource, $exporter] = iso19115Resource();
    Title::factory()->alternativeTitle()->create([
        'resource_id' => $resource->id,
        'value' => 'Alternative title',
    ]);
    Description::factory()->methods()->create([
        'resource_id' => $resource->id,
        'value' => 'Collected using calibrated sensors.',
    ]);
    Subject::factory()->gcmd()->create(['resource_id' => $resource->id]);
    GeoLocation::factory()->withBox(-12.5, 14.75, -8.25, 52.5)->create([
        'resource_id' => $resource->id,
        'place' => 'North Atlantic',
    ]);
    $right = Right::factory()->create([
        'name' => 'Creative Commons Attribution 4.0 International',
        'uri' => 'https://creativecommons.org/licenses/by/4.0/',
    ]);
    $resource->rights()->attach($right);

    $xml = $exporter->export($resource->fresh());
    [, $xpath] = parseIso19115($xml);

    expect($xpath->evaluate('string(//cit:alternateTitle/gco:CharacterString)'))->toBe('Alternative title')
        ->and($xpath->evaluate('string(//mri:purpose/gco:CharacterString)'))->toBe('Collected using calibrated sensors.')
        ->and($xpath->evaluate('string(//mri:descriptiveKeywords//mri:keyword/*[self::gco:CharacterString or self::gcx:Anchor])'))
        ->toContain('EARTH SCIENCE')
        ->and($xpath->evaluate('string(//mri:descriptiveKeywords//mri:thesaurusName//cit:title/gco:CharacterString)'))
        ->toBe('GCMD Science Keywords')
        ->and($xpath->evaluate('string(//gex:EX_Extent/gex:description/gco:CharacterString)'))->toBe('North Atlantic')
        ->and($xpath->evaluate('string(//gex:westBoundLongitude/gco:Decimal)'))->toBe('-12.5')
        ->and($xpath->evaluate('string(//gex:eastBoundLongitude/gco:Decimal)'))->toBe('14.75')
        ->and($xpath->evaluate('string(//mco:useLimitation/gco:CharacterString)'))
        ->toBe('Creative Commons Attribution 4.0 International')
        ->and($xpath->evaluate('string(//mco:reference//cit:linkage/gco:CharacterString)'))
        ->toBe('https://creativecommons.org/licenses/by/4.0/');
});

test('exports resolved CGI Simple Lithology keywords as linked ISO anchors', function () {
    [$resource, $exporter] = iso19115Resource();
    Subject::create([
        'resource_id' => $resource->id,
        'value' => 'Basalt',
        'subject_scheme' => 'CGI Simple Lithology',
        'scheme_uri' => 'http://resource.geosciml.org/classifierscheme/cgi/2016.01/simplelithology',
        'value_uri' => 'http://resource.geosciml.org/classifier/cgi/lithology/basalt',
        'breadcrumb_path' => 'Rock > Igneous material > Igneous rock > Basalt',
    ]);

    $xml = $exporter->export($resource->fresh());
    [, $xpath] = parseIso19115($xml);
    $validation = app(Iso19115XmlValidator::class)->validate($xml);

    expect($xpath->evaluate(
        'string(//mri:MD_Keywords[mri:thesaurusName//cit:title/gco:CharacterString="CGI Simple Lithology"]/mri:keyword/gcx:Anchor)',
    ))->toBe('Basalt')
        ->and($xpath->evaluate(
            'string(//mri:MD_Keywords[mri:thesaurusName//cit:title/gco:CharacterString="CGI Simple Lithology"]/mri:keyword/gcx:Anchor/@xlink:href)',
        ))->toBe('http://resource.geosciml.org/classifier/cgi/lithology/basalt')
        ->and($xpath->evaluate(
            'string(//mri:MD_Keywords[mri:thesaurusName//cit:title/gco:CharacterString="CGI Simple Lithology"]//cit:onlineResource//cit:linkage/gco:CharacterString)',
        ))->toBe('http://resource.geosciml.org/classifierscheme/cgi/2016.01/simplelithology')
        ->and($validation->isValid())->toBeTrue();
});

test('preserves unresolved legacy CGI Simple Lithology breadcrumbs as ISO character strings', function () {
    [$resource, $exporter] = iso19115Resource();
    Subject::create([
        'resource_id' => $resource->id,
        'value' => 'Historical rock label',
        'subject_scheme' => 'CGI Simple Lithology',
        'scheme_uri' => 'http://resource.geosciml.org/classifierscheme/cgi/2016.01/simplelithology',
        'value_uri' => null,
        'breadcrumb_path' => 'Rock > Historical rock label',
    ]);

    $xml = $exporter->export($resource->fresh());
    [, $xpath] = parseIso19115($xml);

    expect($xpath->evaluate(
        'string(//mri:MD_Keywords[mri:thesaurusName//cit:title/gco:CharacterString="CGI Simple Lithology"]/mri:keyword/gco:CharacterString)',
    ))->toBe('Rock > Historical rock label')
        ->and((float) $xpath->evaluate(
            'count(//mri:MD_Keywords[mri:thesaurusName//cit:title/gco:CharacterString="CGI Simple Lithology"]/mri:keyword/gcx:Anchor)',
        ))->toBe(0.0)
        ->and(app(Iso19115XmlValidator::class)->validate($xml)->isValid())->toBeTrue();
});

test('preserves series, formats, explicit associations, place geometry and temporal ranges', function () {
    [$resource, $exporter] = iso19115Resource();

    $seriesType = DescriptionType::firstOrCreate(
        ['slug' => 'SeriesInformation'],
        ['name' => 'Series Information', 'is_active' => true],
    );
    Description::create([
        'resource_id' => $resource->id,
        'description_type_id' => $seriesType->id,
        'value' => 'Global Seismic Data Collection',
        'language' => 'en',
    ]);
    Format::create([
        'resource_id' => $resource->id,
        'value' => 'application/netcdf',
    ]);

    $doiType = IdentifierType::firstOrCreate(
        ['slug' => 'DOI'],
        ['name' => 'DOI', 'is_active' => true],
    );
    $isPartOf = RelationType::firstOrCreate(
        ['slug' => 'IsPartOf'],
        ['name' => 'Is Part Of', 'is_active' => true],
    );
    $other = RelationType::firstOrCreate(
        ['slug' => 'Other'],
        ['name' => 'Other', 'is_active' => true],
    );
    RelatedIdentifier::create([
        'resource_id' => $resource->id,
        'identifier' => '10.5880/parent.collection',
        'identifier_type_id' => $doiType->id,
        'relation_type_id' => $isPartOf->id,
        'citation_label' => 'Parent collection',
        'position' => 0,
    ]);
    RelatedIdentifier::create([
        'resource_id' => $resource->id,
        'identifier' => '10.5880/ambiguous.relation',
        'identifier_type_id' => $doiType->id,
        'relation_type_id' => $other->id,
        'position' => 1,
    ]);

    $collected = DateType::firstOrCreate(
        ['slug' => 'Collected'],
        ['name' => 'Collected', 'is_active' => true],
    );
    ResourceDate::create([
        'resource_id' => $resource->id,
        'date_type_id' => $collected->id,
        'start_date' => '2023-04-01',
        'end_date' => '2023-04-30',
    ]);
    $valid = DateType::firstOrCreate(
        ['slug' => 'Valid'],
        ['name' => 'Valid', 'is_active' => true],
    );
    ResourceDate::create([
        'resource_id' => $resource->id,
        'date_type_id' => $valid->id,
        'date_value' => '2024',
    ]);
    ResourceDate::create([
        'resource_id' => $resource->id,
        'date_type_id' => $collected->id,
        'start_date' => '2025-01-01',
    ]);
    GeoLocation::factory()->withPoint(13.064, 52.384)->create([
        'resource_id' => $resource->id,
        'place' => 'Potsdam, Germany',
    ]);

    $xml = $exporter->export($resource->fresh());
    [, $xpath] = parseIso19115($xml);
    $validation = app(Iso19115XmlValidator::class)->validate($xml);

    expect($xpath->evaluate('string(//cit:series/cit:CI_Series/cit:name/gco:CharacterString)'))
        ->toBe('Global Seismic Data Collection')
        ->and($xpath->evaluate('string(//mri:resourceFormat/mrd:MD_Format//cit:title/gco:CharacterString)'))
        ->toBe('application/netcdf')
        ->and($xpath->evaluate('string(//mri:associatedResource//mri:DS_AssociationTypeCode/@codeListValue)'))
        ->toBe('largerWorkCitation')
        ->and((float) $xpath->evaluate('count(//mri:associatedResource)'))->toBe(1.0)
        ->and($xpath->evaluate('string(//gex:EX_GeographicDescription//mcc:code/gco:CharacterString)'))
        ->toBe('Potsdam, Germany')
        ->and($xpath->evaluate('string(//gex:EX_BoundingPolygon//gml:Point/gml:pos)'))
        ->toBe('13.064 52.384')
        ->and($xpath->evaluate('string(//gex:EX_TemporalExtent//gml:beginPosition)'))
        ->toBe('2023-04-01')
        ->and($xpath->evaluate('string(//gex:EX_TemporalExtent//gml:endPosition)'))
        ->toBe('2023-04-30')
        ->and($xpath->evaluate('string(//gml:TimeInstant/gml:timePosition)'))->toBe('2024')
        ->and($xpath->evaluate(
            'string(//gml:TimePeriod[gml:beginPosition="2025-01-01"]/gml:endPosition/@indeterminatePosition)',
        ))->toBe('unknown')
        ->and($validation->isValid())->toBeTrue();
});

test('derives a bounding box from a point and from polygon vertices', function () {
    [$pointResource, $exporter] = iso19115Resource();
    GeoLocation::factory()->withPoint(13.25, 52.5)->create([
        'resource_id' => $pointResource->id,
        'place' => null,
    ]);

    [, $pointXpath] = parseIso19115($exporter->export($pointResource->fresh()));
    expect($pointXpath->evaluate('string(//gex:westBoundLongitude/gco:Decimal)'))->toBe('13.25')
        ->and($pointXpath->evaluate('string(//gex:eastBoundLongitude/gco:Decimal)'))->toBe('13.25')
        ->and($pointXpath->evaluate('string(//gex:southBoundLatitude/gco:Decimal)'))->toBe('52.5')
        ->and($pointXpath->evaluate('string(//gex:northBoundLatitude/gco:Decimal)'))->toBe('52.5');

    [$polygonResource] = iso19115Resource();
    GeoLocation::factory()->withPolygon([
        ['longitude' => -5.0, 'latitude' => 10.0],
        ['longitude' => 8.0, 'latitude' => -2.0],
        ['longitude' => 3.0, 'latitude' => 4.0],
    ])->create([
        'resource_id' => $polygonResource->id,
        'place' => null,
    ]);

    $polygonXml = $exporter->export($polygonResource->fresh());
    [, $polygonXpath] = parseIso19115($polygonXml);
    expect($polygonXpath->evaluate('string(//gex:westBoundLongitude/gco:Decimal)'))->toBe('-5')
        ->and($polygonXpath->evaluate('string(//gex:eastBoundLongitude/gco:Decimal)'))->toBe('8')
        ->and($polygonXpath->evaluate('string(//gex:southBoundLatitude/gco:Decimal)'))->toBe('-2')
        ->and($polygonXpath->evaluate('string(//gex:northBoundLatitude/gco:Decimal)'))->toBe('10')
        ->and($polygonXpath->evaluate('string(//gml:Polygon//gml:posList)'))
        ->toBe('-5 10 8 -2 3 4 -5 10')
        ->and(app(Iso19115XmlValidator::class)->validate($polygonXml)->isValid())->toBeTrue();
});

test('exports IGSN sample metadata without inventing absent fields', function () {
    $physicalObject = ResourceType::firstOrCreate(
        ['slug' => 'physical-object'],
        ['name' => 'Physical Object', 'is_active' => true],
    );
    [$parent] = iso19115Resource(['doi' => '10.5880/parent.igsn']);
    [$resource, $exporter] = iso19115Resource(['resource_type_id' => $physicalObject->id]);
    IgsnMetadata::create([
        'resource_id' => $resource->id,
        'parent_resource_id' => $parent->id,
        'sample_type' => 'Rock',
        'material' => 'Basalt',
        'sample_purpose' => 'Petrological analysis',
        'collection_method' => 'Drilling',
        'collection_date_precision' => 'day',
        'depth_min' => '12.50',
        'depth_scale' => 'm',
        'platform_type' => 'research vessel',
        'platform_description' => 'Ice-capable vessel',
        'current_archive' => 'GFZ Sample Archive',
        'current_archive_contact' => 'samples@example.org',
        'sample_access' => 'open',
        'operator' => 'GFZ',
        'coordinate_system' => 'WGS 84',
        'user_code' => 'GFZ-42',
        'upload_status' => IgsnMetadata::STATUS_PENDING,
    ]);
    IgsnClassification::create([
        'resource_id' => $resource->id,
        'value' => 'Igneous',
        'position' => 0,
    ]);

    $xml = $exporter->export($resource->fresh());
    [, $xpath] = parseIso19115($xml);

    expect($xpath->evaluate('string(//mdb:metadataScope//mcc:MD_ScopeCode/@codeListValue)'))->toBe('sample')
        ->and($xpath->evaluate('string(//mri:purpose/gco:CharacterString)'))->toBe('Petrological analysis')
        ->and($xpath->evaluate('string(//mri:supplementalInformation/gco:CharacterString)'))
        ->toContain('Sample type: Rock')
        ->toContain('Collection date precision: day')
        ->toContain('Current archive contact: samples@example.org')
        ->toContain('Sample access: open')
        ->toContain('Coordinate system: WGS 84')
        ->toContain('User code: GFZ-42')
        ->and($xpath->evaluate(
            'string(//mri:MD_Keywords[mri:thesaurusName//cit:title/gco:CharacterString="IGSN sample classification"]/mri:keyword/gco:CharacterString)',
        ))->toBe('Igneous')
        ->and($xpath->evaluate(
            'string(//mri:associatedResource//mcc:MD_Identifier/mcc:code/gco:CharacterString)',
        ))->toBe('10.5880/parent.igsn')
        ->and($xpath->evaluate('string(//mri:associatedResource//mri:DS_AssociationTypeCode/@codeListValue)'))
        ->toBe('largerWorkCitation')
        ->and($xml)->not->toContain('Depth maximum:')
        ->and(app(Iso19115XmlValidator::class)->validate($xml)->isValid())->toBeTrue();
});

test('uses every approved immutable resource type and scope mapping', function (string $slug, string $name, string $scope) {
    $type = ResourceType::firstOrCreate(
        ['slug' => $slug],
        ['name' => $name, 'is_active' => true],
    );
    [$resource, $exporter] = iso19115Resource(['resource_type_id' => $type->id]);

    [, $xpath] = parseIso19115($exporter->export($resource));

    expect($xpath->evaluate('string(//mdb:metadataScope//mcc:MD_ScopeCode/@codeListValue)'))->toBe($scope);
})->with([
    ['dataset', 'Dataset', 'dataset'],
    ['physical-object', 'Physical Object', 'sample'],
    ['collection', 'Collection', 'collection'],
    ['model', 'Model', 'model'],
    ['instrument', 'Instrument', 'collectionHardware'],
    ['service', 'Service', 'service'],
    ['software', 'Software', 'software'],
    ['computational-notebook', 'Computational Notebook', 'software'],
    ['workflow', 'Workflow', 'software'],
    ['interactive-resource', 'Interactive Resource', 'application'],
    ['image', 'Image', 'document'],
]);

test('maps a georeferenced image to coverage', function () {
    $image = ResourceType::firstOrCreate(
        ['slug' => 'image'],
        ['name' => 'Image', 'is_active' => true],
    );
    [$resource, $exporter] = iso19115Resource(['resource_type_id' => $image->id]);
    GeoLocation::factory()->withPoint(1, 2)->create(['resource_id' => $resource->id]);

    [, $xpath] = parseIso19115($exporter->export($resource->fresh()));

    expect($xpath->evaluate('string(//mdb:metadataScope//mcc:MD_ScopeCode/@codeListValue)'))->toBe('coverage');
});

test('does not treat an empty image geolocation as coverage', function () {
    $image = ResourceType::firstOrCreate(
        ['slug' => 'image'],
        ['name' => 'Image', 'is_active' => true],
    );
    [$resource, $exporter] = iso19115Resource(['resource_type_id' => $image->id]);
    GeoLocation::factory()->create([
        'resource_id' => $resource->id,
        'place' => null,
    ]);

    [, $xpath] = parseIso19115($exporter->export($resource->fresh()));

    expect($xpath->evaluate('string(//mdb:metadataScope//mcc:MD_ScopeCode/@codeListValue)'))->toBe('document');
});

test('rejects excluded types and honors the feature flag', function () {
    $project = ResourceType::firstOrCreate(
        ['slug' => 'project'],
        ['name' => 'Project', 'is_active' => true],
    );
    [$resource, $exporter] = iso19115Resource(['resource_type_id' => $project->id]);

    expect(fn () => $exporter->export($resource))->toThrow(
        RuntimeException::class,
        'resource type is not supported',
    );

    config(['iso19115.enabled' => false]);
    $dataset = Resource::factory()->create();
    expect(app(Iso19115ResourceProfileService::class)->supports($dataset))->toBeFalse();
});

test('published landing pages are linked through canonical internal metadata URLs including external templates', function () {
    [$resource, $exporter] = iso19115Resource(['doi' => '10.5880/test.iso.links']);
    $landingPage = LandingPage::factory()->published()->create([
        'resource_id' => $resource->id,
        'doi_prefix' => '10.5880/test.iso.links',
        'slug' => 'linked-resource',
    ]);
    $landingPage->files()->create([
        'url' => 'https://downloads.example.org/data%20package.nc',
        'position' => 0,
    ]);
    $landingPage->links()->create([
        'url' => 'https://example.org/project',
        'label' => 'Project website',
        'position' => 0,
    ]);
    $landingPage->links()->create([
        'url' => 'javascript:alert(1)',
        'label' => 'Unsafe link',
        'position' => 1,
    ]);

    $xml = $exporter->export($resource->fresh());
    [, $xpath] = parseIso19115($xml);

    expect($xpath->evaluate('string(/mdb:MD_Metadata/mdb:metadataIdentifier//mcc:code/gco:CharacterString)'))
        ->toBe('http://localhost/10.5880/test.iso.links/linked-resource/metadata/iso-19115-3.xml')
        ->and($xpath->evaluate('string(/mdb:MD_Metadata/mdb:metadataLinkage//cit:linkage/gco:CharacterString)'))
        ->toBe('http://localhost/10.5880/test.iso.links/linked-resource/metadata/iso-19115-3.xml')
        ->and($xpath->evaluate('string(//mri:citation//cit:onlineResource//cit:linkage/gco:CharacterString)'))
        ->toBe('http://localhost/10.5880/test.iso.links/linked-resource')
        ->and((float) $xpath->evaluate('count(/mdb:MD_Metadata/mdb:distributionInfo//mrd:onLine)'))
        ->toBe(3.0)
        ->and($xpath->evaluate(
            'string(/mdb:MD_Metadata/mdb:distributionInfo//cit:CI_OnlineResource[cit:function/cit:CI_OnLineFunctionCode/@codeListValue="download"]/cit:name/gco:CharacterString)',
        ))->toBe('data package.nc')
        ->and($xpath->evaluate(
            'string(/mdb:MD_Metadata/mdb:distributionInfo//cit:CI_OnlineResource[cit:name/gco:CharacterString="Project website"]/cit:linkage/gco:CharacterString)',
        ))->toBe('https://example.org/project')
        ->and($xml)->not->toContain('javascript:alert')
        ->and(app(Iso19115XmlValidator::class)->validate($xml)->isValid())->toBeTrue();
});

test('distribution links follow landing-page publication and download-availability policy', function () {
    [$unavailableResource, $exporter] = iso19115Resource(['doi' => '10.5880/test.iso.unavailable']);
    $unavailableLandingPage = LandingPage::factory()->published()->create([
        'resource_id' => $unavailableResource->id,
        'doi_prefix' => '10.5880/test.iso.unavailable',
        'slug' => 'unavailable-downloads',
        'downloads_unavailable' => true,
    ]);
    $unavailableLandingPage->files()->create([
        'url' => 'https://downloads.example.org/hidden.nc',
        'position' => 0,
    ]);

    $unavailableXml = $exporter->export($unavailableResource->fresh());
    [, $unavailableXpath] = parseIso19115($unavailableXml);

    expect((float) $unavailableXpath->evaluate('count(/mdb:MD_Metadata/mdb:distributionInfo//mrd:onLine)'))
        ->toBe(1.0)
        ->and($unavailableXml)->not->toContain('hidden.nc');

    [$draftResource] = iso19115Resource(['doi' => '10.5880/test.iso.draft']);
    $draftLandingPage = LandingPage::factory()->create([
        'resource_id' => $draftResource->id,
        'doi_prefix' => '10.5880/test.iso.draft',
        'slug' => 'draft-downloads',
        'is_published' => false,
    ]);
    $draftLandingPage->files()->create([
        'url' => 'https://downloads.example.org/draft.nc',
        'position' => 0,
    ]);

    $draftXml = $exporter->export($draftResource->fresh());
    [, $draftXpath] = parseIso19115($draftXml);

    expect((float) $draftXpath->evaluate('count(/mdb:MD_Metadata/mdb:distributionInfo)'))->toBe(0.0)
        ->and($draftXml)->not->toContain('draft.nc');
});

test('uses nilReason for an absent abstract and validator reports a warning', function () {
    $resource = Resource::factory()->create();
    Title::factory()->create(['resource_id' => $resource->id]);

    $xml = app(Iso19115XmlExporter::class)->export($resource);
    $result = app(Iso19115XmlValidator::class)->validate($xml);

    expect($xml)->toContain('gco:nilReason="missing"')
        ->and($result->isValid())->toBeTrue()
        ->and($result->warnings)->toContain('The resource has no abstract value; ISO nilReason="missing" was emitted.')
        ->and(collect($result->warnings)->contains(
            fn (string $warning): bool => str_contains($warning, '[rule.mri.datasetextent]'),
        ))->toBeTrue();
});

test('validator rejects malformed, unsafe and structurally invalid documents', function () {
    $validator = app(Iso19115XmlValidator::class);

    expect($validator->validate('<mdb:MD_Metadata')->isValid())->toBeFalse()
        ->and($validator->validate('<!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><foo>&xxe;</foo>')->errors)
        ->toContain('DOCTYPE and ENTITY declarations are not permitted.')
        ->and($validator->validate('<foo/>')->errors)
        ->toContain('The root element must be mdb:MD_Metadata in the ISO 19115-1 mdb/1.3 namespace.');
});

test('validator rejects child elements outside the official XSD sequence', function () {
    [$resource, $exporter] = iso19115Resource();
    $person = Person::factory()->create();
    ResourceCreator::create([
        'resource_id' => $resource->id,
        'creatorable_type' => Person::class,
        'creatorable_id' => $person->id,
        'position' => 1,
        'is_contact' => true,
        'email' => 'schema-order@example.org',
    ]);

    [$document, $xpath] = parseIso19115($exporter->export($resource->fresh()));
    $status = $xpath->query('//mri:MD_DataIdentification/mri:status')?->item(0);
    $pointOfContact = $xpath->query('//mri:MD_DataIdentification/mri:pointOfContact')?->item(0);
    $identification = $status?->parentNode;

    expect($status)->toBeInstanceOf(DOMElement::class)
        ->and($pointOfContact)->toBeInstanceOf(DOMElement::class)
        ->and($identification)->toBeInstanceOf(DOMElement::class);

    $identification->insertBefore($pointOfContact, $status);
    $invalidXml = $document->saveXML();
    expect($invalidXml)->toBeString();

    $result = app(Iso19115XmlValidator::class)->validate($invalidXml);

    expect($result->isValid())->toBeFalse()
        ->and($result->errors)
        ->toContain('mri:MD_DataIdentification child elements do not follow the official XSD sequence.');
});

test('validator detects invalid geographic ranges and missing non-dataset scope names', function () {
    [$resource, $exporter] = iso19115Resource();
    $xml = $exporter->export($resource);
    $xml = str_replace(
        '<mcc:MD_ScopeCode',
        '<mcc:MD_ScopeCode',
        str_replace('codeListValue="dataset">dataset', 'codeListValue="sample">sample', $xml),
    );
    $xml = str_replace(
        '</mri:MD_DataIdentification>',
        '<mri:extent><gex:EX_Extent><gex:geographicElement><gex:EX_GeographicBoundingBox>'
        .'<gex:westBoundLongitude><gco:Decimal>200</gco:Decimal></gex:westBoundLongitude>'
        .'<gex:eastBoundLongitude><gco:Decimal>-200</gco:Decimal></gex:eastBoundLongitude>'
        .'<gex:southBoundLatitude><gco:Decimal>-95</gco:Decimal></gex:southBoundLatitude>'
        .'<gex:northBoundLatitude><gco:Decimal>95</gco:Decimal></gex:northBoundLatitude>'
        .'</gex:EX_GeographicBoundingBox></gex:geographicElement></gex:EX_Extent></mri:extent>'
        .'</mri:MD_DataIdentification>',
        $xml,
    );

    $result = app(Iso19115XmlValidator::class)->validate($xml);

    expect($result->isValid())->toBeFalse()
        ->and($result->errors)->toContain('A non-dataset metadata scope requires a name.')
        ->and($result->errors)->toContain('Geographic bounding-box coordinates are outside valid longitude/latitude ranges.')
        ->and($result->errors)->toContain('Geographic bounding-box minimum coordinates must not exceed maximum coordinates.');
});

test('validator uses pinned local XSD assets and reports schema diagnostics', function () {
    $catalogPath = config('iso19115.validation.catalog');
    $configuredCatalogs = getenv('XML_CATALOG_FILES');
    expect($catalogPath)->toBeString()
        ->and($configuredCatalogs)->toBeString()
        ->and($configuredCatalogs)->toContain($catalogPath);

    [$resource, $exporter] = iso19115Resource();
    $xml = $exporter->export($resource);
    $invalid = str_replace(
        '</mdb:MD_Metadata>',
        '<mdb:notAnIsoElement/></mdb:MD_Metadata>',
        $xml,
    );

    $result = app(Iso19115XmlValidator::class)->validate($invalid);

    expect($result->isValid())->toBeFalse()
        ->and(collect($result->errors)->contains(
            fn (string $error): bool => str_starts_with($error, 'XSD validation error at line'),
        ))->toBeTrue();

    config(['iso19115.validation.schema' => storage_path('missing-iso-profile.xsd')]);
    expect(app(Iso19115XmlValidator::class)->validate($xml)->errors)
        ->toContain('The pinned ISO 19115-3 aggregation schema is missing.');
});

test('exports end-only coverage as an ISO temporal extent with an unknown begin', function () {
    [$resource, $exporter] = iso19115Resource();
    GeoLocation::create([
        'resource_id' => $resource->id,
        'end_date' => '2026-08-27',
        'end_time' => '17:37',
        'timezone' => 'UTC',
    ]);

    [$document, $xpath] = parseIso19115($exporter->export($resource->fresh()));
    $begin = $xpath->query('//gml:TimePeriod[starts-with(@gml:id, "coverage-temporal-")]/gml:beginPosition')->item(0);
    $end = $xpath->query('//gml:TimePeriod[starts-with(@gml:id, "coverage-temporal-")]/gml:endPosition')->item(0);

    expect($document)->toBeInstanceOf(DOMDocument::class)
        ->and($begin)->toBeInstanceOf(DOMElement::class)
        ->and($begin->getAttribute('indeterminatePosition'))->toBe('unknown')
        ->and($end)->toBeInstanceOf(DOMElement::class)
        ->and($end->textContent)->toBe('2026-08-27T17:37Z');
});

test('pinned validation manifest is complete and every asset hash matches', function () {
    $manifestPath = config('iso19115.validation.manifest');
    expect($manifestPath)->toBeString()->and(is_file($manifestPath))->toBeTrue();

    $packageRoot = dirname($manifestPath);
    $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['isoTc211Commit'] ?? null)
        ->toBe('973b2d578265657246404a1889c544c8d8374c9b')
        ->and($manifest['selection'] ?? null)
        ->toBe('Transitive xs:import/xs:include/xs:redefine closure rooted at ernie-profile.xsd, plus validation metadata')
        ->and($manifest['files'] ?? null)->toBeArray()
        ->and(count($manifest['files']))->toBeGreaterThan(100);

    $manifestPaths = [];
    foreach ($manifest['files'] as $entry) {
        expect($entry)->toBeArray()
            ->and($entry['path'] ?? null)->toBeString()
            ->and($entry['source'] ?? null)->toBeString()
            ->and($entry['sha256'] ?? null)->toMatch('/^[a-f0-9]{64}$/')
            ->and($entry['bytes'] ?? null)->toBeInt();

        $relativePath = str_replace('/', DIRECTORY_SEPARATOR, $entry['path']);
        expect($relativePath)->not->toContain('..');
        $assetPath = $packageRoot.DIRECTORY_SEPARATOR.$relativePath;
        expect(is_file($assetPath))->toBeTrue("Pinned validation asset is missing: {$entry['path']}")
            ->and(hash_file('sha256', $assetPath))->toBe(
                $entry['sha256'],
                "Pinned validation asset hash mismatch: {$entry['path']}",
            )
            ->and(filesize($assetPath))->toBe(
                $entry['bytes'],
                "Pinned validation asset size mismatch: {$entry['path']}",
            )
            ->and(str_contains((string) file_get_contents($assetPath), "\r"))->toBeFalse(
                "Pinned validation asset contains a carriage return: {$entry['path']}",
            );
        $manifestPaths[] = str_replace('\\', '/', $entry['path']);
    }

    $inventory = collect(
        new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($packageRoot, FilesystemIterator::SKIP_DOTS),
        ),
    )
        ->filter(fn (SplFileInfo $file): bool => $file->isFile() && $file->getFilename() !== 'manifest.json')
        ->map(fn (SplFileInfo $file): string => str_replace(
            '\\',
            '/',
            substr($file->getPathname(), strlen($packageRoot) + 1),
        ))
        ->sort()
        ->values()
        ->all();

    sort($manifestPaths);
    expect($manifestPaths)->toBe($inventory);

    $schemaInventory = array_values(array_filter(
        $inventory,
        fn (string $path): bool => str_ends_with($path, '.xsd'),
    ));
    $nonSchemaInventory = array_values(array_diff($inventory, $schemaInventory));

    expect(iso19115ReachableSchemaPaths($packageRoot))->toBe($schemaInventory)
        ->and($nonSchemaInventory)->toBe([
            'catalog.xml',
            'ernie-profile-schematron.xsl',
        ]);
});
