<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\FunderIdentifierTypeDetector;
use Illuminate\Support\Str;

/**
 * Service for parsing IGSN CSV files.
 *
 * Parses pipe-delimited (|) CSV files containing IGSN metadata and returns
 * structured data ready for storage. Handles multi-value fields separated
 * by semicolons (;) or commas (,).
 */
class IgsnCsvParserService
{
    /**
     * CSV delimiter character.
     */
    private const DELIMITER = '|';

    /**
     * Required fields that must be present and non-empty.
     */
    private const REQUIRED_FIELDS = [
        'igsn',
        'title',
        'name',
    ];

    /**
     * Recommended fields - warnings issued if missing.
     */
    private const RECOMMENDED_FIELDS = [
        'latitude',
        'longitude',
        'collector',
        'collection_start_date',
    ];

    /**
     * Fields that contain multiple values separated by semicolon and space.
     */
    private const SEMICOLON_MULTI_VALUE_FIELDS = [
        'sample_other_names',
        'classification',
        'contributor',
        'contributorType',
        'identifier',
        'identifierType',
        'relatedIdentifier',
        'relatedIdentifierType',
        'relationtype',
        'relatedidentifierType', // lowercase variant in DIVE CSV
        'funderName',
        'funderIdentifier',
        'size',
        'size_unit',
    ];

    /**
     * Fields that contain multiple values separated by comma and space.
     */
    private const COMMA_MULTI_VALUE_FIELDS = [
        'geological_age',
        'geological_unit',
    ];

    /**
     * Parse CSV content and return structured data.
     *
     * @param  string  $csvContent  Raw CSV file content
     * @return array{rows: list<array<string, mixed>>, warnings: list<array{row: int, field: string, message: string}>, errors: list<array{row: int, message: string}>, headers: list<string>}
     */
    public function parse(string $csvContent): array
    {
        $lines = $this->splitLines($csvContent);

        if (count($lines) < 2) {
            return [
                'rows' => [],
                'warnings' => [],
                'errors' => [['row' => 0, 'message' => 'CSV file must contain a header row and at least one data row.']],
                'headers' => [],
            ];
        }

        $headers = $this->parseHeaders($lines[0]);

        // Validate required headers exist
        $missingHeaders = $this->getMissingRequiredHeaders($headers);
        if (count($missingHeaders) > 0) {
            return [
                'rows' => [],
                'warnings' => [],
                'errors' => [['row' => 1, 'message' => 'Missing required columns: '.implode(', ', $missingHeaders)]],
                'headers' => $headers,
            ];
        }

        $rows = [];
        $warnings = [];
        $errors = [];

        // Parse data rows (skip header)
        for ($i = 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if ($line === '') {
                continue;
            }

            $rowNumber = $i + 1; // 1-indexed for user display
            $rowResult = $this->parseRow($line, $headers, $rowNumber);

            if (count($rowResult['errors']) > 0) {
                $errors = array_merge($errors, $rowResult['errors']);

                continue;
            }

            $rows[] = $rowResult['data'];
            $warnings = array_merge($warnings, $rowResult['warnings']);
        }

        return [
            'rows' => $rows,
            'warnings' => $warnings,
            'errors' => $errors,
            'headers' => $headers,
        ];
    }

    /**
     * Parse a single CSV row.
     *
     * @param  list<string>  $headers
     * @return array{data: array<string, mixed>, warnings: list<array{row: int, field: string, message: string}>, errors: list<array{row: int, message: string}>}
     */
    private function parseRow(string $line, array $headers, int $rowNumber): array
    {
        // PHP 8.4+ requires escape parameter to avoid deprecation
        $values = str_getcsv($line, self::DELIMITER, '"', '');
        $warnings = [];
        $errors = [];

        // Ensure we have enough values for all headers
        while (count($values) < count($headers)) {
            $values[] = '';
        }

        $data = [];
        foreach ($headers as $index => $header) {
            $value = trim($values[$index] ?? '');
            $data[$header] = $value;
        }

        // Validate required fields
        foreach (self::REQUIRED_FIELDS as $field) {
            if (! isset($data[$field]) || $data[$field] === '') {
                $errors[] = [
                    'row' => $rowNumber,
                    'message' => "Missing required field: {$field}",
                ];
            }
        }

        if (count($errors) > 0) {
            return ['data' => [], 'warnings' => [], 'errors' => $errors];
        }

        // Check recommended fields
        foreach (self::RECOMMENDED_FIELDS as $field) {
            if (! isset($data[$field]) || $data[$field] === '') {
                $warnings[] = [
                    'row' => $rowNumber,
                    'field' => $field,
                    'message' => "Recommended field '{$field}' is empty.",
                ];
            }
        }

        // Parse multi-value fields
        $parsedData = $this->parseMultiValueFields($data);

        // Parse structured data
        $parsedData['_contributors'] = $this->parseContributors($data);
        $parsedData['_related_identifiers'] = $this->parseRelatedIdentifiers($data);
        $parsedData['_funding_references'] = $this->parseFundingReferences($data);
        $parsedData['_creator'] = $this->parseCreator($data);
        $parsedData['_geo_location'] = $this->parseGeoLocation($data);
        $parsedData['_sizes'] = $this->parseSizes($parsedData);
        $parsedData['_row_number'] = $rowNumber;

        return ['data' => $parsedData, 'warnings' => $warnings, 'errors' => []];
    }

    /**
     * Parse multi-value fields into arrays.
     *
     * @param  array<string, string>  $data
     * @return array<string, mixed>
     */
    private function parseMultiValueFields(array $data): array
    {
        $result = $data;

        // Parse semicolon-separated fields
        foreach (self::SEMICOLON_MULTI_VALUE_FIELDS as $field) {
            if (isset($data[$field])) {
                $result[$field] = $data[$field] !== '' ? $this->splitMultiValue($data[$field], '; ') : [];
            }
        }

        // Parse comma-separated fields
        foreach (self::COMMA_MULTI_VALUE_FIELDS as $field) {
            if (isset($data[$field])) {
                $result[$field] = $data[$field] !== '' ? $this->splitMultiValue($data[$field], ', ') : [];
            }
        }

        return $result;
    }

    /**
     * Parse contributor data from CSV row.
     *
     * @param  array<string, string>  $data
     * @return list<array{name: string, type: string, identifier: string|null, identifierType: string|null}>
     */
    private function parseContributors(array $data): array
    {
        $names = $this->splitMultiValue($data['contributor'] ?? '', '; ');
        $types = $this->splitMultiValue($data['contributorType'] ?? '', '; ');
        $identifiers = $this->splitMultiValue($data['identifier'] ?? '', '; ');
        $identifierTypes = $this->splitMultiValue($data['identifierType'] ?? $data['identifiertype'] ?? '', '; ');

        $contributors = [];
        $count = max(count($names), 1);

        for ($i = 0; $i < $count; $i++) {
            $name = $names[$i] ?? '';
            if ($name === '') {
                continue;
            }

            $contributors[] = [
                'name' => $name,
                'type' => $types[$i] ?? 'Other',
                'identifier' => $this->normalizeIdentifier($identifiers[$i] ?? ''),
                'identifierType' => $identifierTypes[$i] ?? null,
            ];
        }

        return $contributors;
    }

    /**
     * Parse related identifiers from CSV row.
     *
     * CSV has multiple relatedIdentifier columns. We need to handle both sets.
     * Also handles parent_igsn as a special relatedIdentifier with type IGSN
     * and relationType IsPartOf.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{identifier: string, type: string, relationType: string}>
     */
    private function parseRelatedIdentifiers(array $data): array
    {
        $result = [];

        // Handle parent_igsn as a relatedIdentifier with IsPartOf relation
        $parentIgsn = trim((string) ($data['parent_igsn'] ?? ''));
        if ($parentIgsn !== '') {
            $result[] = [
                'identifier' => $parentIgsn,
                'type' => 'IGSN',
                'relationType' => 'IsPartOf',
            ];
        }

        // Get all related identifier fields (may be arrays from multi-value parsing)
        $identifiers = is_array($data['relatedIdentifier'] ?? null)
            ? $data['relatedIdentifier']
            : $this->splitMultiValue((string) ($data['relatedIdentifier'] ?? ''), '; ');

        // Handle case sensitivity variations in column names
        $types = is_array($data['relatedIdentifierType'] ?? $data['relatedidentifierType'] ?? null)
            ? ($data['relatedIdentifierType'] ?? $data['relatedidentifierType'])
            : $this->splitMultiValue((string) ($data['relatedIdentifierType'] ?? $data['relatedidentifierType'] ?? ''), '; ');

        $relationTypes = is_array($data['relationtype'] ?? null)
            ? $data['relationtype']
            : $this->splitMultiValue((string) ($data['relationtype'] ?? ''), '; ');

        $count = count($identifiers);
        for ($i = 0; $i < $count; $i++) {
            $rawIdentifier = $identifiers[$i] ?? '';
            $identifier = is_string($rawIdentifier) ? trim($rawIdentifier) : '';
            if ($identifier === '') {
                continue;
            }

            $normalizedIdentifier = $this->normalizeIdentifier($identifier);
            if ($normalizedIdentifier === null) {
                continue;
            }

            $rawType = $types[$i] ?? 'DOI';
            $rawRelationType = $relationTypes[$i] ?? 'IsRelatedTo';

            $result[] = [
                'identifier' => $normalizedIdentifier,
                'type' => is_string($rawType) ? $rawType : 'DOI',
                'relationType' => is_string($rawRelationType) ? $rawRelationType : 'IsRelatedTo',
            ];
        }

        return $result;
    }

    /**
     * Parse funding references from CSV row.
     *
     * Automatically detects the funderIdentifierType based on the identifier URL pattern.
     *
     * @param  array<string, string>  $data
     * @return list<array{name: string, identifier: string|null, identifierType: string|null}>
     */
    private function parseFundingReferences(array $data): array
    {
        $names = $this->splitMultiValue($data['funderName'] ?? '', '; ');
        $identifiers = $this->splitMultiValue($data['funderIdentifier'] ?? '', '; ');

        $funders = [];
        $namesCount = count($names);
        for ($i = 0; $i < $namesCount; $i++) {
            $name = trim($names[$i]);
            if ($name === '') {
                continue;
            }

            $identifier = isset($identifiers[$i]) ? trim($identifiers[$i]) : '';
            $normalizedIdentifier = $identifier !== '' ? $this->normalizeIdentifier($identifier) : null;

            $funders[] = [
                'name' => $name,
                'identifier' => $normalizedIdentifier,
                'identifierType' => FunderIdentifierTypeDetector::detect($normalizedIdentifier),
            ];
        }

        return $funders;
    }

    /**
     * Parse creator (collector) data from CSV row.
     *
     * Supports two input modes:
     * 1. Separate givenName/familyName columns (preferred when available)
     * 2. Combined collector field (parsed as "FamilyName, GivenName" or "GivenName FamilyName")
     *
     * @param  array<string, string>  $data
     * @return array{familyName: string|null, givenName: string|null, orcid: string|null, affiliation: string|null, ror: string|null}
     */
    private function parseCreator(array $data): array
    {
        $familyName = null;
        $givenName = null;

        // Check for dedicated givenName/familyName columns first (these are curated and reliable)
        $csvGivenName = trim($data['givenName'] ?? '');
        $csvFamilyName = trim($data['familyName'] ?? '');

        if ($csvGivenName !== '' || $csvFamilyName !== '') {
            // Use dedicated columns when available
            $givenName = $csvGivenName !== '' ? $csvGivenName : null;
            $familyName = $csvFamilyName !== '' ? $csvFamilyName : null;
        } else {
            // Fallback: Parse from collector field (format: "FamilyName, GivenName" or "GivenName FamilyName")
            $collectorName = trim($data['collector'] ?? '');

            if ($collectorName !== '') {
                if (str_contains($collectorName, ',')) {
                    // Format: "FamilyName, GivenName"
                    $parts = explode(',', $collectorName, 2);
                    $familyName = trim($parts[0]);
                    $givenName = isset($parts[1]) ? trim($parts[1]) : null;
                } else {
                    // Format: "GivenName FamilyName" (assume last word is family name)
                    $parts = explode(' ', $collectorName);
                    if (count($parts) > 1) {
                        $familyName = array_pop($parts);
                        $givenName = implode(' ', $parts);
                    } else {
                        $familyName = $collectorName;
                    }
                }
            }
        }

        // Get ORCID from 'orcid' column (CSV actual column name) or 'collector_identifier' (unit test format)
        $orcid = $data['orcid'] ?? $data['collector_identifier'] ?? '';

        // Get affiliation from 'affiliation' (real CSV) or 'collector_affiliation' (unit test format)
        $affiliation = $data['affiliation'] ?? $data['collector_affiliation'] ?? '';

        return [
            'familyName' => $familyName ?: null,
            'givenName' => $givenName ?: null,
            'orcid' => $this->normalizeIdentifier($orcid),
            'affiliation' => ! empty($affiliation) ? $affiliation : null,
            'ror' => $this->normalizeIdentifier($data['ror'] ?? $data['collector_affiliation_identifier'] ?? ''),
        ];
    }

    /**
     * Parse size data from CSV row into structured arrays.
     *
     * Decomposes size values and their corresponding units into numeric value, unit, and type.
     * Supports multiple size specifications separated by semicolons.
     *
     * Example: size="0.9; 146" + size_unit="Drilled Length [m]; Core Diameter [mm]"
     *   → [
     *       ['numeric_value' => '0.9', 'unit' => 'm', 'type' => 'Drilled Length'],
     *       ['numeric_value' => '146', 'unit' => 'mm', 'type' => 'Core Diameter'],
     *     ]
     *
     * @param  array<string, mixed>  $data  Parsed data (size/size_unit already split into arrays)
     * @return list<array{numeric_value: string, unit: string|null, type: string|null}>
     */
    private function parseSizes(array $data): array
    {
        $sizes = is_array($data['size'] ?? null)
            ? $data['size']
            : $this->splitMultiValue((string) ($data['size'] ?? ''), '; ');

        $units = is_array($data['size_unit'] ?? null)
            ? $data['size_unit']
            : $this->splitMultiValue((string) ($data['size_unit'] ?? ''), '; ');

        $result = [];
        $count = count($sizes);

        for ($i = 0; $i < $count; $i++) {
            $sizeValue = trim((string) ($sizes[$i] ?? ''));
            if ($sizeValue === '') {
                continue;
            }

            $unitString = trim((string) ($units[$i] ?? ''));
            $parsed = $this->parseUnitString($unitString);

            $result[] = [
                'numeric_value' => $sizeValue,
                'unit' => $parsed['unit'],
                'type' => $parsed['type'],
            ];
        }

        return $result;
    }

    /**
     * Parse a unit string like "Drilled Length [m]" into type and unit components.
     *
     * @return array{type: string|null, unit: string|null}
     */
    private function parseUnitString(string $unitString): array
    {
        if ($unitString === '') {
            return ['type' => null, 'unit' => null];
        }

        // Match pattern: "Type [unit]" e.g., "Drilled Length [m]"
        if (preg_match('/^(.+?)\s*\[([^\]]+)\]$/', $unitString, $matches)) {
            return [
                'type' => trim($matches[1]),
                'unit' => trim($matches[2]),
            ];
        }

        // No bracket pattern found — treat the whole string as type
        return ['type' => $unitString, 'unit' => null];
    }

    /**
     * Parse geo location data from CSV row.
     *
     * @param  array<string, string>  $data
     * @return array{latitude: float|null, longitude: float|null, elevation: float|null, elevationUnit: string|null, place: string|null}
     */
    private function parseGeoLocation(array $data): array
    {
        $placeParts = array_filter([
            $data['locality'] ?? $data['primary_location_name'] ?? null,
            $data['city'] ?? null,
            $data['province'] ?? null,
            $data['country'] ?? null,
            $data['location_description'] ?? null,
        ]);

        // Handle both camelCase and snake_case for elevation unit
        $elevationUnit = $data['elevationUnit'] ?? $data['elevation_unit'] ?? null;

        return [
            'latitude' => $this->parseFloat($data['latitude'] ?? ''),
            'longitude' => $this->parseFloat($data['longitude'] ?? ''),
            'elevation' => $this->parseFloat($data['elevation'] ?? ''),
            'elevationUnit' => ! empty($elevationUnit) ? $elevationUnit : null,
            'place' => count($placeParts) > 0 ? implode(', ', $placeParts) : null,
        ];
    }

    /**
     * Split CSV content into lines, handling different line endings.
     *
     * @return list<string>
     */
    private function splitLines(string $content): array
    {
        // Normalize line endings
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        return explode("\n", $content);
    }

    /**
     * Parse header row.
     *
     * @return list<string>
     */
    private function parseHeaders(string $headerLine): array
    {
        // PHP 8.4+ requires escape parameter to avoid deprecation
        $headers = str_getcsv($headerLine, self::DELIMITER, '"', '');

        $result = [];
        foreach ($headers as $h) {
            $result[] = is_string($h) ? trim($h) : '';
        }

        return $result;
    }

    /**
     * Get missing required headers.
     *
     * @param  list<string>  $headers
     * @return list<string>
     */
    private function getMissingRequiredHeaders(array $headers): array
    {
        $normalizedHeaders = array_map('strtolower', $headers);

        $missing = [];
        foreach (self::REQUIRED_FIELDS as $field) {
            if (! in_array(strtolower($field), $normalizedHeaders, true)) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * Split a multi-value string into an array.
     *
     * @return list<string>
     */
    private function splitMultiValue(string $value, string $delimiter): array
    {
        if ($value === '' || $delimiter === '') {
            return [];
        }

        $parts = explode($delimiter, $value);

        $result = [];
        foreach ($parts as $v) {
            $trimmed = trim($v);
            if ($trimmed !== '') {
                $result[] = $trimmed;
            }
        }

        return $result;
    }

    /**
     * Normalize an identifier (URL, ORCID, ROR, DOI).
     */
    private function normalizeIdentifier(string $identifier): ?string
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return null;
        }

        // Already a full URL
        if (Str::startsWith($identifier, ['http://', 'https://'])) {
            return $identifier;
        }

        // ORCID without URL
        if (preg_match('/^\d{4}-\d{4}-\d{4}-\d{3}[\dX]$/i', $identifier)) {
            return "https://orcid.org/{$identifier}";
        }

        return $identifier;
    }

    /**
     * Parse a string to float, returning null if invalid.
     */
    private function parseFloat(string $value): ?float
    {
        $value = trim($value);

        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    /**
     * Parse description JSON field.
     *
     * @return array<mixed>|null
     */
    public function parseDescriptionJson(string $json): ?array
    {
        if ($json === '') {
            return null;
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * Parse collection date range.
     *
     * @return array{start: string|null, end: string|null}
     */
    public function parseCollectionDates(string $startDate, string $endDate): array
    {
        return [
            'start' => $this->normalizeDate($startDate),
            'end' => $this->normalizeDate($endDate),
        ];
    }

    /**
     * Normalize a date string. Preserves YYYY, YYYY-MM, or YYYY-MM-DD format.
     */
    private function normalizeDate(string $date): ?string
    {
        $date = trim($date);

        if ($date === '') {
            return null;
        }

        // Year only (YYYY)
        if (preg_match('/^\d{4}$/', $date)) {
            return $date;
        }

        // Year-month (YYYY-MM)
        if (preg_match('/^\d{4}-\d{2}$/', $date)) {
            return $date;
        }

        // Full date (YYYY-MM-DD)
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }

        // Try to parse other formats
        $timestamp = strtotime($date);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }

        return null;
    }
}
