<?php

namespace App\Services;

use App\Models\Resource;
use Illuminate\Support\Str;

/**
 * Fake DataCite Registration Service for testing
 * 
 * Returns successful responses without making actual API calls
 * Used in E2E tests where HTTP mocking is not available
 */
class FakeDataCiteRegistrationService
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
     * @param  Resource  $resource  The resource to register
     * @param  string  $prefix  The DOI prefix to use
     * @return array<string, mixed> The DataCite API response format
     *
     * @throws \InvalidArgumentException If prefix is not allowed
     * @throws \RuntimeException If resource doesn't have a landing page
     */
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

        // Generate a fake DOI
        $suffix = 'test-' . Str::random(8);
        $doi = "{$prefix}/{$suffix}";

        \Illuminate\Support\Facades\Log::info('FakeDataCiteRegistrationService: Returning successful response', [
            'doi' => $doi,
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
                    'url' => $resource->landingPage->public_url,
                    'state' => 'findable',
                ],
            ],
        ];
    }

    /**
     * Update metadata for an existing DOI (faked for testing)
     *
     * @param  Resource  $resource  The resource with an existing DOI
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

        // Return DataCite API response format
        return [
            'data' => [
                'id' => $resource->doi,
                'type' => 'dois',
                'attributes' => [
                    'doi' => $resource->doi,
                    'url' => $resource->landingPage->public_url,
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
