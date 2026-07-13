<?php

// Enforce strict scalar types to catch hidden type coercion bugs.
declare(strict_types = 1);

namespace App\Services\DateType; 

use Illuminate\Support\Facades\Http; 

class DateTypeSchemaorgExtractionService
{
    private const SCHEMA_ORG_BASE_URL = 'https://data.crosscite.org/application/vnd.schemaorg.ld+json/';

    private const ALLOWED_GFZ_HOSTS = [
        'dataservices.gfz.de',
        'dataservices.gfz-potsdam.de',
    ];

    private const SCHEMA_ORG_DATE_FIELDS = [
        'dateCreated' => 'Created',
        'datePublished' => 'Issued',
    ];


    /**
     * @return array<int, array<string, mixed>>
     */

    public function loadAllowedSchemaorg(string $doi): array 
    {
        
        $doi = trim($doi);

        $url = self::SCHEMA_ORG_BASE_URL.$doi;

        if (! $this->isHttpUrl($url)) {
            return [$this->skip($url, 'unsupported_protocol')];

        }

        try {
            $response = Http::acceptJson()
                ->timeout(10)
                ->connectTimeout(5)
                ->withoutRedirecting()
                ->get($url);
            
            if (!$response -> successful()) {
                return [$this->skip($url, 'schemaorg_unreachable')];
            }
        } catch (\Throwable $e) {
            return [$this->skip($url, 'schemaorg_direct_failed', $e->getMessage())];
        }

        $data = $response -> json();

        $sourceUrl = $data['url'] ?? null;

        if (! is_string($sourceUrl) || ! $this->isAllowedGfzUrl($sourceUrl)) {
            return [$this->skip($url, 'unsupported_source_url')];
        }

        return $this->extractSchemaorgDateSuggestions($data, $sourceUrl, $url);
    }

    private function isHttpUrl(string $url): bool
    {
        $url = trim($url);

        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://');
    }

    private function isAllowedGfzUrl(string $url): bool 
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
        return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true)) {
        return false;
        }

        $host = $parts['host'] ?? null;

        if (! is_string($host) || $host === '') {
            return false;
        }

        return in_array(strtolower($host), self::ALLOWED_GFZ_HOSTS, true);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private function extractSchemaorgDateSuggestions (array $data, string $sourceUrl, string $url): array 
    {
        $suggestions = [];

        foreach (self::SCHEMA_ORG_DATE_FIELDS as $field => $dateType) {
            $value = $data[$field] ?? null;

            if (is_int($value)) {
                $value = (string) $value;
            }

            if (! is_string($value) || trim($value) === '') {
            continue;
            }
            $normalizedValue = DateTypeNormalizerService::normalize($value);

            if ($normalizedValue === null) 
            {
                continue;
            }

            $suggestions[] = [
            'suggestion_kind' => 'addition',
            'target_date_type' => $dateType,
            'normalized_value' => $normalizedValue,
            'source_url' => $sourceUrl,
            'evidence_source' => 'schema.org',
            'evidence_url' => $url,
            'schema_org_field' => $field,
            'confidence' => 'high',
            'is_ambiguous' => false,
            ];
        }

        return $this->deduplicateSuggestions($suggestions);

    }


    /**
     * @param  array<int, array<string, mixed>>  $suggestions
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateSuggestions(array $suggestions): array
    {
        $seen = [];
        $unique = [];

        foreach ($suggestions as $suggestion) {
            $key = ($suggestion['target_date_type'] ?? '').'|'.($suggestion['normalized_value'] ?? '').'|'.($suggestion['source_url'] ?? '');

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $suggestion;
        }

        return $unique;
    }

    /**
     * @return array<string, mixed>
     */

    private function skip(string $url, string $reason, ?string $error = null): array 
    {
        return [
            'source_url' => trim($url),
            'probe_method' => 'SKIP',
            'skip_reason' => $reason,
            'error' => $error,
            'raw_evidence' => [], 
            'suggestions' => [],
        ];
    
    }
}
