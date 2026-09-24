<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccessLevel;
use App\Models\Resource;
use App\Support\DataCiteSchemaVersion;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Config;
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
class DataCiteRegistrationService implements DataCiteServiceInterface
{
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
    public function __construct(
        private readonly DataCiteMemberApiClient $client,
    ) {
        $this->testMode = $this->client->isTestMode();

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
    #[\NoDiscard('DOI registration response must be checked for success')]
    public function registerDoi(Resource $resource, string $prefix): array
    {
        // Read the current dates, rights and landing page immediately before the
        // external create. The list and editor may hold an older model snapshot.
        $resource = Resource::query()->with(Resource::DATACITE_EXPORT_RELATIONS)->findOrFail($resource->id);
        if ($resource->doi !== null && $resource->doi !== '') {
            throw new \InvalidArgumentException('This resource already has a DOI; update its metadata instead.');
        }
        // Validate prefix
        if (! in_array($prefix, $this->prefixes, true)) {
            throw new \InvalidArgumentException(
                "Invalid prefix '{$prefix}'. Allowed prefixes: ".implode(', ', $this->prefixes)
            );
        }

        // Check if resource has a landing page
        $resource->loadMissing('landingPage');
        $landingPage = $resource->landingPage;
        if ($landingPage === null) {
            throw new \RuntimeException(
                "Resource #{$resource->id} must have a landing page before registering a DOI."
            );
        }

        // event=publish makes the identifier findable immediately.
        app(EmbargoService::class)->assertCanRegister($resource);
        $releaseEmbargo = app(EmbargoService::class)->isEmbargoed($resource);
        if ($releaseEmbargo && $resource->embargo_registration_started_at !== null) {
            return $this->reconcileEmbargoDoi($resource, $prefix);
        }
        if ($releaseEmbargo) {
            $resource->access_level = AccessLevel::OPEN;
        }

        // Generate DataCite metadata using the existing exporter
        $jsonExporter = new DataCiteJsonExporter;
        $dataCiteData = $jsonExporter->export($resource);
        if ($releaseEmbargo) {
            $resource->access_level = AccessLevel::EMBARGOED;
        }

        // Build the registration payload
        $payload = [
            'data' => [
                'type' => 'dois',
                'attributes' => array_merge(
                    $dataCiteData['data']['attributes'],
                    [
                        'prefix' => $prefix,
                        'url' => $releaseEmbargo ? url("/datasets/{$resource->id}") : $landingPage->public_url,
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
            'url' => $landingPage->public_url,
            'endpoint' => $this->endpoint,
        ]);

        // Log payload (mask sensitive data in production)
        if (config('app.debug')) {
            Log::debug('DOI registration payload', [
                'payload' => $payload,
            ]);
        }

        $claimedAttempt = false;
        try {
            // Claim before the external create. Another request must reconcile
            // this attempt rather than sending another POST.
            if ($releaseEmbargo) {
                if (! app(EmbargoService::class)->claimRegistration($resource, $prefix)) {
                    return $this->reconcileEmbargoDoi(Resource::query()->findOrFail($resource->id), $prefix);
                }
                $claimedAttempt = true;
                $current = Resource::query()->with(Resource::DATACITE_EXPORT_RELATIONS)->findOrFail($resource->id);
                try {
                    app(EmbargoService::class)->assertCanRegister($current);
                    if (app(EmbargoService::class)->availableDate($current) !== app(EmbargoService::class)->availableDate($resource)) {
                        throw new \InvalidArgumentException('The embargo date changed before registration. Please retry.');
                    }
                } catch (\InvalidArgumentException $exception) {
                    app(EmbargoService::class)->clearRejectedRegistration($current);
                    throw $exception;
                }
            }

            // Send POST request to DataCite API
            $response = $this->client->createDoi($payload, allowRetry: ! $releaseEmbargo);
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

            if ($claimedAttempt && in_array($statusCode, [400, 401, 403, 422, 429], true)) {
                app(EmbargoService::class)->clearRejectedRegistration($resource);
            }

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
     * Register an IGSN with DataCite
     *
     * Unlike registerDoi(), this method keeps the existing DOI/IGSN in the payload
     * because IGSNs have pre-defined identifiers that must be preserved.
     *
     * @param  Resource  $resource  The IGSN resource to register
     * @return array<string, mixed> The DataCite API response
     *
     * @throws \RuntimeException If resource doesn't have a DOI/IGSN or landing page
     * @throws \InvalidArgumentException If the IGSN prefix is not allowed
     * @throws RequestException If the API request fails
     */
    #[\NoDiscard('IGSN registration response must be checked for success')]
    public function registerIgsn(Resource $resource): array
    {
        $publicationYear = $resource->publication_year;
        $resource = Resource::query()->with(Resource::DATACITE_EXPORT_RELATIONS)->findOrFail($resource->id);
        $resource->publication_year = $publicationYear;
        // Validate resource has an IGSN (stored in doi field)
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

        // Extract and validate prefix from IGSN
        $prefix = $this->extractPrefix($resource->doi);
        $allowedIgsnPrefixes = $this->testMode
            ? $this->prefixes
            : [trim((string) Config::get('datacite.production.igsn_prefix', ''))];

        if (! in_array($prefix, $allowedIgsnPrefixes, true)) {
            throw new \InvalidArgumentException(
                "IGSN prefix '{$prefix}' is not allowed. Allowed prefixes: ".implode(', ', $allowedIgsnPrefixes)
            );
        }

        // Check if resource has a landing page
        $resource->loadMissing('landingPage');
        $landingPage = $resource->landingPage;
        if ($landingPage === null) {
            throw new \RuntimeException(
                "Resource #{$resource->id} must have a landing page before registering an IGSN."
            );
        }

        // event=publish makes the identifier findable immediately.
        app(EmbargoService::class)->assertCanRegister($resource);
        $releaseEmbargo = app(EmbargoService::class)->isEmbargoed($resource);
        if ($releaseEmbargo && $resource->embargo_registration_started_at !== null) {
            return $this->reconcileEmbargoIgsn($resource, $prefix);
        }
        if ($releaseEmbargo) {
            $resource->access_level = AccessLevel::OPEN;
        }

        // Generate DataCite metadata using the existing exporter
        $jsonExporter = new DataCiteJsonExporter;
        $dataCiteData = $jsonExporter->export($resource);
        if ($releaseEmbargo) {
            $resource->access_level = AccessLevel::EMBARGOED;
        }

        // Build the registration payload – KEEP the DOI (unlike registerDoi which unsets it)
        $payload = [
            'data' => [
                'type' => 'dois',
                'attributes' => array_merge(
                    $dataCiteData['data']['attributes'],
                    [
                        'doi' => $resource->doi,
                        'prefix' => $prefix,
                        'url' => $releaseEmbargo ? url("/datasets/{$resource->id}") : $landingPage->public_url,
                        'event' => 'publish',
                        'publicationYear' => (string) now(config('app.timezone'))->year, // Always use current year at registration time
                    ]
                ),
            ],
        ];

        Log::info('Registering IGSN with DataCite', [
            'resource_id' => $resource->id,
            'igsn' => $resource->doi,
            'prefix' => $prefix,
            'test_mode' => $this->testMode,
            'url' => $landingPage->public_url,
            'endpoint' => $this->endpoint,
        ]);

        if (config('app.debug')) {
            Log::debug('IGSN registration payload', [
                'payload' => $payload,
            ]);
        }

        $claimedAttempt = false;
        try {
            if ($releaseEmbargo) {
                if (! app(EmbargoService::class)->claimRegistration($resource, $prefix)) {
                    return $this->reconcileEmbargoIgsn(Resource::query()->findOrFail($resource->id), $prefix);
                }
                $claimedAttempt = true;
                $current = Resource::query()->with(Resource::DATACITE_EXPORT_RELATIONS)->findOrFail($resource->id);
                try {
                    app(EmbargoService::class)->assertCanRegister($current);
                    if (app(EmbargoService::class)->availableDate($current) !== app(EmbargoService::class)->availableDate($resource)) {
                        throw new \InvalidArgumentException('The embargo date changed before registration. Please retry.');
                    }
                } catch (\InvalidArgumentException $exception) {
                    app(EmbargoService::class)->clearRejectedRegistration($current);
                    throw $exception;
                }
            }

            $response = $this->client->createDoi($payload, allowRetry: ! $releaseEmbargo);
            $response->throw();

            $responseData = $response->json();

            if ($responseData === null) {
                Log::error('DataCite response is not valid JSON', [
                    'resource_id' => $resource->id,
                    'igsn' => $resource->doi,
                    'response_body' => $response->body(),
                ]);

                throw new \RuntimeException('Received invalid JSON response from DataCite API');
            }

            Log::info('IGSN registered successfully', [
                'resource_id' => $resource->id,
                'doi' => $responseData['data']['id'] ?? null,
            ]);

            return $responseData;
        } catch (RequestException $e) {
            $response = $e->response;
            /** @phpstan-ignore notIdentical.alwaysTrue */
            $statusCode = $response !== null ? $response->status() : null;
            /** @phpstan-ignore notIdentical.alwaysTrue */
            $responseBody = $response !== null ? $response->body() : null;
            /** @phpstan-ignore notIdentical.alwaysTrue */
            $responseJson = $response !== null ? $response->json() : null;

            if ($claimedAttempt && in_array($statusCode, [400, 401, 403, 422, 429], true)) {
                app(EmbargoService::class)->clearRejectedRegistration($resource);
            }

            Log::error('Failed to register IGSN with DataCite', [
                'resource_id' => $resource->id,
                'igsn' => $resource->doi,
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
     * Extract the DOI prefix from a full DOI/IGSN string.
     *
     * @example "10.60510/IEYRS123" → "10.60510"
     */
    private function extractPrefix(string $doi): string
    {
        $parts = explode('/', $doi, 2);

        return $parts[0];
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
        $resource->loadMissing('landingPage');
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
                        'schemaVersion' => DataCiteSchemaVersion::KERNEL_4,
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
            // Send PUT request to DataCite API
            $response = $this->client->updateDoi($resource->doi, $payload);
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

    /** @return array<string, mixed> */
    private function reconcileEmbargoDoi(Resource $resource, string $prefix): array
    {
        if ($resource->embargo_registration_prefix !== $prefix) {
            throw new \InvalidArgumentException('An embargo registration attempt with another DOI prefix is pending reconciliation.');
        }

        $targetUrl = url("/datasets/{$resource->id}");
        $response = $this->client->findDoisByUrl($targetUrl, $prefix);
        $response->throw();
        $records = $response->json('data');
        $matches = is_array($records) ? array_values(array_filter($records, function (mixed $record) use ($resource, $prefix): bool {
            return is_array($record) && $this->matchesEmbargoRelease($record, $resource, $prefix);
        })) : [];

        if (count($matches) !== 1) {
            throw new \RuntimeException('The earlier DataCite embargo registration is pending reconciliation. No second POST was sent.');
        }

        return ['data' => ['id' => $matches[0]['id']]];
    }

    /** @return array<string, mixed> */
    private function reconcileEmbargoIgsn(Resource $resource, string $prefix): array
    {
        if ($resource->embargo_registration_prefix !== $prefix || ! is_string($resource->doi)) {
            throw new \InvalidArgumentException('An IGSN embargo registration attempt is pending reconciliation.');
        }

        $response = $this->client->getDoi($resource->doi);
        if ($response->status() === 404) {
            throw new \RuntimeException('The earlier DataCite embargo registration is pending reconciliation. No second POST was sent.');
        }
        $response->throw();
        $record = $response->json('data');
        if (! is_array($record) || ! $this->matchesEmbargoRelease($record, $resource, $prefix)
            || strcasecmp((string) ($record['id'] ?? ''), $resource->doi) !== 0) {
            throw new \RuntimeException('The remote IGSN does not match the expected Open access embargo release. No second POST was sent.');
        }

        return ['data' => ['id' => $record['id']]];
    }

    /** @param array<string, mixed> $record */
    private function matchesEmbargoRelease(array $record, Resource $resource, string $prefix): bool
    {
        $attributes = $record['attributes'] ?? null;
        if (! is_array($attributes) || ! is_string($record['id'] ?? null)
            || ! str_starts_with(strtolower($record['id']), strtolower($prefix).'/')
            || ($attributes['url'] ?? null) !== url("/datasets/{$resource->id}")
            || ($attributes['state'] ?? null) !== 'findable') {
            return false;
        }

        $rights = $attributes['rightsList'] ?? null;
        if (! is_array($rights)) {
            return false;
        }

        foreach ($rights as $right) {
            if (is_array($right) && ($right['rightsIdentifier'] ?? null) === AccessLevel::OPEN->coarIdentifier()) {
                return true;
            }
        }

        return false;
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

    /**
     * Update only the landing-page URL without changing metadata or DOI state.
     *
     * @return array<string, mixed>
     */
    public function updateLandingPageUrl(string $identifier, string $targetUrl): array
    {
        $response = $this->client->updateLandingPageUrl($identifier, $targetUrl);
        $response->throw();

        $responseData = $response->json();
        if (! is_array($responseData)) {
            throw new \RuntimeException('Received invalid JSON response from DataCite API');
        }

        return $responseData;
    }
}
