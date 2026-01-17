<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UploadIgsnCsvRequest;
use App\Services\IgsnCsvParserService;
use App\Services\IgsnStorageService;
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

        // Get file contents
        $contents = $file->get();

        if ($contents === false) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to read file contents.',
            ], 422);
        }

        $filename = $file->getClientOriginalName();

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
                return response()->json([
                    'success' => false,
                    'message' => 'CSV parsing failed.',
                    'errors' => $parseResult['errors'],
                ], 422);
            }

            if (count($parseResult['rows']) === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'No valid data rows found in CSV.',
                ], 422);
            }

            // Validate required fields
            $validationErrors = $this->validateRequiredFields($parseResult['rows']);
            if (count($validationErrors) > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed.',
                    'errors' => $validationErrors,
                ], 422);
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
                return response()->json([
                    'success' => false,
                    'message' => 'No IGSNs were created.',
                    'errors' => $result['errors'],
                ], 422);
            }

            return response()->json([
                'success' => true,
                'created' => $result['created'],
                'errors' => $result['errors'],
                'message' => $result['created'].' IGSN(s) successfully imported.',
            ]);

        } catch (\Exception $e) {
            Log::error('IGSN CSV upload failed', [
                'filename' => $filename,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred during import: '.$e->getMessage(),
            ], 500);
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
}
