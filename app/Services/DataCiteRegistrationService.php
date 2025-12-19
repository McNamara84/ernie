<?php

namespace App\Services;

use App\Models\Resource;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service for interacting with the DataCite API v2 for DOI registration and metadata management.
 *
 * This service handles:
 * - DOI registration with DataCite (minting new DOIs)
 * - Metadata updates for existing DOIs
 * - Automatic switching between test and production APIs based on configuration
 *
 * @see https://support.datacite.org/docs/api API Documentation
 */
class DataCiteRegistrationService
{
    /**
     * The DataCite API client instance
     */
    private PendingRequest $client;

    /**
     * The API endpoint URL
     */
    private string $endpoint;

    /**
     * Available DOI prefixes for the current environment
     *
     * @var array<int, string>
     */
    private array $prefixes;

    /**
     * Whether test mode is enabled
     */
    private bool $testMode;

    /**
     * Initialize the DataCite registration service
     */
    public function __construct()
    {
        $this->testMode = $this->determineTestMode();

        // Select configuration based on test mode
        $config = $this->testMode
            ? Config::get('datacite.test')
            : Config::get('datacite.production');

        $this->endpoint = $config['endpoint'];
        $this->prefixes = $config['prefixes'];

        $username = $config['username'];
        $password = $config['password'];

        // Log configuration for debugging (mask password)
        Log::debug('DataCite API configuration loaded', [
            'test_mode' => $this->testMode,
            'endpoint' => $this->endpoint,
            'username' => $username,
            'has_password' => ! empty($password),
            'prefixes' => $this->prefixes,
        ]);

        // Validate credentials
        if (empty($username) || empty($password)) {
            Log::error('DataCite credentials missing', [
                'test_mode' => $this->testMode,
                'username_empty' => empty($username),
                'password_empty' => empty($password),
            ]);
        }

        // Initialize HTTP client with authentication
        $this->client = Http::withBasicAuth(
            $username,
            $password
        )
            ->withHeaders([
                'Content-Type' => 'application/vnd.api+json',
                'Accept' => 'application/vnd.api+json',
            ])
            ->timeout(30)
            ->retry(3, 100);
    }

    /**
     * Determine if DataCite test mode should be used
     *
     * This method implements critical safety logic to protect against accidental DOI registration
     * in production by users who are still in training.
     *
     * Test mode is activated when:
     * 1. Global test mode is enabled in configuration (config/datacite.php)
     * 2. The authenticated user has the BEGINNER role (restricted to test DOIs only)
     *
     * IMPORTANT: Beginner users are ALWAYS forced to use test mode, regardless of global settings.
     * This safety mechanism cannot be overridden - even if global test_mode=false, beginners
     * will still register test DOIs. This ensures that users in training cannot accidentally
     * register production DOIs while learning the system.
     *
     * @return bool True if test mode should be used, false for production mode
     *
     * @see \App\Enums\UserRole::canRegisterProductionDoi() - Role permission check
     * @see config/datacite.php - Global test mode configuration
     */
    private function determineTestMode(): bool
    {
        $globalTestMode = (bool) Config::get('datacite.test_mode', true);

        // If global test mode is enabled, use test mode
        if ($globalTestMode) {
            return true;
        }

        // CRITICAL SAFETY CHECK: Force test mode for beginner users
        // Beginners cannot register production DOIs even if global test mode is disabled
        /** @var \App\Models\User|null $user */
        $user = auth()->user();

        if ($user !== null && $user->isBeginner()) {
            Log::info('Forcing DataCite test mode for beginner user (safety restriction)', [
                'user_id' => $user->id,
                'user_role' => $user->role->value,
                'reason' => 'Beginners are restricted to test DOIs only',
            ]);

            return true;
        }

        return false;
    }

    /**
     * Register a new DOI with DataCite
     *
     * @param  resource  $resource  The resource to register
     * @param  string  $prefix  The DOI prefix to use (must be in allowed list)
     * @return array<string, mixed> The DataCite API response
     *
     * @throws \InvalidArgumentException If prefix is not allowed
     * @throws \RuntimeException If resource doesn't have a landing page
     * @throws RequestException If the API request fails
     */
    public function registerDoi(Resource $resource, string $prefix): array
    {
        // Validate prefix
        if (! in_array($prefix, $this->prefixes, true)) {
            throw new \InvalidArgumentException(
                "Invalid prefix '{$prefix}'. Allowed prefixes: ".implode(', ', $this->prefixes)
            );
        }

        // Check if resource has a landing page
        $resource->load('landingPage');
        if (! $resource->landingPage) {
            throw new \RuntimeException(
                "Resource #{$resource->id} must have a landing page before registering a DOI."
            );
        }

        // Generate DataCite metadata using the existing exporter
        $jsonExporter = new DataCiteJsonExporter;
        $dataCiteData = $jsonExporter->export($resource);

        // Build the registration payload
        $payload = [
            'data' => [
                'type' => 'dois',
                'attributes' => array_merge(
                    $dataCiteData['data']['attributes'],
                    [
                        'prefix' => $prefix,
                        'url' => $resource->landingPage->public_url,
                        'event' => 'publish', // Publish the DOI immediately
                    ]
                ),
            ],
        ];

        // Remove existing DOI from payload if present (let DataCite generate it)
        unset($payload['data']['attributes']['doi']);

        Log::info('Registering DOI with DataCite', [
            'resource_id' => $resource->id,
            'prefix' => $prefix,
            'test_mode' => $this->testMode,
            'url' => $resource->landingPage->public_url,
            'endpoint' => $this->endpoint,
        ]);

        // Log payload (mask sensitive data in production)
        if (config('app.debug')) {
            Log::debug('DOI registration payload', [
                'payload' => $payload,
            ]);
        }

        try {
            // Send POST request to DataCite API
            $response = $this->client
                ->post("{$this->endpoint}/dois", $payload);
            $response->throw();

            $responseData = $response->json();

            if ($responseData === null) {
                Log::error('DataCite response is not valid JSON', [
                    'resource_id' => $resource->id,
                    'prefix' => $prefix,
                    'response_body' => $response->body(),
                ]);

                throw new \RuntimeException('Received invalid JSON response from DataCite API');
            }

            Log::info('DOI registered successfully', [
                'resource_id' => $resource->id,
                'doi' => $responseData['data']['id'] ?? null,
            ]);

            return $responseData;
        } catch (RequestException $e) {
            // Log detailed error information
            // PHPDoc indicates response is always present, but it can be null at runtime
            $response = $e->response;
            /** @phpstan-ignore notIdentical.alwaysTrue */
            $statusCode = $response !== null ? $response->status() : null;
            /** @phpstan-ignore notIdentical.alwaysTrue */
            $responseBody = $response !== null ? $response->body() : null;
            /** @phpstan-ignore notIdentical.alwaysTrue */
            $responseJson = $response !== null ? $response->json() : null;

            Log::error('Failed to register DOI with DataCite', [
                'resource_id' => $resource->id,
                'prefix' => $prefix,
                'status_code' => $statusCode,
                'error_message' => $e->getMessage(),
                'response_body' => $responseBody,
                'response_json' => $responseJson,
            ]);

            throw $e;
        }
    }

    /**
     * Update metadata for an existing DOI
     *
     * @param  resource  $resource  The resource with an existing DOI
     * @return array<string, mixed> The DataCite API response
     *
     * @throws \RuntimeException If resource doesn't have a DOI or landing page
     * @throws RequestException If the API request fails
     */
    public function updateMetadata(Resource $resource): array
    {
        // Validate resource has a DOI
        if (! $resource->doi) {
            throw new \RuntimeException(
                "Resource #{$resource->id} must have a DOI to update metadata."
            );
        }

        // Check if resource has a landing page
        $resource->load('landingPage');
        if (! $resource->landingPage) {
            throw new \RuntimeException(
                "Resource #{$resource->id} must have a landing page to update metadata."
            );
        }

        // Generate DataCite metadata using the existing exporter
        $jsonExporter = new DataCiteJsonExporter;
        $dataCiteData = $jsonExporter->export($resource);

        // Build the update payload
        $payload = [
            'data' => [
                'type' => 'dois',
                'id' => $resource->doi,
                'attributes' => array_merge(
                    $dataCiteData['data']['attributes'],
                    [
                        'url' => $resource->landingPage->public_url,
                        'event' => 'publish', // Ensure DOI remains published
                    ]
                ),
            ],
        ];

        Log::info('Updating DOI metadata with DataCite', [
            'resource_id' => $resource->id,
            'doi' => $resource->doi,
            'test_mode' => $this->testMode,
        ]);

        try {
            // URL-encode DOI to prevent potential issues with special characters
            // Safe because we validated $resource->doi is not null above (lines 220-224)
            assert($resource->doi !== null); // PHPStan hint: DOI is validated above
            $encodedDoi = urlencode($resource->doi);

            // Send PUT request to DataCite API
            $response = $this->client
                ->put("{$this->endpoint}/dois/{$encodedDoi}", $payload);
            $response->throw();

            $responseData = $response->json();

            if ($responseData === null) {
                Log::error('DataCite response is not valid JSON', [
                    'resource_id' => $resource->id,
                    'doi' => $resource->doi,
                    'response_body' => $response->body(),
                ]);

                throw new \RuntimeException('Received invalid JSON response from DataCite API');
            }

            Log::info('DOI metadata updated successfully', [
                'resource_id' => $resource->id,
                'doi' => $resource->doi,
            ]);

            return $responseData;
        } catch (RequestException $e) {
            // PHPDoc indicates response is always present, but it can be null at runtime
            $response = $e->response;
            /** @phpstan-ignore notIdentical.alwaysTrue */
            $responseJson = $response !== null ? $response->json() : null;

            Log::error('Failed to update DOI metadata with DataCite', [
                'resource_id' => $resource->id,
                'doi' => $resource->doi,
                'error' => $e->getMessage(),
                'response' => $responseJson,
            ]);

            throw $e;
        }
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
     * Check if test mode is enabled
     */
    public function isTestMode(): bool
    {
        return $this->testMode;
    }

    /**
     * Get the current API endpoint
     */
    public function getEndpoint(): string
    {
        return $this->endpoint;
    }
}
