<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccessLevel;
use App\Enums\ContributorCategory;
use App\Enums\Igsn\IgsnClassificationType;
use App\Enums\Igsn\IgsnMeasurementType;
use App\Enums\Igsn\IgsnMetadataValueType;
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
use App\Models\IgsnMeasurement;
use App\Models\IgsnMetadata;
use App\Models\IgsnMetadataValue;
use App\Models\IgsnMethod;
use App\Models\IgsnOperator;
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
use App\Support\IgsnLocationNormalizer;
use App\Support\OrcidNormalizer;
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
                $this->persistNamedDates(
                    $metadata['publish_dates'],
                    'Available',
                    $resource,
                    dateInformation: 'Legacy IGSN publish date',
                );
                $this->persistNamedDates(
                    $metadata['sampling_dates'],
                    'Collected',
                    $resource,
                    preserveDateTime: true,
                    dateInformation: 'Legacy IGSN sampling date',
                );
                $this->persistRootContributors($metadata['root_contributors'], $resource);
                $this->persistLegacyRelations($metadata, $resource, $igsnMetadata);

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

        if ($metadata['sample_access'] !== null) {
            if (! $additive || $this->isEmptyStoredValue($igsnMetadata->sample_access)) {
                $igsnMetadata->sample_access = $metadata['sample_access'];
            }
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
            'place' => IgsnLocationNormalizer::place($location),
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

        $existing = $resource->dates()
            ->where('date_type_id', $this->collectedDateTypeId)
            ->get()
            ->first(fn (ResourceDate $date): bool => $this->canonicalStoredPeriod($date) === $incoming);
        if ($existing !== null) {
            $this->appendDateInformation($existing, 'Legacy IGSN collection period');

            return;
        }

        ResourceDate::create([
            'resource_id' => $resource->id,
            'date_type_id' => $this->collectedDateTypeId,
            'date_value' => $incoming['date_value'],
            'start_date' => $incoming['start_date'],
            'end_date' => $incoming['end_date'],
            'date_information' => 'Legacy IGSN collection period',
        ]);
    }

    /**
     * @return array{date_value: string|null, start_date: string|null, end_date: string|null}|null
     */
    private function canonicalCollectionPeriod(mixed $start, mixed $end): ?array
    {
        $start = is_string($start) ? $this->normalizeLegacyDate($start, true) : null;
        $end = is_string($end) ? $this->normalizeLegacyDate($end, true) : null;

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

        $this->persistContributorAffiliation($relation, $collection['collector_detail']);
    }

    private function matchingCreatorEntity(Resource $resource, string $collector): ?Model
    {
        $target = $this->normalizePersonName($collector);
        foreach ($resource->creators()->with('creatorable')->get() as $creator) {
            $entity = $creator->creatorable;
            if (! $entity instanceof Person) {
                continue;
            }
            $name = $entity->full_name;
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

        $parts = preg_split('/\s+/u', trim($collector), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $familyName = array_pop($parts) ?? trim($collector);
        $givenName = $parts !== [] ? implode(' ', $parts) : null;

        return Person::firstOrCreate(['family_name' => $familyName, 'given_name' => $givenName]);
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
        ?string $dateInformation = null,
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
            $existing = $resource->dates()
                ->where('date_type_id', $dateTypeId)
                ->whereNull('start_date')
                ->whereNull('end_date')
                ->get()
                ->first(fn (ResourceDate $date): bool => is_string($date->date_value)
                    && $this->normalizeLegacyDate($date->date_value, $preserveDateTime) === $normalized);
            if ($existing !== null) {
                $this->appendDateInformation($existing, $dateInformation);
            } else {
                ResourceDate::create([
                    'resource_id' => $resource->id,
                    'date_type_id' => $dateTypeId,
                    'date_value' => $normalized,
                    'date_information' => $dateInformation,
                ]);
            }
        }
    }

    private function appendDateInformation(ResourceDate $date, ?string $information): void
    {
        if ($information === null || $information === '') {
            return;
        }
        $existing = is_string($date->date_information) ? trim($date->date_information) : '';
        $parts = array_values(array_filter(array_map('trim', explode(';', $existing))));
        if (collect($parts)->contains(fn (string $part): bool => strcasecmp($part, $information) === 0)) {
            return;
        }
        $parts[] = $information;
        $date->date_information = implode('; ', $parts);
        $date->save();
    }

    /**
     * @param  list<array{type: string|null, name: string, affiliations: list<string>, identifiers: list<string>}>  $contributors
     */
    private function persistRootContributors(array $contributors, Resource $resource): void
    {
        foreach ($contributors as $contributor) {
            $sourceType = $contributor['type'];
            if ($sourceType === null || strcasecmp($sourceType, 'ProjectLeader') !== 0) {
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
                    if ($type->category === ContributorCategory::PERSON && ! $entity instanceof Person) {
                        return false;
                    }
                    if ($type->category === ContributorCategory::INSTITUTION && ! $entity instanceof Institution) {
                        return false;
                    }
                    $name = $entity instanceof Person ? $entity->full_name : ($entity->name ?? '');

                    return $this->normalizePersonName((string) $name) === $this->normalizePersonName($contributor['name'])
                        && $existing->contributorTypes->contains('id', $type->id);
                });

            if (! $relation instanceof ResourceContributor) {
                $entity = $this->createLegacyContributorEntity($contributor['name'], $type);
                $maximum = $resource->contributors()->max('position');
                $relation = ResourceContributor::create([
                    'resource_id' => $resource->id,
                    'contributorable_type' => $entity::class,
                    'contributorable_id' => $entity->getKey(),
                    'position' => $maximum === null ? 0 : ((int) $maximum) + 1,
                ]);
                $relation->contributorTypes()->syncWithoutDetaching([$type->id]);
            }

            $entity = $relation->contributorable;
            if ($entity instanceof Person && $this->isEmptyStoredValue($entity->name_identifier)) {
                foreach ($contributor['identifiers'] as $identifier) {
                    $orcid = $this->normalizeOrcid($identifier);
                    if ($orcid === null) {
                        continue;
                    }
                    $entity->update([
                        'name_identifier' => $orcid,
                        'name_identifier_scheme' => 'ORCID',
                        'scheme_uri' => 'https://orcid.org',
                    ]);
                    break;
                }
            }

            foreach ($contributor['affiliations'] as $affiliation) {
                $this->persistContributorAffiliation($relation, $affiliation);
            }
        }
    }

    private function persistContributorAffiliation(ResourceContributor $contributor, mixed $value): void
    {
        if (! is_string($value)) {
            return;
        }

        $name = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        if ($name === '' || strcasecmp($name, 'N/A') === 0) {
            return;
        }

        $normalized = $this->normalizeText($name);
        $exists = $contributor->affiliations()->get()->contains(
            fn (Affiliation $affiliation): bool => $this->normalizeText($affiliation->name) === $normalized,
        );
        if ($exists) {
            return;
        }

        Affiliation::create([
            'affiliatable_type' => ResourceContributor::class,
            'affiliatable_id' => $contributor->id,
            'name' => $name,
        ]);
    }

    private function createLegacyContributorEntity(string $name, ContributorType $type): Model
    {
        if ($type->category === ContributorCategory::INSTITUTION) {
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

    private function normalizeOrcid(string $value): ?string
    {
        if (! OrcidNormalizer::isValid($value)) {
            return null;
        }

        return 'https://orcid.org/'.strtoupper(OrcidNormalizer::extractBareId($value));
    }

    /** @param array<string, mixed> $metadata */
    private function persistLegacyRelations(array $metadata, Resource $resource, IgsnMetadata $igsnMetadata): void
    {
        /** @var list<array{numeric_value: string, unit: string|null}> $totalLengths */
        $totalLengths = $metadata['total_lengths'];
        /** @var list<array{start: string|null, end: string|null, unit: string|null, end_unit: string|null}> $ageRanges */
        $ageRanges = $metadata['age_ranges'];
        /** @var list<array{start: string|null, end: string|null, unit: string|null, end_unit: string|null}> $elevationRanges */
        $elevationRanges = $metadata['elevation_ranges'];
        $operators = $metadata['operators'];
        if (is_string($igsnMetadata->operator) && trim($igsnMetadata->operator) !== '') {
            array_unshift($operators, trim($igsnMetadata->operator));
        }

        $this->persistOperators($resource, $operators);
        $this->persistMethods($resource, $metadata['methods']);
        $this->persistMeasurements($resource, IgsnMeasurementType::TotalLength, array_map(
            static fn (array $item): array => [
                'start_value' => $item['numeric_value'],
                'end_value' => null,
                'unit' => $item['unit'],
                'end_unit' => null,
            ],
            $totalLengths,
        ));
        $this->persistMeasurements($resource, IgsnMeasurementType::AgeRange, array_map(
            static fn (array $item): array => [
                'start_value' => $item['start'],
                'end_value' => $item['end'],
                'unit' => $item['unit'],
                'end_unit' => $item['end_unit'],
            ],
            $ageRanges,
        ));
        $this->persistMeasurements($resource, IgsnMeasurementType::ElevationRange, array_map(
            static fn (array $item): array => [
                'start_value' => $item['start'],
                'end_value' => $item['end'],
                'unit' => $item['unit'],
                'end_unit' => $item['end_unit'],
            ],
            $elevationRanges,
        ));

        foreach ($metadata['metadata_values'] as $type => $values) {
            $this->persistMetadataValues($resource, IgsnMetadataValueType::from($type), $values);
        }
    }

    /**
     * @param  list<string>  $values
     */
    private function persistOperators(Resource $resource, array $values): void
    {
        $nextPosition = ((int) ($resource->igsnOperators()->max('position') ?? -1)) + 1;
        foreach ($values as $value) {
            $value = trim($value);
            if ($value === '') {
                continue;
            }
            $operator = IgsnOperator::firstOrCreate(
                [
                    'resource_id' => $resource->id,
                    'normalized_value_hash' => $this->valueHash([$value]),
                ],
                ['value' => $value, 'position' => $nextPosition],
            );
            if ($operator->wasRecentlyCreated) {
                $nextPosition++;
            }
        }
    }

    /** @param list<array{scheme: string|null, value: string}> $methods */
    private function persistMethods(Resource $resource, array $methods): void
    {
        $nextPosition = ((int) ($resource->igsnMethods()->max('position') ?? -1)) + 1;
        foreach ($methods as $method) {
            $value = trim($method['value']);
            if ($value === '') {
                continue;
            }
            $scheme = is_string($method['scheme']) && trim($method['scheme']) !== ''
                ? trim($method['scheme'])
                : null;
            $model = IgsnMethod::firstOrCreate(
                [
                    'resource_id' => $resource->id,
                    'normalized_value_hash' => $this->valueHash([$scheme, $value]),
                ],
                ['scheme' => $scheme, 'value' => $value, 'position' => $nextPosition],
            );
            if ($model->wasRecentlyCreated) {
                $nextPosition++;
            }
        }
    }

    /**
     * @param  list<array{start_value: string|null, end_value: string|null, unit: string|null, end_unit: string|null}>  $items
     */
    private function persistMeasurements(Resource $resource, IgsnMeasurementType $type, array $items): void
    {
        $nextPosition = ((int) ($resource->igsnMeasurements()->where('type', $type->value)->max('position') ?? -1)) + 1;
        foreach ($items as $item) {
            $parts = [$item['start_value'], $item['end_value'], $item['unit'], $item['end_unit']];
            if (array_filter($parts, static fn (?string $value): bool => is_string($value) && trim($value) !== '') === []) {
                continue;
            }
            $model = IgsnMeasurement::firstOrCreate(
                [
                    'resource_id' => $resource->id,
                    'type' => $type->value,
                    'normalized_value_hash' => $this->valueHash($parts),
                ],
                [...$item, 'position' => $nextPosition],
            );
            if ($model->wasRecentlyCreated) {
                $nextPosition++;
            }
        }
    }

    /** @param list<string> $values */
    private function persistMetadataValues(Resource $resource, IgsnMetadataValueType $type, array $values): void
    {
        $nextPosition = ((int) ($resource->igsnMetadataValues()->where('type', $type->value)->max('position') ?? -1)) + 1;
        foreach ($values as $value) {
            $value = trim($value);
            if ($value === '') {
                continue;
            }
            $model = IgsnMetadataValue::firstOrCreate(
                [
                    'resource_id' => $resource->id,
                    'type' => $type->value,
                    'normalized_value_hash' => $this->valueHash([$value]),
                ],
                ['value' => $value, 'position' => $nextPosition],
            );
            if ($model->wasRecentlyCreated) {
                $nextPosition++;
            }
        }
    }

    /** @param list<string|null> $parts */
    private function valueHash(array $parts): string
    {
        return hash('sha256', implode("\x1f", array_map(
            fn (?string $value): string => $this->normalizeText((string) $value),
            $parts,
        )));
    }

    /**
     * @param  list<mixed>  $existing
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
