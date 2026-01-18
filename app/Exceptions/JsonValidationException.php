<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Exception thrown when JSON Schema validation fails.
 *
 * Contains structured validation errors with human-readable messages
 * and technical details for debugging.
 */
class JsonValidationException extends Exception
{
    /**
     * @param  array<int, array{path: string, message: string, keyword: string, context: array<string, mixed>}>  $errors
     */
    public function __construct(
        string $message,
        private readonly array $errors = [],
        private readonly string $schemaVersion = '4.6',
    ) {
        parent::__construct($message);
    }

    /**
     * Get all validation errors.
     *
     * @return array<int, array{path: string, message: string, keyword: string, context: array<string, mixed>}>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get the schema version that was used for validation.
     */
    public function getSchemaVersion(): string
    {
        return $this->schemaVersion;
    }

    /**
     * Get a summary of all error messages.
     *
     * @return array<int, string>
     */
    public function getErrorMessages(): array
    {
        return array_map(
            fn (array $error): string => $error['message'],
            $this->errors
        );
    }

    /**
     * Convert the exception to an array for JSON responses.
     *
     * @return array{message: string, schema_version: string, errors: array<int, array{path: string, message: string, keyword: string, context: array<string, mixed>}>}
     */
    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'schema_version' => $this->schemaVersion,
            'errors' => $this->errors,
        ];
    }
}
