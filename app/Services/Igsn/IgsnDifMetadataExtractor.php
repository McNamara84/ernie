<?php

declare(strict_types=1);

namespace App\Services\Igsn;

use App\Enums\Igsn\IgsnClassificationType;

/**
 * Extracts legacy DIF XML into a normalized, persistence-free structure.
 */
class IgsnDifMetadataExtractor
{
    public function __construct(
        private readonly IgsnVocabularyNormalizerService $vocabularyNormalizer = new IgsnVocabularyNormalizerService,
        private readonly IgsnDescriptionNormalizerService $descriptionNormalizer = new IgsnDescriptionNormalizerService,
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
        if ($root === false) {
            return null;
        }

        $samples = $root->xpath('//*[local-name()="sample"]');
        if (! is_array($samples) || $samples === []) {
            return null;
        }

        $sample = $samples[0];
        $latitudeValues = $this->splitValues($this->directRawValues($sample, 'latitude'), false);
        $longitudeValues = $this->splitValues($this->directRawValues($sample, 'longitude'), false);
        $pairs = $this->coordinatePairs($latitudeValues, $longitudeValues);

        $endLatitude = $this->first($sample, ['latitude_end', 'end_latitude', 'max_latitude']);
        $endLongitude = $this->first($sample, ['longitude_end', 'end_longitude', 'max_longitude']);
        if ($endLatitude !== null && $endLongitude !== null) {
            $pairs[] = ['latitude' => $endLatitude, 'longitude' => $endLongitude];
        }

        $descriptionGroups = $this->descriptionGroups($root, $sample);
        $comments = array_merge(
            $this->directValues($root, 'sample_comment'),
            $this->directValues($root, 'comment'),
            $this->directValues($sample, 'sample_comment'),
            $this->directValues($sample, 'comment'),
            $this->descendantValues($sample, 'comments', 'comment'),
        );
        $material = $this->vocabularyNormalizer->normalizeMaterial($this->first($sample, ['material']));
        $classifications = $this->classificationFields($samples);

        return [
            'scalars' => [
                'sample_type' => $this->first($sample, ['sample_type']),
                'material' => $material,
                'user_code' => $this->first($sample, ['user_code']),
                'cruise_field_program' => $this->first($sample, ['cruise_field_prgrm', 'cruise_field_program']),
                'depth_min' => $this->first($sample, ['depth_min']),
                'depth_max' => $this->first($sample, ['depth_max']),
                'depth_scale' => $this->first($sample, ['depth_scale']),
                'sample_purpose' => $this->first($sample, ['sample_purpose']),
                'collection_method' => $this->first($sample, ['collection_method']),
                'collection_method_description' => $this->first($sample, ['collection_method_descr', 'collection_method_description']),
                'collection_date_precision' => $this->first($sample, ['collection_date_precision']),
                'platform_type' => $this->first($sample, ['platform_type']),
                'platform_name' => $this->first($sample, ['platform_name']),
                'platform_description' => $this->first($sample, ['platform_descr', 'platform_description']),
                'current_archive' => $this->first($sample, ['current_archive']),
                'current_archive_contact' => $this->first($sample, ['current_archive_contact']),
                'original_archive' => $this->first($sample, ['original_archive']),
                'original_archive_contact' => $this->first($sample, ['original_archive_contact']),
                'operator' => $this->first($sample, ['operator']),
                'coordinate_system' => $this->first($sample, ['coordinate_system']),
            ],
            'name' => $this->first($sample, ['name']) ?? $this->first($root, ['name']),
            'other_names' => $this->splitValues(array_merge(
                $this->directValues($sample, 'sample_other_name'),
                $this->directValues($sample, 'sample_other_names'),
            )),
            'parent_igsn' => $this->first($sample, ['parent_igsn']),
            'sample_access' => $this->first($root, ['sampleAccess']) ?? $this->first($sample, ['sample_access']),
            'sample_image' => $this->imageFields($sample),
            'description_groups' => $descriptionGroups,
            'material_descriptions' => $this->descriptionNormalizer->legacyValues($descriptionGroups),
            'comments' => $this->uniqueValues($comments),
            'location' => [
                'pairs' => $pairs,
                'place' => $this->first($sample, ['primary_location_name', 'locality']),
                'location_type' => $this->first($sample, ['primary_location_type', 'location_type']),
                'location_description' => $this->first($sample, ['location_description']),
                'locality_description' => $this->first($sample, ['locality_description']),
                'country' => $this->first($sample, ['country']),
                'province' => $this->first($sample, ['province']),
                'county' => $this->first($sample, ['county']),
                'city' => $this->first($sample, ['city']),
                'elevation' => $this->first($sample, ['elevation']),
                'elevation_unit' => $this->first($sample, ['elevation_unit', 'elevationUnit']),
            ],
            'collection' => [
                'start' => $this->first($sample, ['collection_start_date']),
                'end' => $this->first($sample, ['collection_end_date']),
                'collector' => $this->first($sample, ['collector']) ?? $this->nestedName($root, 'collector'),
                'collector_detail' => $this->first($sample, ['collector_detail']) ?? $this->nestedName($root, 'collector', 'affiliation'),
            ],
            'classifications' => $classifications['items'],
            'rejected_classifications' => $classifications['rejected'],
            'geological_ages' => $this->splitValues($this->directValues($sample, 'geological_age')),
            'geological_units' => $this->splitValues($this->directValues($sample, 'geological_unit')),
            'sizes' => $this->sizes(
                $this->splitValues($this->directRawValues($sample, 'size'), false),
                $this->splitValues($this->directRawValues($sample, 'size_unit'), false),
            ),
        ];
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
