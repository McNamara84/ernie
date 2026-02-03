<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\UploadErrorCode;
use App\Http\Requests\UploadIgsnCsvRequest;
use App\Models\Resource;
use App\Services\IgsnCsvParserService;
use App\Services\IgsnStorageService;
use App\Services\UploadLogService;
use App\Support\UploadError;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Controller for uploading IGSN CSV files.
 *
 * Validates, parses, and stores IGSN data from pipe-delimited CSV files.
 */
class UploadIgsnCsvController extends Controller
{
    public function __construct(
        protected IgsnCsvParserService $parser,
        protected IgsnStorageService $storage,
        protected UploadLogService $uploadLogService,
    ) {}

    /**
     * Handle IGSN CSV file upload.
     *
     * @return JsonResponse
     */
    public function __invoke(UploadIgsnCsvRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $file = $validated['file'];
        $filename = $file->getClientOriginalName();

        // Get file contents
        $contents = $file->get();

        if ($contents === false) {
            $error = UploadError::fromCode(UploadErrorCode::FILE_UNREADABLE);
            $this->uploadLogService->logFailure('csv', $filename, $error);

            return $this->errorResponse(
                UploadErrorCode::FILE_UNREADABLE,
                $filename,
            );
        }

        Log::info('IGSN CSV upload started', [
            'filename' => $filename,
            'size' => strlen($contents),
            'user_id' => $request->user()?->id,
        ]);

        try {
            // Parse CSV
            $parseResult = $this->parser->parse($contents);

            // Check for parsing errors
            if (count($parseResult['errors']) > 0) {
                $errors = $this->convertToUploadErrors($parseResult['errors'], UploadErrorCode::CSV_PARSE_ERROR);
                $this->uploadLogService->logMultipleErrors($filename, $errors);

                return $this->multiErrorResponse(
                    'CSV parsing failed.',
                    $filename,
                    $errors,
                );
            }

            if (count($parseResult['rows']) === 0) {
                $error = UploadError::fromCode(UploadErrorCode::NO_VALID_ROWS);
                $this->uploadLogService->logFailure('csv', $filename, $error);

                return $this->errorResponse(
                    UploadErrorCode::NO_VALID_ROWS,
                    $filename,
                );
            }

            // Validate required fields
            $validationErrors = $this->validateRequiredFields($parseResult['rows']);
            if (count($validationErrors) > 0) {
                $errors = $this->convertToUploadErrors($validationErrors, UploadErrorCode::MISSING_REQUIRED_FIELD);
                $this->uploadLogService->logMultipleErrors($filename, $errors);

                return $this->multiErrorResponse(
                    'Validation failed. Required fields are missing.',
                    $filename,
                    $errors,
                );
            }

            // Check for duplicate IGSNs (already exist in database)
            $duplicateErrors = $this->checkForDuplicateIgsns($parseResult['rows']);
            if (count($duplicateErrors) > 0) {
                $errors = $this->convertToUploadErrors($duplicateErrors, UploadErrorCode::DUPLICATE_IGSN);
                $this->uploadLogService->logMultipleErrors($filename, $errors);

                return $this->multiErrorResponse(
                    'Duplicate IGSN(s) found. IGSNs must be globally unique.',
                    $filename,
                    $errors,
                );
            }

            // Store IGSNs
            $result = $this->storage->storeFromCsv(
                $parseResult['rows'],
                $filename,
                $request->user()?->id
            );

            Log::info('IGSN CSV upload completed', [
                'filename' => $filename,
                'created' => $result['created'],
                'errors' => count($result['errors']),
            ]);

            if ($result['created'] === 0) {
                $errors = $this->convertToUploadErrors($result['errors'], UploadErrorCode::STORAGE_ERROR);
                $this->uploadLogService->logMultipleErrors($filename, $errors);

                return $this->multiErrorResponse(
                    'No IGSNs were created due to storage errors.',
                    $filename,
                    $errors,
                );
            }

            // Log success
            $this->uploadLogService->logSuccess('csv', $filename, [
                'created' => $result['created'],
            ]);

            // Convert any partial errors for the response
            $responseErrors = count($result['errors']) > 0
                ? $this->convertToUploadErrors($result['errors'], UploadErrorCode::STORAGE_ERROR)
                : [];

            return response()->json([
                'success' => true,
                'created' => $result['created'],
                'errors' => array_map(fn (UploadError $e) => $e->toArray(), $responseErrors),
                'message' => $result['created'].' IGSN(s) successfully imported.',
                'filename' => $filename,
            ]);

        } catch (\Exception $e) {
            $error = UploadError::withMessage(
                UploadErrorCode::UNEXPECTED_ERROR,
                'An error occurred during import: '.$e->getMessage()
            );
            $this->uploadLogService->logFailure('csv', $filename, $error, [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(
                UploadErrorCode::UNEXPECTED_ERROR,
                $filename,
                'An error occurred during import: '.$e->getMessage(),
                500
            );
        }
    }

    /**
     * Validate required fields in parsed rows.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{row: int, igsn: string, message: string}>
     */
    private function validateRequiredFields(array $rows): array
    {
        $errors = [];

        foreach ($rows as $row) {
            $rowNum = $row['_row_number'] ?? 0;
            $igsn = $row['igsn'] ?? 'unknown';

            // Required: igsn
            if (empty($row['igsn'])) {
                $errors[] = [
                    'row' => $rowNum,
                    'igsn' => $igsn,
                    'message' => 'IGSN is required.',
                ];
            }

            // Required: title
            if (empty($row['title'])) {
                $errors[] = [
                    'row' => $rowNum,
                    'igsn' => $igsn,
                    'message' => 'Title is required.',
                ];
            }

            // Required: name
            if (empty($row['name'])) {
                $errors[] = [
                    'row' => $rowNum,
                    'igsn' => $igsn,
                    'message' => 'Name is required.',
                ];
            }
        }

        return $errors;
    }

    /**
     * Check for duplicate IGSNs that already exist in the database.
     *
     * IGSNs (International Generic Sample Numbers) must be globally unique.
     * This method validates that none of the IGSNs in the upload already exist.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{row: int, igsn: string, message: string}>
     */
    private function checkForDuplicateIgsns(array $rows): array
    {
        $errors = [];

        // Collect all IGSNs from the upload
        $igsns = [];
        foreach ($rows as $row) {
            if (! empty($row['igsn'])) {
                $igsns[$row['igsn']] = $row['_row_number'] ?? 0;
            }
        }

        if (count($igsns) === 0) {
            return $errors;
        }

        // Check which IGSNs already exist in the database
        $existingIgsns = Resource::whereIn('doi', array_keys($igsns))
            ->pluck('doi')
            ->toArray();

        // Report duplicates
        foreach ($existingIgsns as $existingIgsn) {
            $errors[] = [
                'row' => $igsns[$existingIgsn],
                'igsn' => $existingIgsn,
                'message' => "IGSN '{$existingIgsn}' already exists in the database.",
            ];
        }

        return $errors;
    }

    /**
     * Create a structured error JSON response for a single error.
     */
    private function errorResponse(
        UploadErrorCode $code,
        string $filename,
        ?string $customMessage = null,
        int $status = 422
    ): JsonResponse {
        $message = $customMessage ?? $code->message();

        return response()->json([
            'success' => false,
            'message' => $message,
            'filename' => $filename,
            'error' => [
                'category' => $code->category(),
                'code' => $code->value,
                'message' => $message,
                'field' => null,
                'row' => null,
                'identifier' => null,
            ],
        ], $status);
    }

    /**
     * Create a structured error JSON response for multiple errors.
     *
     * @param  list<UploadError>  $errors
     */
    private function multiErrorResponse(
        string $message,
        string $filename,
        array $errors,
        int $status = 422
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'filename' => $filename,
            'errors' => array_map(fn (UploadError $e) => $e->toArray(), $errors),
        ], $status);
    }

    /**
     * Convert legacy error format to UploadError objects.
     *
     * @param  list<array{row?: int, igsn?: string, message?: string}>  $legacyErrors
     * @return list<UploadError>
     */
    private function convertToUploadErrors(array $legacyErrors, UploadErrorCode $code): array
    {
        return array_map(
            fn (array $e) => UploadError::fromLegacyError($e, $code),
            $legacyErrors
        );
    }
}
