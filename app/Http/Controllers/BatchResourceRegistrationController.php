<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Batch\RegisterResourcesRequest;
use App\Models\Resource;
use App\Services\DataCiteRegistrationService;
use App\Services\Orcid\OrcidPreflightResult;
use App\Services\Orcid\OrcidPreflightValidator;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Controller for batch registration of Resources (non-IGSN datasets) at DataCite.
 *
 * Processes each resource individually with error isolation – one failure
 * does not stop the remaining registrations.
 */
class BatchResourceRegistrationController extends Controller
{
    /**
     * Batch register or update resource DOIs at DataCite.
     *
     * Each resource is processed independently: failures are recorded but do not
     * prevent other resources from being registered. Returns HTTP 200 if all
     * succeed, or HTTP 207 (Multi-Status) if some fail.
     *
     * Resources already having a DOI are updated (metadata refresh). Resources
     * without a DOI require the `prefix` request parameter for initial
     * registration; otherwise those items are reported as failed.
     *
     * IGSN resources (resources carrying IGSN metadata) are rejected via the
     * `failed` list – they must be registered via the IGSN batch endpoint.
     */
    public function register(RegisterResourcesRequest $request): JsonResponse
    {
        /** @var array{ids: array<int, int>, prefix?: string|null} $validated */
        $validated = $request->validated();

        /** @var array<int> $ids */
        $ids = array_values(array_unique($validated['ids']));

        /** @var string|null $prefix */
        $prefix = $validated['prefix'] ?? null;

        /** @var array{success: list<array<string, mixed>>, failed: list<array<string, mixed>>} $results */
        $results = ['success' => [], 'failed' => []];

        /** @var DataCiteRegistrationService $service */
        $service = app(DataCiteRegistrationService::class);

        // Resolve the ORCID preflight validator once and reuse it for every
        // resource in the batch to avoid repeated container lookups.
        $orcidPreflight = app(OrcidPreflightValidator::class);

        // Fetch all resources with ALL relations needed by DataCiteJsonExporter
        // to avoid N+1 queries when export() is called inside the loop.
        $resources = Resource::with(Resource::DATACITE_EXPORT_RELATIONS)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        foreach ($ids as $resourceId) {
            $resource = $resources->get($resourceId);

            if (! $resource instanceof Resource) {
                $results['failed'][] = [
                    'id' => $resourceId,
                    'doi' => null,
                    'reason' => 'Resource not found',
                ];

                continue;
            }

            // IGSN resources must use the dedicated IGSN batch endpoint.
            if ($resource->igsnMetadata !== null) {
                $results['failed'][] = [
                    'id' => $resourceId,
                    'doi' => $resource->doi,
                    'reason' => 'IGSN resources must be registered via /igsns/batch-register',
                ];

                continue;
            }

            if (! $resource->landingPage) {
                $results['failed'][] = [
                    'id' => $resourceId,
                    'doi' => $resource->doi,
                    'reason' => 'No landing page configured',
                ];

                continue;
            }

            $wasAlreadyRegistered = $resource->doi !== null && $resource->doi !== '';

            if (! $wasAlreadyRegistered && ($prefix === null || $prefix === '')) {
                $results['failed'][] = [
                    'id' => $resourceId,
                    'doi' => null,
                    'reason' => 'Resource has no DOI. Provide a prefix to register a new DOI.',
                ];

                continue;
            }

            // Pre-flight ORCID validation (see issue #610). Batch mode has no
            // interactive override, so both confirmed-invalid ORCIDs and
            // transient warnings cause the resource to be skipped with a
            // human-readable reason. The curator can retry individually via
            // the Register DOI modal.
            $preflight = $orcidPreflight->validate($resource, force: false);
            if ($preflight->shouldBlock || $preflight->warnings !== []) {
                $results['failed'][] = [
                    'id' => $resourceId,
                    'doi' => $resource->doi,
                    'reason' => $this->describeOrcidPreflightFailure($preflight),
                ];

                continue;
            }

            try {
                if ($wasAlreadyRegistered) {
                    $response = $service->updateMetadata($resource);
                } else {
                    /** @var string $prefix */
                    $response = $service->registerDoi($resource, $prefix);
                }

                $doi = $response['data']['id'] ?? $resource->doi;

                // Persist the freshly-minted DOI for new registrations.
                if (! $wasAlreadyRegistered && $doi !== null && $doi !== $resource->doi) {
                    $resource->doi = $doi;
                    $resource->save();
                }

                $results['success'][] = [
                    'id' => $resourceId,
                    'doi' => $doi,
                    'updated' => $wasAlreadyRegistered,
                ];

                Log::info('Batch resource registration: success', [
                    'resource_id' => $resourceId,
                    'doi' => $doi,
                    'updated' => $wasAlreadyRegistered,
                ]);
            } catch (RequestException $e) {
                // DataCite API error – extract a user-friendly message.
                // `response->json()` may legitimately return null for non-JSON
                // upstream payloads (HTML error pages, empty bodies, etc.), so
                // everything below must tolerate a non-array result.
                $apiResponse = $e->response;
                $apiError = $apiResponse->json();

                $errorMessage = 'Failed to communicate with DataCite API.';
                if (is_array($apiError)
                    && isset($apiError['errors'])
                    && is_array($apiError['errors'])
                    && count($apiError['errors']) > 0
                ) {
                    $firstError = $apiError['errors'][0];
                    if (is_array($firstError)) {
                        $errorMessage = $firstError['title'] ?? $firstError['detail'] ?? $errorMessage;
                    }
                }

                $results['failed'][] = [
                    'id' => $resourceId,
                    'doi' => $resource->doi,
                    'reason' => $errorMessage,
                ];

                Log::error('Batch resource registration: DataCite API error', [
                    'resource_id' => $resourceId,
                    'error' => $e->getMessage(),
                    'status' => $apiResponse->status(),
                    'api_response' => is_array($apiError) ? $apiError : $apiResponse->body(),
                ]);
            } catch (\InvalidArgumentException|\RuntimeException $e) {
                $results['failed'][] = [
                    'id' => $resourceId,
                    'doi' => $resource->doi,
                    'reason' => $e->getMessage(),
                ];

                Log::warning('Batch resource registration: validation error', [
                    'resource_id' => $resourceId,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable $e) {
                $safeMessage = config('app.debug')
                    ? $e->getMessage()
                    : 'An unexpected error occurred during registration.';

                $results['failed'][] = [
                    'id' => $resourceId,
                    'doi' => $resource->doi,
                    'reason' => $safeMessage,
                ];

                Log::error('Batch resource registration: unexpected error', [
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

    /**
     * Build a human-readable reason string for an ORCID preflight failure.
     *
     * Kept private to the batch controller because the interactive Register
     * DOI endpoint exposes the structured payload instead.
     */
    private function describeOrcidPreflightFailure(OrcidPreflightResult $preflight): string
    {
        $blocking = count($preflight->invalid);
        $warnings = count($preflight->warnings);

        if ($blocking > 0 && $warnings > 0) {
            return "ORCID preflight failed: {$blocking} invalid, {$warnings} unverifiable.";
        }

        if ($blocking > 0) {
            return "ORCID preflight failed: {$blocking} invalid ORCID identifier(s).";
        }

        return "ORCID preflight skipped: {$warnings} identifier(s) could not be verified (ORCID verification service unavailable).";
    }
}
