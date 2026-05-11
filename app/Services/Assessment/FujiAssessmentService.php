<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class FujiAssessmentService
{
    private const UNAVAILABLE_MESSAGE = 'F-UJI is currently unavailable. Please try again shortly.';

    public function isConfigured(): bool
    {
        return (bool) Config::get('fuji.enabled', false)
            && $this->baseUrl() !== null
            && $this->username() !== null
            && $this->password() !== null;
    }

    /**
     * @return array{healthy: bool, message: string|null}
     */
    public function healthStatus(): array
    {
        if (! $this->isConfigured()) {
            return [
                'healthy' => false,
                'message' => 'F-UJI is not configured.',
            ];
        }

        try {
            $response = $this->baseRequest()
                ->get($this->healthEndpoint());
        } catch (ConnectionException $exception) {
            $this->logTransportFailure('health check', $exception);

            return [
                'healthy' => false,
                'message' => self::UNAVAILABLE_MESSAGE,
            ];
        } catch (\Throwable $exception) {
            $this->logTransportFailure('health check', $exception);

            return [
                'healthy' => false,
                'message' => self::UNAVAILABLE_MESSAGE,
            ];
        }

        if (! $response->successful()) {
            $this->logUnsuccessfulResponse('health check', $response);

            return [
                'healthy' => false,
                'message' => self::UNAVAILABLE_MESSAGE,
            ];
        }

        return [
            'healthy' => true,
            'message' => null,
        ];
    }

    /**
     * @return array{score: float, payload: array<string, mixed>, resolvedUrl: string|null, normalizedIdentifier: string|null}
     */
    public function assessIdentifier(string $identifier): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('F-UJI is not configured.');
        }

        try {
            $response = $this->baseRequest()
                ->acceptJson()
                ->asJson()
                ->post($this->endpoint(), $this->buildPayload($identifier));
        } catch (ConnectionException $exception) {
            $this->logTransportFailure('assessment', $exception, [
                'identifier' => $identifier,
            ]);

            throw new RuntimeException(self::UNAVAILABLE_MESSAGE, previous: $exception);
        } catch (\Throwable $exception) {
            $this->logTransportFailure('assessment', $exception, [
                'identifier' => $identifier,
            ]);

            throw new RuntimeException(self::UNAVAILABLE_MESSAGE, previous: $exception);
        }

        if (! $response->successful()) {
            $this->logUnsuccessfulResponse('assessment', $response, [
                'identifier' => $identifier,
            ]);

            throw new RuntimeException(self::UNAVAILABLE_MESSAGE);
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json();
        $score = data_get($payload, 'summary.score_percent.FAIR');

        if (! is_numeric($score)) {
            throw new RuntimeException('F-UJI response does not contain summary.score_percent.FAIR.');
        }

        $resolvedUrl = data_get($payload, 'resolved_url');
        $normalizedIdentifier = data_get($payload, 'request.normalized_object_identifier');

        return [
            'score' => round((float) $score, 2),
            'payload' => $payload,
            'resolvedUrl' => is_string($resolvedUrl) ? $resolvedUrl : null,
            'normalizedIdentifier' => is_string($normalizedIdentifier) ? $normalizedIdentifier : null,
        ];
    }

    /**
     * @return array<string, bool|string>
     */
    private function buildPayload(string $identifier): array
    {
        $payload = [
            'object_identifier' => $identifier,
            'test_debug' => (bool) Config::get('fuji.test_debug', false),
            'use_datacite' => (bool) Config::get('fuji.use_datacite', true),
            'use_github' => (bool) Config::get('fuji.use_github', false),
        ];

        $metricVersion = Config::get('fuji.metric_version');

        if (is_string($metricVersion) && trim($metricVersion) !== '') {
            $payload['metric_version'] = $metricVersion;
        }

        return $payload;
    }

    private function endpoint(): string
    {
        return rtrim($this->baseUrl() ?? '', '/') . '/fuji/api/v1/evaluate';
    }

    private function healthEndpoint(): string
    {
        return rtrim($this->baseUrl() ?? '', '/') . '/fuji/api/v1/ui/';
    }

    private function baseUrl(): ?string
    {
        $value = Config::get('fuji.base_url');

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }

    private function username(): ?string
    {
        $value = Config::get('fuji.username');

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }

    private function password(): ?string
    {
        $value = Config::get('fuji.password');

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }

    private function timeout(): int
    {
        return max(1, (int) Config::get('fuji.timeout', 60));
    }

    private function connectTimeout(): int
    {
        return max(1, (int) Config::get('fuji.connect_timeout', 10));
    }

    private function baseRequest(): PendingRequest
    {
        return Http::withBasicAuth(
            $this->username() ?? '',
            $this->password() ?? '',
        )
            ->connectTimeout($this->connectTimeout())
            ->timeout($this->timeout());
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logTransportFailure(string $operation, \Throwable $exception, array $context = []): void
    {
        Log::warning('F-UJI request failed', [
            ...$context,
            'operation' => $operation,
            'base_url' => $this->baseUrl(),
            'exception_class' => $exception::class,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logUnsuccessfulResponse(string $operation, Response $response, array $context = []): void
    {
        Log::warning('F-UJI returned unsuccessful response', [
            ...$context,
            'operation' => $operation,
            'base_url' => $this->baseUrl(),
            'status' => $response->status(),
            'body' => $this->responseBodyExcerpt($response),
        ]);
    }

    private function responseBodyExcerpt(Response $response): ?string
    {
        $body = trim(preg_replace('/\s+/', ' ', $response->body()) ?? '');

        if ($body === '') {
            return null;
        }

        return Str::limit($body, 200, '...');
    }
}