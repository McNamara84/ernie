<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\JsonValidationException;
use Illuminate\Support\Facades\Log;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;

/**
 * Validates JSON data against the DataCite 4.6 JSON Schema.
 *
 * This service provides validation for exported Resource and IGSN data
 * to ensure compliance with the DataCite Metadata Schema before export.
 *
 * Supports two validation modes:
 * - Non-strict (default for exports): DOI/identifiers are optional, allows draft resources
 * - Strict (for registration): All required fields including DOI must be present
 */
class JsonSchemaValidator
{
    private const SCHEMA_PATH = 'resources/data/scheme/datacite_4.6_schema.json';

    private const SCHEMA_VERSION = '4.6';

    private Validator $validator;

    private ?object $schema = null;

    public function __construct()
    {
        $this->validator = new Validator;

        // Set max errors to collect all validation issues
        $this->validator->setMaxErrors(50);
    }

    /**
     * Validate JSON data against the DataCite schema.
     *
     * @param  array<string, mixed>  $data  The data to validate
     * @param  bool  $strictMode  If true, requires identifiers/DOI (for registration). If false, allows draft resources.
     * @return bool True if validation passes
     *
     * @throws JsonValidationException If validation fails
     */
    public function validate(array $data, bool $strictMode = false): bool
    {
        $schema = $this->loadSchema();
        $dataObject = $this->arrayToObject($data);

        $result = $this->validator->validate($dataObject, $schema);

        $errors = [];

        if (! $result->isValid()) {
            $errors = $this->formatErrors($result->error());
        }

        // In strict mode, additionally check for required identifiers
        if ($strictMode && empty($data['identifiers'])) {
            $errors[] = [
                'path' => '/identifiers',
                'message' => "Required field 'identifiers' is missing. DOI is required for DataCite registration. (Path: /identifiers)",
                'keyword' => 'required',
                'context' => [
                    'raw_message' => 'The identifiers field is required for DataCite registration but is missing.',
                ],
            ];
        }

        if (! empty($errors)) {
            $this->logValidationErrors($data, $errors);

            throw new JsonValidationException(
                message: 'JSON export validation failed against DataCite Schema version '.self::SCHEMA_VERSION,
                errors: $errors,
                schemaVersion: self::SCHEMA_VERSION,
            );
        }

        return true;
    }

    /**
     * Validate JSON data and return boolean without throwing.
     *
     * @param  array<string, mixed>  $data  The data to validate
     * @param  array<int, array{path: string, message: string, keyword: string, context: array<string, mixed>}>|null  $errors  Reference to store errors if validation fails
     * @param  bool  $strictMode  If true, requires identifiers/DOI (for registration)
     */
    public function isValid(array $data, ?array &$errors = null, bool $strictMode = false): bool
    {
        try {
            $this->validate($data, $strictMode);

            return true;
        } catch (JsonValidationException $e) {
            $errors = $e->getErrors();

            return false;
        }
    }

    /**
     * Load the DataCite JSON Schema.
     */
    private function loadSchema(): object
    {
        if ($this->schema !== null) {
            return $this->schema;
        }

        $schemaPath = base_path(self::SCHEMA_PATH);

        if (! file_exists($schemaPath)) {
            throw new \RuntimeException("DataCite schema file not found at: {$schemaPath}");
        }

        $schemaContent = file_get_contents($schemaPath);

        if ($schemaContent === false) {
            throw new \RuntimeException("Failed to read DataCite schema file: {$schemaPath}");
        }

        $decoded = json_decode($schemaContent);

        if (json_last_error() !== JSON_ERROR_NONE || $decoded === null) {
            throw new \RuntimeException('Failed to parse DataCite schema: '.json_last_error_msg());
        }

        $this->schema = $decoded;

        return $this->schema;
    }

    /**
     * Convert array to object recursively for JSON Schema validation.
     *
     * @param  array<string, mixed>|array<int, mixed>  $data
     * @return object|array<int|string, mixed>
     */
    private function arrayToObject(array $data): object|array
    {
        // Check if it's an associative array (object-like)
        if ($this->isAssociativeArray($data)) {
            $object = new \stdClass;
            foreach ($data as $key => $value) {
                $object->{$key} = is_array($value) ? $this->arrayToObject($value) : $value;
            }

            return $object;
        }

        // It's a sequential array (list)
        return array_map(
            fn ($item) => is_array($item) ? $this->arrayToObject($item) : $item,
            $data
        );
    }

    /**
     * Check if an array is associative (has string keys).
     *
     * @param  array<mixed>  $array
     */
    private function isAssociativeArray(array $array): bool
    {
        if ($array === []) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Format validation errors into a structured array.
     *
     * @return array<int, array{path: string, message: string, keyword: string, context: array<string, mixed>}>
     */
    private function formatErrors(?ValidationError $error): array
    {
        if ($error === null) {
            return [];
        }

        $formatter = new ErrorFormatter;
        $formattedErrors = $formatter->format($error, true);

        $errors = [];
        foreach ($formattedErrors as $path => $messages) {
            foreach ($messages as $message) {
                $errors[] = [
                    'path' => $path,
                    'message' => $this->createHybridMessage($path, $message),
                    'keyword' => $this->extractKeyword($message),
                    'context' => [
                        'raw_message' => $message,
                    ],
                ];
            }
        }

        return $errors;
    }

    /**
     * Create a hybrid error message with both human-readable text and technical details.
     */
    private function createHybridMessage(string $path, string $rawMessage): string
    {
        // Map common validation keywords to human-readable messages
        $humanReadable = $this->getHumanReadableMessage($path, $rawMessage);

        return "{$humanReadable} (Path: {$path})";
    }

    /**
     * Get a human-readable message based on the error path and raw message.
     */
    private function getHumanReadableMessage(string $path, string $rawMessage): string
    {
        // Extract the field name from the path
        $fieldName = $this->extractFieldName($path);

        // Common error patterns and their human-readable equivalents
        $patterns = [
            '/required property/i' => "Required field '{$fieldName}' is missing",
            '/type must be/i' => "Field '{$fieldName}' has an invalid type",
            '/enum/i' => "Field '{$fieldName}' has an invalid value",
            '/minimum|maximum/i' => "Field '{$fieldName}' is out of range",
            '/pattern/i' => "Field '{$fieldName}' does not match the expected pattern",
            '/minLength|maxLength/i' => "Field '{$fieldName}' has an invalid length",
            '/minItems|maxItems/i' => "Field '{$fieldName}' has an invalid number of items",
            '/format/i' => "Field '{$fieldName}' has an invalid format (e.g., date, URI)",
            '/additionalProperties/i' => "Field '{$fieldName}' contains unexpected properties",
        ];

        foreach ($patterns as $pattern => $message) {
            if (preg_match($pattern, $rawMessage)) {
                return $message;
            }
        }

        // Fallback to a generic message with the field name
        return "Validation error in field '{$fieldName}'";
    }

    /**
     * Extract the field name from a JSON path.
     */
    private function extractFieldName(string $path): string
    {
        // Handle root path
        if ($path === '/' || $path === '') {
            return 'root';
        }

        // Extract the last segment of the path
        $segments = explode('/', trim($path, '/'));
        $lastSegment = end($segments);

        // Handle array indices
        if (is_numeric($lastSegment) && count($segments) > 1) {
            return $segments[count($segments) - 2]."[{$lastSegment}]";
        }

        return $lastSegment ?: 'unknown';
    }

    /**
     * Extract the validation keyword from the error message.
     */
    private function extractKeyword(string $message): string
    {
        $keywords = [
            'required', 'type', 'enum', 'minimum', 'maximum',
            'pattern', 'minLength', 'maxLength', 'minItems', 'maxItems',
            'format', 'additionalProperties', 'oneOf', 'anyOf', 'allOf',
        ];

        $messageLower = strtolower($message);
        foreach ($keywords as $keyword) {
            if (str_contains($messageLower, $keyword)) {
                return $keyword;
            }
        }

        return 'unknown';
    }

    /**
     * Log validation errors for debugging and auditing.
     *
     * Logs at error level since validation failures prevent successful export
     * and should be visible in production logs.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array{path: string, message: string, keyword: string, context: array<string, mixed>}>  $errors
     */
    private function logValidationErrors(array $data, array $errors): void
    {
        // Safely extract DOI from different possible locations
        $doi = $data['doi']
            ?? (is_array($data['identifier'] ?? null) ? ($data['identifier']['identifier'] ?? null) : null)
            ?? 'unknown';

        Log::error('DataCite JSON Schema validation failed', [
            'doi' => $doi,
            'schema_version' => self::SCHEMA_VERSION,
            'error_count' => count($errors),
            'errors' => array_map(fn ($e) => [
                'path' => $e['path'],
                'message' => $e['message'],
            ], array_slice($errors, 0, 10)), // Log only first 10 errors
        ]);
    }
}
