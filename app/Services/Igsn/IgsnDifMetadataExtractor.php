<?php

declare(strict_types=1);

namespace App\Services\Igsn;

use App\Enums\Igsn\IgsnClassificationType;
use App\Support\DataCiteDateNormalizer;

/**
 * Extracts legacy DIF XML into a normalized, persistence-free structure.
 */
class IgsnDifMetadataExtractor
{
    /** @var list<string> */
    private const KNOWN_LEAF_NAMES = [
        'identifier', 'name', 'parentIdentifier', 'relatedIdentifier', 'description',
        'resourceType', 'material', 'alternateMaterial', 'collectionMethod',
        'alternateCollectionMethod', 'collectionTime', 'sampleAccess',
        'user_code', 'sample_type', 'igsn', 'parent_igsn', 'is_private', 'publish_date',
        'latitude', 'longitude', 'latitude_end', 'longitude_end', 'end_latitude',
        'end_longitude', 'max_latitude', 'max_longitude', 'coordinate_system',
        'elevation', 'elevation_end', 'elevation_unit', 'elevation_end_unit',
        'sampling_date', 'primary_location_type', 'primary_location_name',
        'location_type', 'location_description', 'locality', 'locality_description',
        'country', 'province', 'county', 'city', 'classification',
        'classification_comment', 'field_name', 'depth_min', 'depth_max', 'depth_scale',
        'size', 'size_unit', 'age_min', 'age_max', 'age_unit', 'geological_age',
        'geological_unit', 'method', 'collection_method', 'collection_method_descr',
        'collection_method_description', 'length', 'length_unit', 'sample_comment',
        'comment', 'cruise_field_prgrm', 'cruise_field_program', 'platform_type',
        'platform_name', 'platform_descr', 'platform_description', 'operator',
        'funding_agency', 'collector', 'collector_detail', 'collection_start_date',
        'collection_end_date', 'collection_date_precision', 'current_archive',
        'current_archive_contact', 'original_archive', 'original_archive_contact',
        'sample_other_name', 'sample_other_names', 'sample_purpose', 'sample_request',
        'sampled_by', 'sample_access', 'elevationUnit', 'navigation_type', 'launch_platform_name', 'launch_type_name',
        'sample_image', 'sample_image_path', 'external_url',
        '@type', '@publishdate', '@xsi:schemaLocation', '@schemaLocation',
        '@contributorType',
    ];

    /** @var list<string> */
    private const KNOWN_SAMPLE_DIRECT_NAMES = [
        'name', 'description', 'resourceType', 'material', 'alternateMaterial', 'collectionMethod',
        'alternateCollectionMethod', 'collectionTime', 'user_code', 'sample_type', 'igsn', 'parent_igsn',
        'is_private', 'publish_date', 'latitude', 'longitude', 'latitude_end',
        'longitude_end', 'end_latitude', 'end_longitude', 'max_latitude', 'max_longitude',
        'coordinate_system', 'elevation', 'elevation_end', 'elevation_unit', 'elevationUnit',
        'elevation_end_unit', 'sampling_date', 'primary_location_type',
        'primary_location_name', 'location_type', 'location_description', 'locality',
        'locality_description', 'country', 'province', 'county', 'city', 'classification',
        'classification_comment', 'field_name', 'depth_min', 'depth_max', 'depth_scale',
        'size', 'size_unit', 'age_min', 'age_max', 'age_unit', 'geological_age',
        'geological_unit', 'collection_method', 'collection_method_descr',
        'collection_method_description', 'length', 'length_unit', 'sample_comment',
        'comment', 'cruise_field_prgrm', 'cruise_field_program', 'platform_type',
        'platform_name', 'platform_descr', 'platform_description', 'operator',
        'funding_agency', 'collector', 'collector_detail', 'collection_start_date',
        'collection_end_date', 'collection_date_precision', 'current_archive',
        'current_archive_contact', 'original_archive', 'original_archive_contact',
        'sample_other_name', 'sample_other_names', 'sample_purpose', 'sample_request', 'sample_access',
        'sampled_by', 'navigation_type', 'launch_platform_name', 'launch_type_name',
        'sample_image', 'sample_image_path', 'external_url', '@publishdate',
    ];

    public function __construct(
        private readonly IgsnVocabularyNormalizerService $vocabularyNormalizer = new IgsnVocabularyNormalizerService,
        private readonly IgsnDescriptionNormalizerService $descriptionNormalizer = new IgsnDescriptionNormalizerService,
        private readonly IgsnLegacyDifSerializerService $legacySerializer = new IgsnLegacyDifSerializerService,
    ) {}

    /**
     * Extract only the fields covered by Issue #1167. This deliberately avoids
     * validating unrelated controlled vocabularies during the targeted backfill.
     *
     * @return array{description_groups: list<array{entries: list<array{value: string, scheme: string|null}>}>, material_descriptions: list<string>, locality_description: string|null}|null
     */
    public function extractDescriptionFields(string $difXml): ?array
    {
        $root = @simplexml_load_string($difXml, \SimpleXMLElement::class, LIBXML_NONET);
        if ($root === false) {
            return null;
        }

        $samples = $root->xpath('//*[local-name()="sample"]');
        if (! is_array($samples) || $samples === []) {
            return null;
        }

        $groups = $this->descriptionGroups($root, $samples[0]);

        return [
            'description_groups' => $groups,
            'material_descriptions' => $this->descriptionNormalizer->legacyValues($groups),
            'locality_description' => $this->first($samples[0], ['locality_description']),
        ];
    }

    /**
     * Extract only the first sample's image descriptor without validating any
     * unrelated controlled vocabulary.
     *
     * @return array{file_name: string|null, base_url: string|null}|null
     */
    public function extractImageFields(string $difXml): ?array
    {
        $root = @simplexml_load_string($difXml, \SimpleXMLElement::class, LIBXML_NONET);
        if ($root === false) {
            return null;
        }

        $samples = $root->xpath('//*[local-name()="sample"]');
        if (! is_array($samples) || $samples === []) {
            return null;
        }

        return $this->imageFields($samples[0]);
    }

    /**
     * Extract classifications from every sample block without validating or
     * changing any unrelated DIF metadata.
     *
     * @return array{
     *     items: list<array{value: string, classification_type: IgsnClassificationType|null}>,
     *     rejected: list<array{value: string, material: string|null, sample_index: int}>
     * }|null
     */
    public function extractClassificationFields(string $difXml): ?array
    {
        $root = @simplexml_load_string($difXml, \SimpleXMLElement::class, LIBXML_NONET);
        if ($root === false) {
            return null;
        }

        $samples = $root->xpath('//*[local-name()="sample"]');
        if (! is_array($samples) || $samples === []) {
            return null;
        }

        return $this->classificationFields($samples);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function extract(string $difXml): ?array
    {
        $root = @simplexml_load_string($difXml, \SimpleXMLElement::class, LIBXML_NONET);
        $legacy = $this->legacySerializer->serialize($difXml);
        if ($root === false || $legacy === null) {
            return null;
        }

        $samples = $root->xpath('//*[local-name()="sample"]');
        if (! is_array($samples) || $samples === []) {
            return null;
        }

        $conflicts = [];
        $pairs = [];
        foreach ($samples as $sample) {
            $latitudeValues = $this->splitValues($this->directRawValues($sample, 'latitude'), false);
            $longitudeValues = $this->splitValues($this->directRawValues($sample, 'longitude'), false);
            $pairs = array_merge($pairs, $this->coordinatePairs($latitudeValues, $longitudeValues));

            $endLatitude = $this->first($sample, ['latitude_end', 'end_latitude', 'max_latitude']);
            $endLongitude = $this->first($sample, ['longitude_end', 'end_longitude', 'max_longitude']);
            if ($endLatitude !== null && $endLongitude !== null) {
                $pairs[] = ['latitude' => $endLatitude, 'longitude' => $endLongitude];
            }
        }
        $pairs = $this->uniqueCoordinatePairs($pairs);

        $descriptionGroups = $this->descriptionGroupsForSamples($root, $samples);
        $comments = array_merge(
            $this->directValues($root, 'sample_comment'),
            $this->directValues($root, 'comment'),
            $this->valuesAcrossSamples($samples, ['sample_comment', 'comment']),
            $this->descendantValuesAcrossSamples($samples, 'comments', 'comment'),
        );
        $materialValues = [];
        foreach ($this->valuesAcrossSamples($samples, ['material']) as $rawMaterial) {
            $normalized = $this->vocabularyNormalizer->normalizeMaterial($rawMaterial);
            if ($normalized !== null) {
                $materialValues[] = $normalized;
            }
        }
        if ($materialValues === []) {
            foreach ($this->valuesAcrossSamples($samples, ['alternateMaterial']) as $rawMaterial) {
                $normalized = $this->vocabularyNormalizer->normalizeMaterial($rawMaterial);
                if ($normalized !== null) {
                    $materialValues[] = $normalized;
                }
            }
        }
        if ($materialValues === []) {
            $rootMaterial = $this->first($root, ['material', 'alternateMaterial']);
            $normalizedRootMaterial = $this->vocabularyNormalizer->normalizeMaterial($rootMaterial);
            if ($normalizedRootMaterial !== null) {
                $materialValues[] = $normalizedRootMaterial;
            }
        }
        $material = $this->resolveScalarValues('material', $materialValues, $conflicts);
        $classifications = $this->classificationFields($samples);
        $rootRelatedIdentifiers = $this->rootRelatedIdentifiers($root);
        $rootContributors = $this->rootContributors($root);
        $rootFunders = array_values(array_map(
            static fn (array $contributor): string => $contributor['name'],
            array_filter(
                $rootContributors,
                static fn (array $contributor): bool => strcasecmp($contributor['type'] ?? '', 'Funder') === 0,
            ),
        ));
        $rootOperators = array_values(array_map(
            static fn (array $contributor): string => $contributor['name'],
            array_filter(
                $rootContributors,
                static fn (array $contributor): bool => strcasecmp($contributor['type'] ?? '', 'Other') === 0,
            ),
        ));

        $supplementalFunders = $this->valuesAcrossSamples($samples, ['funding_agency']);
        $supplementalOperators = $this->uniqueValues([
            ...$this->descendantValuesAcrossSamples($samples, 'operators', 'operator'),
            ...$this->valuesAcrossSamples($samples, ['operator']),
        ]);
        $fundingAgencies = $this->preferredValues(
            'funding_agency_source',
            $supplementalFunders,
            $rootFunders,
            $conflicts,
        );
        $operators = $this->preferredValues(
            'operator_source',
            $supplementalOperators,
            $rootOperators,
            $conflicts,
        );
        $publishDates = $this->normalizeDateValues('publish_date', $this->publishDates($samples), false, $conflicts);
        $samplingDates = $this->valuesAcrossSamples($samples, ['sampling_date']);
        if ($samplingDates === []) {
            $samplingDates = $this->valuesAcrossSamples($samples, ['collectionTime']);
        }
        if ($samplingDates === []) {
            $samplingDates = $this->directValues($root, 'collectionTime');
        }
        $samplingDates = $this->normalizeDateValues('sampling_date', $samplingDates, true, $conflicts);
        $publishDatesForProjection = $publishDates;
        if (count($publishDates) > 1) {
            $conflicts[] = [
                'field' => 'publish_date',
                'values' => array_map(
                    static fn (string $value): array => ['value' => $value, 'sample_indexes' => []],
                    $publishDates,
                ),
            ];
            $publishDatesForProjection = [];
        }
        $totalLengths = $this->numberUnitPairsAcrossSamples($samples, 'length', 'length_unit');
        $methods = $this->methodsAcrossSamples($samples);
        $isPrivate = $this->booleanAcrossSamples($samples, 'is_private', $conflicts);
        $name = $this->scalarAcrossSamples($samples, ['name'], 'name', $conflicts)
            ?? $this->first($root, ['name']);
        $parentIgsn = $this->scalarAcrossSamples($samples, ['parent_igsn'], 'parent_igsn', $conflicts)
            ?? $this->first($root, ['parentIdentifier']);

        $fieldNames = $this->valuesAcrossSamples($samples, ['field_name']);
        $classificationComments = $this->valuesAcrossSamples($samples, ['classification_comment']);
        $sampleRequests = $this->valuesAcrossSamples($samples, ['sample_request']);
        $sampledBy = $this->valuesAcrossSamples($samples, ['sampled_by']);
        $launchPlatformNames = $this->valuesAcrossSamples($samples, ['launch_platform_name']);
        $launchTypeNames = $this->valuesAcrossSamples($samples, ['launch_type_name']);
        $navigationTypes = $this->valuesAcrossSamples($samples, ['navigation_type']);
        $ageRanges = $this->rangesAcrossSamples($samples, 'age_min', 'age_max', 'age_unit');
        $elevationRanges = $this->rangesAcrossSamples(
            $samples,
            'elevation',
            'elevation_end',
            'elevation_unit',
            'elevation_end_unit',
        );
        $legacyAudit = [
            'schema_namespace' => $legacy['schema_namespace'],
            'sample_count' => $legacy['sample_count'],
            'unknown_paths' => $this->unknownPaths($legacy['fields']),
        ];

        return [
            'scalars' => [
                'sample_type' => $this->scalarAcrossSamples($samples, ['sample_type', 'resourceType'], 'sample_type', $conflicts)
                    ?? $this->first($root, ['resourceType']),
                'material' => $material,
                'is_private' => $isPrivate,
                'user_code' => $this->scalarAcrossSamples($samples, ['user_code'], 'user_code', $conflicts),
                'cruise_field_program' => $this->scalarAcrossSamples($samples, ['cruise_field_prgrm', 'cruise_field_program'], 'cruise_field_program', $conflicts),
                'depth_min' => $this->scalarAcrossSamples($samples, ['depth_min'], 'depth_min', $conflicts),
                'depth_max' => $this->scalarAcrossSamples($samples, ['depth_max'], 'depth_max', $conflicts),
                'depth_scale' => $this->scalarAcrossSamples($samples, ['depth_scale'], 'depth_scale', $conflicts),
                'sample_purpose' => $this->scalarAcrossSamples($samples, ['sample_purpose'], 'sample_purpose', $conflicts),
                'collection_method' => $this->scalarAcrossSamples(
                    $samples,
                    ['collection_method', 'collectionMethod', 'alternateCollectionMethod'],
                    'collection_method',
                    $conflicts,
                ) ?? $this->first($root, ['collectionMethod', 'alternateCollectionMethod']),
                'collection_method_description' => $this->scalarAcrossSamples($samples, ['collection_method_descr', 'collection_method_description'], 'collection_method_description', $conflicts),
                'collection_date_precision' => $this->scalarAcrossSamples($samples, ['collection_date_precision'], 'collection_date_precision', $conflicts),
                'platform_type' => $this->scalarAcrossSamples($samples, ['platform_type'], 'platform_type', $conflicts),
                'platform_name' => $this->scalarAcrossSamples($samples, ['platform_name'], 'platform_name', $conflicts),
                'platform_description' => $this->scalarAcrossSamples($samples, ['platform_descr', 'platform_description'], 'platform_description', $conflicts),
                'current_archive' => $this->scalarAcrossSamples($samples, ['current_archive'], 'current_archive', $conflicts),
                'current_archive_contact' => $this->scalarAcrossSamples($samples, ['current_archive_contact'], 'current_archive_contact', $conflicts),
                'original_archive' => $this->scalarAcrossSamples($samples, ['original_archive'], 'original_archive', $conflicts),
                'original_archive_contact' => $this->scalarAcrossSamples($samples, ['original_archive_contact'], 'original_archive_contact', $conflicts),
                'operator' => count($operators) === 1 ? $operators[0] : null,
                'coordinate_system' => $this->scalarAcrossSamples($samples, ['coordinate_system'], 'coordinate_system', $conflicts),
            ],
            'name' => $name,
            'other_names' => $this->splitValues(array_merge(
                $this->valuesAcrossSamples($samples, ['sample_other_name']),
                $this->valuesAcrossSamples($samples, ['sample_other_names']),
                $this->descendantValuesAcrossSamples($samples, 'sample_other_names', 'sample_other_name'),
            )),
            'parent_igsn' => $parentIgsn,
            'sample_access' => $this->first($root, ['sampleAccess'])
                ?? $this->scalarAcrossSamples($samples, ['sample_access', 'sampleAccess'], 'sample_access', $conflicts),
            'sample_image' => $this->imageFieldsAcrossSamples($samples, $conflicts),
            'description_groups' => $descriptionGroups,
            'material_descriptions' => $this->descriptionNormalizer->legacyValues($descriptionGroups),
            'comments' => $this->uniqueValues($comments),
            'location' => [
                'pairs' => $pairs,
                'place' => $this->scalarAcrossSamples($samples, ['primary_location_name', 'locality'], 'place', $conflicts),
                'location_type' => $this->scalarAcrossSamples($samples, ['primary_location_type', 'location_type'], 'location_type', $conflicts),
                'location_description' => $this->scalarAcrossSamples($samples, ['location_description'], 'location_description', $conflicts),
                'locality_description' => $this->scalarAcrossSamples($samples, ['locality_description'], 'locality_description', $conflicts),
                'country' => $this->scalarAcrossSamples($samples, ['country'], 'country', $conflicts),
                'province' => $this->scalarAcrossSamples($samples, ['province'], 'province', $conflicts),
                'county' => $this->scalarAcrossSamples($samples, ['county'], 'county', $conflicts),
                'city' => $this->scalarAcrossSamples($samples, ['city'], 'city', $conflicts),
                'elevation' => $this->scalarAcrossSamples($samples, ['elevation'], 'elevation', $conflicts),
                'elevation_unit' => $this->scalarAcrossSamples($samples, ['elevation_unit', 'elevationUnit'], 'elevation_unit', $conflicts),
            ],
            'collection' => [
                'start' => $this->scalarAcrossSamples($samples, ['collection_start_date'], 'collection_start_date', $conflicts),
                'end' => $this->scalarAcrossSamples($samples, ['collection_end_date'], 'collection_end_date', $conflicts),
                'collector' => $this->scalarAcrossSamples($samples, ['collector'], 'collector', $conflicts) ?? $this->nestedName($root, 'collector'),
                'collector_detail' => $this->scalarAcrossSamples($samples, ['collector_detail'], 'collector_detail', $conflicts) ?? $this->nestedName($root, 'collector', 'affiliation'),
            ],
            'classifications' => $classifications['items'],
            'rejected_classifications' => $classifications['rejected'],
            'geological_ages' => $this->splitValues($this->valuesAcrossSamples($samples, ['geological_age'])),
            'geological_units' => $this->splitValues($this->valuesAcrossSamples($samples, ['geological_unit'])),
            'sizes' => $this->sizesAcrossSamples($samples),
            'root_related_identifiers' => $rootRelatedIdentifiers,
            'root_contributors' => $rootContributors,
            'operators' => $operators,
            'methods' => $methods,
            'total_lengths' => $totalLengths,
            'age_ranges' => $ageRanges,
            'elevation_ranges' => $elevationRanges,
            'metadata_values' => [
                'field_name' => $fieldNames,
                'classification_comment' => $classificationComments,
                'sample_request' => $sampleRequests,
                'sampled_by' => $sampledBy,
                'launch_platform_name' => $launchPlatformNames,
                'launch_type_name' => $launchTypeNames,
                'navigation_type' => $navigationTypes,
            ],
            'funding_agencies' => $fundingAgencies,
            'publish_dates' => $publishDatesForProjection,
            'sampling_dates' => $samplingDates,
            'conflicts' => $conflicts,
            'legacy_dif' => $legacyAudit,
        ];
    }

    /**
     * Supplemental values are authoritative; root values only fill an empty source.
     *
     * @param  list<string>  $supplemental
     * @param  list<string>  $root
     * @param  list<array<string, mixed>>  $conflicts
     * @return list<string>
     */
    private function preferredValues(string $field, array $supplemental, array $root, array &$conflicts): array
    {
        $supplemental = $this->uniqueValues($supplemental);
        $root = $this->uniqueValues($root);

        if ($supplemental === []) {
            return $root;
        }

        if ($root !== [] && $this->normalizedList($supplemental) !== $this->normalizedList($root)) {
            $conflicts[] = [
                'field' => $field,
                'supplemental_values' => $supplemental,
                'root_values' => $root,
            ];
        }

        return $supplemental;
    }

    /** @param list<string> $values
     * @return list<string>
     */
    private function normalizedList(array $values): array
    {
        $values = array_map($this->normalizedValueKey(...), $values);
        sort($values);

        return $values;
    }

    /**
     * @param  list<string>  $values
     * @param  list<array<string, mixed>>  $conflicts
     * @return list<string>
     */
    private function normalizeDateValues(
        string $field,
        array $values,
        bool $preserveDateTime,
        array &$conflicts,
    ): array {
        $normalized = [];
        foreach ($values as $value) {
            $candidate = trim($value);
            if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $candidate, $matches) === 1) {
                $candidate = sprintf('%04d-%02d-%02d', (int) $matches[1], (int) $matches[2], (int) $matches[3]);
            }
            $date = DataCiteDateNormalizer::normalize($candidate, $preserveDateTime);
            if ($date === null) {
                $conflicts[] = ['field' => 'invalid_'.$field, 'values' => [$value]];

                continue;
            }
            $normalized[] = $date;
        }

        return $this->uniqueValues($normalized);
    }

    /**
     * @param  array<int, \SimpleXMLElement>  $samples
     * @return array{
     *     items: list<array{value: string, classification_type: IgsnClassificationType|null}>,
     *     rejected: list<array{value: string, material: string|null, sample_index: int}>
     * }
     */
    private function classificationFields(array $samples): array
    {
        $items = [];
        $rejected = [];
        $itemIndexesByValue = [];
        $seenRejected = [];

        foreach ($samples as $sampleIndex => $sample) {
            $rawValues = $this->splitValues($this->directValues($sample, 'classification'));
            if ($rawValues === []) {
                continue;
            }

            $rawMaterial = $this->first($sample, ['material']);
            try {
                $material = $this->vocabularyNormalizer->normalizeMaterial($rawMaterial);
            } catch (\InvalidArgumentException) {
                foreach ($rawValues as $value) {
                    $this->addRejectedClassification(
                        $rejected,
                        $seenRejected,
                        $value,
                        $rawMaterial,
                        $sampleIndex,
                    );
                }

                continue;
            }

            $partition = $this->vocabularyNormalizer->partitionClassifications(
                $material,
                $rawValues,
            );
            $classificationType = $this->vocabularyNormalizer->classificationType($material);

            foreach ($partition['values'] as $value) {
                $key = mb_strtolower($value);
                $itemIndex = $itemIndexesByValue[$key] ?? null;
                if ($itemIndex !== null) {
                    if ($items[$itemIndex]['classification_type'] === null && $classificationType !== null) {
                        $items[$itemIndex]['classification_type'] = $classificationType;
                    }

                    continue;
                }

                $itemIndexesByValue[$key] = count($items);
                $items[] = [
                    'value' => $value,
                    'classification_type' => $classificationType,
                ];
            }

            foreach ($partition['rejected'] as $value) {
                $this->addRejectedClassification(
                    $rejected,
                    $seenRejected,
                    $value,
                    $material,
                    $sampleIndex,
                );
            }
        }

        return ['items' => $items, 'rejected' => $rejected];
    }

    /**
     * @param  list<array{value: string, material: string|null, sample_index: int}>  $rejected
     * @param  array<string, true>  $seenRejected
     */
    private function addRejectedClassification(
        array &$rejected,
        array &$seenRejected,
        string $value,
        ?string $material,
        int $sampleIndex,
    ): void {
        $key = implode('|', [mb_strtolower((string) $material), mb_strtolower($value)]);
        if (isset($seenRejected[$key])) {
            return;
        }

        $seenRejected[$key] = true;
        $rejected[] = [
            'value' => $value,
            'material' => $material,
            'sample_index' => $sampleIndex,
        ];
    }

    /** @return array{file_name: string|null, base_url: string|null} */
    private function imageFields(\SimpleXMLElement $sample): array
    {
        return [
            'file_name' => $this->first($sample, ['sample_image']),
            'base_url' => $this->first($sample, ['sample_image_path']),
        ];
    }

    /**
     * @param  array<int, \SimpleXMLElement>  $samples
     * @param  list<array<string, mixed>>  $conflicts
     * @return array{file_name: string|null, base_url: string|null}
     */
    private function imageFieldsAcrossSamples(array $samples, array &$conflicts): array
    {
        return [
            'file_name' => $this->scalarAcrossSamples($samples, ['sample_image'], 'sample_image', $conflicts),
            'base_url' => $this->scalarAcrossSamples($samples, ['sample_image_path'], 'sample_image_path', $conflicts),
        ];
    }

    /**
     * @param  array<int, \SimpleXMLElement>  $samples
     * @param  list<string>  $names
     * @return list<string>
     */
    private function valuesAcrossSamples(array $samples, array $names): array
    {
        $values = [];
        foreach ($samples as $sample) {
            foreach ($names as $name) {
                $values = array_merge($values, $this->directValues($sample, $name));
            }
        }

        return $this->uniqueValues($values);
    }

    /**
     * @param  array<int, \SimpleXMLElement>  $samples
     * @return list<string>
     */
    private function descendantValuesAcrossSamples(array $samples, string $container, string $name): array
    {
        $values = [];
        foreach ($samples as $sample) {
            $values = array_merge($values, $this->descendantValues($sample, $container, $name));
        }

        return $this->uniqueValues($values);
    }

    /**
     * @param  array<int, \SimpleXMLElement>  $samples
     * @param  list<string>  $names
     * @param  list<array<string, mixed>>  $conflicts
     */
    private function scalarAcrossSamples(
        array $samples,
        array $names,
        string $field,
        array &$conflicts,
    ): ?string {
        $values = [];
        foreach ($samples as $sampleIndex => $sample) {
            foreach ($names as $name) {
                $directValues = $this->directValues($sample, $name);
                foreach ($directValues as $value) {
                    $key = $this->normalizedValueKey($value);
                    $values[$key] ??= ['value' => $value, 'sample_indexes' => []];
                    $values[$key]['sample_indexes'][] = $sampleIndex;
                }
                if ($directValues !== []) {
                    break;
                }
            }
        }

        if ($values === []) {
            return null;
        }
        if (count($values) === 1) {
            return (string) reset($values)['value'];
        }

        $conflicts[] = [
            'field' => $field,
            'values' => array_values($values),
        ];

        return null;
    }

    /**
     * @param  list<string>  $values
     * @param  list<array<string, mixed>>  $conflicts
     */
    private function resolveScalarValues(string $field, array $values, array &$conflicts): ?string
    {
        $values = $this->uniqueValues($values);
        if ($values === []) {
            return null;
        }
        if (count($values) === 1) {
            return $values[0];
        }

        $conflicts[] = [
            'field' => $field,
            'values' => array_map(
                static fn (string $value): array => ['value' => $value, 'sample_indexes' => []],
                $values,
            ),
        ];

        return null;
    }

    /**
     * @param  array<int, \SimpleXMLElement>  $samples
     * @param  list<array<string, mixed>>  $conflicts
     */
    private function booleanAcrossSamples(array $samples, string $name, array &$conflicts): ?bool
    {
        $raw = $this->scalarAcrossSamples($samples, [$name], $name, $conflicts);
        if ($raw === null) {
            return null;
        }

        return match (mb_strtolower(trim($raw))) {
            '1', 'true', 'yes', 'y' => true,
            '0', 'false', 'no', 'n' => false,
            default => null,
        };
    }

    /**
     * @param  array<int, \SimpleXMLElement>  $samples
     * @return list<array{scheme: string|null, value: string}>
     */
    private function methodsAcrossSamples(array $samples): array
    {
        $methods = [];
        $seen = [];
        foreach ($samples as $sample) {
            $nodes = $sample->xpath('./*[local-name()="methods"]/*[local-name()="method"]');
            if (! is_array($nodes)) {
                continue;
            }

            foreach ($nodes as $node) {
                $value = trim((string) $node);
                if ($value === '' || strcasecmp($value, 'N/A') === 0) {
                    continue;
                }
                $scheme = $this->attribute($node, ['methodScheme']);
                $key = $this->normalizedValueKey((string) $scheme).'|'.$this->normalizedValueKey($value);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $methods[] = ['scheme' => $scheme, 'value' => $value];
            }
        }

        return $methods;
    }

    /**
     * @param  array<int, \SimpleXMLElement>  $samples
     * @return list<array{numeric_value: string, unit: string|null}>
     */
    private function numberUnitPairsAcrossSamples(array $samples, string $valueName, string $unitName): array
    {
        $pairs = [];
        $seen = [];
        foreach ($samples as $sample) {
            $values = $this->splitValues($this->directRawValues($sample, $valueName), false);
            $units = $this->splitValues($this->directRawValues($sample, $unitName), false);
            foreach ($values as $index => $value) {
                if (! is_numeric($value)) {
                    continue;
                }
                $unit = $units[$index] ?? ($units[0] ?? null);
                $key = $this->normalizedValueKey($value).'|'.$this->normalizedValueKey((string) $unit);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $pairs[] = ['numeric_value' => $value, 'unit' => $unit];
            }
        }

        return $pairs;
    }

    /**
     * @param  array<int, \SimpleXMLElement>  $samples
     * @return list<array{start: string|null, end: string|null, unit: string|null, end_unit: string|null}>
     */
    private function rangesAcrossSamples(
        array $samples,
        string $startName,
        string $endName,
        string $unitName,
        ?string $endUnitName = null,
    ): array {
        $ranges = [];
        $seen = [];
        foreach ($samples as $sample) {
            $start = $this->first($sample, [$startName]);
            $end = $this->first($sample, [$endName]);
            $unit = $this->first($sample, [$unitName]);
            $endUnit = $endUnitName !== null ? $this->first($sample, [$endUnitName]) : null;
            if ($start === null && $end === null) {
                continue;
            }
            $range = ['start' => $start, 'end' => $end, 'unit' => $unit, 'end_unit' => $endUnit];
            $key = $this->normalizedValueKey(json_encode($range, JSON_THROW_ON_ERROR));
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $ranges[] = $range;
            }
        }

        return $ranges;
    }

    /** @param array<int, \SimpleXMLElement> $samples
     * @return list<string>
     */
    private function publishDates(array $samples): array
    {
        $values = [];
        foreach ($samples as $sample) {
            $elementValues = $this->directValues($sample, 'publish_date');
            if ($elementValues !== []) {
                $values = array_merge($values, $elementValues);

                continue;
            }
            $attribute = $this->attribute($sample, ['publishdate']);
            if ($attribute !== null) {
                $values[] = $attribute;
            }
        }

        return $this->uniqueValues($values);
    }

    /**
     * @return list<array{identifier: string, identifier_type: string, relation_type: string}>
     */
    private function rootRelatedIdentifiers(\SimpleXMLElement $root): array
    {
        $nodes = $root->xpath('./*[local-name()="relatedIdentifiers"]/*[local-name()="relatedIdentifier"]');
        if (! is_array($nodes)) {
            return [];
        }

        $identifiers = [];
        $seen = [];
        foreach ($nodes as $node) {
            $identifier = trim((string) $node);
            $type = $this->attribute($node, ['type', 'relatedIdentifierType']) ?? 'DOI';
            $relationType = $this->attribute($node, ['relationType']) ?? '';
            if ($identifier === '' || strcasecmp($relationType, 'hasDocument') !== 0) {
                continue;
            }
            $key = $this->normalizedValueKey($type).'|'.$this->normalizedValueKey($relationType).'|'.$this->normalizedValueKey($identifier);
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $identifiers[] = [
                    'identifier' => $identifier,
                    'identifier_type' => $type,
                    'relation_type' => $relationType,
                ];
            }
        }

        return $identifiers;
    }

    /**
     * @return list<array{type: string|null, name: string, affiliations: list<string>, identifiers: list<string>}>
     */
    private function rootContributors(\SimpleXMLElement $root): array
    {
        $nodes = $root->xpath('./*[local-name()="contributors"]/*[local-name()="contributor"]');
        if (! is_array($nodes)) {
            return [];
        }

        $contributors = [];
        foreach ($nodes as $node) {
            $name = $this->first($node, ['name']);
            if ($name === null) {
                continue;
            }
            $affiliationNodes = $node->xpath(
                './/*[local-name()="affiliation"]/*[local-name()="name"] | .//*[local-name()="affiliation"][not(*)]',
            );
            $identifierNodes = $node->xpath('.//*[local-name()="identifier"]');
            $contributors[] = [
                'type' => $this->attribute($node, ['type', 'contributorType']),
                'name' => $name,
                'affiliations' => is_array($affiliationNodes) ? $this->normalizeNodes($affiliationNodes) : [],
                'identifiers' => is_array($identifierNodes) ? $this->normalizeNodes($identifierNodes) : [],
            ];
        }

        return $contributors;
    }

    /** @param list<string> $names */
    private function attribute(\SimpleXMLElement $element, array $names): ?string
    {
        foreach ($names as $name) {
            $nodes = $element->xpath('./@*[local-name()="'.$name.'"]');
            if (is_array($nodes) && isset($nodes[0])) {
                $value = trim((string) $nodes[0]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<array{path: string, value: string, attributes: array<string, string>, namespace: string|null, sample_index: int|null}>  $fields
     * @return list<string>
     */
    private function unknownPaths(array $fields): array
    {
        $unknown = [];
        foreach ($fields as $field) {
            if (! $this->isKnownFieldPath($field['path'])) {
                $unknown[] = $field['path'].($field['sample_index'] === null
                    ? ''
                    : sprintf(' [sample=%d]', $field['sample_index']));
            }
        }

        return $this->uniqueValues($unknown);
    }

    private function isKnownFieldPath(string $path): bool
    {
        $parts = explode('/', $path);
        $leaf = end($parts);
        if (! in_array($leaf, self::KNOWN_LEAF_NAMES, true)) {
            return false;
        }

        $sampleMarker = '/sample/';
        $samplePosition = strpos('/'.$path, $sampleMarker);
        if ($samplePosition !== false) {
            $relative = substr('/'.$path, $samplePosition + strlen($sampleMarker));
            if (! str_contains($relative, '/')) {
                return in_array($relative, self::KNOWN_SAMPLE_DIRECT_NAMES, true);
            }

            return in_array($relative, [
                'operators/operator',
                'methods/method',
                'sample_other_names/sample_other_name',
                'descriptions/description',
                'comments/comment',
                'relatedIdentifiers/relatedIdentifier',
            ], true);
        }

        if (preg_match('#^resource/(?:identifier|name|parentIdentifier|description|resourceType|material|alternateMaterial|collectionMethod|alternateCollectionMethod|collectionTime|sampleAccess|sample_comment|comment)$#', $path) === 1) {
            return true;
        }
        if (preg_match('#^resource/@(?:xsi:)?schemaLocation$#', $path) === 1) {
            return true;
        }
        if ($path === 'resource/relatedIdentifiers/relatedIdentifier') {
            return true;
        }
        if (preg_match('#^resource/contributors/contributor/@(?:type|contributorType)$#', $path) === 1) {
            return true;
        }

        return preg_match(
            '#^resource/(?:contributors/contributor|collector)(?:(?:/affiliations?/affiliation)|/affiliation)?/(?:name|identifier)$#',
            $path,
        ) === 1;
    }

    /**
     * @param  list<array{latitude: string, longitude: string}>  $pairs
     * @return list<array{latitude: string, longitude: string}>
     */
    private function uniqueCoordinatePairs(array $pairs): array
    {
        $unique = [];
        foreach ($pairs as $pair) {
            $key = $this->normalizedValueKey($pair['latitude']).'|'.$this->normalizedValueKey($pair['longitude']);
            $unique[$key] ??= $pair;
        }

        return array_values($unique);
    }

    /**
     * @param  array<int, \SimpleXMLElement>  $samples
     * @return list<array{numeric_value: string, unit: string|null, type: string|null}>
     */
    private function sizesAcrossSamples(array $samples): array
    {
        $sizes = [];
        $seen = [];
        foreach ($samples as $sample) {
            foreach ($this->sizes(
                $this->splitValues($this->directRawValues($sample, 'size'), false),
                $this->splitValues($this->directRawValues($sample, 'size_unit'), false),
            ) as $size) {
                $key = $this->normalizedValueKey(json_encode($size, JSON_THROW_ON_ERROR));
                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $sizes[] = $size;
                }
            }
        }

        return $sizes;
    }

    /**
     * @param  array<int, \SimpleXMLElement>  $samples
     * @return list<array{entries: list<array{value: string, scheme: string|null}>}>
     */
    private function descriptionGroupsForSamples(\SimpleXMLElement $root, array $samples): array
    {
        $groups = [];
        foreach ($samples as $sample) {
            $containers = $sample->xpath('./*[local-name()="descriptions"]');
            if (is_array($containers)) {
                foreach ($containers as $container) {
                    $nodes = $container->xpath('./*[local-name()="description"]');
                    if (is_array($nodes)) {
                        $groups[] = ['entries' => $this->descriptionEntries($nodes)];
                    }
                }
            }
        }

        $groups = $this->descriptionNormalizer->normalizeGroups($groups);
        if ($groups !== []) {
            return $groups;
        }

        $entries = [];
        foreach ($samples as $sample) {
            $nodes = $sample->xpath('./*[local-name()="description"]');
            $entries = array_merge($entries, $this->descriptionEntries(is_array($nodes) ? $nodes : []));
        }
        $seen = [];
        foreach ($entries as $entry) {
            $seen[$this->descriptionValueKey($entry['value'])] = true;
        }
        $rootNodes = $root->xpath('./*[local-name()="description"]');
        foreach ($this->descriptionEntries(is_array($rootNodes) ? $rootNodes : []) as $entry) {
            $key = $this->descriptionValueKey($entry['value']);
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $entries[] = $entry;
            }
        }

        return $this->descriptionNormalizer->normalizeGroups([['entries' => $entries]]);
    }

    private function normalizedValueKey(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }

    /** @return list<string> */
    private function directValues(\SimpleXMLElement $parent, string $name): array
    {
        return $this->uniqueValues($this->directRawValues($parent, $name));
    }

    /** @return list<string> */
    private function directRawValues(\SimpleXMLElement $parent, string $name): array
    {
        $nodes = $parent->xpath('./*[local-name()="'.$name.'"]');
        if (! is_array($nodes)) {
            return [];
        }

        $values = [];
        foreach ($nodes as $node) {
            $values[] = trim((string) $node);
        }

        return $values;
    }

    /** @return list<string> */
    private function descendantValues(\SimpleXMLElement $parent, string $container, string $name): array
    {
        $nodes = $parent->xpath('./*[local-name()="'.$container.'"]/*[local-name()="'.$name.'"]');

        return is_array($nodes) ? $this->normalizeNodes($nodes) : [];
    }

    /**
     * @return list<array{entries: list<array{value: string, scheme: string|null}>}>
     */
    private function descriptionGroups(\SimpleXMLElement $root, \SimpleXMLElement $sample): array
    {
        $containers = $sample->xpath('./*[local-name()="descriptions"]');
        $groups = [];

        if (is_array($containers)) {
            foreach ($containers as $container) {
                $nodes = $container->xpath('./*[local-name()="description"]');
                if (is_array($nodes)) {
                    $groups[] = ['entries' => $this->descriptionEntries($nodes)];
                }
            }
        }

        $groups = $this->descriptionNormalizer->normalizeGroups($groups);
        if ($groups !== []) {
            return $groups;
        }

        $sampleNodes = $sample->xpath('./*[local-name()="description"]');
        $rootNodes = $root->xpath('./*[local-name()="description"]');
        $entries = $this->descriptionEntries(is_array($sampleNodes) ? $sampleNodes : []);
        $seen = [];
        foreach ($entries as $entry) {
            $seen[$this->descriptionValueKey($entry['value'])] = true;
        }

        foreach ($this->descriptionEntries(is_array($rootNodes) ? $rootNodes : []) as $entry) {
            $key = $this->descriptionValueKey($entry['value']);
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $entries[] = $entry;
            }
        }

        return $this->descriptionNormalizer->normalizeGroups([['entries' => $entries]]);
    }

    /**
     * @param  array<int, \SimpleXMLElement>  $nodes
     * @return list<array{value: string, scheme: string|null}>
     */
    private function descriptionEntries(array $nodes): array
    {
        $entries = [];

        foreach ($nodes as $node) {
            $schemeNodes = $node->xpath('./@*[local-name()="descriptionScheme"]');
            $scheme = is_array($schemeNodes) && isset($schemeNodes[0]) ? trim((string) $schemeNodes[0]) : null;
            $entries[] = [
                'value' => trim((string) $node),
                'scheme' => $scheme !== '' ? $scheme : null,
            ];
        }

        return $entries;
    }

    private function descriptionValueKey(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    /** @param list<string> $names */
    private function first(\SimpleXMLElement $parent, array $names): ?string
    {
        foreach ($names as $name) {
            $values = $this->directValues($parent, $name);
            if ($values !== []) {
                return $values[0];
            }
        }

        return null;
    }

    private function nestedName(\SimpleXMLElement $root, string $element, ?string $child = null): ?string
    {
        $path = './*[local-name()="'.$element.'"]';
        if ($child !== null) {
            $path .= '/*[local-name()="'.$child.'"]';
        }
        $path .= '/*[local-name()="name"]';
        $nodes = $root->xpath($path);
        $values = is_array($nodes) ? $this->normalizeNodes($nodes) : [];

        return $values[0] ?? null;
    }

    /**
     * @param  array<int, \SimpleXMLElement>  $nodes
     * @return list<string>
     */
    private function normalizeNodes(array $nodes): array
    {
        $values = [];
        foreach ($nodes as $node) {
            $values[] = trim((string) $node);
        }

        return $this->uniqueValues($values);
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function splitValues(array $values, bool $unique = true): array
    {
        $split = [];
        foreach ($values as $value) {
            foreach (preg_split('/\s*;\s*/', $value) ?: [] as $part) {
                $split[] = $part;
            }
        }

        if ($unique) {
            return $this->uniqueValues($split);
        }

        return array_values(array_filter(
            array_map('trim', $split),
            static fn (string $value): bool => $value !== '' && strcasecmp($value, 'N/A') !== 0,
        ));
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function uniqueValues(array $values): array
    {
        $result = [];
        $seen = [];
        foreach ($values as $value) {
            $value = trim($value);
            if ($value === '' || strcasecmp($value, 'N/A') === 0) {
                continue;
            }
            $key = mb_strtolower($value);
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $value;
            }
        }

        return $result;
    }

    /**
     * @param  list<string>  $latitudes
     * @param  list<string>  $longitudes
     * @return list<array{latitude: string, longitude: string}>
     */
    private function coordinatePairs(array $latitudes, array $longitudes): array
    {
        $pairs = [];
        foreach ($latitudes as $index => $latitude) {
            if (isset($longitudes[$index])) {
                $pairs[] = ['latitude' => $latitude, 'longitude' => $longitudes[$index]];
            }
        }

        return $pairs;
    }

    /**
     * @param  list<string>  $values
     * @param  list<string>  $units
     * @return list<array{numeric_value: string, unit: string|null, type: string|null}>
     */
    private function sizes(array $values, array $units): array
    {
        $sizes = [];
        $seen = [];
        foreach ($values as $index => $value) {
            if (! is_numeric($value)) {
                continue;
            }

            $label = $units[$index] ?? null;
            $type = $label;
            $unit = null;
            if ($label !== null && preg_match('/^(.*?)\s*\[([^]]+)]\s*$/', $label, $matches) === 1) {
                $type = trim($matches[1]);
                $unit = trim($matches[2]);
            }

            $size = [
                'numeric_value' => $value,
                'unit' => $unit,
                'type' => $type !== '' ? $type : null,
            ];
            $key = implode('|', [
                mb_strtolower($size['numeric_value']),
                mb_strtolower($size['unit'] ?? ''),
                mb_strtolower($size['type'] ?? ''),
            ]);
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $sizes[] = $size;
            }
        }

        return $sizes;
    }
}
