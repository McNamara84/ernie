<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Resource;

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
        \Illuminate\Support\Facades\Log::info('FakeDataCiteRegistrationService: registerDoi called', [
            'resource_id' => $resource->id,
            'prefix' => $prefix,
        ]);

        // Validate prefix (same as real service)
        if (! in_array($prefix, $this->prefixes, true)) {
            throw new \InvalidArgumentException(
                "Invalid prefix '{$prefix}'. Allowed prefixes: ".implode(', ', $this->prefixes)
            );
        }

        // Check if resource has a landing page (same as real service)
        $resource->load('landingPage');
        if (! $resource->landingPage) {
            \Illuminate\Support\Facades\Log::error('FakeDataCiteRegistrationService: Resource has no landing page', [
                'resource_id' => $resource->id,
            ]);
            throw new \RuntimeException(
                "Resource #{$resource->id} must have a landing page before registering a DOI."
            );
        }

        \Illuminate\Support\Facades\Log::info('FakeDataCiteRegistrationService: Generating fake DOI', [
            'resource_id' => $resource->id,
            'has_landing_page' => true,
            'landing_page_id' => $resource->landingPage->id,
        ]);

        // Generate a predictable fake DOI suffix for easier debugging in tests
        // Format: test-{resource_id}-{timestamp}
        $timestamp = now()->format('YmdHis');
        $suffix = "test-{$resource->id}-{$timestamp}";
        $doi = "{$prefix}/{$suffix}";

        \Illuminate\Support\Facades\Log::info('FakeDataCiteRegistrationService: Returning successful response', [
            'doi' => $doi,
        ]);

        // Use the landing page's computed public_url accessor
        $publicUrl = $resource->landingPage->public_url;
        \Illuminate\Support\Facades\Log::info('FakeDataCiteRegistrationService: Got public URL', [
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
        // Validate resource has a DOI (same as real service)
        if (! $resource->doi) {
            throw new \RuntimeException(
                "Resource #{$resource->id} must have a DOI to update metadata."
            );
        }

        // Check if resource has a landing page (same as real service)
        $resource->load('landingPage');
        if (! $resource->landingPage) {
            throw new \RuntimeException(
                "Resource #{$resource->id} must have a landing page to update metadata."
            );
        }

        // Use the landing page's computed public_url accessor
        $publicUrl = $resource->landingPage->public_url;

        // Return DataCite API response format
        return [
            'data' => [
                'id' => $resource->doi,
                'type' => 'dois',
                'attributes' => [
                    'doi' => $resource->doi,
                    'url' => $publicUrl,
                    'state' => 'findable',
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
    public function registerIgsn(Resource $resource): array
    {
        \Illuminate\Support\Facades\Log::info('FakeDataCiteRegistrationService: registerIgsn called', [
            'resource_id' => $resource->id,
            'igsn' => $resource->doi,
        ]);

        // Validate resource has an IGSN
        if (! $resource->doi) {
            throw new \RuntimeException(
                "Resource #{$resource->id} must have an IGSN to register."
            );
        }

        // Validate DOI/IGSN format: must be 10.NNNN/suffix
        if (! preg_match('/^10\.\d{4,}(?:\.\d+)*\/\S+$/', $resource->doi)) {
            throw new \InvalidArgumentException(
                "IGSN '{$resource->doi}' has an invalid format. Expected: 10.XXXXX/SUFFIX"
            );
        }

        // Extract prefix and validate
        $prefix = explode('/', $resource->doi, 2)[0];
        if (! in_array($prefix, $this->prefixes, true)) {
            throw new \InvalidArgumentException(
                "IGSN prefix '{$prefix}' is not allowed. Allowed prefixes: " . implode(', ', $this->prefixes)
            );
        }

        // Check if resource has a landing page
        $resource->loadMissing('landingPage');
        if (! $resource->landingPage) {
            throw new \RuntimeException(
                "Resource #{$resource->id} must have a landing page before registering an IGSN."
            );
        }

        $publicUrl = $resource->landingPage->public_url;

        // Return DataCite API response format – IGSN is kept as DOI
        return [
            'data' => [
                'id' => $resource->doi,
                'type' => 'dois',
                'attributes' => [
                    'doi' => $resource->doi,
                    'prefix' => $prefix,
                    'url' => $publicUrl,
                    'state' => 'findable',
                ],
            ],
        ];
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
}
