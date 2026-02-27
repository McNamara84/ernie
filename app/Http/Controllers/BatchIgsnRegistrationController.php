<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\IgsnMetadata;
use App\Models\Resource;
use App\Services\DataCiteRegistrationService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Controller for batch registration of IGSNs at DataCite.
 *
 * Processes each IGSN individually with error isolation – one failure
 * does not stop the remaining registrations.
 */
class BatchIgsnRegistrationController extends Controller
{
    /**
     * Maximum number of IGSNs that can be registered in a single batch.
     *
     * Kept intentionally low because each registration performs a synchronous
     * HTTP request to the DataCite API, so large batches can exceed web-server
     * request timeouts or tie up PHP workers.
     */
    private const MAX_BATCH_SIZE = 25;

    /**
     * Batch register or update IGSNs at DataCite.
     *
     * Each IGSN is processed independently: failures are recorded but do not
     * prevent other IGSNs from being registered. Returns HTTP 200 if all succeed,
     * or HTTP 207 (Multi-Status) if some fail.
     */
    public function register(Request $request): JsonResponse
    {
        // Authorization: only users who can register production DOIs may register IGSNs
        if (! $request->user()?->can('register-production-doi')) {
            abort(403, 'You are not authorized to register IGSNs.');
        }

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:' . self::MAX_BATCH_SIZE],
            'ids.*' => ['required', 'integer', 'exists:resources,id'],
        ]);

        /** @var array<int> $ids */
        $ids = array_values(array_unique($validated['ids']));

        /** @var array{success: list<array<string, mixed>>, failed: list<array<string, mixed>>} $results */
        $results = ['success' => [], 'failed' => []];

        /** @var DataCiteRegistrationService $service */
        $service = app(DataCiteRegistrationService::class);

        // Fetch all resources with ALL relations needed by DataCiteJsonExporter
        // to avoid N+1 queries when export() is called inside the loop.
        $resources = Resource::with([
            'igsnMetadata',
            'landingPage',
            'resourceType',
            'language',
            'publisher',
            'titles.titleType',
            'creators.creatorable',
            'creators.affiliations',
            'contributors.contributorable',
            'contributors.contributorTypes',
            'contributors.affiliations',
            'descriptions.descriptionType',
            'dates.dateType',
            'subjects',
            'geoLocations',
            'rights',
            'relatedIdentifiers.identifierType',
            'relatedIdentifiers.relationType',
            'fundingReferences.funderIdentifierType',
            'alternateIdentifiers',
            'sizes',
            'formats',
        ])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        foreach ($ids as $resourceId) {
            $resource = $resources->get($resourceId);

            if (! $resource instanceof Resource || $resource->igsnMetadata === null) {
                $results['failed'][] = [
                    'id' => $resourceId,
                    'igsn' => null,
                    'reason' => 'IGSN not found',
                ];

                continue;
            }

            if (! $resource->landingPage) {
                $results['failed'][] = [
                    'id' => $resourceId,
                    'igsn' => $resource->doi,
                    'reason' => 'No landing page configured',
                ];

                continue;
            }

            $metadata = $resource->igsnMetadata;
            $wasAlreadyRegistered = $metadata->isRegistered();

            // Set publicationYear to current year only for new registrations (Issue #438).
            // Already-registered IGSNs keep their original publicationYear.
            // Only persisted after a successful DataCite response.
            if (! $wasAlreadyRegistered) {
                $resource->publication_year = (int) date('Y');
            }

            try {
                $metadata->updateStatus(IgsnMetadata::STATUS_REGISTERING);

                if ($wasAlreadyRegistered) {
                    $response = $service->updateMetadata($resource);
                } else {
                    $response = $service->registerIgsn($resource);
                }

                $doi = $response['data']['id'] ?? $resource->doi;

                // Update resource DOI if DataCite returned a different one
                if (! $wasAlreadyRegistered && $doi !== null && $doi !== $resource->doi) {
                    $resource->doi = $doi;
                }

                // Persist publicationYear (and possibly updated DOI) after successful DataCite response
                $resource->save();

                $metadata->updateStatus(IgsnMetadata::STATUS_REGISTERED);

                $results['success'][] = [
                    'id' => $resourceId,
                    'igsn' => $resource->doi,
                    'doi' => $doi,
                    'updated' => $wasAlreadyRegistered,
                ];

                Log::info('Batch IGSN registration: success', [
                    'resource_id' => $resourceId,
                    'doi' => $doi,
                    'updated' => $wasAlreadyRegistered,
                ]);
            } catch (RequestException $e) {
                // DataCite API error – extract user-friendly message
                $apiResponse = $e->response;
                /** @phpstan-ignore notIdentical.alwaysTrue */
                $apiError = $apiResponse !== null ? $apiResponse->json() : null;

                $errorMessage = 'Failed to communicate with DataCite API.';
                if (isset($apiError['errors']) && is_array($apiError['errors']) && count($apiError['errors']) > 0) {
                    $firstError = $apiError['errors'][0];
                    $errorMessage = $firstError['title'] ?? $firstError['detail'] ?? $errorMessage;
                }

                $metadata->markAsError($errorMessage);

                $results['failed'][] = [
                    'id' => $resourceId,
                    'igsn' => $resource->doi,
                    'reason' => $errorMessage,
                ];

                Log::error('Batch IGSN registration: DataCite API error', [
                    'resource_id' => $resourceId,
                    'error' => $e->getMessage(),
                    'api_response' => $apiError,
                ]);
            } catch (\InvalidArgumentException|\RuntimeException $e) {
                // Actionable errors (invalid prefix, missing landing page, etc.)
                $metadata->markAsError($e->getMessage());

                $results['failed'][] = [
                    'id' => $resourceId,
                    'igsn' => $resource->doi,
                    'reason' => $e->getMessage(),
                ];

                Log::warning('Batch IGSN registration: validation error', [
                    'resource_id' => $resourceId,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable $e) {
                // Unexpected errors – hide internal details
                $safeMessage = config('app.debug')
                    ? $e->getMessage()
                    : 'An unexpected error occurred during registration.';

                $metadata->markAsError('An unexpected error occurred during registration.');

                $results['failed'][] = [
                    'id' => $resourceId,
                    'igsn' => $resource->doi,
                    'reason' => $safeMessage,
                ];

                Log::error('Batch IGSN registration: unexpected error', [
                    'resource_id' => $resourceId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        // 200 if all succeed, 207 Multi-Status if some fail
        $statusCode = $results['failed'] === [] ? 200 : 207;

        return response()->json($results, $statusCode);
    }
}
