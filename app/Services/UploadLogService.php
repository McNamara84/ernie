<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UploadErrorCode;
use App\Support\UploadError;
use Illuminate\Support\Facades\Log;

/**
 * Service for logging upload failures with structured data.
 *
 * Provides consistent logging for file upload failures, making them
 * visible on the /logs page for administrators to monitor and debug.
 */
class UploadLogService
{
    /**
     * Log an upload failure.
     *
     * @param  'xml'|'csv'  $uploadType  Type of upload (xml or csv)
     * @param  string  $filename  Name of the uploaded file
     * @param  UploadError  $error  Structured error information
     * @param  array<string, mixed>  $context  Additional context for logging
     */
    public function logFailure(
        string $uploadType,
        string $filename,
        UploadError $error,
        array $context = []
    ): void {
        $logLevel = $error->code->logLevel();

        Log::$logLevel("Upload failed: {$uploadType}", [
            'upload_type' => $uploadType,
            'filename' => $filename,
            'error_code' => $error->code->value,
            'error_category' => $error->category(),
            'error_message' => $error->message(),
            'error_field' => $error->field,
            'error_row' => $error->row,
            'error_identifier' => $error->identifier,
            'user_id' => auth()->id(),
            'user_email' => auth()->user()?->email,
            'ip_address' => request()->ip(),
            'timestamp' => now()->toIso8601String(),
            ...$context,
        ]);
    }

    /**
     * Log a simple upload failure from an error code.
     *
     * @param  'xml'|'csv'  $uploadType
     */
    public function logSimpleFailure(
        string $uploadType,
        string $filename,
        UploadErrorCode $code,
        ?string $customMessage = null,
        array $context = []
    ): void {
        $error = $customMessage
            ? UploadError::withMessage($code, $customMessage)
            : UploadError::fromCode($code);

        $this->logFailure($uploadType, $filename, $error, $context);
    }

    /**
     * Log multiple row errors (for CSV uploads with partial failures).
     *
     * @param  string  $filename  Name of the CSV file
     * @param  list<UploadError>  $errors  Array of row-level errors
     * @param  array<string, mixed>  $context  Additional context
     */
    public function logMultipleErrors(
        string $filename,
        array $errors,
        array $context = []
    ): void {
        // Limit logged errors to prevent log bloat
        $maxLoggedErrors = 20;
        $errorCount = count($errors);

        $loggedErrors = array_map(
            fn (UploadError $e) => $e->toArray(),
            array_slice($errors, 0, $maxLoggedErrors)
        );

        Log::warning('IGSN CSV upload had row errors', [
            'upload_type' => 'csv',
            'filename' => $filename,
            'total_errors' => $errorCount,
            'logged_errors' => count($loggedErrors),
            'errors' => $loggedErrors,
            'user_id' => auth()->id(),
            'user_email' => auth()->user()?->email,
            'ip_address' => request()->ip(),
            'timestamp' => now()->toIso8601String(),
            ...$context,
        ]);
    }

    /**
     * Log legacy-format errors (for backward compatibility).
     *
     * @param  list<array{row?: int, igsn?: string, message?: string}>  $legacyErrors
     */
    public function logLegacyErrors(
        string $filename,
        array $legacyErrors,
        UploadErrorCode $defaultCode = UploadErrorCode::CSV_PARSE_ERROR
    ): void {
        $errors = array_map(
            fn (array $e) => UploadError::fromLegacyError($e, $defaultCode),
            $legacyErrors
        );

        $this->logMultipleErrors($filename, $errors);
    }

    /**
     * Log a successful upload (for audit trail).
     *
     * @param  'xml'|'csv'  $uploadType
     * @param  array<string, mixed>  $context
     */
    public function logSuccess(
        string $uploadType,
        string $filename,
        array $context = []
    ): void {
        Log::info("Upload successful: {$uploadType}", [
            'upload_type' => $uploadType,
            'filename' => $filename,
            'user_id' => auth()->id(),
            'user_email' => auth()->user()?->email,
            'timestamp' => now()->toIso8601String(),
            ...$context,
        ]);
    }
}
