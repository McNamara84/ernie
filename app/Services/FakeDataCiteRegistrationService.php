<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DataCiteVerificationException;
use App\Models\Resource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Fake DataCite Registration Service for testing
 *
 * Returns successful responses without making actual API calls
 * Used in E2E tests where HTTP mocking is not available
 */
class FakeDataCiteRegistrationService implements DataCiteServiceInterface
{
    /**
     * Available DOI prefixes for testing
     *
     * @var array<int, string>
     */
    private array $prefixes = ['10.83279', '10.83186', '10.83114'];

    /**
     * Register a new DOI with DataCite (faked for testing)
     *
     * @param  resource  $resource  The resource to register
     * @param  string  $prefix  The DOI prefix to use
     * @return array<string, mixed> The DataCite API response format
     *
     * @throws \InvalidArgumentException If prefix is not allowed
     * @throws \RuntimeException If resource doesn't have a landing page
     */
    #[\NoDiscard('DOI registration response must be checked for success')]
    public function registerDoi(Resource $resource, string $prefix): array
    {
        // Log that fake service is being used
        Log::info('FakeDataCiteRegistrationService: registerDoi called', [
            'resource_id' => $resource->id,
            'prefix' => $prefix,
        ]);

        if ($resource->doi) {
            $prefix = explode('/', $resource->doi, 2)[0];
        }

        // Validate prefix (same as real service)
        if (! in_array($prefix, $this->prefixes, true)) {
            throw new \InvalidArgumentException(
                "Invalid prefix '{$prefix}'. Allowed prefixes: ".implode(', ', $this->prefixes)
            );
        }

        // Check if resource has a landing page (same as real service)
        $resource->load('landingPage');
        if (! $resource->landingPage) {
            Log::error('FakeDataCiteRegistrationService: Resource has no landing page', [
                'resource_id' => $resource->id,
            ]);
            throw new \RuntimeException(
                "Resource #{$resource->id} must have a landing page before registering a DOI."
            );
        }

        Log::info('FakeDataCiteRegistrationService: Generating fake DOI', [
            'resource_id' => $resource->id,
            'has_landing_page' => true,
            'landing_page_id' => $resource->landingPage->id,
        ]);

        app(EmbargoService::class)->normalizeResource($resource);
        app(EmbargoService::class)->assertCanRegister($resource);

        // Generate a predictable fake DOI suffix for easier debugging in tests
        // Format: test-{resource_id}-{timestamp}
        $timestamp = now()->format('YmdHis');
        $suffix = "test-{$resource->id}-{$timestamp}";
        $doi = $resource->doi ?: "{$prefix}/{$suffix}";
        ($resource->landingPage ?? throw new \RuntimeException('The landing page no longer exists.'))->publish();
        $resource->update(['doi' => $doi]);
        app(ResourceStorageService::class)->ensureSystemDate($resource, 'Issued');
        $this->setRegistrationState($doi, 'findable');

        Log::info('FakeDataCiteRegistrationService: Returning successful response', [
            'doi' => $doi,
        ]);

        // Use the landing page's computed public_url accessor
        $publicUrl = $resource->landingPage->public_url ?? url("/datasets/{$resource->id}");
        Log::info('FakeDataCiteRegistrationService: Got public URL', [
            'url' => $publicUrl,
        ]);

        // Return DataCite API response format
        return [
            'data' => [
                'id' => $doi,
                'type' => 'dois',
                'attributes' => [
                    'doi' => $doi,
                    'prefix' => $prefix,
                    'suffix' => $suffix,
                    'url' => $publicUrl,
                    'state' => 'findable',
                ],
            ],
        ];
    }

    /**
     * Update metadata for an existing DOI (faked for testing)
     *
     * @param  resource  $resource  The resource with an existing DOI
     * @return array<string, mixed> The DataCite API response format
     *
     * @throws \RuntimeException If resource doesn't have a DOI or landing page
     */
    public function updateMetadata(Resource $resource): array
    {
        $resource = Resource::query()->with('landingPage')->findOrFail($resource->id);

        // Validate resource has a DOI (same as real service)
        if (! $resource->doi) {
            throw new \RuntimeException(
                "Resource #{$resource->id} must have a DOI to update metadata."
            );
        }

        // Check if resource has a landing page (same as real service)
        $resource->load('landingPage');
        $landingPage = $resource->landingPage;
        if ($landingPage === null) {
            throw new \RuntimeException(
                "Resource #{$resource->id} must have a landing page to update metadata."
            );
        }

        app(EmbargoService::class)->assertCanUpdateMetadata($resource);
        if (! $landingPage->is_published) {
            throw new DataCiteVerificationException('landing_page_not_published', 'Publish the landing page explicitly before updating DataCite metadata.');
        }
        $state = $this->getRegistrationState((string) $resource->doi);
        if (! in_array($state, ['registered', 'findable'], true)) {
            throw new DataCiteVerificationException('doi_registration_required', 'Explicit DOI publication is required.');
        }

        // Use the landing page's computed public_url accessor
        $publicUrl = $landingPage->public_url;

        // Return DataCite API response format
        return [
            'data' => [
                'id' => $resource->doi,
                'type' => 'dois',
                'attributes' => [
                    'doi' => $resource->doi,
                    'url' => $publicUrl,
                    'state' => $state,
                ],
            ],
        ];
    }

    /**
     * Register an IGSN with DataCite (faked for testing)
     *
     * Unlike registerDoi(), this method keeps the existing DOI/IGSN in the payload
     * because IGSNs have pre-defined identifiers that must be preserved.
     *
     * @param  Resource  $resource  The IGSN resource to register
     * @return array<string, mixed> The DataCite API response format
     *
     * @throws \RuntimeException If resource doesn't have a DOI/IGSN or landing page
     * @throws \InvalidArgumentException If the IGSN prefix is not allowed
     */
    public function registerIgsn(Resource $resource, ?int $actorId = null): array
    {
        if (! $resource->doi) {
            throw new \RuntimeException('The resource must have an IGSN before publication.');
        }

        return $this->registerDoi($resource, explode('/', $resource->doi, 2)[0]);
    }

    /**
     * Get available DOI prefixes for the current environment
     *
     * @return array<int, string>
     */
    public function getAvailablePrefixes(): array
    {
        return $this->prefixes;
    }

    /**
     * Check if test mode is enabled (always true for fake service)
     */
    public function isTestMode(): bool
    {
        return true;
    }

    /**
     * Get the current API endpoint (fake endpoint for testing)
     */
    public function getEndpoint(): string
    {
        return 'https://fake.datacite.org';
    }

    /** @return array<string, mixed> */
    public function updateLandingPageUrl(string $identifier, string $targetUrl): array
    {
        $resource = Resource::where('doi', $identifier)->sole();
        $response = $this->updateMetadata($resource);
        $response['data']['attributes']['url'] = $targetUrl;

        return $response;
    }

    public function getRegistrationState(string $doi): string
    {
        return Cache::get('fake-datacite:test:'.strtolower($doi), 'missing');
    }

    /** Seed remote evidence independently of the resource for browser fixtures. */
    public function setRegistrationState(string $doi, string $state): void
    {
        if (! in_array($state, ['missing', 'draft', 'registered', 'findable'], true)) {
            throw new \InvalidArgumentException('Unknown fake DOI state.');
        }
        Cache::put('fake-datacite:test:'.strtolower($doi), $state, now()->addDay());
    }
}
