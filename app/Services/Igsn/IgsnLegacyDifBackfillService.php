<?php

declare(strict_types=1);

namespace App\Services\Igsn;

use App\Enums\Igsn\IgsnMeasurementType;
use App\Models\IgsnMetadata;
use App\Models\Person;
use App\Models\Resource;
use App\Services\BotProtection\LandingPageRenderDataCacheService;
use App\Services\IgsnDifXmlParser;
use App\Services\LegacyIgsnPortalService;
use App\Support\DataCiteDateNormalizer;
use App\Support\IgsnIdentifier;
use App\Support\IgsnLocationNormalizer;
use App\Support\LegacyIgsnDatacenterCatalog;
use App\Support\OrcidNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class IgsnLegacyDifBackfillService
{
    public function __construct(
        private readonly LegacyIgsnPortalService $legacyPortal,
        private readonly IgsnDifMetadataExtractor $extractor,
        private readonly IgsnDifXmlParser $parser,
        private readonly LandingPageRenderDataCacheService $landingPageCache,
        private readonly IgsnSampleImageUrlService $sampleImageUrlService,
        private readonly IgsnExternalSampleImageProbeService $externalImageProbe,
        private readonly IgsnSampleImageStorageService $sampleImageStorageService,
        private readonly IgsnGeometryNormalizer $geometryNormalizer,
    ) {}

    /**
     * @param  list<string>  $dois
     * @param  list<string>  $datacenters
     * @return array{
     *     scanned: int,
     *     changed: int,
     *     unchanged: int,
     *     manual_review: int,
     *     privacy_conflict: int,
     *     missing_dif: int,
     *     invalid_dif: int,
     *     unknown_paths: int,
     *     portal_errors: int,
     *     database_errors: int,
     *     image_unavailable: int,
     *     image_probe_errors: int,
     *     errors: int,
     *     cache_invalidation_failures: int,
     *     last_scanned_resource_id: int|null,
     *     sync_resource_ids: list<int>,
     *     records: list<array<string, int|string|null>>
     * }
     */
    public function run(
        bool $apply = false,
        int $afterId = 0,
        int $limit = 0,
        int $chunk = 100,
        array $dois = [],
        array $datacenters = [],
    ): array {
        $chunk = max(1, min(100, $chunk));
        $limit = max(0, $limit);
        $cursor = max(0, $afterId);
        $doiFilter = $this->normalizeDoiFilter($dois);
        $datacenterNames = $this->normalizeDatacenterFilter($datacenters);
        /** @var array{
         *     scanned: int, changed: int, unchanged: int, manual_review: int, privacy_conflict: int,
         *     missing_dif: int, invalid_dif: int, unknown_paths: int, portal_errors: int, database_errors: int,
         *     image_unavailable: int, image_probe_errors: int, errors: int,
         *     cache_invalidation_failures: int, last_scanned_resource_id: int|null,
         *     sync_resource_ids: list<int>, records: list<array<string, int|string|null>>
         * } $stats
         */
        $stats = [
            'scanned' => 0,
            'changed' => 0,
            'unchanged' => 0,
            'manual_review' => 0,
            'privacy_conflict' => 0,
            'missing_dif' => 0,
            'invalid_dif' => 0,
            'unknown_paths' => 0,
            'portal_errors' => 0,
            'database_errors' => 0,
            'image_unavailable' => 0,
            'image_probe_errors' => 0,
            'errors' => 0,
            'cache_invalidation_failures' => 0,
            'last_scanned_resource_id' => null,
            'sync_resource_ids' => [],
            'records' => [],
        ];

        while ($limit === 0 || $stats['scanned'] < $limit) {
            $batchSize = $limit === 0 ? $chunk : min($chunk, $limit - $stats['scanned']);
            $resources = Resource::query()
                ->with([
                    'igsnMetadata',
                    'datacenter',
                    'landingPage',
                    'relatedIdentifiers.identifierType',
                    'relatedIdentifiers.relationType',
                    'fundingReferences',
                    'dates.dateType',
                    'alternateIdentifiers',
                    'geoLocations',
                    'sizes',
                    'igsnClassifications',
                    'igsnGeologicalAges',
                    'igsnGeologicalUnits',
                    'igsnOperators',
                    'igsnMethods',
                    'igsnMeasurements',
                    'igsnMetadataValues',
                    'contributors.contributorable',
                    'contributors.contributorTypes',
                    'contributors.affiliations',
                ])
                ->whereHas('igsnMetadata')
                ->where('id', '>', $cursor)
                ->when($doiFilter !== [], fn (Builder $query): Builder => $query->whereIn('doi', $doiFilter))
                ->when(
                    $datacenterNames !== [],
                    fn (Builder $query): Builder => $query->whereHas(
                        'datacenter',
                        fn (Builder $datacenter): Builder => $datacenter->whereIn('name', $datacenterNames),
                    ),
                )
                ->orderBy('id')
                ->limit($batchSize)
                ->get();

            if ($resources->isEmpty()) {
                break;
            }

            $cursor = (int) $resources->last()->id;
            $stats['last_scanned_resource_id'] = $cursor;
            $byHandle = [];

            foreach ($resources as $resource) {
                $stats['scanned']++;
                $handle = is_string($resource->doi) ? IgsnIdentifier::handleFromDoi($resource->doi) : null;
                if ($handle === null) {
                    $stats['errors']++;
                    $stats['records'][] = $this->emptyRecord($resource, '', 'error', 'Invalid IGSN DOI.');

                    continue;
                }
                $byHandle[$handle] = $resource;
            }

            if ($byHandle === []) {
                continue;
            }

            try {
                $documents = $this->legacyPortal->difForHandles(array_keys($byHandle));
            } catch (Throwable $exception) {
                Log::warning('Legacy IGSN DIF backfill batch failed', [
                    'handles' => array_keys($byHandle),
                    'error_class' => $exception::class,
                ]);
                foreach ($byHandle as $handle => $resource) {
                    $stats['portal_errors']++;
                    $stats['errors']++;
                    $stats['records'][] = $this->emptyRecord($resource, $handle, 'error', 'Legacy DIF request failed.');
                }

                continue;
            }

            foreach ($byHandle as $handle => $resource) {
                $difXml = $documents[$handle] ?? null;
                if (! is_string($difXml)) {
                    $stats['missing_dif']++;
                    $stats['records'][] = $this->emptyRecord($resource, $handle, 'missing_dif', 'No DIF metadata returned.');

                    continue;
                }

                try {
                    $metadata = $this->extractor->extract($difXml);
                    if ($metadata === null) {
                        $stats['invalid_dif']++;
                        $stats['records'][] = $this->emptyRecord(
                            $resource,
                            $handle,
                            'invalid_dif',
                            'DIF metadata does not contain a readable sample.',
                        );

                        continue;
                    }

                    $diff = $this->diff($resource, $metadata);
                    if ($diff['sample_image']['status'] === IgsnExternalSampleImageProbeService::STATUS_UNAVAILABLE) {
                        $stats['image_unavailable']++;
                    } elseif ($diff['sample_image']['status'] === IgsnExternalSampleImageProbeService::STATUS_FAILED) {
                        $stats['image_probe_errors']++;
                    }
                    $syncEligible = $this->isDataCiteSyncEligible($resource);
                    $applyStarted = false;
                    $cacheInvalidationFailed = false;
                    if ($apply && $diff['changed']) {
                        $applyStarted = true;
                        $this->apply((int) $resource->id, $difXml, $diff['sample_image']);
                        if ($syncEligible) {
                            $stats['sync_resource_ids'][] = (int) $resource->id;
                        }
                        if (! $this->invalidateLandingPage($resource)) {
                            $cacheInvalidationFailed = true;
                            $stats['cache_invalidation_failures']++;
                        }
                    }

                    if ($diff['changed']) {
                        $stats['changed']++;
                    } else {
                        $stats['unchanged']++;
                    }
                    if ($diff['conflicts'] !== []) {
                        $stats['manual_review']++;
                    }
                    if (collect($diff['conflicts'])->contains(
                        static fn (string $conflict): bool => str_starts_with($conflict, 'privacy:'),
                    )) {
                        $stats['privacy_conflict']++;
                    }
                    $stats['unknown_paths'] += count($metadata['legacy_dif']['unknown_paths']);
                    $stats['records'][] = [
                        'resource_id' => (int) $resource->id,
                        'doi' => (string) $resource->doi,
                        'handle' => $handle,
                        'datacenter' => $this->datacenterName($resource),
                        'schema_namespace' => (string) ($metadata['legacy_dif']['schema_namespace'] ?? ''),
                        'status' => $diff['changed'] ? ($apply ? 'updated' : 'would_update') : 'unchanged',
                        'changed_fields' => implode(' | ', $diff['changed_fields']),
                        'existing_values' => json_encode($this->reportExistingValues($resource), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                        'source_values' => json_encode($this->reportSourceValues($metadata), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                        'inserted_values' => $apply && $diff['changed'] ? implode(' | ', $diff['changed_fields']) : '',
                        'conflicts' => implode(' | ', $diff['conflicts']),
                        'unknown_paths' => implode(' | ', $metadata['legacy_dif']['unknown_paths']),
                        'missing_dif' => 0,
                        'sample_image_status' => $diff['sample_image']['status'],
                        'sample_image_url' => $diff['sample_image']['url'],
                        'sample_image_message' => $diff['sample_image']['message'],
                        'datacite_sync_status' => $apply && $diff['changed'] && $syncEligible ? 'pending' : 'not_queued',
                        'sync_run_id' => null,
                        'message' => $cacheInvalidationFailed ? 'Landing-page cache invalidation failed; the database update remains applied.' : '',
                    ];
                } catch (Throwable $exception) {
                    if (($applyStarted ?? false) === true) {
                        $stats['database_errors']++;
                    } else {
                        $stats['invalid_dif']++;
                    }
                    $stats['errors']++;
                    $stats['records'][] = $this->emptyRecord($resource, $handle, 'error', $exception->getMessage());
                    Log::warning('Legacy IGSN DIF backfill record failed', [
                        'resource_id' => $resource->id,
                        'handle' => $handle,
                        'error_class' => $exception::class,
                    ]);
                }
            }
        }

        $stats['sync_resource_ids'] = array_values(array_unique($stats['sync_resource_ids']));

        return [
            'scanned' => $stats['scanned'],
            'changed' => $stats['changed'],
            'unchanged' => $stats['unchanged'],
            'manual_review' => $stats['manual_review'],
            'privacy_conflict' => $stats['privacy_conflict'],
            'missing_dif' => $stats['missing_dif'],
            'invalid_dif' => $stats['invalid_dif'],
            'unknown_paths' => $stats['unknown_paths'],
            'portal_errors' => $stats['portal_errors'],
            'database_errors' => $stats['database_errors'],
            'image_unavailable' => $stats['image_unavailable'],
            'image_probe_errors' => $stats['image_probe_errors'],
            'errors' => $stats['errors'],
            'cache_invalidation_failures' => $stats['cache_invalidation_failures'],
            'last_scanned_resource_id' => $stats['last_scanned_resource_id'],
            'sync_resource_ids' => $stats['sync_resource_ids'],
            'records' => $stats['records'],
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{
     *     changed: bool,
     *     changed_fields: list<string>,
     *     conflicts: list<string>,
     *     sample_image: array{
     *         status: string,
     *         url: string|null,
     *         message: string,
     *         source_url: string|null,
     *         probe: array{status: string, url: string|null, message: string}|null
     *     }
     * }
     */
    private function diff(Resource $resource, array $metadata): array
    {
        $changedFields = [];
        $conflicts = array_map(
            static fn (array $conflict): string => (string) ($conflict['field'] ?? 'unknown'),
            $metadata['conflicts'],
        );
        $igsn = $resource->igsnMetadata;
        if ($igsn === null) {
            throw new RuntimeException('IGSN metadata relation is missing.');
        }

        foreach ($metadata['scalars'] as $field => $value) {
            if ($value === null) {
                continue;
            }
            $existing = $igsn->{$field};
            if ($field === 'is_private') {
                if ($existing !== null && (bool) $existing !== (bool) $value) {
                    $conflicts[] = sprintf('privacy:is_private (%s != %s)', $this->displayValue($existing), $this->displayValue($value));
                }

                continue;
            }
            if ($this->isEmpty($existing)) {
                $changedFields[] = 'igsn_metadata.'.$field;
            } elseif ($this->normalizeComparable($existing) !== $this->normalizeComparable($value)) {
                $conflicts[] = sprintf('%s (%s != %s)', $field, $this->displayValue($existing), $this->displayValue($value));
            }
        }

        $this->diffLegacySpecificValues($resource, $metadata, $changedFields);
        $sampleImage = $this->diffExistingImportTargets($resource, $metadata, $changedFields);

        foreach ($metadata['root_related_identifiers'] as $identifier) {
            if (strcasecmp($identifier['identifier_type'], 'DOI') !== 0
                || strcasecmp($identifier['relation_type'], 'hasDocument') !== 0) {
                continue;
            }

            $doi = $this->normalizeDoi($identifier['identifier']);
            if ($doi === null) {
                continue;
            }

            $exists = $resource->relatedIdentifiers->contains(
                fn ($related): bool => $related->identifierType->slug === 'DOI'
                    && $related->relationType->slug === 'Cites'
                    && $this->normalizeDoi((string) $related->identifier) === $doi,
            );
            if (! $exists) {
                $changedFields[] = 'related_identifiers';
                break;
            }
        }

        foreach ($metadata['funding_agencies'] as $funder) {
            if (! $resource->fundingReferences->contains(
                fn ($reference): bool => $this->normalizeComparable($reference->funder_name) === $this->normalizeComparable($funder),
            )) {
                $changedFields[] = 'funding_references';
                break;
            }
        }

        foreach ([
            ['field' => 'publish_dates', 'type' => 'Available', 'preserve' => false, 'information' => 'Legacy IGSN publish date'],
            ['field' => 'sampling_dates', 'type' => 'Collected', 'preserve' => true, 'information' => 'Legacy IGSN sampling date'],
        ] as $dateProjection) {
            $field = $dateProjection['field'];
            $type = $dateProjection['type'];
            foreach ($metadata[$field] as $value) {
                if (! $resource->dates->contains(
                    fn ($date): bool => $date->dateType->slug === $type
                        && $this->normalizeDateComparable($date->date_value, $dateProjection['preserve'])
                            === $this->normalizeDateComparable($value, $dateProjection['preserve'])
                        && $this->dateInformationContains($date->date_information, $dateProjection['information']),
                )) {
                    $changedFields[] = 'dates.'.$type;
                    break 2;
                }
            }
        }

        $changedFields = array_values(array_unique($changedFields));
        $conflicts = array_values(array_unique($conflicts));

        return [
            'changed' => $changedFields !== [],
            'changed_fields' => $changedFields,
            'conflicts' => $conflicts,
            'sample_image' => $sampleImage,
        ];
    }

    /**
     * @param  array{
     *     status: string,
     *     url: string|null,
     *     message: string,
     *     source_url: string|null,
     *     probe: array{status: string, url: string|null, message: string}|null
     * }  $sampleImage
     */
    private function apply(int $resourceId, string $difXml, array $sampleImage): void
    {
        DB::transaction(function () use ($resourceId, $difXml, $sampleImage): void {
            $resource = Resource::query()->whereKey($resourceId)->lockForUpdate()->firstOrFail();
            $igsn = $resource->igsnMetadata()->lockForUpdate()->first();
            if ($igsn === null || ! $this->parser->enrichFromDifXml($difXml, $resource, $igsn, additive: true)) {
                throw new RuntimeException('Unable to apply the legacy DIF metadata.');
            }

            if (is_string($sampleImage['source_url']) && $sampleImage['source_url'] !== '') {
                if ($igsn->sample_image_source_url === null) {
                    $igsn->forceFill(['sample_image_source_url' => $sampleImage['source_url']])->save();
                }
                if (is_array($sampleImage['probe'])) {
                    $this->sampleImageStorageService->sync($igsn, externalProbeResult: $sampleImage['probe']);
                }
            }
        });
    }

    private function invalidateLandingPage(Resource $resource): bool
    {
        $landingPage = $resource->landingPage;
        if ($landingPage === null || ! $landingPage->isPublished()) {
            return true;
        }

        try {
            if (! $this->landingPageCache->forgetById((int) $landingPage->id)) {
                return false;
            }
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<string>  $changedFields
     */
    private function diffLegacySpecificValues(Resource $resource, array $metadata, array &$changedFields): void
    {
        $operatorValues = $metadata['operators'];
        $storedOperator = $resource->igsnMetadata?->operator;
        if (is_string($storedOperator) && trim($storedOperator) !== '') {
            array_unshift($operatorValues, trim($storedOperator));
        }
        if ($this->hasMissingStringValues(
            $resource->igsnOperators->pluck('value')->all(),
            $operatorValues,
        )) {
            $changedFields[] = 'igsn_operators';
        }

        $existingMethods = $resource->igsnMethods
            ->map(fn ($method): string => $this->structuredKey([$method->scheme, $method->value]))
            ->all();
        foreach ($metadata['methods'] as $method) {
            if (! in_array($this->structuredKey([$method['scheme'], $method['value']]), $existingMethods, true)) {
                $changedFields[] = 'igsn_methods';
                break;
            }
        }

        $measurementSources = [
            IgsnMeasurementType::TotalLength->value => array_map(
                static fn (array $item): array => [$item['numeric_value'], null, $item['unit'], null],
                $metadata['total_lengths'],
            ),
            IgsnMeasurementType::AgeRange->value => array_map(
                static fn (array $item): array => [$item['start'], $item['end'], $item['unit'], $item['end_unit']],
                $metadata['age_ranges'],
            ),
            IgsnMeasurementType::ElevationRange->value => array_map(
                static fn (array $item): array => [$item['start'], $item['end'], $item['unit'], $item['end_unit']],
                $metadata['elevation_ranges'],
            ),
        ];
        foreach ($measurementSources as $type => $items) {
            $existing = $resource->igsnMeasurements
                ->filter(fn ($measurement): bool => $measurement->type->value === $type)
                ->map(fn ($measurement): string => $this->structuredKey([
                    $measurement->start_value,
                    $measurement->end_value,
                    $measurement->unit,
                    $measurement->end_unit,
                ]))
                ->all();
            foreach ($items as $item) {
                if (! in_array($this->structuredKey($item), $existing, true)) {
                    $changedFields[] = 'igsn_measurements.'.$type;
                    break;
                }
            }
        }

        foreach ($metadata['metadata_values'] as $type => $values) {
            $existing = $resource->igsnMetadataValues
                ->filter(fn ($value): bool => $value->type->value === $type)
                ->pluck('value')
                ->all();
            if ($this->hasMissingStringValues($existing, $values)) {
                $changedFields[] = 'igsn_metadata_values.'.$type;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<string>  $changedFields
     * @return array{
     *     status: string,
     *     url: string|null,
     *     message: string,
     *     source_url: string|null,
     *     probe: array{status: string, url: string|null, message: string}|null
     * }
     */
    private function diffExistingImportTargets(Resource $resource, array $metadata, array &$changedFields): array
    {
        $sampleImage = [
            'status' => 'not_applicable',
            'url' => null,
            'message' => '',
            'source_url' => null,
            'probe' => null,
        ];
        $igsn = $resource->igsnMetadata;
        if ($igsn === null) {
            return $sampleImage;
        }

        if ($metadata['sample_access'] !== null
            && ($this->isEmpty($igsn->sample_access) || $resource->access_level === null)) {
            $changedFields[] = 'igsn_metadata.sample_access';
        }

        $description = is_array($igsn->description_json) ? $igsn->description_json : [];
        if ($metadata['parent_igsn'] !== null && $this->isEmpty($description['parent_igsn_handle'] ?? null)) {
            $changedFields[] = 'igsn_metadata.parent_igsn_handle';
        }
        foreach (['material_descriptions', 'comments'] as $key) {
            $existing = is_array($description[$key] ?? null) ? $description[$key] : [];
            if ($this->hasMissingStringValues($existing, $metadata[$key])) {
                $changedFields[] = 'igsn_metadata.description_json.'.$key;
            }
        }
        $existingGroups = is_array($description['description_groups'] ?? null)
            ? array_map($this->structuredKey(...), $description['description_groups'])
            : [];
        foreach ($metadata['description_groups'] as $group) {
            if (! in_array($this->structuredKey($group), $existingGroups, true)) {
                $changedFields[] = 'igsn_metadata.description_json.description_groups';
                break;
            }
        }

        $resolvedImage = $this->sampleImageUrlService->resolve(
            $metadata['sample_image']['base_url'],
            $metadata['sample_image']['file_name'],
        );
        if ($resolvedImage['status'] === IgsnSampleImageUrlService::STATUS_MANAGED
            && $igsn->sample_image_source_url === null
            && $igsn->sample_image_external_url === null) {
            $changedFields[] = 'igsn_metadata.sample_image';
        }

        $externalImage = $resolvedImage;
        if ($externalImage['status'] !== IgsnSampleImageUrlService::STATUS_EXTERNAL) {
            $storedImage = $this->sampleImageUrlService->classifySourceUrl($igsn->sample_image_source_url);
            if ($storedImage['status'] === IgsnSampleImageUrlService::STATUS_EXTERNAL) {
                $externalImage = $storedImage;
            }
        }

        if ($externalImage['status'] === IgsnSampleImageUrlService::STATUS_EXTERNAL
            && is_string($externalImage['external_url'])
            && is_string($externalImage['source_url'])) {
            $probe = $this->externalImageProbe->probe($externalImage['external_url']);
            $sampleImage = [
                'status' => $probe['status'],
                'url' => $probe['url'],
                'message' => $probe['message'],
                'source_url' => $externalImage['source_url'],
                'probe' => $probe,
            ];

            if ($igsn->sample_image_source_url === null) {
                $changedFields[] = 'igsn_metadata.sample_image_source_url';
            }
            if ($probe['status'] === IgsnExternalSampleImageProbeService::STATUS_AVAILABLE
                && $igsn->sample_image_external_url !== $probe['url']) {
                $changedFields[] = 'igsn_metadata.sample_image_external_url';
            }
            if ($probe['status'] === IgsnExternalSampleImageProbeService::STATUS_UNAVAILABLE
                && $igsn->sample_image_external_url !== null) {
                $changedFields[] = 'igsn_metadata.sample_image_external_url';
            }
        }

        if ($metadata['name'] !== null
            && ! $this->hasAlternateIdentifier($resource, $metadata['name'], 'Local accession number')) {
            $changedFields[] = 'alternate_identifiers.name';
        }
        foreach ($metadata['other_names'] as $name) {
            if (! $this->hasAlternateIdentifier($resource, $name, 'Local sample name')) {
                $changedFields[] = 'alternate_identifiers.other_names';
                break;
            }
        }

        $this->diffLocation($resource, $metadata['location'], $changedFields);
        $this->diffCollectionPeriod($resource, $metadata['collection'], $changedFields);
        $this->diffContributors($resource, $metadata, $changedFields);

        if ($this->hasMissingStringValues(
            $resource->igsnClassifications->pluck('value')->all(),
            array_column($metadata['classifications'], 'value'),
        )) {
            $changedFields[] = 'igsn_classifications';
        }
        if ($this->hasMissingStringValues(
            $resource->igsnGeologicalAges->pluck('value')->all(),
            $metadata['geological_ages'],
        )) {
            $changedFields[] = 'igsn_geological_ages';
        }
        if ($this->hasMissingStringValues(
            $resource->igsnGeologicalUnits->pluck('value')->all(),
            $metadata['geological_units'],
        )) {
            $changedFields[] = 'igsn_geological_units';
        }

        $existingSizes = $resource->sizes
            ->map(fn ($size): string => $this->structuredKey([
                $this->normalizeNumericComparable($size->numeric_value),
                $size->unit,
                $size->type,
            ]))
            ->all();
        foreach ($metadata['sizes'] as $size) {
            if (! in_array($this->structuredKey([
                $this->normalizeNumericComparable($size['numeric_value']),
                $size['unit'],
                $size['type'],
            ]), $existingSizes, true)) {
                $changedFields[] = 'sizes';
                break;
            }
        }

        return $sampleImage;
    }

    /** @param array<string, mixed> $location
     * @param  list<string>  $changedFields
     */
    private function diffLocation(Resource $resource, array $location, array &$changedFields): void
    {
        $sourceText = [
            'place' => IgsnLocationNormalizer::place($location),
            'location_type' => $location['location_type'],
            'location_description' => $location['location_description'],
            'locality_description' => $location['locality_description'],
            'country' => $location['country'],
            'province' => $location['province'],
            'county' => $location['county'],
            'city' => $location['city'],
            'elevation' => is_numeric($location['elevation']) ? (string) (float) $location['elevation'] : null,
            'elevation_unit' => $location['elevation_unit'],
        ];
        $geometry = $this->geometryNormalizer->normalize($location['pairs']);
        if (array_filter($sourceText, fn (mixed $value): bool => ! $this->isEmpty($value)) === [] && $geometry === null) {
            return;
        }

        $existing = $resource->geoLocations->first();
        if ($existing === null) {
            $changedFields[] = 'geo_locations';

            return;
        }
        foreach ($sourceText as $field => $value) {
            if (! $this->isEmpty($value) && $this->isEmpty($existing->{$field})) {
                $changedFields[] = 'geo_locations.'.$field;
            }
        }
        if ($geometry !== null && ! $existing->hasPoint() && ! $existing->hasBox() && ! $existing->hasPolygon() && ! $existing->hasLine()) {
            $changedFields[] = 'geo_locations.geometry';
        }
    }

    /** @param array<string, mixed> $collection
     * @param  list<string>  $changedFields
     */
    private function diffCollectionPeriod(Resource $resource, array $collection, array &$changedFields): void
    {
        $start = is_string($collection['start']) ? $this->normalizeDateComparable($collection['start'], true) : null;
        $end = is_string($collection['end']) ? $this->normalizeDateComparable($collection['end'], true) : null;
        if ($start === null && $end === null) {
            return;
        }
        $source = $start === null || $end === null || $start === $end
            ? [$start ?? $end, null, null]
            : [null, $start, $end];
        $exists = $resource->dates->contains(function ($date) use ($source): bool {
            if ($date->dateType->slug !== 'Collected') {
                return false;
            }

            return $this->structuredKey([
                $this->normalizeDateComparable($date->date_value, true),
                $this->normalizeDateComparable($date->start_date, true),
                $this->normalizeDateComparable($date->end_date, true),
            ]) === $this->structuredKey($source)
                && $this->dateInformationContains($date->date_information, 'Legacy IGSN collection period');
        });
        if (! $exists) {
            $changedFields[] = 'dates.Collected.period';
        }
    }

    /** @param array<string, mixed> $metadata
     * @param  list<string>  $changedFields
     */
    private function diffContributors(Resource $resource, array $metadata, array &$changedFields): void
    {
        $expected = [];
        if (is_string($metadata['collection']['collector']) && trim($metadata['collection']['collector']) !== '') {
            $collectorAffiliation = $metadata['collection']['collector_detail'];
            $expected[] = [
                'name' => $metadata['collection']['collector'],
                'type' => 'DataCollector',
                'affiliations' => is_string($collectorAffiliation) && trim($collectorAffiliation) !== ''
                    ? [$collectorAffiliation]
                    : [],
                'identifiers' => [],
            ];
        }
        foreach ($metadata['root_contributors'] as $contributor) {
            if (strcasecmp((string) $contributor['type'], 'ProjectLeader') === 0) {
                $expected[] = [
                    'name' => $contributor['name'],
                    'type' => 'ProjectLeader',
                    'affiliations' => $contributor['affiliations'],
                    'identifiers' => $contributor['identifiers'],
                ];
            }
        }

        foreach ($expected as $contributor) {
            $targetName = $this->normalizePersonName($contributor['name']);
            $existing = $resource->contributors->first(function ($existing) use ($contributor, $targetName): bool {
                $entity = $existing->contributorable;
                if (! $entity instanceof Person) {
                    return false;
                }

                return $this->normalizePersonName($entity->full_name) === $targetName
                    && $existing->contributorTypes->contains('slug', $contributor['type']);
            });
            if ($existing === null) {
                $changedFields[] = 'contributors.'.$contributor['type'];

                continue;
            }

            $existingAffiliations = $existing->affiliations->pluck('name')->all();
            if ($this->hasMissingStringValues($existingAffiliations, $contributor['affiliations'])) {
                $changedFields[] = 'contributors.'.$contributor['type'].'.affiliations';
            }

            $expectedOrcid = $this->firstValidOrcid($contributor['identifiers']);
            $entity = $existing->contributorable;
            if ($expectedOrcid !== null
                && $entity instanceof Person
                && $this->isEmpty($entity->name_identifier)) {
                $changedFields[] = 'contributors.'.$contributor['type'].'.orcid';
            }
        }
    }

    private function hasAlternateIdentifier(Resource $resource, string $value, string $type): bool
    {
        $normalized = $this->normalizeComparable($value);

        return $resource->alternateIdentifiers->contains(
            fn ($identifier): bool => $this->normalizeComparable($identifier->value) === $normalized
                && strcasecmp((string) $identifier->type, $type) === 0,
        );
    }

    /** @param list<string> $identifiers */
    private function firstValidOrcid(array $identifiers): ?string
    {
        foreach ($identifiers as $identifier) {
            if (OrcidNormalizer::isValid($identifier)) {
                return 'https://orcid.org/'.strtoupper(OrcidNormalizer::extractBareId($identifier));
            }
        }

        return null;
    }

    /** @param array<int, mixed> $existing
     * @param  array<int, mixed>  $incoming
     */
    private function hasMissingStringValues(array $existing, array $incoming): bool
    {
        $existing = array_map(fn (mixed $value): string => $this->normalizeComparable($value), $existing);

        return collect($incoming)->contains(
            fn (mixed $value): bool => ! in_array($this->normalizeComparable($value), $existing, true),
        );
    }

    private function structuredKey(mixed $value): string
    {
        return json_encode($this->normalizeStructuredValue($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function normalizeStructuredValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->normalizeComparable($value);
        }
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map($this->normalizeStructuredValue(...), $value);
    }

    private function normalizePersonName(string $value): string
    {
        $parts = preg_split('/[\s,]+/u', $this->normalizeComparable($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        sort($parts);

        return implode('|', $parts);
    }

    private function dateInformationContains(mixed $value, string $expected): bool
    {
        if (! is_string($value)) {
            return false;
        }

        return collect(explode(';', $value))->contains(
            fn (string $part): bool => strcasecmp(trim($part), $expected) === 0,
        );
    }

    /** @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function reportSourceValues(array $metadata): array
    {
        unset($metadata['conflicts'], $metadata['legacy_dif']);

        return $metadata;
    }

    /** @return array<string, mixed> */
    private function reportExistingValues(Resource $resource): array
    {
        $igsn = $resource->igsnMetadata;
        $scalars = [];
        if ($igsn !== null) {
            foreach ([
                'sample_type', 'material', 'is_private', 'depth_min', 'depth_max', 'depth_scale',
                'sample_purpose', 'collection_method', 'collection_method_description',
                'collection_date_precision', 'cruise_field_program', 'platform_type', 'platform_name',
                'platform_description', 'current_archive', 'current_archive_contact', 'original_archive',
                'original_archive_contact', 'sample_access', 'operator', 'coordinate_system', 'user_code',
            ] as $field) {
                $value = $igsn->{$field};
                if (! $this->isEmpty($value)) {
                    $scalars[$field] = $value;
                }
            }
        }

        return [
            'scalars' => $scalars,
            'description_json' => $igsn?->description_json,
            'operators' => $resource->igsnOperators->sortBy('position')->pluck('value')->values()->all(),
            'methods' => $resource->igsnMethods->sortBy('position')->map(
                static fn ($method): array => ['scheme' => $method->scheme, 'value' => $method->value],
            )->values()->all(),
            'measurements' => $resource->igsnMeasurements->sortBy('position')->map(
                static fn ($measurement): array => [
                    'type' => $measurement->type->value,
                    'start' => $measurement->start_value,
                    'end' => $measurement->end_value,
                    'unit' => $measurement->unit,
                    'end_unit' => $measurement->end_unit,
                ],
            )->values()->all(),
            'metadata_values' => $resource->igsnMetadataValues->sortBy('position')->map(
                static fn ($value): array => ['type' => $value->type->value, 'value' => $value->value],
            )->values()->all(),
            'alternate_identifiers' => $resource->alternateIdentifiers->sortBy('position')->map(
                static fn ($identifier): array => ['type' => $identifier->type, 'value' => $identifier->value],
            )->values()->all(),
            'classifications' => $resource->igsnClassifications->sortBy('position')->pluck('value')->values()->all(),
            'geological_ages' => $resource->igsnGeologicalAges->sortBy('position')->pluck('value')->values()->all(),
            'geological_units' => $resource->igsnGeologicalUnits->sortBy('position')->pluck('value')->values()->all(),
            'funding_agencies' => $resource->fundingReferences->sortBy('position')->pluck('funder_name')->values()->all(),
            'dates' => $resource->dates->map(static fn ($date): array => [
                'type' => $date->dateType->slug,
                'value' => $date->date_value,
                'start' => $date->start_date,
                'end' => $date->end_date,
                'information' => $date->date_information,
            ])->values()->all(),
            'sizes' => $resource->sizes->map(static fn ($size): array => [
                'numeric_value' => $size->numeric_value,
                'unit' => $size->unit,
                'type' => $size->type,
            ])->values()->all(),
            'related_identifiers' => $resource->relatedIdentifiers->map(static fn ($identifier): array => [
                'identifier' => $identifier->identifier,
                'identifier_type' => $identifier->identifierType->slug,
                'relation_type' => $identifier->relationType->slug,
            ])->values()->all(),
            'sample_image' => [
                'source_url' => $igsn?->sample_image_source_url,
                'external_url' => $igsn?->sample_image_external_url,
                'storage_path' => $igsn?->sample_image_storage_path,
            ],
        ];
    }

    /** @param list<string> $dois
     * @return list<string>
     */
    private function normalizeDoiFilter(array $dois): array
    {
        $normalized = [];
        foreach ($dois as $doi) {
            $value = IgsnIdentifier::normalizeInputToDoi($doi);
            if ($value === null) {
                throw new InvalidArgumentException(sprintf('Invalid IGSN DOI or handle filter: "%s".', $doi));
            }
            $normalized[$value] = true;
        }

        return array_keys($normalized);
    }

    /** @param list<string> $datacenters
     * @return list<string>
     */
    private function normalizeDatacenterFilter(array $datacenters): array
    {
        $names = [];
        foreach ($datacenters as $datacenter) {
            $key = strtoupper(trim($datacenter));
            if (! str_starts_with($key, 'IGSNDB.')) {
                $key = 'IGSNDB.'.$key;
            }
            $entry = LegacyIgsnDatacenterCatalog::find($key);
            if ($entry === null) {
                throw new InvalidArgumentException(sprintf('Unknown legacy IGSN datacenter: "%s".', $datacenter));
            }
            $names[$entry['name']] = true;
        }

        return array_keys($names);
    }

    private function normalizeDoi(string $value): ?string
    {
        $value = trim($value);
        $value = preg_replace('#^(?:https?://(?:dx\.)?doi\.org/|doi:\s*)#i', '', $value) ?? $value;
        if (preg_match('#^10\.\d{4,9}/\S+$#i', $value) !== 1) {
            return null;
        }

        return mb_strtolower($value);
    }

    private function normalizeComparable(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $value) ?? (string) $value));
    }

    private function normalizeNumericComparable(mixed $value): string
    {
        $value = trim((string) $value);
        if (! is_numeric($value)) {
            return $this->normalizeComparable($value);
        }

        $normalized = rtrim(rtrim(sprintf('%.12F', (float) $value), '0'), '.');

        return $normalized === '-0' || $normalized === '' ? '0' : $normalized;
    }

    private function normalizeDateComparable(mixed $value, bool $preserveDateTime = false): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $matches) === 1) {
            $value = sprintf('%04d-%02d-%02d', (int) $matches[1], (int) $matches[2], (int) $matches[3]);
        }

        return DataCiteDateNormalizer::normalize($value, $preserveDateTime);
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private function displayValue(mixed $value): string
    {
        return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }

    private function isDataCiteSyncEligible(Resource $resource): bool
    {
        return is_string($resource->doi)
            && trim($resource->doi) !== ''
            && $resource->igsnMetadata?->upload_status === IgsnMetadata::STATUS_REGISTERED;
    }

    private function datacenterName(Resource $resource): string
    {
        if ($resource->datacenter_id === null) {
            return '';
        }

        $datacenter = $resource->datacenter;

        return $datacenter === null ? '' : (string) $datacenter->name;
    }

    /** @return array<string, int|string|null> */
    private function emptyRecord(Resource $resource, string $handle, string $status, string $message): array
    {
        return [
            'resource_id' => (int) $resource->id,
            'doi' => (string) $resource->doi,
            'handle' => $handle,
            'datacenter' => $this->datacenterName($resource),
            'schema_namespace' => '',
            'status' => $status,
            'changed_fields' => '',
            'existing_values' => '',
            'source_values' => '',
            'inserted_values' => '',
            'conflicts' => '',
            'unknown_paths' => '',
            'missing_dif' => $status === 'missing_dif' ? 1 : 0,
            'sample_image_status' => '',
            'sample_image_url' => null,
            'sample_image_message' => '',
            'datacite_sync_status' => 'not_queued',
            'sync_run_id' => null,
            'message' => $message,
        ];
    }
}
