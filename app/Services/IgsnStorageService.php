<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ContributorType;
use App\Models\DateType;
use App\Models\FundingReference;
use App\Models\GeoLocation;
use App\Models\IdentifierType;
use App\Models\IgsnClassification;
use App\Models\IgsnGeologicalAge;
use App\Models\IgsnGeologicalUnit;
use App\Models\IgsnMetadata;
use App\Models\Person;
use App\Models\Publisher;
use App\Models\RelatedIdentifier;
use App\Models\RelationType;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\ResourceDate;
use App\Models\ResourceType;
use App\Models\Size;
use App\Models\Title;
use App\Models\TitleType;
use App\Services\Entities\AffiliationService;
use App\Services\Entities\PersonService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for storing IGSN data from parsed CSV.
 *
 * Creates Resource records with type "Physical Object" and all
 * related IGSN-specific metadata.
 */
class IgsnStorageService
{
    private ?int $physicalObjectTypeId = null;

    private ?int $mainTitleTypeId = null;

    private ?int $otherTitleTypeId = null;

    private ?int $collectedDateTypeId = null;

    private ?int $defaultPublisherId = null;

    public function __construct(
        protected IgsnCsvParserService $parser,
        protected PersonService $personService,
        protected AffiliationService $affiliationService,
    ) {}

    /**
     * Store multiple IGSNs from parsed CSV data.
     *
     * @param  list<array<string, mixed>>  $parsedRows  Parsed CSV rows from IgsnCsvParserService
     * @param  string  $filename  Original CSV filename
     * @param  int|null  $userId  ID of the user performing the upload
     * @return array{created: int, errors: list<array{row: int, igsn: string, message: string}>}
     */
    public function storeFromCsv(array $parsedRows, string $filename, ?int $userId = null): array
    {
        $this->loadLookupIds();

        return DB::transaction(function () use ($parsedRows, $filename, $userId): array {
            $created = 0;
            $errors = [];

            foreach ($parsedRows as $row) {
                try {
                    $this->createIgsnResource($row, $filename, $userId);
                    $created++;
                } catch (\Exception $e) {
                    Log::error('IGSN storage failed', [
                        'igsn' => $row['igsn'] ?? 'unknown',
                        'row' => $row['_row_number'] ?? 0,
                        'error' => $e->getMessage(),
                    ]);

                    $errors[] = [
                        'row' => $row['_row_number'] ?? 0,
                        'igsn' => $row['igsn'] ?? 'unknown',
                        'message' => $e->getMessage(),
                    ];
                }
            }

            return ['created' => $created, 'errors' => $errors];
        });
    }

    /**
     * Create a single IGSN Resource with all relations.
     *
     * @param  array<string, mixed>  $data  Parsed row data
     * @param  string  $filename  Original CSV filename
     * @param  int|null  $userId  User ID
     */
    private function createIgsnResource(array $data, string $filename, ?int $userId): Resource
    {
        // Create the base Resource
        $resource = Resource::create([
            'doi' => $data['igsn'],
            'publication_year' => $this->extractYear($data['collection_start_date'] ?? ''),
            'resource_type_id' => $this->physicalObjectTypeId,
            'publisher_id' => $this->defaultPublisherId,
            'created_by_user_id' => $userId,
        ]);

        // Create titles (main title + alternative titles for name and sample_other_names)
        $this->createTitles($resource, $data);

        // Create IGSN metadata (1:1)
        $this->createIgsnMetadata($resource, $data, $filename);

        // Create creator from collector info
        $this->createCreator($resource, $data);

        // Create contributors
        $this->createContributors($resource, $data);

        // Create geo location
        $this->createGeoLocation($resource, $data);

        // Create collection date
        $this->createCollectionDate($resource, $data);

        // Create related identifiers
        $this->createRelatedIdentifiers($resource, $data);

        // Create funding references
        $this->createFundingReferences($resource, $data);

        // Create size from size and size_unit fields
        $this->createSize($resource, $data);

        // Create IGSN-specific relations (classifications, geological ages/units)
        $this->createIgsnRelations($resource, $data);

        return $resource;
    }

    /**
     * Load lookup table IDs for reuse.
     */
    private function loadLookupIds(): void
    {
        $this->physicalObjectTypeId = ResourceType::where('slug', 'physical-object')->value('id');
        $this->mainTitleTypeId = TitleType::where('slug', 'MainTitle')->value('id');
        $this->otherTitleTypeId = TitleType::where('slug', 'Other')->value('id');
        $this->collectedDateTypeId = DateType::where('slug', 'Collected')->value('id');
        $this->defaultPublisherId = Publisher::getDefault()?->id;

        if ($this->physicalObjectTypeId === null) {
            throw new \RuntimeException('ResourceType "physical-object" not found. Please run seeders.');
        }

        if ($this->mainTitleTypeId === null || $this->otherTitleTypeId === null) {
            throw new \RuntimeException('Required TitleTypes not found. Please run seeders.');
        }
    }

    /**
     * Create titles for the resource.
     *
     * @param  array<string, mixed>  $data
     */
    private function createTitles(Resource $resource, array $data): void
    {
        // Main title from 'title' field
        Title::create([
            'resource_id' => $resource->id,
            'value' => $data['title'],
            'title_type_id' => $this->mainTitleTypeId,
        ]);

        // Sample name as Title with type "Other" (also exported as alternateIdentifier)
        if (! empty($data['name'])) {
            Title::create([
                'resource_id' => $resource->id,
                'value' => $data['name'],
                'title_type_id' => $this->otherTitleTypeId,
            ]);
        }

        // Other sample names as Titles with type "Other" (also exported as alternateIdentifiers)
        $otherNames = $data['sample_other_names'] ?? [];
        if (is_array($otherNames)) {
            foreach ($otherNames as $name) {
                if (! empty($name)) {
                    Title::create([
                        'resource_id' => $resource->id,
                        'value' => $name,
                        'title_type_id' => $this->otherTitleTypeId,
                    ]);
                }
            }
        }
    }

    /**
     * Create IGSN metadata record.
     *
     * @param  array<string, mixed>  $data
     */
    private function createIgsnMetadata(Resource $resource, array $data, string $filename): void
    {
        IgsnMetadata::create([
            'resource_id' => $resource->id,
            'parent_resource_id' => null, // Set later if parent IGSN is specified
            'sample_type' => $data['sample_type'] ?? null,
            'material' => $data['material'] ?? null,
            'is_private' => ($data['is_private'] ?? '0') === '1',
            'size' => is_numeric($data['size'] ?? null) ? (float) $data['size'] : null,
            'size_unit' => $data['size_unit'] ?? null,
            'depth_min' => is_numeric($data['depth_min'] ?? null) ? (float) $data['depth_min'] : null,
            'depth_max' => is_numeric($data['depth_max'] ?? null) ? (float) $data['depth_max'] : null,
            'depth_scale' => $data['depth_scale'] ?? null,
            'sample_purpose' => $data['sample_purpose'] ?? null,
            'collection_method' => $data['collection_method'] ?? null,
            'collection_method_description' => $data['collection_method_descr'] ?? null,
            'collection_date_precision' => $data['collection_date_precision'] ?? null,
            'cruise_field_program' => $data['cruise_field_prgrm'] ?? null,
            'platform_type' => $data['platform_type'] ?? null,
            'platform_name' => $data['platform_name'] ?? null,
            'platform_description' => $data['platform_descr'] ?? null,
            'current_archive' => $data['current_archive'] ?? null,
            'current_archive_contact' => $data['current_archive_contact'] ?? null,
            'sample_access' => $data['sampleAccess'] ?? null,
            'operator' => $data['operator'] ?? null,
            'coordinate_system' => $data['coordinate_system'] ?? null,
            'user_code' => $data['user_code'] ?? null,
            'description_json' => $this->parser->parseDescriptionJson($data['description'] ?? ''),
            'upload_status' => IgsnMetadata::STATUS_UPLOADED,
            'csv_filename' => $filename,
            'csv_row_number' => $data['_row_number'] ?? null,
        ]);
    }

    /**
     * Create creator from collector data.
     *
     * @param  array<string, mixed>  $data
     */
    private function createCreator(Resource $resource, array $data): void
    {
        $creator = $data['_creator'] ?? [];

        if (empty($creator['familyName']) && empty($creator['givenName'])) {
            return;
        }

        // Find or create person using PersonService's expected format
        $person = $this->personService->findOrCreate([
            'lastName' => $creator['familyName'] ?? '',
            'firstName' => $creator['givenName'] ?? null,
            'orcid' => $creator['orcid'] ?? null,
        ]);

        // Create resource creator using polymorphic relation
        $resourceCreator = ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => Person::class,
            'creatorable_id' => $person->id,
            'position' => 0,
        ]);

        // Add affiliation if provided (using AffiliationService's expected format)
        if (! empty($creator['affiliation'])) {
            $this->affiliationService->syncForCreator($resourceCreator, [
                'affiliations' => [
                    [
                        'value' => $creator['affiliation'],
                        'rorId' => $creator['ror'] ?? null,
                    ],
                ],
            ]);
        }
    }

    /**
     * Create contributors from parsed data.
     *
     * @param  array<string, mixed>  $data
     */
    private function createContributors(Resource $resource, array $data): void
    {
        $contributors = $data['_contributors'] ?? [];

        foreach ($contributors as $index => $contributor) {
            $this->createContributor($resource, $contributor, $index);
        }
    }

    /**
     * Create a single contributor.
     *
     * @param  array{name: string, type: string, identifier: string|null, identifierType: string|null}  $contributor
     */
    private function createContributor(Resource $resource, array $contributor, int $position): void
    {
        // Parse name into given/family name
        $nameParts = $this->parseContributorName($contributor['name']);

        // Find contributor type
        $contributorType = ContributorType::whereRaw('LOWER(name) = ?', [strtolower($contributor['type'])])
            ->orWhereRaw('LOWER(slug) = ?', [strtolower($contributor['type'])])
            ->first();

        $contributorTypeId = $contributorType !== null
            ? $contributorType->id
            : ContributorType::where('slug', 'Other')->value('id');

        // Find or create person using PersonService's expected format
        $person = $this->personService->findOrCreate([
            'lastName' => $nameParts['familyName'],
            'firstName' => $nameParts['givenName'],
            'orcid' => $contributor['identifier'],
        ]);

        ResourceContributor::create([
            'resource_id' => $resource->id,
            'contributor_type_id' => $contributorTypeId,
            'contributorable_type' => Person::class,
            'contributorable_id' => $person->id,
            'position' => $position,
        ]);
    }

    /**
     * Parse contributor name into given and family name.
     *
     * @return array{familyName: string, givenName: string|null}
     */
    private function parseContributorName(string $name): array
    {
        $name = trim($name);

        // Format: "FamilyName, GivenName"
        if (str_contains($name, ',')) {
            $parts = explode(',', $name, 2);

            return [
                'familyName' => trim($parts[0]),
                'givenName' => trim($parts[1] ?? ''),
            ];
        }

        // Format: "GivenName FamilyName" (assume last word is family name)
        $parts = explode(' ', $name);
        if (count($parts) > 1) {
            $familyName = array_pop($parts);

            return [
                'familyName' => $familyName,
                'givenName' => implode(' ', $parts),
            ];
        }

        return [
            'familyName' => $name,
            'givenName' => null,
        ];
    }

    /**
     * Create geo location from parsed data.
     *
     * @param  array<string, mixed>  $data
     */
    private function createGeoLocation(Resource $resource, array $data): void
    {
        $geo = $data['_geo_location'] ?? [];

        // Only create if we have some data
        if (empty($geo['latitude']) && empty($geo['longitude']) && empty($geo['place'])) {
            return;
        }

        GeoLocation::create([
            'resource_id' => $resource->id,
            'point_latitude' => $geo['latitude'],
            'point_longitude' => $geo['longitude'],
            'elevation' => $geo['elevation'],
            'elevation_unit' => $geo['elevationUnit'],
            'place' => $geo['place'],
        ]);
    }

    /**
     * Create collection date from parsed data.
     *
     * @param  array<string, mixed>  $data
     */
    private function createCollectionDate(Resource $resource, array $data): void
    {
        $dates = $this->parser->parseCollectionDates(
            $data['collection_start_date'] ?? '',
            $data['collection_end_date'] ?? ''
        );

        if ($dates['start'] === null) {
            return;
        }

        // Use start_date and end_date for date ranges (date_value is for single dates only)
        ResourceDate::create([
            'resource_id' => $resource->id,
            'date_type_id' => $this->collectedDateTypeId,
            'start_date' => $dates['start'],
            'end_date' => $dates['end'],
        ]);
    }

    /**
     * Create related identifiers from parsed data.
     *
     * @param  array<string, mixed>  $data
     */
    private function createRelatedIdentifiers(Resource $resource, array $data): void
    {
        $identifiers = $data['_related_identifiers'] ?? [];

        foreach ($identifiers as $index => $ri) {
            $this->createRelatedIdentifier($resource, $ri, $index);
        }
    }

    /**
     * Create a single related identifier.
     *
     * @param  array{identifier: string, type: string, relationType: string}  $ri
     */
    private function createRelatedIdentifier(Resource $resource, array $ri, int $position): void
    {
        $identifierType = IdentifierType::whereRaw('LOWER(name) = ?', [strtolower($ri['type'])])
            ->orWhereRaw('LOWER(slug) = ?', [strtolower($ri['type'])])
            ->first();

        $relationType = RelationType::whereRaw('LOWER(name) = ?', [strtolower($ri['relationType'])])
            ->orWhereRaw('LOWER(slug) = ?', [strtolower($ri['relationType'])])
            ->first();

        if ($identifierType === null || $relationType === null) {
            Log::warning('Unknown identifier or relation type', [
                'identifierType' => $ri['type'],
                'relationType' => $ri['relationType'],
            ]);

            return;
        }

        RelatedIdentifier::create([
            'resource_id' => $resource->id,
            'identifier' => $ri['identifier'],
            'identifier_type_id' => $identifierType->id,
            'relation_type_id' => $relationType->id,
            'position' => $position,
        ]);
    }

    /**
     * Create funding references from parsed data.
     *
     * @param  array<string, mixed>  $data
     */
    private function createFundingReferences(Resource $resource, array $data): void
    {
        $funders = $data['_funding_references'] ?? [];

        foreach ($funders as $funder) {
            if (empty($funder['name'])) {
                continue;
            }

            FundingReference::create([
                'resource_id' => $resource->id,
                'funder_name' => $funder['name'],
                'funder_identifier' => $funder['identifier'],
            ]);
        }
    }

    /**
     * Create size entry from size and size_unit fields.
     *
     * Combines size value and unit into a single DataCite size string.
     * Example: "15.5" + "cm" = "15.5 cm"
     *
     * @param  array<string, mixed>  $data
     */
    private function createSize(Resource $resource, array $data): void
    {
        $sizeValue = $data['size'] ?? null;
        $sizeUnit = $data['size_unit'] ?? null;

        // Skip if no size value
        if ($sizeValue === null || $sizeValue === '') {
            return;
        }

        // Build combined size string
        $sizeString = (string) $sizeValue;
        if (! empty($sizeUnit)) {
            $sizeString .= ' '.$sizeUnit;
        }

        Size::create([
            'resource_id' => $resource->id,
            'value' => $sizeString,
        ]);
    }

    /**
     * Create IGSN-specific relations (classifications, geological ages/units).
     *
     * @param  array<string, mixed>  $data
     */
    private function createIgsnRelations(Resource $resource, array $data): void
    {
        // Classifications
        $classifications = $data['classification'] ?? [];
        if (is_array($classifications)) {
            foreach ($classifications as $index => $value) {
                if (! empty($value)) {
                    IgsnClassification::create([
                        'resource_id' => $resource->id,
                        'value' => $value,
                        'position' => $index,
                    ]);
                }
            }
        }

        // Geological ages
        $ages = $data['geological_age'] ?? [];
        if (is_array($ages)) {
            foreach ($ages as $index => $value) {
                if (! empty($value)) {
                    IgsnGeologicalAge::create([
                        'resource_id' => $resource->id,
                        'value' => $value,
                        'position' => $index,
                    ]);
                }
            }
        }

        // Geological units
        $units = $data['geological_unit'] ?? [];
        if (is_array($units)) {
            foreach ($units as $index => $value) {
                if (! empty($value)) {
                    IgsnGeologicalUnit::create([
                        'resource_id' => $resource->id,
                        'value' => $value,
                        'position' => $index,
                    ]);
                }
            }
        }
    }

    /**
     * Extract year from date string.
     */
    private function extractYear(string $date): ?int
    {
        if ($date === '') {
            return null;
        }

        // Try to extract year from YYYY-MM-DD or similar format
        if (preg_match('/^(\d{4})/', $date, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
