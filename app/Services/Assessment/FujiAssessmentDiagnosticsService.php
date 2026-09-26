<?php

declare(strict_types=1);

namespace App\Services\Assessment;

final class FujiAssessmentDiagnosticsService
{
    /**
     * @param  array<string, mixed>|null  $payload
     * @return array{softwareVersion: string|null, metricVersion: string|null, resolvedUrl: string|null, f4Status: string|null, metadataSources: list<array{source: string|null, format: string|null, schema: string|null, url: string|null}>}
     */
    public function fromPayload(?array $payload): array
    {
        $sources = [];
        $harvested = $payload['harvested_metadata'] ?? null;

        if (is_array($harvested)) {
            foreach ($harvested as $record) {
                if (! is_array($record)) {
                    continue;
                }

                $sources[] = [
                    'source' => $this->string($record['metadata_source'] ?? $record['source'] ?? $record['method'] ?? null),
                    'format' => $this->string($record['metadata_format'] ?? $record['format'] ?? null),
                    'schema' => $this->string($record['metadata_schema'] ?? $record['schema'] ?? null),
                    'url' => $this->string($record['url'] ?? $record['source_url'] ?? null),
                ];
            }
        }

        $f4Status = null;
        $results = $payload['results'] ?? null;
        if (! is_array($results)) {
            $results = [];
        }

        foreach ($results as $result) {
            if (! is_array($result) || ($result['metric_identifier'] ?? null) !== 'FsF-F4-01M') {
                continue;
            }

            $test = data_get($result, 'metric_tests.FsF-F4-01M-1.metric_test_status');
            $f4Status = $this->string($test) ?? $this->string($result['test_status'] ?? null);
            break;
        }

        return [
            'softwareVersion' => $this->string($payload['software_version'] ?? null),
            'metricVersion' => $this->string($payload['metric_version'] ?? null),
            'resolvedUrl' => $this->string($payload['resolved_url'] ?? null),
            'f4Status' => $f4Status,
            'metadataSources' => $sources,
        ];
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
