<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\RegisterDoiRequest;
use App\Http\Resources\DataCitePrefixResource;
use App\Models\Resource;
use App\Services\DataCiteRegistrationService;
use App\Services\Orcid\OrcidPreflightValidator;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
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

            // If the resource already has a DOI, update metadata instead of registering.
            if ($resource->doi) {
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

            // Register a new DOI.
            $validated = $request->validated();
            $prefix = $validated['prefix'];

            Log::info('Registering new DOI', [
                'resource_id' => $resource->id,
                'prefix' => $prefix,
                'test_mode' => $service->isTestMode(),
            ]);

            $response = $service->registerDoi($resource, $prefix);
            $doi = $response['data']['id'] ?? null;

            if (! $doi) {
                Log::error('DataCite response missing DOI', [
                    'resource_id' => $resource->id,
                    'response' => $response,
                ]);

                return response()->json([
                    'error' => 'Registration incomplete',
                    'message' => 'DOI was registered but the response did not contain the DOI identifier.',
                ], 500);
            }

            $resource->doi = $doi;
            $resource->save();

            Log::info('DOI saved to resource', [
                'resource_id' => $resource->id,
                'doi' => $doi,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'DOI registered successfully',
                'doi' => $doi,
                'mode' => $service->isTestMode() ? 'test' : 'production',
                'updated' => false,
            ]);
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

    /**
     * Get available DataCite prefixes based on test mode configuration.
     */
    public function getDataCitePrefixes(): JsonResponse
    {
        return (new DataCitePrefixResource([
            'test' => config('datacite.test.prefixes', []),
            'production' => config('datacite.production.prefixes', []),
            'test_mode' => (bool) config('datacite.test_mode', true),
        ]))->response();
    }
}
