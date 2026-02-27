<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\IgsnMetadata;
use App\Models\Resource;
use App\Services\DataCiteRegistrationService;
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
     */
    private const MAX_BATCH_SIZE = 100;

    /**
     * Batch register or update IGSNs at DataCite.
     *
     * Each IGSN is processed independently: failures are recorded but do not
     * prevent other IGSNs from being registered. Returns HTTP 200 if all succeed,
     * or HTTP 207 (Multi-Status) if some fail.
     */
    public function register(Request $request): JsonResponse
    {
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

        // Fetch all resources in a single query to avoid N+1
        $resources = Resource::with(['igsnMetadata', 'landingPage'])
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

            try {
                // Update publicationYear to current year (Issue #438)
                $resource->publication_year = (int) date('Y');
                $resource->save();

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
                    $resource->save();
                }

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
            } catch (\Throwable $e) {
                $metadata->markAsError($e->getMessage());

                $results['failed'][] = [
                    'id' => $resourceId,
                    'igsn' => $resource->doi,
                    'reason' => $e->getMessage(),
                ];

                Log::error('Batch IGSN registration: failed', [
                    'resource_id' => $resourceId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // 200 if all succeed, 207 Multi-Status if some fail
        $statusCode = $results['failed'] === [] ? 200 : 207;

        return response()->json($results, $statusCode);
    }
}
