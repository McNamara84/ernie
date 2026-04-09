<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ContributorType;
use App\Models\DateType;
use App\Models\GeoLocation;
use App\Models\IgsnClassification;
use App\Models\IgsnGeologicalAge;
use App\Models\IgsnGeologicalUnit;
use App\Models\IgsnMetadata;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceDate;
use Illuminate\Support\Facades\Log;

/**
 * Parses DIF XML from Solr/DB and maps it to IgsnMetadata + related models.
 *
 * The DIF XML format (from the legacy IGSN infrastructure) contains
 * IGSN-specific metadata under <supplementalMetadata><record><sample>.
 *
 * @see docs/implementation-plans/import-igsns-from-datacite.md Section 3.5
 */
class IgsnDifXmlParser
{
    /**
     * Parse DIF XML and enrich an existing Resource + IgsnMetadata.
     *
     * @param  string  $difXml  Raw DIF XML string (not base64-encoded)
     * @param  Resource  $resource  The Resource to enrich
     * @param  IgsnMetadata  $igsnMetadata  The IgsnMetadata to populate
     * @return bool True if enrichment was successful
     */
    public function enrichFromDifXml(string $difXml, Resource $resource, IgsnMetadata $igsnMetadata): bool
    {
        try {
            $xml = @simplexml_load_string($difXml);
            if ($xml === false) {
                Log::warning('Failed to parse DIF XML', ['resource_id' => $resource->id]);

                return false;
            }

            // Navigate to <sample> element — try multiple known paths
            $sample = $this->findSampleElement($xml);
            if ($sample === null) {
                Log::debug('No <sample> element found in DIF XML', ['resource_id' => $resource->id]);

                return false;
            }

            // Map scalar fields to IgsnMetadata
            $this->mapScalarFields($sample, $igsnMetadata);

            // Map related models (geo, dates, contributors, classifications)
            $this->mapGeoLocation($sample, $resource);
            $this->mapCollectionDates($sample, $resource);
            $this->mapCollector($sample, $resource);
            $this->mapClassifications($sample, $resource);
            $this->mapGeologicalAges($sample, $resource);
            $this->mapGeologicalUnits($sample, $resource);

            $igsnMetadata->save();

            return true;
        } catch (\Throwable $e) {
            Log::warning('DIF XML enrichment failed', [
                'resource_id' => $resource->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Find the <sample> element in the DIF XML, trying multiple path patterns.
     */
    private function findSampleElement(\SimpleXMLElement $xml): ?\SimpleXMLElement
    {
        // Path 1: <supplementalMetadata><record><sample xmlns="...">
        // Register all namespaces and search
        $namespaces = $xml->getNamespaces(true);

        foreach ($namespaces as $prefix => $uri) {
            if (str_contains($uri, 'igsn') || str_contains($uri, 'pmd.gfz')) {
                $xml->registerXPathNamespace('igsn', $uri);
                $results = $xml->xpath('//igsn:sample');
                if (is_array($results) && count($results) > 0) {
                    return $results[0];
                }
            }
        }

        // Path 2: Direct <sample> without namespace
        $results = $xml->xpath('//sample');
        if (is_array($results) && count($results) > 0) {
            return $results[0];
        }

        // Path 3: nested under supplementalMetadata/record
        if (isset($xml->supplementalMetadata->record->sample)) {
            return $xml->supplementalMetadata->record->sample;
        }

        // Path 4: Children with default namespace
        foreach ($xml->children() as $child) {
            if ($child->getName() === 'supplementalMetadata') {
                foreach ($child->children() as $record) {
                    foreach ($record->children() as $possibleSample) {
                        if ($possibleSample->getName() === 'sample') {
                            return $possibleSample;
                        }
                    }
                    // Also check with namespace
                    foreach ($namespaces as $uri) {
                        foreach ($record->children($uri) as $possibleSample) {
                            if ($possibleSample->getName() === 'sample') {
                                return $possibleSample;
                            }
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Map scalar DIF XML fields to IgsnMetadata columns.
     */
    private function mapScalarFields(\SimpleXMLElement $sample, IgsnMetadata $igsnMetadata): void
    {
        $mappings = [
            'sample_type' => 'sample_type',
            'material' => 'material',
            'user_code' => 'user_code',
            'cruise_field_program' => 'cruise_field_program',
            'depth_min' => 'depth_min',
            'depth_max' => 'depth_max',
            'depth_scale' => 'depth_scale',
            'sample_purpose' => 'sample_purpose',
            'collection_method' => 'collection_method',
            'collection_method_descr' => 'collection_method_description',
            'platform_type' => 'platform_type',
            'platform_name' => 'platform_name',
            'platform_description' => 'platform_description',
            'current_archive' => 'current_archive',
            'current_archive_contact' => 'current_archive_contact',
            'sample_access' => 'sample_access',
            'operator' => 'operator',
            'coordinate_system' => 'coordinate_system',
        ];

        // Try both with and without namespace
        $ns = $this->detectSampleNamespace($sample);

        foreach ($mappings as $xmlField => $dbField) {
            $value = $this->getElementText($sample, $xmlField, $ns);
            if ($value !== null && $value !== '' && strtolower($value) !== 'n/a') {
                $igsnMetadata->$dbField = $value;
            }
        }

        // Store parent_igsn in description_json for later resolution
        $parentIgsn = $this->getElementText($sample, 'parent_igsn', $ns);
        if ($parentIgsn !== null && $parentIgsn !== '' && strtolower($parentIgsn) !== 'n/a') {
            $existing = $igsnMetadata->description_json ?? [];
            $existing['parent_igsn_handle'] = $parentIgsn;
            $igsnMetadata->description_json = $existing;
        }

        // Store original and current repository info in description_json
        $originalArchive = $this->getElementText($sample, 'original_archive', $ns);
        $originalArchiveContact = $this->getElementText($sample, 'original_archive_contact', $ns);
        if ($originalArchive !== null && $originalArchive !== '' && strtolower($originalArchive) !== 'n/a') {
            $existing = $igsnMetadata->description_json ?? [];
            $existing['original_archive'] = $originalArchive;
            if ($originalArchiveContact !== null && $originalArchiveContact !== '') {
                $existing['original_archive_contact'] = $originalArchiveContact;
            }
            $igsnMetadata->description_json = $existing;
        }
    }

    /**
     * Detect the namespace used by the <sample> element.
     */
    private function detectSampleNamespace(\SimpleXMLElement $sample): ?string
    {
        $namespaces = $sample->getNamespaces(true);
        foreach ($namespaces as $uri) {
            if (str_contains($uri, 'igsn') || str_contains($uri, 'pmd.gfz')) {
                return $uri;
            }
        }

        // Check default namespace
        $defaultNs = $sample->getNamespaces(false);
        foreach ($defaultNs as $uri) {
            if ($uri !== '') {
                return $uri;
            }
        }

        return null;
    }

    /**
     * Get text content of an XML element, handling namespaces.
     */
    private function getElementText(\SimpleXMLElement $parent, string $name, ?string $ns): ?string
    {
        // Try with namespace first
        if ($ns !== null) {
            $children = $parent->children($ns);
            if (isset($children->$name)) {
                $text = trim((string) $children->$name);

                return $text !== '' ? $text : null;
            }
        }

        // Try without namespace
        if (isset($parent->$name)) {
            $text = trim((string) $parent->$name);

            return $text !== '' ? $text : null;
        }

        return null;
    }

    /**
     * Map geo coordinates from DIF XML to GeoLocation.
     */
    private function mapGeoLocation(\SimpleXMLElement $sample, Resource $resource): void
    {
        $ns = $this->detectSampleNamespace($sample);

        $lat = $this->getElementText($sample, 'latitude', $ns);
        $lon = $this->getElementText($sample, 'longitude', $ns);
        $elevation = $this->getElementText($sample, 'elevation', $ns);
        $country = $this->getElementText($sample, 'country', $ns);
        $city = $this->getElementText($sample, 'city', $ns);

        // Only create if we have at least coordinates or location name
        if ($lat === null && $lon === null && $country === null && $city === null) {
            return;
        }

        // Skip if geo already exists for this resource
        if ($resource->geoLocations()->exists()) {
            return;
        }

        $place = collect([$city, $country])->filter()->implode(', ');

        $geoData = [
            'resource_id' => $resource->id,
            'place' => $place !== '' ? $place : null,
        ];

        if ($lat !== null && $lon !== null && is_numeric($lat) && is_numeric($lon)) {
            $geoData['point_latitude'] = (float) $lat;
            $geoData['point_longitude'] = (float) $lon;
        }

        if ($elevation !== null && is_numeric($elevation)) {
            $geoData['elevation'] = (float) $elevation;
            $geoData['elevation_unit'] = 'm';
        }

        GeoLocation::create($geoData);
    }

    /**
     * Map collection dates from DIF XML to ResourceDate.
     */
    private function mapCollectionDates(\SimpleXMLElement $sample, Resource $resource): void
    {
        $ns = $this->detectSampleNamespace($sample);

        $startDate = $this->getElementText($sample, 'collection_start_date', $ns);
        $endDate = $this->getElementText($sample, 'collection_end_date', $ns);

        if ($startDate === null && $endDate === null) {
            return;
        }

        $collectedTypeId = DateType::where('name', 'Collected')->value('id');
        if ($collectedTypeId === null) {
            return;
        }

        // Skip only if a 'Collected' date already exists for this resource
        if ($resource->dates()->where('date_type_id', $collectedTypeId)->exists()) {
            return;
        }

        $dateValue = $startDate ?? '';
        if ($endDate !== null && $endDate !== $startDate) {
            $dateValue .= " – {$endDate}";
        }

        ResourceDate::create([
            'resource_id' => $resource->id,
            'date_type_id' => $collectedTypeId,
            'date_value' => trim($dateValue),
        ]);
    }

    /**
     * Map collector (chief scientist) from DIF XML to ResourceContributor.
     */
    private function mapCollector(\SimpleXMLElement $sample, Resource $resource): void
    {
        $ns = $this->detectSampleNamespace($sample);

        $collector = $this->getElementText($sample, 'collector', $ns);
        if ($collector === null || strtolower($collector) === 'n/a') {
            return;
        }

        $dataCollectorType = ContributorType::where('slug', 'DataCollector')->first();
        if ($dataCollectorType === null) {
            return;
        }

        // Skip only if a DataCollector contributor already exists
        if ($resource->contributors()
            ->whereHas('contributorTypes', fn ($q) => $q->where('contributor_types.id', $dataCollectorType->id))
            ->exists()) {
            return;
        }

        // Parse name (format: "Lastname, Firstname" or just "Name")
        $parts = explode(',', $collector, 2);
        $familyName = trim($parts[0]);
        $givenName = isset($parts[1]) ? trim($parts[1]) : null;

        $person = Person::firstOrCreate(
            ['family_name' => $familyName, 'given_name' => $givenName],
        );

        ResourceContributor::create([
            'resource_id' => $resource->id,
            'contributorable_type' => Person::class,
            'contributorable_id' => $person->id,
            'position' => 0,
        ])->contributorTypes()->sync([$dataCollectorType->id]);
    }

    /**
     * Map rock classifications from DIF XML.
     */
    private function mapClassifications(\SimpleXMLElement $sample, Resource $resource): void
    {
        $ns = $this->detectSampleNamespace($sample);

        $classification = $this->getElementText($sample, 'classification', $ns);
        if ($classification === null || strtolower($classification) === 'n/a') {
            return;
        }

        if ($resource->igsnClassifications()->exists()) {
            return;
        }

        IgsnClassification::create([
            'resource_id' => $resource->id,
            'value' => $classification,
        ]);
    }

    /**
     * Map geological ages from DIF XML.
     */
    private function mapGeologicalAges(\SimpleXMLElement $sample, Resource $resource): void
    {
        $ns = $this->detectSampleNamespace($sample);

        $age = $this->getElementText($sample, 'geological_age', $ns);
        if ($age === null || strtolower($age) === 'n/a') {
            return;
        }

        if ($resource->igsnGeologicalAges()->exists()) {
            return;
        }

        IgsnGeologicalAge::create([
            'resource_id' => $resource->id,
            'value' => $age,
        ]);
    }

    /**
     * Map geological units from DIF XML.
     */
    private function mapGeologicalUnits(\SimpleXMLElement $sample, Resource $resource): void
    {
        $ns = $this->detectSampleNamespace($sample);

        $unit = $this->getElementText($sample, 'geological_unit', $ns);
        if ($unit === null || strtolower($unit) === 'n/a') {
            return;
        }

        if ($resource->igsnGeologicalUnits()->exists()) {
            return;
        }

        IgsnGeologicalUnit::create([
            'resource_id' => $resource->id,
            'value' => $unit,
        ]);
    }
}
