<?php

declare(strict_types=1);

namespace App\Services\Igsn;

final class IgsnSampleImageUrlService
{
    public const STATUS_MANAGED = 'managed';

    public const STATUS_EXTERNAL = 'external';

    public const STATUS_MISSING = 'missing';

    public const STATUS_UNSUPPORTED = 'unsupported';

    /**
     * @return array{status: string, source_url: string|null, external_url: string|null, reason: string|null}
     */
    public function resolve(?string $baseUrl, ?string $fileName): array
    {
        $baseUrl = is_string($baseUrl) ? trim($baseUrl) : '';
        $fileName = is_string($fileName) ? trim($fileName) : '';

        if ($baseUrl === '' || $this->isMissingValue($fileName)) {
            return $this->result(self::STATUS_MISSING, reason: 'missing_image_metadata');
        }

        $decodedFileName = $this->decodePathValue($fileName);
        if ($decodedFileName === null
            || $decodedFileName === ''
            || str_contains($decodedFileName, '/')
            || str_contains($decodedFileName, '\\')
            || $decodedFileName === '.'
            || $decodedFileName === '..'
            || str_contains($decodedFileName, "\0")) {
            return $this->result(self::STATUS_UNSUPPORTED, reason: 'invalid_file_name');
        }

        $parts = parse_url($baseUrl);
        if (! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || array_key_exists('fragment', $parts)
            || array_key_exists('query', $parts)) {
            return $this->result(self::STATUS_UNSUPPORTED, reason: 'invalid_base_url');
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if (! in_array($scheme, ['http', 'https'], true) || $port !== null) {
            return $this->result(self::STATUS_UNSUPPORTED, reason: 'unsupported_url');
        }

        $decodedBasePath = $this->decodePathValue((string) ($parts['path'] ?? '/'), rejectDotSegments: true);
        if ($decodedBasePath === null) {
            return $this->result(self::STATUS_UNSUPPORTED, reason: 'invalid_base_path');
        }

        $basePath = '/'.ltrim($decodedBasePath, '/');
        $basePath = rtrim($basePath, '/').'/';
        $encodedBasePath = implode('/', array_map('rawurlencode', explode('/', $basePath)));
        $encodedFileName = rawurlencode($decodedFileName);
        $sourceUrl = $scheme.'://'.$host.$encodedBasePath.$encodedFileName;

        $gfzHost = strtolower((string) config('igsn_images.gfz.host'));
        $gfzPrefix = (string) config('igsn_images.gfz.path_prefix', '/extern/IGSN/');
        if ($scheme === 'https' && hash_equals($gfzHost, $host) && str_starts_with($basePath, $gfzPrefix)) {
            return $this->result(self::STATUS_MANAGED, $sourceUrl);
        }

        $legacyIcdpHost = strtolower((string) config('igsn_images.icdp.legacy_host'));
        $canonicalIcdpHost = strtolower((string) config('igsn_images.icdp.canonical_host'));
        if (in_array($host, [$legacyIcdpHost, $canonicalIcdpHost], true)
            && $this->hasAllowedIcdpPrefix($basePath)) {
            $externalUrl = 'https://'.$canonicalIcdpHost.$encodedBasePath.$encodedFileName;

            return $this->result(self::STATUS_EXTERNAL, $sourceUrl, $externalUrl);
        }

        return $this->result(self::STATUS_UNSUPPORTED, $sourceUrl, reason: 'unsupported_source');
    }

    /**
     * Reclassify a fully resolved source URL stored in the database.
     *
     * @return array{status: string, source_url: string|null, external_url: string|null, reason: string|null}
     */
    public function classifySourceUrl(?string $sourceUrl): array
    {
        if (! is_string($sourceUrl) || trim($sourceUrl) === '') {
            return $this->result(self::STATUS_MISSING, reason: 'missing_source_url');
        }

        $parts = parse_url(trim($sourceUrl));
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'], $parts['path'])) {
            return $this->result(self::STATUS_UNSUPPORTED, reason: 'invalid_source_url');
        }

        $path = (string) $parts['path'];
        $fileName = basename($path);
        $basePath = substr($path, 0, -strlen($fileName));
        $baseUrl = strtolower((string) $parts['scheme']).'://'.strtolower((string) $parts['host']).$basePath;

        return $this->resolve($baseUrl, $fileName);
    }

    private function isMissingValue(string $value): bool
    {
        return $value === '' || in_array(strtoupper($value), ['N/A', 'NA', 'NN'], true);
    }

    private function decodePathValue(string $value, bool $rejectDotSegments = false): ?string
    {
        $candidate = $value;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            if (str_contains($candidate, "\0")
                || str_contains($candidate, '\\')
                || preg_match('/%(?:00|2f|5c)/i', $candidate) === 1
                || ($rejectDotSegments && $this->containsDotSegment($candidate))) {
                return null;
            }

            $decoded = rawurldecode($candidate);
            if ($decoded === $candidate) {
                return $decoded;
            }

            $candidate = $decoded;
        }

        return null;
    }

    private function containsDotSegment(string $path): bool
    {
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return true;
            }
        }

        return false;
    }

    private function hasAllowedIcdpPrefix(string $path): bool
    {
        foreach ((array) config('igsn_images.icdp.path_prefixes', []) as $prefix) {
            if (is_string($prefix) && str_starts_with(strtolower($path), strtolower($prefix))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{status: string, source_url: string|null, external_url: string|null, reason: string|null}
     */
    private function result(string $status, ?string $sourceUrl = null, ?string $externalUrl = null, ?string $reason = null): array
    {
        return [
            'status' => $status,
            'source_url' => $sourceUrl,
            'external_url' => $externalUrl,
            'reason' => $reason,
        ];
    }
}
