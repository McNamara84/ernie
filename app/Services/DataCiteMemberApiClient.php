<?php

declare(strict_types=1);

namespace App\Services;

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
    ) {
        $authenticatedUser = auth()->user();
        $user = $authenticatedUser instanceof User ? $authenticatedUser : null;
        $this->testMode = app(DataCiteModeResolverService::class)->shouldUseTestMode($user);
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

    public function getDoi(string $identifier, bool $deferWhenLimited = false): Response
    {
        return $this->send(
            'GET',
            $this->doiUrl($identifier),
            deferWhenLimited: $deferWhenLimited,
            credentialIdentifier: $identifier,
        );
    }

    /** @param array<string, mixed> $payload */
    public function createDoi(array $payload): Response
    {
        return $this->send(
            'POST',
            "{$this->endpoint}/dois",
            $payload,
            $this->writeAttempts(),
            credentialIdentifier: $this->payloadIdentifier($payload),
        );
    }

    /** @param array<string, mixed> $payload */
    public function updateDoi(string $identifier, array $payload): Response
    {
        return $this->send(
            'PUT',
            $this->doiUrl($identifier),
            $payload,
            $this->writeAttempts(),
            credentialIdentifier: $identifier,
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
     * @param  array<string, mixed>|null  $payload
     */
    private function send(
        string $method,
        string $url,
        ?array $payload = null,
        int $maxAttempts = 1,
        bool $deferWhenLimited = false,
        ?string $credentialIdentifier = null,
    ): Response {
        $maxAttempts = max(1, $maxAttempts);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $this->limiter->waitForSlot($deferWhenLimited);
            $request = $this->request($credentialIdentifier);

            try {
                $response = $payload === null
                    ? $request->send($method, $url)
                    : $request->send($method, $url, ['json' => $payload]);
            } catch (ConnectionException $exception) {
                if ($attempt >= $maxAttempts) {
                    throw $exception;
                }

                continue;
            }

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

    private function writeAttempts(): int
    {
        return max(1, (int) config('datacite.transport_transient_attempts', 3));
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

        return Http::withBasicAuth($username, $password)
            ->withHeaders([
                'Content-Type' => 'application/vnd.api+json',
                'Accept' => 'application/vnd.api+json',
                'User-Agent' => $userAgent,
            ])
            ->connectTimeout(max(1, (int) config('datacite.landing_page_url_update.connect_timeout_seconds', 10)))
            ->timeout(max(1, (int) config('datacite.landing_page_url_update.timeout_seconds', 30)));
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
