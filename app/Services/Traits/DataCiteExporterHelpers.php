<?php

namespace App\Services\Traits;

use App\Models\Affiliation;
use App\Models\FundingReference;
use App\Models\GeoLocation;
use App\Models\Institution;
use App\Models\Person;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\ResourceDate;

/**
 * Shared helper methods for DataCite XML and JSON exporters.
 *
 * This trait consolidates ~350 lines of duplicated code between
 * DataCiteXmlExporter and DataCiteJsonExporter.
 */
trait DataCiteExporterHelpers
{
    /**
     * Required relationships to eager load for DataCite export.
     *
     * @return array<int, string>
     */
    protected function getRequiredRelations(): array
    {
        return [
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
            'geoLocations',
            'rights',
            'relatedIdentifiers.identifierType',
            'relatedIdentifiers.relationType',
            'fundingReferences.funderIdentifierType',
            'sizes',
            'formats',
        ];
    }

    /**
     * Format a person's name for DataCite export.
     *
     * Format: "FamilyName, GivenName" (DataCite preferred format)
     */
    protected function formatPersonName(Person $person): string
    {
        if ($person->family_name && $person->given_name) {
            return "{$person->family_name}, {$person->given_name}";
        }

        if ($person->family_name) {
            return $person->family_name;
        }

        if ($person->given_name) {
            return $person->given_name;
        }

        return 'Unknown';
    }

    /**
     * Format an institution's name for DataCite export.
     */
    protected function formatInstitutionName(Institution $institution): string
    {
        return $institution->name ?? 'Unknown Institution';
    }

    /**
     * Build name identifier data for a person (typically ORCID).
     *
     * @return array{nameIdentifier: string, nameIdentifierScheme: string, schemeUri: string}|null
     */
    protected function buildPersonNameIdentifier(Person $person): ?array
    {
        if (! $person->name_identifier) {
            return null;
        }

        $scheme = $person->name_identifier_scheme ?? 'ORCID';

        return [
            'nameIdentifier' => $person->name_identifier,
            'nameIdentifierScheme' => $scheme,
            'schemeUri' => $this->getSchemeUri($scheme),
        ];
    }

    /**
     * Build name identifier data for an institution (typically ROR).
     *
     * @return array{nameIdentifier: string, nameIdentifierScheme: string, schemeUri: string}|null
     */
    protected function buildInstitutionNameIdentifier(Institution $institution): ?array
    {
        if (! $institution->name_identifier) {
            return null;
        }

        $scheme = $institution->name_identifier_scheme ?? 'ROR';

        return [
            'nameIdentifier' => $institution->name_identifier,
            'nameIdentifierScheme' => $scheme,
            'schemeUri' => $this->getSchemeUri($scheme),
        ];
    }

    /**
     * Get the scheme URI for a given identifier scheme.
     */
    protected function getSchemeUri(string $scheme): string
    {
        return match (strtoupper($scheme)) {
            'ORCID' => 'https://orcid.org',
            'ROR' => 'https://ror.org',
            'ISNI' => 'https://isni.org',
            'GRID' => 'https://www.grid.ac',
            default => '',
        };
    }

    /**
     * Transform an affiliation to DataCite format.
     *
     * @return array<string, string|null>
     */
    protected function transformAffiliation(Affiliation $affiliation): array
    {
        $data = [
            'name' => $affiliation->name,
        ];

        if ($affiliation->identifier) {
            $data['affiliationIdentifier'] = $affiliation->identifier;
            $data['affiliationIdentifierScheme'] = $affiliation->identifier_scheme ?? 'ROR';

            if ($affiliation->scheme_uri) {
                $data['schemeURI'] = $affiliation->scheme_uri;
            }
        }

        return $data;
    }

    /**
     * Transform all affiliations for a creator or contributor.
     *
     * @return array<int, array<string, string|null>>
     */
    protected function transformAffiliations(ResourceCreator|ResourceContributor $author): array
    {
        $affiliations = [];

        foreach ($author->affiliations as $affiliation) {
            $affiliations[] = $this->transformAffiliation($affiliation);
        }

        return $affiliations;
    }

    /**
     * Format a date value according to DataCite schema.
     *
     * - Single date: use date_value or start_date
     * - Closed range: start_date/end_date
     * - Open-ended range: exported as single date (DataCite doesn't support trailing slash)
     */
    protected function formatDateValue(ResourceDate $date): ?string
    {
        if ($date->isRange()) {
            // Closed range with both dates
            return $date->start_date.'/'.$date->end_date;
        }

        if ($date->isOpenEndedRange()) {
            // Open-ended range - exported as single date
            return $date->start_date;
        }

        // Single date
        $value = $date->date_value ?? $date->start_date;

        return $value !== null && $value !== '' ? $value : null;
    }

    /**
     * Transform a GeoLocation point to DataCite format.
     *
     * @return array{pointLongitude: float|string, pointLatitude: float|string}|null
     */
    protected function transformGeoLocationPoint(GeoLocation $geoLocation): ?array
    {
        if ($geoLocation->point_longitude === null || $geoLocation->point_latitude === null) {
            return null;
        }

        return [
            'pointLongitude' => $geoLocation->point_longitude,
            'pointLatitude' => $geoLocation->point_latitude,
        ];
    }

    /**
     * Transform a GeoLocation box to DataCite format.
     *
     * @return array{westBoundLongitude: float|string, eastBoundLongitude: float|string, southBoundLatitude: float|string, northBoundLatitude: float|string}|null
     */
    protected function transformGeoLocationBox(GeoLocation $geoLocation): ?array
    {
        if ($geoLocation->west_bound_longitude === null ||
            $geoLocation->east_bound_longitude === null ||
            $geoLocation->south_bound_latitude === null ||
            $geoLocation->north_bound_latitude === null) {
            return null;
        }

        return [
            'westBoundLongitude' => $geoLocation->west_bound_longitude,
            'eastBoundLongitude' => $geoLocation->east_bound_longitude,
            'southBoundLatitude' => $geoLocation->south_bound_latitude,
            'northBoundLatitude' => $geoLocation->north_bound_latitude,
        ];
    }

    /**
     * Transform a GeoLocation polygon to DataCite format.
     *
     * Uses the polygon_points JSON column from geo_locations table,
     * which stores an array of {longitude, latitude} points.
     *
     * @return array{polygonPoints: array<int, array{pointLongitude: float|string, pointLatitude: float|string}>, inPolygonPoint?: array{pointLongitude: float|string, pointLatitude: float|string}}|null
     */
    protected function transformGeoLocationPolygon(GeoLocation $geoLocation): ?array
    {
        $points = $geoLocation->polygon_points;

        if ($points === null || count($points) < 3) {
            return null;
        }

        $polygonPoints = array_map(fn (array $point) => [
            'pointLongitude' => $point['longitude'],
            'pointLatitude' => $point['latitude'],
        ], $points);

        $result = ['polygonPoints' => $polygonPoints];

        // Check for in-polygon-point from geo_locations columns
        if ($geoLocation->in_polygon_point_longitude !== null && $geoLocation->in_polygon_point_latitude !== null) {
            $result['inPolygonPoint'] = [
                'pointLongitude' => $geoLocation->in_polygon_point_longitude,
                'pointLatitude' => $geoLocation->in_polygon_point_latitude,
            ];
        }

        return $result;
    }

    /**
     * Transform a funding reference to DataCite format.
     *
     * @return array<string, mixed>
     */
    protected function transformFundingReference(FundingReference $funding): array
    {
        $data = [
            'funderName' => $funding->funder_name,
        ];

        // Add funder identifier if available
        if ($funding->funder_identifier) {
            $data['funderIdentifier'] = $funding->funder_identifier;
            $data['funderIdentifierType'] = $funding->funderIdentifierType->name ?? 'Other';

            if ($funding->scheme_uri) {
                $data['schemeUri'] = $funding->scheme_uri;
            }
        }

        // Add award information
        if ($funding->award_number) {
            $data['awardNumber'] = $funding->award_number;
        }

        if ($funding->award_uri) {
            $data['awardUri'] = $funding->award_uri;
        }

        if ($funding->award_title) {
            $data['awardTitle'] = $funding->award_title;
        }

        return $data;
    }

    /**
     * Build creator data structure from a Person for DataCite format.
     *
     * @return array<string, mixed>
     */
    protected function buildPersonCreatorData(ResourceCreator $creator, Person $person): array
    {
        $data = [
            'name' => $this->formatPersonName($person),
            'nameType' => 'Personal',
        ];

        // Add given/family name separately (DataCite recommendation)
        if ($person->given_name) {
            $data['givenName'] = $person->given_name;
        }
        if ($person->family_name) {
            $data['familyName'] = $person->family_name;
        }

        // Add name identifier (ORCID)
        if ($nameIdentifier = $this->buildPersonNameIdentifier($person)) {
            $data['nameIdentifiers'] = [$nameIdentifier];
        }

        // Add affiliations
        $affiliations = $this->transformAffiliations($creator);
        if (! empty($affiliations)) {
            $data['affiliation'] = $affiliations;
        }

        return $data;
    }

    /**
     * Build creator data structure from an Institution for DataCite format.
     *
     * @return array<string, mixed>
     */
    protected function buildInstitutionCreatorData(ResourceCreator $creator, Institution $institution): array
    {
        $data = [
            'name' => $this->formatInstitutionName($institution),
            'nameType' => 'Organizational',
        ];

        // Add name identifier (ROR)
        if ($nameIdentifier = $this->buildInstitutionNameIdentifier($institution)) {
            $data['nameIdentifiers'] = [$nameIdentifier];
        }

        // Add affiliations
        $affiliations = $this->transformAffiliations($creator);
        if (! empty($affiliations)) {
            $data['affiliation'] = $affiliations;
        }

        return $data;
    }

    /**
     * Build contributor data structure from a Person for DataCite format.
     *
     * @return array<string, mixed>
     */
    protected function buildPersonContributorData(ResourceContributor $contributor, Person $person): array
    {
        $data = $this->buildPersonCreatorData(
            // Create a temporary creator-like object for reuse
            new ResourceCreator([
                'creatorable_id' => $person->id,
                'creatorable_type' => Person::class,
            ]),
            $person
        );

        // Add contributor type
        $data['contributorType'] = $contributor->contributorType->name;

        // Re-add affiliations from the actual contributor
        $affiliations = $this->transformAffiliations($contributor);
        if (! empty($affiliations)) {
            $data['affiliation'] = $affiliations;
        } else {
            unset($data['affiliation']);
        }

        return $data;
    }

    /**
     * Build contributor data structure from an Institution for DataCite format.
     *
     * @return array<string, mixed>
     */
    protected function buildInstitutionContributorData(ResourceContributor $contributor, Institution $institution): array
    {
        $data = $this->buildInstitutionCreatorData(
            new ResourceCreator([
                'creatorable_id' => $institution->id,
                'creatorable_type' => Institution::class,
            ]),
            $institution
        );

        // Add contributor type
        $data['contributorType'] = $contributor->contributorType->name;

        // Re-add affiliations from the actual contributor
        $affiliations = $this->transformAffiliations($contributor);
        if (! empty($affiliations)) {
            $data['affiliation'] = $affiliations;
        } else {
            unset($data['affiliation']);
        }

        return $data;
    }
}
