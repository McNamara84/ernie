<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccessLevel;
use App\Models\Affiliation;
use App\Models\AlternateIdentifier;
use App\Models\ContributorType;
use App\Models\DateType;
use App\Models\GeoLocation;
use App\Models\IgsnClassification;
use App\Models\IgsnGeologicalAge;
use App\Models\IgsnGeologicalUnit;
use App\Models\IgsnMetadata;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceDate;
use App\Models\Size;
use App\Services\Igsn\IgsnDifMetadataExtractor;
use App\Services\Igsn\IgsnGeometryNormalizer;
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
    ) {}

    public function enrichFromDifXml(string $difXml, Resource $resource, IgsnMetadata $igsnMetadata): bool
    {
        $metadata = $this->extractor->extract($difXml);
        if ($metadata === null) {
            Log::warning('Failed to extract DIF XML metadata', ['resource_id' => $resource->id]);

            return false;
        }

        try {
            DB::transaction(function () use ($metadata, $resource, $igsnMetadata): void {
                $this->persistScalars($metadata, $resource, $igsnMetadata);
                $this->persistAlternateIdentifiers($metadata, $resource);
                $this->persistGeoLocation($metadata['location'], $resource);
                $this->persistCollectionDate($metadata['collection'], $resource);
                $this->persistCollector($metadata['collection'], $resource);
                $this->persistValueRelations($metadata, $resource);
                $this->persistSizes($metadata['sizes'], $resource);

                $igsnMetadata->save();
                $resource->save();
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

    /** @param array<string, mixed> $metadata */
    private function persistScalars(array $metadata, Resource $resource, IgsnMetadata $igsnMetadata): void
    {
        foreach ($metadata['scalars'] as $field => $value) {
            if ($value !== null) {
                $igsnMetadata->{$field} = $value;
            }
        }

        $description = $igsnMetadata->description_json ?? [];
        if ($metadata['parent_igsn'] !== null) {
            $description['parent_igsn_handle'] = strtoupper($metadata['parent_igsn']);
        }
        if ($metadata['comments'] !== []) {
            $description['comments'] = $this->mergeUnique($description['comments'] ?? [], $metadata['comments']);
        }
        $igsnMetadata->description_json = $description !== [] ? $description : null;

        if ($metadata['sample_access'] !== null) {
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

        $hasRange = $collection['start'] !== null && $collection['end'] !== null;
        ResourceDate::firstOrCreate([
            'resource_id' => $resource->id,
            'date_type_id' => $this->collectedDateTypeId,
            'date_value' => $hasRange ? null : ($collection['start'] ?? $collection['end']),
            'start_date' => $hasRange ? $collection['start'] : null,
            'end_date' => $hasRange ? $collection['end'] : null,
        ]);
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
        foreach ($metadata['classifications'] as $value) {
            IgsnClassification::firstOrCreate(['resource_id' => $resource->id, 'value' => $value]);
        }
        foreach ($metadata['geological_ages'] as $value) {
            IgsnGeologicalAge::firstOrCreate(['resource_id' => $resource->id, 'value' => $value]);
        }
        foreach ($metadata['geological_units'] as $value) {
            IgsnGeologicalUnit::firstOrCreate(['resource_id' => $resource->id, 'value' => $value]);
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
