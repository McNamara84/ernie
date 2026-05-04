<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
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
     * @return array{score: float, payload: array<string, mixed>, resolvedUrl: string|null, normalizedIdentifier: string|null}
     */
    public function assessIdentifier(string $identifier): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('F-UJI is not configured.');
        }

        $response = Http::withBasicAuth(
            $this->username() ?? '',
            $this->password() ?? '',
        )
            ->acceptJson()
            ->asJson()
            ->connectTimeout($this->connectTimeout())
            ->timeout($this->timeout())
            ->post($this->endpoint(), $this->buildPayload($identifier));

        if (! $response->successful()) {
            throw new RuntimeException(sprintf('F-UJI assessment failed with status %d.', $response->status()));
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
}