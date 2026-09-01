<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccessLevel;
use App\Enums\Igsn\IgsnClassificationType;
use App\Models\Affiliation;
use App\Models\AlternateIdentifier;
use App\Models\ContributorType;
use App\Models\DateType;
use App\Models\FundingReference;
use App\Models\GeoLocation;
use App\Models\IdentifierType;
use App\Models\IgsnClassification;
use App\Models\IgsnGeologicalAge;
use App\Models\IgsnGeologicalUnit;
use App\Models\IgsnMetadata;
use App\Models\Institution;
use App\Models\Person;
use App\Models\RelatedIdentifier;
use App\Models\RelationType;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceDate;
use App\Models\Size;
use App\Services\Igsn\IgsnDifMetadataExtractor;
use App\Services\Igsn\IgsnGeometryNormalizer;
use App\Services\Igsn\IgsnSampleImageUrlService;
use App\Support\DataCiteDateNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Persists normalized legacy DIF metadata without duplicating DataCite fields.
 */
class IgsnDifXmlParser
{
    private ?int $collectedDateTypeId = null;

    private ?ContributorType $dataCollectorType = null;

    public function __construct(
        private readonly IgsnDifMetadataExtractor $extractor = new IgsnDifMetadataExtractor,
        private readonly IgsnGeometryNormalizer $geometryNormalizer = new IgsnGeometryNormalizer,
        private readonly IgsnSampleImageUrlService $sampleImageUrlService = new IgsnSampleImageUrlService,
    ) {}

    public function enrichFromDifXml(
        string $difXml,
        Resource $resource,
        IgsnMetadata $igsnMetadata,
        bool $additive = false,
    ): bool {
        try {
            $metadata = $this->extractor->extract($difXml);
        } catch (\InvalidArgumentException $exception) {
            Log::warning('Failed to normalize DIF XML metadata', [
                'resource_id' => $resource->id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        if ($metadata === null) {
            Log::warning('Failed to extract DIF XML metadata', ['resource_id' => $resource->id]);

            return false;
        }

        foreach ($metadata['rejected_classifications'] as $classification) {
            Log::warning('Skipped unsupported DIF classification', [
                'resource_id' => $resource->id,
                'material' => $classification['material'],
                'classification' => $classification['value'],
                'sample_index' => $classification['sample_index'],
            ]);
        }

        try {
            DB::transaction(function () use ($metadata, $resource, $igsnMetadata, $additive): void {
                $this->persistScalars($metadata, $resource, $igsnMetadata, $additive);
                $this->persistSampleImageDescriptor($metadata['sample_image'], $igsnMetadata, $additive);
                $this->persistAlternateIdentifiers($metadata, $resource);
                $this->persistGeoLocation($metadata['location'], $resource);
                $this->persistCollectionDate($metadata['collection'], $resource);
                $this->persistCollector($metadata['collection'], $resource);
                $this->persistValueRelations($metadata, $resource);
                $this->persistSizes($metadata['sizes'], $resource);
                $this->persistRelatedIdentifiers($metadata['root_related_identifiers'], $resource);
                $this->persistFundingReferences($metadata['funding_agencies'], $resource);
                $this->persistNamedDates($metadata['publish_dates'], 'Available', $resource);
                $this->persistNamedDates($metadata['sampling_dates'], 'Collected', $resource, preserveDateTime: true);
                $this->persistRootContributors($metadata['root_contributors'], $resource);
                $this->persistLegacyDif($metadata['legacy_dif'], $igsnMetadata, $additive);

                if ($igsnMetadata->isDirty()) {
                    $igsnMetadata->save();
                }
                if ($resource->isDirty()) {
                    $resource->save();
                }
            });

            return true;
        } catch (\Throwable $exception) {
            Log::warning('DIF XML enrichment failed', [
                'resource_id' => $resource->id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /** @param array{file_name: string|null, base_url: string|null} $image */
    private function persistSampleImageDescriptor(array $image, IgsnMetadata $igsnMetadata, bool $additive): void
    {
        if ($additive && ($igsnMetadata->sample_image_source_url !== null || $igsnMetadata->sample_image_external_url !== null)) {
            return;
        }

        $resolved = $this->sampleImageUrlService->resolve($image['base_url'], $image['file_name']);

        if ($resolved['status'] === IgsnSampleImageUrlService::STATUS_MANAGED) {
            $sourceChanged = $igsnMetadata->sample_image_source_url !== $resolved['source_url'];
            $igsnMetadata->sample_image_source_url = $resolved['source_url'];
            $igsnMetadata->sample_image_external_url = null;
            if ($sourceChanged) {
                $igsnMetadata->sample_image_storage_path = null;
                $igsnMetadata->sample_image_mime_type = null;
                $igsnMetadata->sample_image_size = null;
            }

            return;
        }

        if ($resolved['status'] === IgsnSampleImageUrlService::STATUS_EXTERNAL) {
            $igsnMetadata->sample_image_source_url = $resolved['source_url'];
            $igsnMetadata->sample_image_external_url = $resolved['external_url'];
            $igsnMetadata->sample_image_storage_path = null;
            $igsnMetadata->sample_image_mime_type = null;
            $igsnMetadata->sample_image_size = null;
        }
    }

    /** @param array<string, mixed> $metadata */
    private function persistScalars(array $metadata, Resource $resource, IgsnMetadata $igsnMetadata, bool $additive): void
    {
        foreach ($metadata['scalars'] as $field => $value) {
            if ($value !== null && (! $additive || $this->isEmptyStoredValue($igsnMetadata->{$field}))) {
                $igsnMetadata->{$field} = $value;
            }
        }

        $description = $igsnMetadata->description_json ?? [];
        if ($metadata['parent_igsn'] !== null && (! $additive || empty($description['parent_igsn_handle']))) {
            $description['parent_igsn_handle'] = strtoupper($metadata['parent_igsn']);
        }
        if ($additive) {
            $this->mergeDescriptionValues($description, 'material_descriptions', $metadata['material_descriptions']);
            $this->mergeDescriptionGroups($description, $metadata['description_groups']);
            $this->mergeDescriptionValues($description, 'comments', $metadata['comments']);
        } else {
            $this->replaceDescriptionValues($description, 'material_descriptions', $metadata['material_descriptions']);
            $this->replaceDescriptionGroups($description, $metadata['description_groups']);
            $this->replaceDescriptionValues($description, 'comments', $metadata['comments']);
        }
        $igsnMetadata->description_json = $description !== [] ? $description : null;

        if ($metadata['sample_access'] !== null && (! $additive || $this->isEmptyStoredValue($igsnMetadata->sample_access))) {
            $igsnMetadata->sample_access = $metadata['sample_access'];
            if ($resource->access_level === null) {
                $resource->access_level = AccessLevel::fromSampleAccess($metadata['sample_access']);
            }
        }
    }

    /** @param array<string, mixed> $metadata */
    private function persistAlternateIdentifiers(array $metadata, Resource $resource): void
    {
        if ($metadata['name'] !== null) {
            $existing = $this->matchingAlternateIdentifier($resource, $metadata['name']);
            if ($existing !== null && strcasecmp($existing->type, 'Local') === 0) {
                $existing->type = 'Local accession number';
                $existing->save();
            } elseif ($existing === null || strcasecmp($existing->type, 'Local accession number') !== 0) {
                $this->createAlternateIdentifier($resource, $metadata['name'], 'Local accession number');
            }
        }

        foreach ($metadata['other_names'] as $name) {
            if (! $this->hasAlternateIdentifier($resource, $name, 'Local sample name')) {
                $this->createAlternateIdentifier($resource, $name, 'Local sample name');
            }
        }
    }

    private function matchingAlternateIdentifier(Resource $resource, string $value): ?AlternateIdentifier
    {
        $normalized = $this->normalizeText($value);

        return $resource->alternateIdentifiers()->get()
            ->first(fn (AlternateIdentifier $identifier): bool => $this->normalizeText($identifier->value) === $normalized);
    }

    private function hasAlternateIdentifier(Resource $resource, string $value, string $type): bool
    {
        $normalizedValue = $this->normalizeText($value);

        return $resource->alternateIdentifiers()->get()->contains(
            fn (AlternateIdentifier $identifier): bool => $this->normalizeText($identifier->value) === $normalizedValue
                && strcasecmp($identifier->type, $type) === 0,
        );
    }

    private function createAlternateIdentifier(Resource $resource, string $value, string $type): void
    {
        $maximum = $resource->alternateIdentifiers()->max('position');
        AlternateIdentifier::create([
            'resource_id' => $resource->id,
            'value' => $value,
            'type' => $type,
            'position' => $maximum === null ? 0 : ((int) $maximum) + 1,
        ]);
    }

    /** @param array<string, mixed> $location */
    private function persistGeoLocation(array $location, Resource $resource): void
    {
        $text = [
            'place' => $location['place'] ?? $this->fallbackPlace($location),
            'location_type' => $location['location_type'],
            'location_description' => $location['location_description'],
            'locality_description' => $location['locality_description'],
            'country' => $location['country'],
            'province' => $location['province'],
            'county' => $location['county'],
            'city' => $location['city'],
            'elevation' => is_numeric($location['elevation']) ? (float) $location['elevation'] : null,
            'elevation_unit' => $location['elevation_unit'],
        ];
        $geometry = $this->geometryNormalizer->normalize($location['pairs']);
        $existing = $resource->geoLocations()->first();

        if ($existing !== null) {
            foreach ($text as $field => $value) {
                if ($existing->{$field} === null && $value !== null) {
                    $existing->{$field} = $value;
                }
            }
            if ($geometry !== null && ! $this->hasGeometry($existing)) {
                $this->replaceGeometry($existing, $geometry);
            }
            if ($existing->isDirty()) {
                $existing->save();
            }

            return;
        }

        $attributes = array_filter(
            array_merge($text, $geometry ?? []),
            static fn (mixed $value): bool => $value !== null,
        );
        if ($attributes !== []) {
            GeoLocation::create(['resource_id' => $resource->id, ...$attributes]);
        }
    }

    private function hasGeometry(GeoLocation $location): bool
    {
        return $location->hasPoint()
            || $location->hasBox()
            || $location->hasPolygon()
            || $location->hasLine();
    }

    /** @param array<string, mixed> $geometry */
    private function replaceGeometry(GeoLocation $location, array $geometry): void
    {
        foreach ([
            'geo_type',
            'point_latitude',
            'point_longitude',
            'south_bound_latitude',
            'north_bound_latitude',
            'west_bound_longitude',
            'east_bound_longitude',
            'polygon_points',
            'in_polygon_point_latitude',
            'in_polygon_point_longitude',
        ] as $field) {
            $location->{$field} = null;
        }

        $location->fill($geometry);
    }

    /** @param array<string, mixed> $location */
    private function fallbackPlace(array $location): ?string
    {
        $parts = array_values(array_filter([
            $location['city'],
            $location['province'],
            $location['country'],
        ], static fn (mixed $value): bool => is_string($value) && $value !== ''));

        return $parts !== [] ? implode(', ', array_unique($parts)) : null;
    }

    /** @param array<string, mixed> $collection */
    private function persistCollectionDate(array $collection, Resource $resource): void
    {
        if ($collection['start'] === null && $collection['end'] === null) {
            return;
        }

        $this->collectedDateTypeId ??= DateType::query()->where('name', 'Collected')->value('id');
        if ($this->collectedDateTypeId === null) {
            return;
        }

        $incoming = $this->canonicalCollectionPeriod($collection['start'], $collection['end']);
        if ($incoming === null) {
            return;
        }

        $exists = $resource->dates()
            ->where('date_type_id', $this->collectedDateTypeId)
            ->get()
            ->contains(fn (ResourceDate $date): bool => $this->canonicalStoredPeriod($date) === $incoming);
        if ($exists) {
            return;
        }

        ResourceDate::create([
            'resource_id' => $resource->id,
            'date_type_id' => $this->collectedDateTypeId,
            'date_value' => $incoming['date_value'],
            'start_date' => $incoming['start_date'],
            'end_date' => $incoming['end_date'],
        ]);
    }

    /**
     * @return array{date_value: string|null, start_date: string|null, end_date: string|null}|null
     */
    private function canonicalCollectionPeriod(mixed $start, mixed $end): ?array
    {
        $start = is_string($start) && trim($start) !== '' ? trim($start) : null;
        $end = is_string($end) && trim($end) !== '' ? trim($end) : null;

        if ($start === null && $end === null) {
            return null;
        }

        if ($start === null || $end === null || $start === $end) {
            return [
                'date_value' => $start ?? $end,
                'start_date' => null,
                'end_date' => null,
            ];
        }

        return [
            'date_value' => null,
            'start_date' => $start,
            'end_date' => $end,
        ];
    }

    /** @return array{date_value: string|null, start_date: string|null, end_date: string|null}|null */
    private function canonicalStoredPeriod(ResourceDate $date): ?array
    {
        if ($date->date_value !== null) {
            return $this->canonicalCollectionPeriod($date->date_value, null);
        }

        return $this->canonicalCollectionPeriod($date->start_date, $date->end_date);
    }

    /** @param array<string, mixed> $collection */
    private function persistCollector(array $collection, Resource $resource): void
    {
        $collector = $collection['collector'];
        if (! is_string($collector) || $collector === '') {
            return;
        }

        $this->dataCollectorType ??= ContributorType::query()->where('slug', 'DataCollector')->first();
        if ($this->dataCollectorType === null) {
            return;
        }

        $entity = $this->matchingCreatorEntity($resource, $collector) ?? $this->createCollectorEntity($collector);
        $relation = $resource->contributors()->with('contributorTypes')->get()->first(
            fn (ResourceContributor $contributor): bool => $contributor->contributorable_type === $entity::class
                && $contributor->contributorable_id === $entity->getKey()
                && $contributor->contributorTypes->contains('id', $this->dataCollectorType->id),
        );

        if ($relation === null) {
            $maximum = $resource->contributors()->max('position');
            $relation = ResourceContributor::create([
                'resource_id' => $resource->id,
                'contributorable_type' => $entity::class,
                'contributorable_id' => $entity->getKey(),
                'position' => $maximum === null ? 0 : ((int) $maximum) + 1,
            ]);
            $relation->contributorTypes()->syncWithoutDetaching([$this->dataCollectorType->id]);
        }

        if (is_string($collection['collector_detail']) && $collection['collector_detail'] !== '') {
            Affiliation::firstOrCreate([
                'affiliatable_type' => ResourceContributor::class,
                'affiliatable_id' => $relation->id,
                'name' => $collection['collector_detail'],
            ]);
        }
    }

    private function matchingCreatorEntity(Resource $resource, string $collector): ?Model
    {
        $target = $this->normalizePersonName($collector);
        foreach ($resource->creators()->with('creatorable')->get() as $creator) {
            $entity = $creator->creatorable;
            $name = $entity instanceof Person ? $entity->full_name : ($entity->name ?? '');
            if ($this->normalizePersonName($name) === $target) {
                return $entity;
            }
        }

        return null;
    }

    private function createCollectorEntity(string $collector): Model
    {
        if (str_contains($collector, ',')) {
            [$familyName, $givenName] = array_pad(array_map('trim', explode(',', $collector, 2)), 2, null);

            return Person::firstOrCreate(['family_name' => $familyName, 'given_name' => $givenName]);
        }

        return Institution::firstOrCreate(['name' => $collector]);
    }

    /** @param array<string, mixed> $metadata */
    private function persistValueRelations(array $metadata, Resource $resource): void
    {
        $this->persistClassifications(
            $metadata['classifications'],
            $resource,
        );
        $this->persistPositionedValues(IgsnGeologicalAge::class, $metadata['geological_ages'], $resource);
        $this->persistPositionedValues(IgsnGeologicalUnit::class, $metadata['geological_units'], $resource);
    }

    /**
     * @param  list<array{value: string, classification_type: IgsnClassificationType|null}>  $items
     */
    private function persistClassifications(array $items, Resource $resource): void
    {
        $maximum = IgsnClassification::query()->where('resource_id', $resource->id)->max('position');
        $nextPosition = $maximum === null ? 0 : ((int) $maximum) + 1;

        foreach ($items as $item) {
            $classification = IgsnClassification::firstOrCreate(
                ['resource_id' => $resource->id, 'value' => $item['value']],
                ['classification_type' => $item['classification_type'], 'position' => $nextPosition],
            );

            if ($classification->wasRecentlyCreated) {
                $nextPosition++;
            } elseif ($item['classification_type'] !== null) {
                IgsnClassification::query()
                    ->whereKey($classification->id)
                    ->whereNull('classification_type')
                    ->update([
                        'classification_type' => $item['classification_type']->value,
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    /**
     * @param  class-string<IgsnClassification|IgsnGeologicalAge|IgsnGeologicalUnit>  $modelClass
     * @param  list<string>  $values
     */
    private function persistPositionedValues(string $modelClass, array $values, Resource $resource): void
    {
        $maximum = $modelClass::query()->where('resource_id', $resource->id)->max('position');
        $nextPosition = $maximum === null ? 0 : ((int) $maximum) + 1;

        foreach ($values as $value) {
            $model = $modelClass::firstOrCreate(
                ['resource_id' => $resource->id, 'value' => $value],
                ['position' => $nextPosition],
            );
            if ($model->wasRecentlyCreated) {
                $nextPosition++;
            }
        }
    }

    /** @param list<array{numeric_value: string, unit: string|null, type: string|null}> $sizes */
    private function persistSizes(array $sizes, Resource $resource): void
    {
        foreach ($sizes as $size) {
            Size::firstOrCreate(['resource_id' => $resource->id, ...$size]);
        }
    }

    /**
     * @param  list<array{identifier: string, identifier_type: string, relation_type: string}>  $identifiers
     */
    private function persistRelatedIdentifiers(array $identifiers, Resource $resource): void
    {
        if ($identifiers === []) {
            return;
        }

        $doiTypeId = IdentifierType::query()
            ->where('slug', 'DOI')
            ->orWhere('name', 'DOI')
            ->value('id');
        $citesTypeId = RelationType::query()
            ->where('slug', 'Cites')
            ->orWhere('name', 'Cites')
            ->value('id');
        if ($doiTypeId === null || $citesTypeId === null) {
            throw new \RuntimeException('DOI/Cites lookup values are required for legacy IGSN relations.');
        }

        $nextPosition = ((int) ($resource->relatedIdentifiers()->max('position') ?? -1)) + 1;
        foreach ($identifiers as $identifier) {
            if (strcasecmp($identifier['identifier_type'], 'DOI') !== 0
                || strcasecmp($identifier['relation_type'], 'hasDocument') !== 0) {
                continue;
            }

            $doi = $this->normalizeDoi($identifier['identifier']);
            if ($doi === null) {
                continue;
            }

            $exists = $resource->relatedIdentifiers()
                ->where('identifier_type_id', $doiTypeId)
                ->where('relation_type_id', $citesTypeId)
                ->get()
                ->contains(function (RelatedIdentifier $related) use ($doi): bool {
                    $existingDoi = $this->normalizeDoi($related->identifier);

                    return $existingDoi !== null && strcasecmp($existingDoi, $doi) === 0;
                });
            if ($exists) {
                continue;
            }

            RelatedIdentifier::create([
                'resource_id' => $resource->id,
                'identifier' => $doi,
                'identifier_type_id' => $doiTypeId,
                'relation_type_id' => $citesTypeId,
                'source' => RelatedIdentifier::SOURCE_LEGACY_IGSN_DIF,
                'position' => $nextPosition++,
            ]);
        }
    }

    /** @param list<string> $funders */
    private function persistFundingReferences(array $funders, Resource $resource): void
    {
        foreach ($funders as $funder) {
            $normalized = $this->normalizeText($funder);
            $exists = $resource->fundingReferences()->get()->contains(
                fn (FundingReference $reference): bool => $this->normalizeText($reference->funder_name) === $normalized,
            );
            if (! $exists) {
                FundingReference::create([
                    'resource_id' => $resource->id,
                    'funder_name' => trim($funder),
                ]);
            }
        }
    }

    /** @param list<string> $values */
    private function persistNamedDates(
        array $values,
        string $type,
        Resource $resource,
        bool $preserveDateTime = false,
    ): void {
        if ($values === []) {
            return;
        }

        $dateTypeId = DateType::query()
            ->where('slug', $type)
            ->orWhere('name', $type)
            ->value('id');
        if ($dateTypeId === null) {
            return;
        }

        foreach ($values as $value) {
            $normalized = $this->normalizeLegacyDate($value, $preserveDateTime);
            if ($normalized === null) {
                continue;
            }
            $exists = $resource->dates()
                ->where('date_type_id', $dateTypeId)
                ->where('date_value', $normalized)
                ->whereNull('start_date')
                ->whereNull('end_date')
                ->exists();
            if (! $exists) {
                ResourceDate::create([
                    'resource_id' => $resource->id,
                    'date_type_id' => $dateTypeId,
                    'date_value' => $normalized,
                ]);
            }
        }
    }

    /**
     * @param  list<array{type: string|null, name: string, affiliations: list<string>, identifiers: list<string>}>  $contributors
     */
    private function persistRootContributors(array $contributors, Resource $resource): void
    {
        foreach ($contributors as $contributor) {
            $sourceType = $contributor['type'];
            if ($sourceType === null || strcasecmp($sourceType, 'Funder') === 0) {
                continue;
            }

            $type = ContributorType::query()
                ->where('slug', $sourceType)
                ->orWhere('name', $sourceType)
                ->first();
            if (! $type instanceof ContributorType) {
                continue;
            }

            $relation = $resource->contributors()
                ->with(['contributorable', 'contributorTypes'])
                ->get()
                ->first(function (ResourceContributor $existing) use ($contributor, $type): bool {
                    $entity = $existing->contributorable;
                    $name = $entity instanceof Person ? $entity->full_name : ($entity->name ?? '');

                    return $this->normalizePersonName((string) $name) === $this->normalizePersonName($contributor['name'])
                        && $existing->contributorTypes->contains('id', $type->id);
                });

            if (! $relation instanceof ResourceContributor) {
                $entity = $this->createLegacyContributorEntity($contributor['name'], $sourceType);
                $maximum = $resource->contributors()->max('position');
                $relation = ResourceContributor::create([
                    'resource_id' => $resource->id,
                    'contributorable_type' => $entity::class,
                    'contributorable_id' => $entity->getKey(),
                    'position' => $maximum === null ? 0 : ((int) $maximum) + 1,
                ]);
                $relation->contributorTypes()->syncWithoutDetaching([$type->id]);
            }

            foreach ($contributor['affiliations'] as $affiliation) {
                Affiliation::firstOrCreate([
                    'affiliatable_type' => ResourceContributor::class,
                    'affiliatable_id' => $relation->id,
                    'name' => $affiliation,
                ]);
            }
        }
    }

    private function createLegacyContributorEntity(string $name, string $type): Model
    {
        if (strcasecmp($type, 'ProjectLeader') !== 0) {
            return Institution::firstOrCreate(['name' => trim($name)]);
        }

        if (str_contains($name, ',')) {
            [$familyName, $givenName] = array_pad(array_map('trim', explode(',', $name, 2)), 2, null);

            return Person::firstOrCreate(['family_name' => $familyName, 'given_name' => $givenName]);
        }

        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $familyName = array_pop($parts) ?? trim($name);
        $givenName = $parts !== [] ? implode(' ', $parts) : null;

        return Person::firstOrCreate(['family_name' => $familyName, 'given_name' => $givenName]);
    }

    /** @param array<string, mixed> $legacy */
    private function persistLegacyDif(array $legacy, IgsnMetadata $metadata, bool $additive): void
    {
        $payload = $additive
            ? $this->mergeLegacyPayload($metadata->legacy_dif_json ?? [], $legacy)
            : $legacy;

        if ($payload !== ($metadata->legacy_dif_json ?? [])) {
            $metadata->legacy_dif_json = $payload;
            $metadata->legacy_dif_imported_at = now();
        }
        if (! $additive || $metadata->legacy_dif_schema_namespace === null) {
            $metadata->legacy_dif_schema_namespace = $legacy['schema_namespace'] ?? null;
        }
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeLegacyPayload(array $existing, array $incoming): array
    {
        if ($existing === []) {
            return $incoming;
        }

        $merged = $existing;
        foreach ($incoming as $key => $value) {
            if (! array_key_exists($key, $merged) || $merged[$key] === null || $merged[$key] === []) {
                $merged[$key] = $value;

                continue;
            }
            if (! is_array($merged[$key]) || ! is_array($value)) {
                continue;
            }

            if (array_is_list($merged[$key]) && array_is_list($value)) {
                $merged[$key] = $this->mergeStructuredList($merged[$key], $value);
            } else {
                $merged[$key] = $this->mergeLegacyPayload($merged[$key], $value);
            }
        }

        return $merged;
    }

    /** @param list<mixed> $existing
     * @param  list<mixed>  $incoming
     * @return list<mixed>
     */
    private function mergeStructuredList(array $existing, array $incoming): array
    {
        $result = $existing;
        $seen = [];
        foreach ($existing as $value) {
            $seen[$this->structuredValueKey($value)] = true;
        }
        foreach ($incoming as $value) {
            $key = $this->structuredValueKey($value);
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $value;
            }
        }

        return $result;
    }

    private function structuredValueKey(mixed $value): string
    {
        if (is_array($value)) {
            ksort($value);
        }

        return $this->normalizeText(json_encode($value, JSON_THROW_ON_ERROR));
    }

    private function normalizeLegacyDate(string $value, bool $preserveDateTime): ?string
    {
        $value = trim($value);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $matches) === 1) {
            $value = sprintf('%04d-%02d-%02d', (int) $matches[1], (int) $matches[2], (int) $matches[3]);
        }

        return DataCiteDateNormalizer::normalize($value, $preserveDateTime);
    }

    private function normalizeDoi(string $value): ?string
    {
        $value = trim($value);
        $value = preg_replace('#^(?:https?://(?:dx\.)?doi\.org/|doi:\s*)#i', '', $value) ?? $value;
        if (preg_match('#^10\.\d{4,9}/\S+$#i', $value) !== 1) {
            return null;
        }

        return $value;
    }

    private function isEmptyStoredValue(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    /**
     * @param  array<int, mixed>  $existing
     * @param  list<string>  $incoming
     * @return list<string>
     */
    private function mergeUnique(array $existing, array $incoming): array
    {
        $result = [];
        foreach (array_merge($existing, $incoming) as $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }
            $result[$this->normalizeText($value)] = trim($value);
        }

        return array_values($result);
    }

    /**
     * @param  array<mixed>  $description
     * @param  list<string>  $values
     */
    private function replaceDescriptionValues(array &$description, string $key, array $values): void
    {
        if ($values === []) {
            unset($description[$key]);

            return;
        }

        $description[$key] = $this->mergeUnique([], $values);
    }

    /**
     * @param  array<mixed>  $description
     * @param  list<array{entries: list<array{value: string, scheme: string|null}>}>  $groups
     */
    private function replaceDescriptionGroups(array &$description, array $groups): void
    {
        if ($groups === []) {
            unset($description['description_groups']);

            return;
        }

        $description['description_groups'] = $groups;
    }

    /**
     * @param  array<mixed>  $description
     * @param  list<string>  $values
     */
    private function mergeDescriptionValues(array &$description, string $key, array $values): void
    {
        if ($values === []) {
            return;
        }

        $existing = is_array($description[$key] ?? null) ? $description[$key] : [];
        $description[$key] = $this->mergeUnique($existing, $values);
    }

    /**
     * @param  array<mixed>  $description
     * @param  list<array{entries: list<array{value: string, scheme: string|null}>}>  $groups
     */
    private function mergeDescriptionGroups(array &$description, array $groups): void
    {
        if ($groups === []) {
            return;
        }

        $existing = is_array($description['description_groups'] ?? null)
            ? array_values($description['description_groups'])
            : [];
        $description['description_groups'] = $this->mergeStructuredList($existing, $groups);
    }

    private function normalizeText(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
    }

    private function normalizePersonName(string $value): string
    {
        $parts = preg_split('/[\s,]+/u', $this->normalizeText($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        sort($parts);

        return implode('|', $parts);
    }
}
