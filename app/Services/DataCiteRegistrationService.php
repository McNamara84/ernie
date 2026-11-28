<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Resource;
use App\Support\IgsnIdentifier;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
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
        return $this->publicationService()->publish($resource, $prefix);
    }

    /** @return array<string, mixed> */
    public function registerIgsn(Resource $resource, ?int $actorId = null): array
    {
        if (! $resource->doi) {
            throw new \RuntimeException('An IGSN must be assigned before publication.');
        }

        return $this->publicationService()->publish($resource, igsn: true, actorId: $actorId);
    }

    public function shouldUpdateIgsn(Resource $resource, string $operation): bool
    {
        if ($operation === 'verify') {
            $doi = IgsnIdentifier::forRegistration((string) $resource->doi, $this->client->igsnPrefix());

            return app(DataCiteRegistrationVerifierService::class)->verify($this->client, $doi)->isRegistered();
        }

        return $operation === 'update';
    }

    public function getRegistrationState(string $doi): string
    {
        return app(DataCiteRegistrationVerifierService::class)->verify($this->client, $doi)->state;
    }

    private function publicationService(): ResourceDoiPublicationService
    {
        return new ResourceDoiPublicationService($this->client, app(DataCiteRegistrationVerifierService::class), app(ResourceDoiPublicationTargetService::class));
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
        // A queued job or editor can hold an older access level or landing page.
        $resource = Resource::query()->with(Resource::DATACITE_EXPORT_RELATIONS)->findOrFail($resource->id);

        $igsnPrefix = $resource->igsnMetadata !== null && ! $this->testMode ? $this->client->igsnPrefix() : null;
        $payload = app(DataCitePayloadService::class)->metadataUpdate($resource, $igsnPrefix);
        $doi = $payload['data']['id'];

        Log::info('Updating DOI metadata with DataCite', [
            'resource_id' => $resource->id,
            'doi' => $resource->doi,
            'test_mode' => $this->testMode,
        ]);

        try {
            $beforeWrite = $igsnPrefix === null ? null : function () use ($resource, $doi, $payload): void {
                $this->prepareIgsnUpdateTarget($resource, $doi, $payload['data']['attributes']['url']);
            };
            $response = $this->client->updateDoi($doi, $payload, beforeWrite: $beforeWrite);
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

            if ($doi !== $resource->doi) {
                DB::transaction(function () use ($resource, $doi): void {
                    $current = Resource::query()->lockForUpdate()->findOrFail($resource->id);
                    if ($current->doi === $resource->doi) {
                        $current->doi = $doi;
                        $current->save();
                    } elseif ($current->doi !== $doi) {
                        throw new \RuntimeException('The IGSN changed while DataCite was updating its metadata. Review the sample before retrying.');
                    }
                });
            }

            Log::info('DOI metadata updated successfully', ['resource_id' => $resource->id, 'doi' => $doi]);

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

    private function prepareIgsnUpdateTarget(Resource $resource, string $doi, string $target): void
    {
        DB::transaction(function () use ($resource, $doi, $target): void {
            $current = Resource::query()->lockForUpdate()->findOrFail($resource->id);
            if ($current->doi !== $resource->doi) {
                throw new \RuntimeException('The IGSN changed before the metadata update. Review the sample and retry.');
            }
            $page = $current->landingPage()->lockForUpdate()->firstOrFail();
            $page->useRegistrationDoi((string) $current->doi, $doi);
            if (! $page->is_published || $page->public_url !== $target) {
                throw new \RuntimeException('The landing page changed before the metadata update. Review the sample and retry.');
            }
            if ($page->isDirty('doi_prefix')) {
                $page->save();
            }
        });
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
