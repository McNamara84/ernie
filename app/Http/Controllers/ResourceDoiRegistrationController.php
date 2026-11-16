<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\DataCiteRequestDeferredException;
use App\Exceptions\DataCiteVerificationException;
use App\Exceptions\DataCiteWriteSupersededException;
use App\Http\Requests\RegisterDoiRequest;
use App\Http\Resources\DataCitePrefixResource;
use App\Models\Resource;
use App\Models\ResourceDoiPublication;
use App\Services\DataCiteModeResolverService;
use App\Services\DataCiteRegistrationService;
use App\Services\Orcid\OrcidPreflightValidator;
use App\Services\ResourceCacheService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ResourceDoiRegistrationController extends Controller
{
    /**
     * Register a DOI with DataCite or update metadata for an existing DOI.
     */
    public function registerDoi(RegisterDoiRequest $request, Resource $resource): JsonResponse
    {
        try {
            $resource->load('landingPage');
            if (! $resource->landingPage) {
                return response()->json([
                    'error' => 'Landing page required',
                    'message' => 'A landing page must be created before registering a DOI. Please set up a landing page first.',
                ], 422);
            }

            // Resolve service from container (allows testing with fake service).
            $service = app(DataCiteRegistrationService::class);

            // Pre-flight ORCID validation (issue #610). Confirmed-invalid ORCIDs block
            // registration unconditionally; transient errors require resubmission with
            // `force=true`.
            $force = $request->boolean('force');
            $preflight = app(OrcidPreflightValidator::class)->validate($resource, $force);

            if ($preflight->shouldBlock) {
                Log::info('DOI registration blocked by ORCID preflight', [
                    'resource_id' => $resource->id,
                    'invalid_count' => count($preflight->invalid),
                    'warning_count' => count($preflight->warnings),
                ]);

                return response()->json([
                    'error' => 'orcid_validation_failed',
                    'message' => 'One or more ORCID identifiers could not be verified. Please correct them before registering a DOI.',
                    ...$preflight->toPayload(),
                ], 422);
            }

            if ($preflight->needsConfirmation) {
                Log::info('DOI registration paused for ORCID warning confirmation', [
                    'resource_id' => $resource->id,
                    'warning_count' => count($preflight->warnings),
                ]);

                return response()->json([
                    'error' => 'orcid_validation_warning',
                    'message' => 'ORCID verification service is temporarily unreachable for one or more creators or contributors. Review the warnings and confirm to continue.',
                    ...$preflight->toPayload(),
                ], 409);
            }

            $publishing = $request->routeIs('resources.publish-doi');
            if (! $publishing) {
                Log::info('Updating existing DOI metadata', [
                    'resource_id' => $resource->id,
                    'doi' => $resource->doi,
                ]);

                $response = $service->updateMetadata($resource);
                $doi = $response['data']['id'] ?? $resource->doi;

                return response()->json([
                    'success' => true,
                    'message' => 'DOI metadata updated successfully',
                    'doi' => $doi,
                    'mode' => $service->isTestMode() ? 'test' : 'production',
                    'updated' => true,
                ]);
            }

            $response = $service->registerDoi($resource, (string) $request->validated('prefix', ''));
            $complete = ($response['complete'] ?? true) === true;
            $result = [
                'success' => $complete,
                'message' => $complete ? 'DOI and landing page published successfully' : 'The landing page is published; DOI publication needs reconciliation. No second DOI was created.',
                'doi' => $response['data']['id'] ?? $resource->doi,
                'mode' => $service->isTestMode() ? 'test' : 'production',
                'updated' => false,
                'publication' => $response['publication'] ?? null,
            ];
            if ($complete) {
                try {
                    $result['publishedRecordCounts'] = app(ResourceCacheService::class)->getPublishedResourceCounts();
                } catch (\Throwable $exception) {
                    Log::warning('Unable to retrieve published counts', ['error' => $exception->getMessage()]);
                }
            }

            return response()->json($result, $complete ? 200 : 202);
        } catch (DataCiteVerificationException $e) {
            return response()->json(['error' => $e->reason, 'message' => $e->getMessage()], $e->retryable ? 503 : 422);
        } catch (DataCiteWriteSupersededException $e) {
            return response()->json(['error' => 'doi_update_superseded', 'message' => $e->getMessage()], 409);
        } catch (LockTimeoutException $e) {
            return response()->json([
                'error' => 'doi_update_in_progress',
                'message' => 'Your metadata is saved in ERNIE. Another update of this DOI is still in progress. Please retry shortly.',
            ], 503)->header('Retry-After', '2');
        } catch (DataCiteRequestDeferredException $e) {
            $retryAfter = max(1, (int) ceil($e->retryAfterMilliseconds / 1000));
            Log::notice('Interactive DOI update deferred by DataCite capacity', [
                'resource_id' => $resource->id,
                'retry_after_seconds' => $retryAfter,
            ]);

            return response()->json([
                'error' => 'datacite_temporarily_busy',
                'message' => 'Your metadata is saved in ERNIE. DataCite is temporarily busy; the external update has not been confirmed. Please retry shortly.',
                'retry_after_seconds' => $retryAfter,
            ], 503)->header('Retry-After', (string) $retryAfter);
        } catch (\InvalidArgumentException $e) {
            Log::warning('Invalid DOI registration request', [
                'resource_id' => $resource->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Invalid request',
                'message' => $e->getMessage(),
            ], 422);
        } catch (\RuntimeException $e) {
            Log::warning('DOI registration runtime error', [
                'resource_id' => $resource->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Registration failed',
                'message' => $e->getMessage(),
            ], 422);
        } catch (RequestException $e) {
            $response = $e->response;
            /** @phpstan-ignore notIdentical.alwaysTrue */
            $statusCode = $response !== null ? $response->status() : 500;
            /** @phpstan-ignore notIdentical.alwaysTrue */
            $apiError = $response !== null ? $response->json() : null;

            Log::error('DataCite API error during DOI registration', [
                'resource_id' => $resource->id,
                'status' => $statusCode,
                'error' => $e->getMessage(),
                'api_response' => $apiError,
            ]);

            $errorMessage = 'Failed to communicate with DataCite API.';
            if (isset($apiError['errors']) && is_array($apiError['errors']) && count($apiError['errors']) > 0) {
                $firstError = $apiError['errors'][0];
                $errorMessage = $firstError['title'] ?? $firstError['detail'] ?? $errorMessage;
            }

            return response()->json([
                'error' => 'DataCite API error',
                'message' => $errorMessage,
                'details' => config('app.debug') ? $apiError : null,
            ], $statusCode >= 400 && $statusCode < 500 ? $statusCode : 500);
        } catch (\Exception $e) {
            Log::error('Unexpected error during DOI registration', [
                'resource_id' => $resource->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Unexpected error',
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'An unexpected error occurred during DOI registration. Please contact support.',
            ], 500);
        }
    }

    /** Read only, requested explicitly when the curator opens a DataCite action. */
    public function status(Resource $resource): JsonResponse
    {
        $service = app(DataCiteRegistrationService::class);
        $publication = ResourceDoiPublication::where('resource_id', $resource->id)->latest('id')->first();
        $local = [
            'landing_page_published' => $resource->landingPage?->is_published === true,
            'publication' => $publication?->summary(),
            'mode' => $service->isTestMode() ? 'test' : 'production',
        ];
        try {
            $state = $resource->doi ? $service->getRegistrationState($resource->doi) : 'unassigned';

            return response()->json([...$local, 'state' => $state, 'can_update' => $local['landing_page_published'] && in_array($state, ['registered', 'findable'], true), 'can_publish' => $resource->landingPage?->is_tombstone === false]);
        } catch (\Throwable $exception) {
            return response()->json([...$local, 'state' => 'unavailable', 'can_update' => false, 'can_publish' => false, 'message' => $exception->getMessage()], 503);
        }
    }

    /**
     * Get available DataCite prefixes based on test mode configuration.
     */
    public function getDataCitePrefixes(Request $request): JsonResponse
    {
        $resolver = app(DataCiteModeResolverService::class);

        return (new DataCitePrefixResource([
            'test' => config('datacite.test.prefixes', []),
            'production' => config('datacite.production.prefixes', []),
            'test_mode' => $resolver->shouldUseTestMode($request->user()),
        ]))->response();
    }
}
