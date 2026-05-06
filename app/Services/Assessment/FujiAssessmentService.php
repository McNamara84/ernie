<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class FujiAssessmentService
{
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
        } catch (\Throwable $exception) {
            return [
                'healthy' => false,
                'message' => sprintf('F-UJI health check failed: %s', $exception->getMessage()),
            ];
        }

        if (! $response->successful()) {
            return [
                'healthy' => false,
                'message' => $this->unsuccessfulResponseMessage('F-UJI health check failed', $response),
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

        $response = $this->baseRequest()
            ->acceptJson()
            ->asJson()
            ->post($this->endpoint(), $this->buildPayload($identifier));

        if (! $response->successful()) {
            throw new RuntimeException($this->unsuccessfulResponseMessage('F-UJI assessment failed', $response));
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

    private function unsuccessfulResponseMessage(string $prefix, Response $response): string
    {
        $message = sprintf('%s with status %d.', $prefix, $response->status());
        $body = trim(preg_replace('/\s+/', ' ', $response->body()) ?? '');

        if ($body === '') {
            return $message;
        }

        return sprintf('%s Response: %s', $message, Str::limit($body, 200, '...'));
    }
}