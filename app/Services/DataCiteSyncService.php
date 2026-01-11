<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Resource;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * Service for automatic DataCite synchronization when saving resources.
 *
 * This service handles the "sync on save" logic (Issue #383):
 * - Checks if a resource is registered with DataCite (has DOI)
 * - If registered: Updates metadata at DataCite
 * - If not registered: No action needed
 * - API errors do NOT prevent database save (graceful degradation)
 *
 * @see https://github.com/McNamara84/ernie/issues/383
 */
class DataCiteSyncService
{
    public function __construct(
        private readonly DataCiteRegistrationService $registrationService,
    ) {}

    /**
     * Synchronize resource metadata with DataCite if registered.
     *
     * This method should be called AFTER the resource has been saved to the database.
     * It will attempt to update the metadata at DataCite if the resource has a DOI.
     *
     * Important: This method never throws exceptions. All errors are captured
     * and returned as DataCiteSyncResult::failed() to allow graceful degradation.
     *
     * @param  Resource  $resource  The resource to sync (must be freshly saved)
     * @return DataCiteSyncResult Result of the sync attempt
     */
    public function syncIfRegistered(Resource $resource): DataCiteSyncResult
    {
        // Check if resource has a DOI (= is registered with DataCite)
        if ($resource->doi === null || $resource->doi === '') {
            Log::debug('DataCite sync skipped: Resource has no DOI', [
                'resource_id' => $resource->id,
            ]);

            return DataCiteSyncResult::notRequired();
        }

        // Check if resource has a landing page (required for DataCite update)
        $resource->loadMissing('landingPage');
        if ($resource->landingPage === null) {
            // At this point we know DOI is not null (checked above)
            $doi = $resource->doi;
            assert($doi !== null);

            Log::warning('DataCite sync skipped: Resource has DOI but no landing page', [
                'resource_id' => $resource->id,
                'doi' => $doi,
            ]);

            return DataCiteSyncResult::failed(
                $doi,
                'Landing page is required to update DataCite metadata.'
            );
        }

        return $this->performSync($resource);
    }

    /**
     * Perform the actual DataCite metadata update.
     *
     * @param  Resource  $resource  Resource with DOI and landing page
     * @return DataCiteSyncResult Result of the sync attempt
     */
    private function performSync(Resource $resource): DataCiteSyncResult
    {
        // At this point we know DOI is not null (checked in syncIfRegistered)
        $doi = $resource->doi;
        assert($doi !== null);

        try {
            Log::info('Starting automatic DataCite sync', [
                'resource_id' => $resource->id,
                'doi' => $doi,
                'test_mode' => $this->registrationService->isTestMode(),
            ]);

            $this->registrationService->updateMetadata($resource);

            Log::info('DataCite sync completed successfully', [
                'resource_id' => $resource->id,
                'doi' => $doi,
            ]);

            return DataCiteSyncResult::succeeded($doi);

        } catch (RequestException $e) {
            $errorMessage = $this->extractErrorMessage($e);

            // PHPDoc indicates response is always present, but it can be null at runtime
            $response = $e->response;
            /** @phpstan-ignore notIdentical.alwaysTrue */
            $statusCode = $response !== null ? $response->status() : null;

            Log::error('DataCite sync failed (API error)', [
                'resource_id' => $resource->id,
                'doi' => $doi,
                'error' => $errorMessage,
                'status_code' => $statusCode,
            ]);

            return DataCiteSyncResult::failed($doi, $errorMessage);

        } catch (\RuntimeException $e) {
            Log::error('DataCite sync failed (runtime error)', [
                'resource_id' => $resource->id,
                'doi' => $doi,
                'error' => $e->getMessage(),
            ]);

            return DataCiteSyncResult::failed($doi, $e->getMessage());
        }
    }

    /**
     * Extract user-friendly error message from DataCite API exception.
     *
     * @param  RequestException  $e  The HTTP client exception
     * @return string Human-readable error message
     */
    private function extractErrorMessage(RequestException $e): string
    {
        // PHPDoc indicates response is always present, but it can be null at runtime
        $response = $e->response;

        /** @phpstan-ignore identical.alwaysFalse */
        if ($response === null) {
            return 'Unable to connect to DataCite API. Please try again later.';
        }

        /** @var array{errors?: array<int, array{title?: string, detail?: string}>}|null $apiError */
        $apiError = $response->json();

        /** @phpstan-ignore booleanAnd.rightAlwaysTrue */
        if (isset($apiError['errors']) && is_array($apiError['errors']) && count($apiError['errors']) > 0) {
            $firstError = $apiError['errors'][0];

            return $firstError['title'] ?? $firstError['detail'] ?? 'DataCite API error';
        }

        return match ($response->status()) {
            401 => 'DataCite authentication failed. Please contact support.',
            403 => 'Access denied by DataCite. Please contact support.',
            404 => 'DOI not found at DataCite. It may have been deleted.',
            422 => 'Invalid metadata format. Please review your data.',
            429 => 'Too many requests to DataCite. Please wait and try again.',
            500, 502, 503, 504 => 'DataCite service is temporarily unavailable.',
            default => 'DataCite API error (HTTP '.$response->status().')',
        };
    }
}
