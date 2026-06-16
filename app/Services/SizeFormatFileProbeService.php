<?php

// dadurch passieren weniger versteckte Fehler.
declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;

class SizeFormatFileProbeService
{
    private const MAX_DIRECTORY_DEPTH = 5;

    private const MAX_DIRECTORY_COUNT = 100;

    private const ALLOWED_LINK_TEXTS = [
        'Download data',
        'Download data and description',
        'Download code',
        'Download model',
        'Download static version',
        'Download data and README',
        'Download video and description',
        'Download static versions of Assetmaster and Modelprop and their description',
        'Download static version of DEUS (20210621) and description',
        'Download static version of Quakeledger and description',
        'Download static version of DOuGLAS',
        'Download static code version',
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function extractAndProbe(string $url): array
    {
        $url = trim($url);

        if (! $this->isHttpUrl($url)) {

            return [$this->skip($url, 'unsupported_protocol')];

        }

        if (str_starts_with($url, 'https://doi.org/')) {

            try {
                $response = Http::timeout(10)
                    ->connectTimeout(5)
                    ->get($url);
                if (! $response->successful()) {
                    return [$this->skip($url, 'doi_redirect_unreachable')];
                }

                $url = (string) $response->effectiveUri();
            } catch (\Throwable $e) {
                return [$this->skip($url, 'doi_redirect_failed', $e->getMessage())];

            }

        }

        if (! str_starts_with($url, 'https://dataservices.gfz-potsdam.de/')) {
            return [$this->skip($url, 'unsupported_source_url')];
        }

        if (! $this->isHttpUrl($url)) {
            return [$this->skip($url, 'unsupported_protocol')];
        }

        try {
            $response = Http::timeout(10)
                ->connectTimeout(5)
                ->get($url);

            if (! $response->successful()) {
                return [$this->skip($url, 'landing_page_unreachable')];
            }

            $landingPageUrl = (string) $response->effectiveUri();
            $html = $response->body();

            if ($this->containsBlockedAccess($html)) {
                return [$this->skip($landingPageUrl, 'blocked_access_or_form_required')];
            }

            $downloadUrls = $this->extractPiwikDownloadLinks($landingPageUrl, $html);

            if (empty($downloadUrls)) {
                return [$this->skip($landingPageUrl, 'no_eligible_file_links_found')];
            }

            $results = [];

            foreach ($downloadUrls as $downloadUrl) {
                $results[] = $this->probeDirectoryListing($downloadUrl);
            }

            return $results;

        } catch (\Throwable $e) {
            return [$this->skip($url, 'exception', $e->getMessage())];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function probeDirectoryListing(string $url): array
    {
        $url = trim($url);

        if (! $this->isHttpUrl($url)) {
            return $this->skip($url, 'unsupported_protocol');
        }

        try {
            $response = Http::timeout(10)
                ->connectTimeout(5)
                ->get($url);

            if (! $response->successful()) {
                return $this->skip($url, 'directory_listing_unreachable');
            }

            $directoryUrl = (string) $response->effectiveUri();
            $html = $response->body();

            if ($this->containsBlockedAccess($html)) {
                return $this->skip($directoryUrl, 'blocked_access_or_form_required');
            }

            $files = $this->extractFilesFromApacheIndex($directoryUrl, $html);
            $visitedDirectories = [
                $this->canonicalDirectoryUrl($directoryUrl) => true,
            ];
            $directoryCount = 1;

            foreach ($this->extractSubdirectoryUrls($directoryUrl, $directoryUrl, $html) as $subdirectoryUrl) {
                $files = array_merge(
                    $files,
                    $this->probeSubdirectory(
                        $subdirectoryUrl,
                        $directoryUrl,
                        1,
                        $visitedDirectories,
                        $directoryCount,
                    ),
                );
            }

            $files = $this->deduplicateFiles($files);

            if (empty($files)) {
                return $this->skip($url, 'no_files_found');
            }

            $result = [
                'source_url' => $directoryUrl,
                'probe_method' => 'DIRECTORY_LISTING',
                'http_status' => $response->status(),
                'raw_evidence' => [
                    'files' => $files,
                ],
            ];

            $result['suggestions'] = $this->buildSuggestions([$result]);

            return $result;

        } catch (\Throwable $e) {
            return $this->skip($url, 'exception', $e->getMessage());
        }
    }

    /**
     *  @return array<string, mixed>
     */
    public function inferMetadataFromFileUrl(string $fileUrl): array
    {
        $fileUrl = trim($fileUrl);

        if (! $this->isHttpUrl($fileUrl)) {
            return $this->skip($fileUrl, 'unsupported_protocol');
        }

        try {
            $response = Http::timeout(10)
                ->connectTimeout(5)
                ->head($fileUrl);

            $suggestions = [];

            if ($response->successful()) {
                $contentType = $response->header('Content-Type');
                $contentLength = $response->header('Content-Length');

                if (trim((string) $contentType) !== '') {
                    $suggestions[] = [
                        'type' => 'format',
                        'inferred_value' => trim(explode(';', $contentType)[0]),
                        'source_url' => $fileUrl,
                        'probe_method' => 'CONTENT_TYPE_HEADER',
                        'evidence' => [
                            'content_type' => $contentType,
                        ],
                        'confidence' => 'high',
                    ];
                }

                if (ctype_digit((string) $contentLength)) {
                    $suggestions[] = [
                        'type' => 'size',
                        'inferred_value' => $this->formatBytes((int) $contentLength),
                        'source_url' => $fileUrl,
                        'probe_method' => 'CONTENT_LENGTH_HEADER',
                        'evidence' => [
                            'content_length' => (int) $contentLength,
                        ],
                        'confidence' => 'high',
                    ];
                }

                if (! empty($suggestions)) {
                    return [
                        'source_url' => $fileUrl,
                        'probe_method' => 'HTTP_HEAD',
                        'http_status' => $response->status(),
                        'raw_evidence' => [
                            'headers' => [
                                'content_type' => $contentType,
                                'content_length' => $contentLength,
                            ],
                        ],
                        'suggestions' => $suggestions,
                    ];
                }
            }

            $rangeResult = $this->inferFromRangedGet($fileUrl);

            if (($rangeResult['probe_method'] ?? null) !== 'SKIP' ){
                return $rangeResult;
            }
            
            return $this->inferFromFilenameFallback($fileUrl);

        } catch (\Throwable $e) {
            return $this->inferFromFilenameFallback($fileUrl, $e->getMessage());
        }
    }

    // macht aus den Rohdaten-Ergebnissen echte Suggestions
    /**
     * @param array<int, array<string, mixed>> $probeResults
     * @return array<int, array<string, mixed>>
     */
    public function buildSuggestions(array $probeResults): array
    {
        $suggestions = [];

        foreach ($probeResults as $probeResult) {
            if (($probeResult['probe_method'] ?? null) === 'SKIP') {
                continue;
            }

            if (! empty($probeResult['suggestions'])) {
                foreach ($probeResult['suggestions'] as $suggestion) {
                    $suggestions[] = $suggestion;
                }

                continue;
            }

            $sourceUrl = $probeResult['source_url'] ?? null;
            $files = $probeResult['raw_evidence']['files'] ?? [];

            $directoryFiles = [];

            foreach ($files as $file) {
                $fileUrl = $file['file_url'] ?? $sourceUrl;
                $format = $file['format'] ?? null;

                if ($format !== null && $format !== '') {
                    $suggestions[] = [
                        'type' => 'format',
                        'inferred_value' => $format,
                        'source_url' => $fileUrl,
                        'probe_method' => 'FILENAME_EXTENSION',
                        'evidence' => [
                            'filename' => $file['filename'] ?? null,
                            'format' => $format,
                        ],
                        'confidence' => $format === 'zip' ? 'low' : 'medium',
                    ];
                }

                if (is_array($file)) {
                    $directoryFiles[] = $file;
                }
            }

            $totalBytes = 0.0;
            $parsedSizeCount = 0;

            foreach ($directoryFiles as $file) {
                $bytes = $this->displayedSizeToBytes((string) ($file['file-size'] ?? ''));

                if ($bytes === null) {
                    continue;
                }

                $totalBytes += $bytes;
                $parsedSizeCount++;
            }

            if ($parsedSizeCount > 0) {
                $suggestions[] = [
                    'type' => 'size',
                    'inferred_value' => $this->formatByteSize($totalBytes),
                    'source_url' => $sourceUrl,
                    'probe_method' => 'DIRECTORY_LISTING',
                    'evidence' => [
                        'files' => $directoryFiles,
                        'parsed_file_count' => $parsedSizeCount,
                        'total_file_count' => count($directoryFiles),
                    ],
                    'confidence' => $parsedSizeCount === count($directoryFiles) ? 'high' : 'low',
                ];
            }
        }

        return $this->deduplicateSuggestions($suggestions);
    }

    /**
     * @return array<string, mixed>
     */
    private function inferFromRangedGet(string $fileUrl): array
    {
        try {
            $response = Http::timeout(10)
                ->connectTimeout(5)
                ->withHeaders([
                    'Range' => 'bytes=0-1023',
                ])
                ->get($fileUrl);

            if (! $response->successful()) {
                return $this->skip($fileUrl, 'ranged_get_unreachable');
            }

            $contentType = $response->header('Content-Type');
            $contentRange = $response->header('Content-Range');

            $suggestions = [];

            if (trim((string) $contentType) !== '') {
                $suggestions[] = [
                    'type' => 'format',
                    'inferred_value' => trim(explode(';', $contentType)[0]),
                    'source_url' => $fileUrl,
                    'probe_method' => 'RANGED_GET_CONTENT_TYPE',
                    'evidence' => [
                        'content_type' => $contentType,
                        'range' => 'bytes=0-1023',
                    ],
                    'confidence' => 'medium',
                ];
            }

            if (preg_match('/\/(\d+)$/', (string) $contentRange, $matches)) {
                $suggestions[] = [
                    'type' => 'size',
                    'inferred_value' => $this->formatBytes((int) $matches[1]),
                    'source_url' => $fileUrl,
                    'probe_method' => 'RANGED_GET_CONTENT_RANGE',
                    'evidence' => [
                        'content_range' => $contentRange,
                        'range' => 'bytes=0-1023',
                    ],
                    'confidence' => 'medium',
                ];
            }

            if (! empty($suggestions)) {
                return [
                    'source_url' => $fileUrl,
                    'probe_method' => 'RANGED_GET',
                    'http_status' => $response->status(),
                    'raw_evidence' => [
                        'headers' => [
                            'content_type' => $contentType,
                            'content_range' => $contentRange,
                            'range' => 'bytes=0-1023',
                        ],
                    ],
                    'suggestions' => $suggestions,
                ];
            }

            return $this->skip($fileUrl, 'no_ranged_get_metadata');

        } catch (\Throwable $e) {
            return $this->skip($fileUrl, 'ranged_get_exception', $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function inferFromFilenameFallback(string $fileUrl, ?string $error = null): array
    {
        $path = (parse_url($fileUrl, PHP_URL_PATH) ?? $fileUrl);
        $filename = basename(is_string($path) ? $path : $fileUrl);
        $extension = $this->extractFileMetadata($filename);

        if ($extension === null) {
            return $this->skip($fileUrl, 'no_header_or_filename_evidence', $error);
        }

        //
        return [
            'source_url' => $fileUrl,
            'probe_method' => 'FILENAME_EXTENSION_FALLBACK',
            'http_status' => null,
            'raw_evidence' => [
                'filename' => $filename,
                'extension' => $extension,
                'error' => $error,
            ],
            'suggestions' => [
                [
                    'type' => 'format',
                    'inferred_value' => $extension,
                    'source_url' => $fileUrl,
                    'probe_method' => 'FILENAME_EXTENSION_FALLBACK',
                    'evidence' => [
                        'filename' => $filename,
                        'extension' => $extension,
                    ],
                    'confidence' => $extension === 'zip' ? 'low' : 'medium',
                ],
            ],
        ];
    }

    /**
     *  @return array<int, string>
     */
    private function extractPiwikDownloadLinks(string $landingPageUrl, string $html): array
    {
        preg_match_all(
            '/<a\b([^>]*)href=["\']([^"\']+)["\']([^>]*)>(.*?)<\/a>/is',
            $html,
            $matches,
            PREG_SET_ORDER
        );

        $urls = [];

        foreach ($matches as $match) {
            $attributes = $match[1].' '.$match[3];
            $href = trim($match[2]);
            $linkText = trim(strip_tags($match[4]));

            if (! str_contains($attributes, 'piwik_download')) {
                continue;
            }

            if (! $this->isAllowedLinkText($linkText)) {
                continue;
            }

            $urls[] = $this->absoluteUrl($landingPageUrl, $href);
        }

        return array_values(array_unique($urls));
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function extractFilesFromApacheIndex(string $baseUrl, string $html): array
    {
        preg_match_all(
            '/<a\s+href=["\']([^"\']+)["\']>([^<]+)<\/a>\s+(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2})\s+([0-9.]+[KMGTP]?)/i',
            $html,
            $matches,
            PREG_SET_ORDER
        );

        $files = [];

        foreach ($matches as $match) {
            $href = trim($match[1]);
            $filename = trim($match[2]);
            $lastModified = trim($match[3]);
            $displayedSize = trim($match[4]);

            if ($filename === '' || str_contains(strtolower($filename), 'parent directory')) {
                continue;
            }

            if (str_ends_with($href, '/')) {
                continue;
            }

            $fileMetadata = $this->extractFileMetadata($filename);

            $files[] = [
                'file_url' => $this->absoluteUrl($baseUrl, $href),
                'filename' => $filename,
                'format' => $fileMetadata,
                'last_modified' => $lastModified,
                'file-size' => $displayedSize,
            ];
        }

        return $files;
    }

    /**
     * @param  array<string, bool>  $visitedDirectories
     * @return array<int, array<string, string|mixed>>
     */
    private function probeSubdirectory(
        string $url,
        string $rootUrl,
        int $depth,
        array &$visitedDirectories,
        int &$directoryCount,
    ): array {
        if ($depth > self::MAX_DIRECTORY_DEPTH || $directoryCount >= self::MAX_DIRECTORY_COUNT) {
            return [];
        }

        $canonicalUrl = $this->canonicalDirectoryUrl($url);

        if (isset($visitedDirectories[$canonicalUrl])) {
            return [];
        }

        $visitedDirectories[$canonicalUrl] = true;
        $directoryCount++;

        try {
            $response = Http::timeout(10)
                ->connectTimeout(5)
                ->get($canonicalUrl);

            if (! $response->successful()) {
                return [];
            }

            $effectiveUrl = $this->canonicalDirectoryUrl((string) $response->effectiveUri());
            $visitedDirectories[$effectiveUrl] = true;
            $html = $response->body();

            if ($this->containsBlockedAccess($html)) {
                return [];
            }

            $files = $this->extractFilesFromApacheIndex($effectiveUrl, $html);

            foreach ($this->extractSubdirectoryUrls($effectiveUrl, $rootUrl, $html) as $subdirectoryUrl) {
                $files = array_merge(
                    $files,
                    $this->probeSubdirectory(
                        $subdirectoryUrl,
                        $rootUrl,
                        $depth + 1,
                        $visitedDirectories,
                        $directoryCount,
                    ),
                );
            }

            return $files;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, string>
     */
    private function extractSubdirectoryUrls(string $currentUrl, string $rootUrl, string $html): array
    {
        preg_match_all(
            '/<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>/i',
            $html,
            $matches,
        );

        $directories = [];

        foreach ($matches[1] as $matchedHref) {
            $href = html_entity_decode(trim((string) $matchedHref), ENT_QUOTES | ENT_HTML5);
            $hrefPath = (string) (parse_url($href, PHP_URL_PATH) ?? '');

            if (
                $href === ''
                || $hrefPath === ''
                || ! str_ends_with($hrefPath, '/')
                || str_starts_with($hrefPath, '../')
                || $hrefPath === './'
                || $hrefPath === '/'
            ) {
                continue;
            }

            $directoryUrl = $this->canonicalDirectoryUrl(
                $this->absoluteUrl($currentUrl, $href),
            );

            if (! $this->isDescendantDirectory($rootUrl, $directoryUrl)) {
                continue;
            }

            $directories[] = $directoryUrl;
        }

        return array_values(array_unique($directories));
    }

    private function isDescendantDirectory(string $rootUrl, string $candidateUrl): bool
    {
        $root = parse_url($this->canonicalDirectoryUrl($rootUrl));
        $candidate = parse_url($this->canonicalDirectoryUrl($candidateUrl));

        if (
            ! is_array($root)
            || ! is_array($candidate)
            || strtolower((string) ($root['scheme'] ?? '')) !== strtolower((string) ($candidate['scheme'] ?? ''))
            || strtolower((string) ($root['host'] ?? '')) !== strtolower((string) ($candidate['host'] ?? ''))
            || ($root['port'] ?? null) !== ($candidate['port'] ?? null)
        ) {
            return false;
        }

        $rootPath = (string) ($root['path'] ?? '/');
        $candidatePath = (string) ($candidate['path'] ?? '/');

        return $candidatePath !== $rootPath && str_starts_with($candidatePath, $rootPath);
    }

    private function canonicalDirectoryUrl(string $url): string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $pathSegments = [];

        foreach (explode('/', (string) ($parts['path'] ?? '/')) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($pathSegments);

                continue;
            }

            $pathSegments[] = $segment;
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = '/'.implode('/', $pathSegments).'/';

        return strtolower($parts['scheme']).'://'.strtolower($parts['host']).$port.$path;
    }

    /**
     * @param  array<int, array<string, mixed>>  $files
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateFiles(array $files): array
    {
        $uniqueFiles = [];

        foreach ($files as $file) {
            $key = (string) ($file['file_url'] ?? $file['filename'] ?? count($uniqueFiles));
            $uniqueFiles[$key] = $file;
        }

        return array_values($uniqueFiles);
    }

    private function extractFileMetadata(string $filename): ?string
    {
        $parts = explode('.', strtolower($filename));

        if (count($parts) < 2) {
            return null;
        }

        $compressionFormats = ['gz', 'bz2', 'xz'];

        $last = end($parts);

        if (in_array($last, $compressionFormats, true) && count($parts) >= 3) {
            return $parts[count($parts) - 2] . '.' . $last;
        }

        return $last;
    }

    private function isAllowedLinkText(string $text): bool
    {
        $normalizedText = trim(preg_replace('/\s+/', ' ', $text) ?? '');

        foreach (self::ALLOWED_LINK_TEXTS as $allowedText) {
            if (strcasecmp($normalizedText, $allowedText) === 0) {
                return true;
            }
        }

        return false;
    }

    private function containsBlockedAccess(string $html): bool
    {
        $blockedIndicators = [
            'Full Name',
            'Purpose of use',
            'captcha',
            'confirm that you are human',
            'Bestätigen Sie, dass Sie ein Mensch sind',
            'registration required',
            'not available for public download',
        ];

        foreach ($blockedIndicators as $indicator) {
            if (stripos($html, $indicator) !== false) {
                return true;
            }
        }

        return false;
    }

    private function absoluteUrl(string $baseUrl, string $href): string
    {
        $baseUrl = trim($baseUrl);
        $href = trim($href);

        if ($this->isHttpUrl($href)) {
            return $href;
        }

        $parts = parse_url($baseUrl);

        if (! isset($parts['scheme'], $parts['host'])) {
            return $href;
        }

        $origin = (string) $parts['scheme'].'://'.(string) $parts['host'];

        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }

        $basePath = $parts['path'] ?? '';

        if ($basePath === '' || str_ends_with($basePath, '/')) {
            $directory = $basePath;
        } else {
            $directory = dirname($basePath).'/';
        }

        return $origin.rtrim($directory, '/').'/'.ltrim($href, '/');
    }

    private function isHttpUrl(string $url): bool
    {
        $url = trim($url);

        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://');
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024 * 1024), 2).' GB';
        }

        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 2).' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 2).' KB';
        }

        return $bytes.' B';
    }

    private function displayedSizeToBytes(string $size): ?float
    {
        if (! preg_match('/^\s*([0-9]+(?:\.[0-9]+)?)\s*([KMGTP]?)B?\s*$/i', $size, $matches)) {
            return null;
        }

        $powers = [
            '' => 0,
            'K' => 1,
            'M' => 2,
            'G' => 3,
            'T' => 4,
            'P' => 5,
        ];
        $unit = strtoupper($matches[2]);

        return (float) $matches[1] * (1024 ** $powers[$unit]);
    }

    private function formatByteSize(float $bytes): string
    {
        $units = ['B', 'K', 'M', 'G', 'T', 'P'];
        $unitIndex = 0;

        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }

        $value = rtrim(rtrim(number_format($bytes, 2, '.', ''), '0'), '.');

        return $value.$units[$unitIndex];
    }

    /**
     * @param array<int, array<string, mixed>> $suggestions
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateSuggestions(array $suggestions): array
    {
        $seen = [];
        $unique = [];

        foreach ($suggestions as $suggestion) {
            $key = ($suggestion['type'] ?? '').'|'.($suggestion['inferred_value'] ?? '').'|'.($suggestion['source_url'] ?? '');

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
