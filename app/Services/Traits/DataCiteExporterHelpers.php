<?php

declare(strict_types=1);

namespace App\Services\Traits;

use App\Models\Affiliation;
use App\Models\FundingReference;
use App\Models\GeoLocation;
use App\Models\Institution;
use App\Models\Person;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\ResourceDate;
use App\Services\DataCite\Mapping\DataCiteFundingReferenceMappingService;
use App\Services\DataCite\Mapping\DataCitePartyMappingService;

/**
 * Shared helper methods for DataCite XML and JSON exporters.
 *
 * This trait consolidates ~350 lines of duplicated code between
 * DataCiteXmlExporter and DataCiteJsonExporter.
 */
trait DataCiteExporterHelpers
{
    protected function dataCitePartyMapper(): DataCitePartyMappingService
    {
        return app(DataCitePartyMappingService::class);
    }

    protected function dataCiteFundingReferenceMapper(): DataCiteFundingReferenceMappingService
    {
        return app(DataCiteFundingReferenceMappingService::class);
    }

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
            'contributors.contributorTypes',
            'contributors.affiliations',
            'descriptions.descriptionType',
            'dates.dateType',
            'subjects',
            'geoLocations',
            'rights',
            'relatedIdentifiers.identifierType',
            'relatedIdentifiers.relationType',
            'fundingReferences.funderIdentifierType',
            'alternateIdentifiers',
            'sizes',
            'formats',
            'igsnMetadata', // For IGSN-specific creator/contributor handling
            'instruments', // For PID4INST instrument PIDs (IsCollectedBy)
        ];
    }

    /**
     * Format a person's name for DataCite export.
     *
     * Format: "FamilyName, GivenName" (DataCite preferred format)
     */
    protected function formatPersonName(Person $person): string
    {
        return $this->dataCitePartyMapper()->formatPersonName($person);
    }

    /**
     * Format an institution's name for DataCite export.
     */
    protected function formatInstitutionName(Institution $institution): string
    {
        return $this->dataCitePartyMapper()->formatInstitutionName($institution);
    }

    /**
     * Build name identifier data for a person (typically ORCID).
     *
     * @return array{nameIdentifier: string, nameIdentifierScheme: string, schemeUri: string}|null
     */
    protected function buildPersonNameIdentifier(Person $person): ?array
    {
        return $this->dataCitePartyMapper()->buildPersonNameIdentifier($person);
    }

    /**
     * Build name identifier data for an institution (typically ROR).
     *
     * @return array{nameIdentifier: string, nameIdentifierScheme: string, schemeUri: string}|null
     */
    protected function buildInstitutionNameIdentifier(Institution $institution): ?array
    {
        return $this->dataCitePartyMapper()->buildInstitutionNameIdentifier($institution);
    }

    /**
     * Get the scheme URI for a given identifier scheme.
     */
    protected function getSchemeUri(string $scheme): string
    {
        return $this->dataCitePartyMapper()->getSchemeUri($scheme);
    }

    /**
     * Transform an affiliation to DataCite format.
     *
     * Includes defense-in-depth:
     * - Always emits schemeURI for known identifier schemes, even if not persisted.
     * - Detects legacy records where a ROR URL was stored in the name field and
     *   attempts to resolve the correct organization name from the ROR data dump.
     *
     * @return array<string, string|null>
     */
    protected function transformAffiliation(Affiliation $affiliation): array
    {
        return $this->dataCitePartyMapper()->transformAffiliation($affiliation);
    }

    /**
     * Transform all affiliations for a creator or contributor.
     *
     * @return array<int, array<string, string|null>>
     */
    protected function transformAffiliations(ResourceCreator|ResourceContributor $author): array
    {
        return $this->dataCitePartyMapper()->transformAffiliations($author);
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
     * Note: Coordinates must be floats for DataCite JSON schema.
     * Laravel's decimal cast returns strings, so we explicitly cast to float.
     *
     * @return array{pointLongitude: float, pointLatitude: float}|null
     */
    protected function transformGeoLocationPoint(GeoLocation $geoLocation): ?array
    {
        if ($geoLocation->point_longitude === null || $geoLocation->point_latitude === null) {
            return null;
        }

        return [
            'pointLongitude' => (float) $geoLocation->point_longitude,
            'pointLatitude' => (float) $geoLocation->point_latitude,
        ];
    }

    /**
     * Transform a GeoLocation box to DataCite format.
     *
     * Note: Coordinates must be floats for DataCite JSON schema.
     * Laravel's decimal cast returns strings, so we explicitly cast to float.
     *
     * @return array{westBoundLongitude: float, eastBoundLongitude: float, southBoundLatitude: float, northBoundLatitude: float}|null
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
            'westBoundLongitude' => (float) $geoLocation->west_bound_longitude,
            'eastBoundLongitude' => (float) $geoLocation->east_bound_longitude,
            'southBoundLatitude' => (float) $geoLocation->south_bound_latitude,
            'northBoundLatitude' => (float) $geoLocation->north_bound_latitude,
        ];
    }

    /**
     * Transform a GeoLocation polygon to DataCite format.
     *
     * Uses the polygon_points JSON column from geo_locations table,
     * which stores an array of {longitude, latitude} points.
     *
     * Note: Coordinates must be floats for DataCite JSON schema.
     *
     * @return array{polygonPoints: array<int, array{pointLongitude: float, pointLatitude: float}>, inPolygonPoint?: array{pointLongitude: float, pointLatitude: float}}|null
     */
    protected function transformGeoLocationPolygon(GeoLocation $geoLocation): ?array
    {
        $points = $geoLocation->polygon_points;

        if ($points === null || count($points) < 3) {
            return null;
        }

        $polygonPoints = array_map(fn (array $point) => [
            'pointLongitude' => (float) $point['longitude'],
            'pointLatitude' => (float) $point['latitude'],
        ], $points);

        $result = ['polygonPoints' => $polygonPoints];

        // Check for in-polygon-point from geo_locations columns
        if ($geoLocation->in_polygon_point_longitude !== null && $geoLocation->in_polygon_point_latitude !== null) {
            $result['inPolygonPoint'] = [
                'pointLongitude' => (float) $geoLocation->in_polygon_point_longitude,
                'pointLatitude' => (float) $geoLocation->in_polygon_point_latitude,
            ];
        }

        return $result;
    }

    /**
     * Convert line points to a thin polygon for DataCite export.
     *
     * Since DataCite has no native line element, lines are exported as thin polygons.
     * A minimal latitude offset (~0.00000001°) is applied to the return path to create
     * a valid closed polygon with near-zero area.
     *
     * Example: Line A→B→C becomes polygon A→B→C→C'→B'→A where primed points
     * have a small latitude offset.
     *
     * @param  array<int, array{longitude: float, latitude: float}>  $linePoints
     * @return array<int, array{longitude: float, latitude: float}>
     */
    protected function convertLineToPolygonPoints(array $linePoints): array
    {
        if (count($linePoints) < 2) {
            return $linePoints;
        }

        $offset = 0.00000001;
        $result = $linePoints;

        // Add return path in reverse order with a minimal latitude offset
        $reversed = array_reverse($linePoints);

        // Skip the first reversed point (= last forward point) to avoid duplication
        for ($i = 1, $count = count($reversed); $i < $count; $i++) {
            $lat = $reversed[$i]['latitude'];

            // Choose offset direction to stay within valid [-90, 90] range
            $adjustedLat = ($lat + $offset > 90.0)
                ? $lat - $offset
                : $lat + $offset;

            $result[] = [
                'longitude' => $reversed[$i]['longitude'],
                'latitude' => $adjustedLat,
            ];
        }

        // Close the polygon: last point = first point
        $result[] = $linePoints[0];

        return $result;
    }

    /**
     * Transform a GeoLocation line to DataCite polygon format.
     *
     * Converts line points to a thin polygon and delegates to polygon format.
     *
     * @return array{polygonPoints: array<int, array{pointLongitude: float, pointLatitude: float}>}|null
     */
    protected function transformGeoLocationLine(GeoLocation $geoLocation): ?array
    {
        $points = $geoLocation->polygon_points;

        if ($points === null || count($points) < 2) {
            return null;
        }

        $polygonPoints = $this->convertLineToPolygonPoints($points);

        return [
            'polygonPoints' => array_map(fn (array $point) => [
                'pointLongitude' => (float) $point['longitude'],
                'pointLatitude' => (float) $point['latitude'],
            ], $polygonPoints),
        ];
    }

    /**
     * Transform a funding reference to DataCite format.
     *
     * @return array<string, mixed>
     */
    protected function transformFundingReference(FundingReference $funding): array
    {
        return $this->dataCiteFundingReferenceMapper()->toArray($funding);
    }

    /**
     * Build creator data structure from a Person for DataCite format.
     *
     * Accepts both ResourceCreator and ResourceContributor to support
     * exporting contributors as creators for IGSN resources.
     *
     * @return array<string, mixed>
     */
    protected function buildPersonCreatorData(ResourceCreator|ResourceContributor $author, Person $person): array
    {
        return $this->dataCitePartyMapper()->buildPersonCreatorData($author, $person);
    }

    /**
     * Build creator data structure from an Institution for DataCite format.
     *
     * Accepts both ResourceCreator and ResourceContributor to support
     * exporting contributors as creators for IGSN resources.
     *
     * @return array<string, mixed>
     */
    protected function buildInstitutionCreatorData(ResourceCreator|ResourceContributor $author, Institution $institution): array
    {
        return $this->dataCitePartyMapper()->buildInstitutionCreatorData($author, $institution);
    }

    /**
     * Build contributor data structure from a Person for DataCite format.
     *
     * @return array<string, mixed>
     */
    protected function buildPersonContributorData(ResourceContributor $contributor, Person $person, ?string $contributorType = null): array
    {
        return $this->dataCitePartyMapper()->buildPersonContributorData($contributor, $person, $contributorType);
    }

    /**
     * Build contributor data structure from an Institution for DataCite format.
     *
     * @return array<string, mixed>
     */
    protected function buildInstitutionContributorData(ResourceContributor $contributor, Institution $institution, ?string $contributorType = null): array
    {
        return $this->dataCitePartyMapper()->buildInstitutionContributorData($contributor, $institution, $contributorType);
    }
}
