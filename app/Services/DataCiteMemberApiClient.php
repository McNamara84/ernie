<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DataCiteRequestDeferredException;
use App\Exceptions\DataCiteWriteSupersededException;
use App\Models\LandingPage;
use App\Models\ResourceTombstoneTransition;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

/**
 * Authenticated DataCite REST API transport shared by all write paths.
 */
class DataCiteMemberApiClient
{
    private readonly bool $testMode;

    private readonly string $endpoint;

    /** @var array<string, mixed> */
    private readonly array $environmentConfig;

    public function __construct(
        private readonly DataCiteRequestLimiter $limiter,
        ?bool $testMode = null,
    ) {
        $authenticatedUser = auth()->user();
        $user = $authenticatedUser instanceof User ? $authenticatedUser : null;
        $this->testMode = $testMode ?? app(DataCiteModeResolverService::class)->shouldUseTestMode($user);
        $config = $this->testMode ? Config::get('datacite.test') : Config::get('datacite.production');
        $this->environmentConfig = is_array($config) ? $config : [];
        $this->endpoint = rtrim((string) ($this->environmentConfig['endpoint'] ?? ''), '/');
    }

    public function isTestMode(): bool
    {
        return $this->testMode;
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }

    public function repositoryClientId(): string
    {
        $clientId = trim((string) ($this->environmentConfig['client_id'] ?? ''));

        if ($clientId === '') {
            throw new \RuntimeException(
                $this->testMode
                    ? 'DataCite test repository client ID is not configured. Please set DATACITE_TEST_CLIENT_ID.'
                    : 'DataCite production repository client ID is not configured. Please set DATACITE_CLIENT_ID.'
            );
        }

        return strtolower($clientId);
    }

    /** @return list<string> */
    public function prefixes(): array
    {
        $prefixes = $this->environmentConfig['prefixes'] ?? [];
        if (! is_array($prefixes)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $prefix): string => strtolower(trim((string) $prefix)),
            $prefixes,
        )));
    }

    public function igsnPrefix(): string
    {
        return strtolower(trim((string) config('datacite.production.igsn_prefix', '')));
    }

    public function getDoi(string $identifier, bool $deferWhenLimited = false): Response
    {
        return $this->send(
            'GET',
            $this->doiUrl($identifier),
            deferWhenLimited: $deferWhenLimited,
            credentialIdentifier: $identifier,
        );
    }

    public function listDois(int $pageNumber = 1, int $pageSize = 1000): Response
    {
        if ($pageNumber < 1) {
            throw new \InvalidArgumentException('The DataCite DOI page number must be at least 1.');
        }
        if ($pageSize < 1 || $pageSize > 1000) {
            throw new \InvalidArgumentException('The DataCite DOI page size must be between 1 and 1000.');
        }

        $query = http_build_query([
            'client-id' => $this->repositoryClientId(),
            'page' => [
                'number' => $pageNumber,
                'size' => $pageSize,
            ],
        ], encoding_type: PHP_QUERY_RFC3986);

        return $this->send(
            'GET',
            "{$this->endpoint}/dois?{$query}",
            maxAttempts: $this->transientAttempts(),
        );
    }

    /** Find a previously accepted create by its resource-specific stable target. */
    public function findDoisByUrl(string $targetUrl, string $prefix): Response
    {
        $query = http_build_query([
            'query' => 'url:"'.str_replace('"', '\\"', $targetUrl).'"',
            'prefix' => $prefix,
            'page' => ['size' => 10],
            'disable-facets' => 'true',
        ], encoding_type: PHP_QUERY_RFC3986);

        return $this->send(
            'GET',
            "{$this->endpoint}/dois?{$query}",
            maxAttempts: $this->transientAttempts(),
            credentialIdentifier: $prefix,
        );
    }

    /** @param array<string, mixed> $payload */
    public function createDoi(array $payload, bool $allowRetry = true): Response
    {
        // An embargo create uses a durable reconciliation marker. A timed-out
        // create may already have minted a DOI, so that path gets one POST.
        return $this->send(
            'POST',
            "{$this->endpoint}/dois",
            $payload,
            $allowRetry ? $this->transientAttempts() : 1,
            credentialIdentifier: $this->payloadIdentifier($payload),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  string|null  $writtenRevision  Receives this call's last PUT revision; verify under the DOI lock before acknowledging it later.
     */
    public function updateDoi(string $identifier, array $payload, ?int $tombstoneTransitionId = null, ?string $expectedWriteRevision = null, ?string &$writtenRevision = null): Response
    {
        return $this->send(
            'PUT',
            $this->doiUrl($identifier),
            $payload,
            $this->transientAttempts(),
            credentialIdentifier: $identifier,
            tombstoneTransitionId: $tombstoneTransitionId,
            expectedWriteRevision: $expectedWriteRevision,
            writtenRevision: $writtenRevision,
        );
    }

    public function updateLandingPageUrl(string $identifier, string $targetUrl, bool $deferWhenLimited = false): Response
    {
        return $this->send('PUT', $this->doiUrl($identifier), [
            'data' => [
                'id' => $identifier,
                'type' => 'dois',
                'attributes' => [
                    'url' => $targetUrl,
                ],
            ],
        ], 1, $deferWhenLimited, $identifier);
    }

    /**
     * Applies the latest lifecycle state inside the DOI lock, even for stale queued payloads.
     *
     * @param  array<string, mixed>  $payload
     * @param  array{transition_id: int, state: mixed}|null  $lifecycleRead
     * @return array<string, mixed>|null Null requests a lifecycle read outside the DOI lock.
     */
    private function protectTombstonePayload(string $identifier, array $payload, ?array $lifecycleRead): ?array
    {
        $transition = ResourceTombstoneTransition::where('doi', $identifier)->latest('id')->first();
        if ($transition === null) {
            return $payload;
        }
        $page = LandingPage::where('resource_id', $transition->resource_id)->first();
        if (! $page?->is_tombstone && in_array($transition->status, ['succeeded', 'superseded'], true)) {
            return $payload;
        }
        if ($transition->test_mode !== $this->testMode) {
            throw new \RuntimeException('The DOI lifecycle belongs to a different DataCite environment.');
        }
        $payload['data']['attributes']['url'] = $transition->target_url;
        if ($transition->target_state === 'registered' && ($payload['data']['attributes']['event'] ?? null) === 'publish') {
            if (($lifecycleRead['transition_id'] ?? null) !== $transition->id) {
                return null;
            }
            if ($lifecycleRead['state'] === 'findable') {
                $payload['data']['attributes']['event'] = 'hide';
            } else {
                unset($payload['data']['attributes']['event']);
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function send(
        string $method,
        string $url,
        ?array $payload = null,
        int $maxAttempts = 1,
        bool $deferWhenLimited = false,
        ?string $credentialIdentifier = null,
        ?int $tombstoneTransitionId = null,
        ?string $expectedWriteRevision = null,
        ?string &$writtenRevision = null,
    ): Response {
        $maxAttempts = max(1, $maxAttempts);
        $writeRevision = $method === 'PUT' && $credentialIdentifier !== null && $payload !== null
            ? DataCiteDoiWriteLockService::run($credentialIdentifier, $this->testMode, function () use ($credentialIdentifier, $tombstoneTransitionId, $expectedWriteRevision): string {
                $this->assertTombstoneTransition($credentialIdentifier, $tombstoneTransitionId);
                if ($expectedWriteRevision !== null) {
                    DataCiteDoiWriteLockService::assertCurrentWrite($credentialIdentifier, $this->testMode, $expectedWriteRevision);
                }

                return DataCiteDoiWriteLockService::observeWrite($credentialIdentifier, $this->testMode);
            }) : null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            if (app(InteractiveApiRequestContextService::class)->remainingMilliseconds() === 0) {
                throw new DataCiteRequestDeferredException(1000);
            }
            try {
                $response = $writeRevision !== null && $credentialIdentifier !== null && $payload !== null
                    ? $this->sendWrite($credentialIdentifier, $url, $payload, $writeRevision, $deferWhenLimited, $tombstoneTransitionId)
                    : $this->limiter->runWhenReady(function () use ($method, $url, $payload, $credentialIdentifier): Response|int {
                        $delay = $this->limiter->reserveSlot(DataCiteDoiWriteLockService::hasHeldLock());
                        if ($delay > 0) {
                            return $delay;
                        }

                        return $this->request($credentialIdentifier)->send($method, $url, $payload === null ? [] : ['json' => $payload]);
                    }, $deferWhenLimited);
            } catch (ConnectionException $exception) {
                if ($attempt >= $maxAttempts) {
                    throw $exception;
                }

                continue;
            }
            // Keep the revision of our own PUT, even if another writer acquires
            // the lock before the caller can finalize its local transition.
            $writtenRevision = $writeRevision;

            if ($response->status() === 429) {
                $this->limiter->imposeCooldown($this->retryAfterSeconds($response));

                return $response;
            }

            if (! ($response->status() === 408 || $response->serverError()) || $attempt >= $maxAttempts) {
                return $response;
            }
        }

        throw new \LogicException('The DataCite request loop ended without a response.');
    }

    /** @param array<string, mixed> $payload */
    private function sendWrite(string $identifier, string $url, array $payload, string &$revision, bool $deferWhenLimited, ?int $tombstoneTransitionId): Response
    {
        $lifecycleRead = null;
        do {
            $response = $this->limiter->runWhenReady(function () use ($identifier, $url, $payload, &$revision, $tombstoneTransitionId, &$lifecycleRead): Response|int|null {
                return DataCiteDoiWriteLockService::run($identifier, $this->testMode, function () use ($identifier, $url, $payload, &$revision, $tombstoneTransitionId, &$lifecycleRead): Response|int|null {
                    DataCiteDoiWriteLockService::assertCurrentWrite($identifier, $this->testMode, $revision);
                    $this->assertTombstoneTransition($identifier, $tombstoneTransitionId);
                    $prepared = $this->protectTombstonePayload($identifier, $payload, $lifecycleRead);
                    if ($prepared === null) {
                        return null;
                    }
                    // Nonblocking admission immediately before sending: a permit
                    // cannot age while waiting for this DOI or a lifecycle GET.
                    $delay = $this->limiter->reserveSlot(nonBlocking: true);
                    if ($delay > 0) {
                        return $delay;
                    }

                    $revision = DataCiteDoiWriteLockService::beginWrite($identifier, $this->testMode);

                    return $this->request($identifier)->put($url, $prepared);
                });
            }, $deferWhenLimited);
            if ($response instanceof Response) {
                return $response;
            }
            $transitionId = (int) ResourceTombstoneTransition::where('doi', $identifier)->latest('id')->value('id');
            $state = $this->getDoi($identifier, $deferWhenLimited);
            $state->throw();
            $lifecycleRead = ['transition_id' => $transitionId, 'state' => $state->json('data.attributes.state')];
        } while (true);
    }

    private function assertTombstoneTransition(string $identifier, ?int $transitionId): void
    {
        if ($transitionId === null) {
            return;
        }
        $latest = ResourceTombstoneTransition::where('doi', $identifier)->latest('id')->first();
        if ($latest?->id !== $transitionId || in_array($latest->status, ['succeeded', 'superseded', 'failed'], true)) {
            throw new DataCiteWriteSupersededException;
        }
    }

    private function transientAttempts(): int
    {
        $attempts = max(1, (int) config('datacite.transport_transient_attempts', 3));

        return app(InteractiveApiRequestContextService::class)->isInteractive() ? min(2, $attempts) : $attempts;
    }

    private function retryAfterSeconds(Response $response): int
    {
        $header = trim((string) $response->header('Retry-After'));
        if ($header !== '' && ctype_digit($header)) {
            return max(1, (int) $header);
        }

        if ($header !== '') {
            $timestamp = strtotime($header);
            if ($timestamp !== false) {
                return max(1, $timestamp - time());
            }
        }

        return 60;
    }

    private function request(?string $credentialIdentifier = null): PendingRequest
    {
        [$username, $password] = $this->credentialsFor($credentialIdentifier);
        $supportEmail = trim((string) config('datacite.landing_page_url_update.support_email', ''));
        $appUrl = rtrim((string) config('app.url'), '/');
        $userAgent = 'ERNIE/1.0 ('.$appUrl;
        if ($supportEmail !== '') {
            $userAgent .= '; mailto:'.$supportEmail;
        }
        $userAgent .= ')';

        $interactive = app(InteractiveApiRequestContextService::class)->isInteractive();

        return Http::withBasicAuth($username, $password)
            ->withHeaders([
                'Content-Type' => 'application/vnd.api+json',
                'Accept' => 'application/vnd.api+json',
                'User-Agent' => $userAgent,
            ])
            ->connectTimeout(min($interactive ? 5 : PHP_INT_MAX, max(1, (int) config('datacite.landing_page_url_update.connect_timeout_seconds', 10))))
            ->timeout(min($interactive ? 20 : PHP_INT_MAX,
                max(1, (int) ceil(app(InteractiveApiRequestContextService::class)->remainingMilliseconds() / 1000)),
                max(1, (int) config('datacite.landing_page_url_update.timeout_seconds', 30))));
    }

    /**
     * @return array{string, string}
     */
    private function credentialsFor(?string $identifier): array
    {
        if (! $this->testMode && $this->isProductionIgsn($identifier)) {
            $username = trim((string) ($this->environmentConfig['igsn_username'] ?? ''));
            $password = (string) ($this->environmentConfig['igsn_password'] ?? '');

            if ($username === '' || $password === '') {
                throw new \RuntimeException(
                    'DataCite production IGSN credentials are not configured. '
                    .'Please set DATACITE_IGSN_USERNAME and DATACITE_IGSN_PASSWORD.'
                );
            }

            return [$username, $password];
        }

        return [
            (string) ($this->environmentConfig['username'] ?? ''),
            (string) ($this->environmentConfig['password'] ?? ''),
        ];
    }

    private function isProductionIgsn(?string $identifier): bool
    {
        if ($identifier === null) {
            return false;
        }

        $prefix = strtolower(trim((string) ($this->environmentConfig['igsn_prefix'] ?? '')));
        $normalizedIdentifier = strtolower(trim($identifier));

        return $prefix !== ''
            && ($normalizedIdentifier === $prefix || str_starts_with($normalizedIdentifier, $prefix.'/'));
    }

    /** @param array<string, mixed> $payload */
    private function payloadIdentifier(array $payload): ?string
    {
        $identifier = data_get($payload, 'data.attributes.doi')
            ?? data_get($payload, 'data.id')
            ?? data_get($payload, 'data.attributes.prefix');

        return is_string($identifier) ? $identifier : null;
    }

    private function doiUrl(string $identifier): string
    {
        return "{$this->endpoint}/dois/".rawurlencode(trim($identifier));
    }
}
