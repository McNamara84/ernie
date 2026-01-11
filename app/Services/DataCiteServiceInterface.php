<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Resource;

/**
 * Interface for DataCite registration services.
 *
 * This interface defines the contract for DataCite DOI registration and metadata management.
 * Both the real DataCiteRegistrationService and the FakeDataCiteRegistrationService
 * implement this interface, allowing for dependency injection in tests.
 *
 * @see DataCiteRegistrationService Real implementation using DataCite API
 * @see FakeDataCiteRegistrationService Fake implementation for E2E testing
 */
interface DataCiteServiceInterface
{
    /**
     * Register a new DOI with DataCite.
     *
     * @param  Resource  $resource  The resource to register
     * @param  string  $prefix  The DOI prefix to use
     * @return array<string, mixed> The DataCite API response format
     *
     * @throws \InvalidArgumentException If prefix is not allowed
     * @throws \RuntimeException If resource doesn't have a landing page
     */
    public function registerDoi(Resource $resource, string $prefix): array;

    /**
     * Update metadata for an existing DOI.
     *
     * @param  Resource  $resource  The resource with an existing DOI
     * @return array<string, mixed> The DataCite API response format
     *
     * @throws \RuntimeException If resource doesn't have a DOI or landing page
     */
    public function updateMetadata(Resource $resource): array;

    /**
     * Get available DOI prefixes for the current environment.
     *
     * @return array<int, string>
     */
    public function getAvailablePrefixes(): array;

    /**
     * Check if test mode is enabled.
     */
    public function isTestMode(): bool;

    /**
     * Get the current API endpoint.
     */
    public function getEndpoint(): string;
}
