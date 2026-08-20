<?php

declare(strict_types=1);

namespace App\Services\Igsn;

/**
 * Extracts legacy DIF XML into a normalized, persistence-free structure.
 */
class IgsnDifMetadataExtractor
{
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

        $rootDescriptions = $this->directValues($root, 'description');
        $sampleDescriptions = array_merge(
            $this->directValues($sample, 'description'),
            $this->descendantValues($sample, 'descriptions', 'description'),
        );

        return [
            'scalars' => [
                'sample_type' => $this->first($sample, ['sample_type']),
                'material' => $this->first($sample, ['material']),
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
            'comments' => $this->uniqueValues(array_merge($rootDescriptions, $sampleDescriptions)),
            'location' => [
                'pairs' => $pairs,
                'place' => $this->first($sample, ['primary_location_name', 'locality']),
                'location_type' => $this->first($sample, ['primary_location_type', 'location_type']),
                'location_description' => $this->first($sample, ['location_description']),
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
            'classifications' => $this->splitValues($this->directValues($sample, 'classification')),
            'geological_ages' => $this->splitValues($this->directValues($sample, 'geological_age')),
            'geological_units' => $this->splitValues($this->directValues($sample, 'geological_unit')),
            'sizes' => $this->sizes(
                $this->splitValues($this->directValues($sample, 'size')),
                $this->splitValues($this->directValues($sample, 'size_unit')),
            ),
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

            $sizes[] = [
                'numeric_value' => $value,
                'unit' => $unit,
                'type' => $type !== '' ? $type : null,
            ];
        }

        return $sizes;
    }
}
