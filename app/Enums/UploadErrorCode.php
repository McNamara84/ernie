<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Centralized error codes for file upload failures.
 *
 * Provides consistent error identification across XML and CSV uploads,
 * with human-readable messages and categorization for logging.
 */
enum UploadErrorCode: string
{
    // Validation errors (user/client errors)
    case FILE_TOO_LARGE = 'file_too_large';
    case INVALID_FILE_TYPE = 'invalid_file_type';
    case FILE_UNREADABLE = 'file_unreadable';
    case FILE_REQUIRED = 'file_required';
    case MISSING_REQUIRED_FIELD = 'missing_required_field';

    // XML-specific errors
    case XML_PARSE_ERROR = 'xml_parse_error';
    case INVALID_XML_STRUCTURE = 'invalid_xml_structure';
    case INVALID_DOI_FORMAT = 'invalid_doi_format';

    // CSV-specific errors
    case CSV_PARSE_ERROR = 'csv_parse_error';
    case INVALID_CSV_STRUCTURE = 'invalid_csv_structure';
    case MISSING_HEADER = 'missing_header';
    case PARENT_NOT_FOUND = 'parent_not_found';
    case NO_VALID_ROWS = 'no_valid_rows';

    // Data errors
    case DUPLICATE_DOI = 'duplicate_doi';
    case DUPLICATE_IGSN = 'duplicate_igsn';

    // Server errors
    case DATABASE_ERROR = 'database_error';
    case SESSION_ERROR = 'session_error';
    case STORAGE_ERROR = 'storage_error';
    case UNEXPECTED_ERROR = 'unexpected_error';

    /**
     * Get human-readable message for error code.
     */
    public function message(): string
    {
        return match ($this) {
            self::FILE_TOO_LARGE => 'The file exceeds the maximum allowed size.',
            self::INVALID_FILE_TYPE => 'The file type is not supported.',
            self::FILE_UNREADABLE => 'The file could not be read.',
            self::FILE_REQUIRED => 'Please upload a file.',
            self::MISSING_REQUIRED_FIELD => 'A required field is missing.',
            self::XML_PARSE_ERROR => 'The XML file could not be parsed.',
            self::INVALID_XML_STRUCTURE => 'The XML structure is invalid or does not conform to DataCite schema.',
            self::INVALID_DOI_FORMAT => 'The DOI format is invalid.',
            self::CSV_PARSE_ERROR => 'The CSV file could not be parsed.',
            self::INVALID_CSV_STRUCTURE => 'The CSV structure is invalid.',
            self::MISSING_HEADER => 'A required CSV header is missing.',
            self::PARENT_NOT_FOUND => 'The parent IGSN could not be found.',
            self::NO_VALID_ROWS => 'No valid data rows found in the CSV file.',
            self::DUPLICATE_DOI => 'This DOI already exists in the database.',
            self::DUPLICATE_IGSN => 'This IGSN already exists in the database.',
            self::DATABASE_ERROR => 'A database error occurred while saving data.',
            self::SESSION_ERROR => 'A session error occurred. Please reload the page.',
            self::STORAGE_ERROR => 'Failed to store the uploaded data.',
            self::UNEXPECTED_ERROR => 'An unexpected error occurred. Please try again.',
        };
    }

    /**
     * Get error category for logging and display purposes.
     *
     * Categories:
     * - validation: User/client errors (wrong file type, too large, etc.)
     * - data: Data-related errors (parsing, duplicates, missing fields)
     * - server: Server-side errors (database, storage, unexpected)
     */
    public function category(): string
    {
        return match ($this) {
            self::FILE_TOO_LARGE,
            self::INVALID_FILE_TYPE,
            self::FILE_UNREADABLE,
            self::FILE_REQUIRED,
            self::MISSING_REQUIRED_FIELD => 'validation',

            self::XML_PARSE_ERROR,
            self::INVALID_XML_STRUCTURE,
            self::INVALID_DOI_FORMAT,
            self::CSV_PARSE_ERROR,
            self::INVALID_CSV_STRUCTURE,
            self::MISSING_HEADER,
            self::PARENT_NOT_FOUND,
            self::NO_VALID_ROWS,
            self::DUPLICATE_DOI,
            self::DUPLICATE_IGSN => 'data',

            self::DATABASE_ERROR,
            self::SESSION_ERROR,
            self::STORAGE_ERROR,
            self::UNEXPECTED_ERROR => 'server',
        };
    }

    /**
     * Get the appropriate log level for this error.
     */
    public function logLevel(): string
    {
        return match ($this->category()) {
            'validation' => 'info',
            'data' => 'warning',
            'server' => 'error',
            default => 'warning',
        };
    }
}
