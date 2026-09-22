<?php

// Enforce strict scalar types to catch hidden type coercion bugs.
declare(strict_types=1);

namespace App\Services;

use App\Services\SizeFormat\SizeFormatDownloadTargetResolverService;
use App\Services\SizeFormat\SizeFormatFormatNormalizerService;
use App\Support\SizeFormatFileRoleClassifier;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class SizeFormatFileProbeService
{
    private const MAX_DIRECTORY_DEPTH = 5;

    private const MAX_DIRECTORY_COUNT = 100;

    private const MAX_DIRECTORY_ZIP_INSPECTIONS = 5;

    private const MAX_DIRECTORY_FILE_SIZE_REQUESTS = 100;

    private const MAX_ZIP_DOWNLOAD_BYTES = 1073741824;

    private const MAX_ZIP_ENTRY_COUNT = 10000;

    private const DIRECT_FILE_EXTENSIONS = [
        '7z',
        'asc',
        'bin',
        'bz2',
        'csv',
        'dat',
        'gz',
        'h5',
        'hdf',
        'hdf5',
        'jpg',
        'jpeg',
        'json',
        'kmz',
        'md',
        'nc',
        'netcdf',
        'pdf',
        'png',
        'rar',
        'tar',
        'tgz',
        'tif',
        'tiff',
        'tsv',
        'txt',
        'xls',
        'xlsx',
        'xml',
        'xz',
        'zip',
    ];

    private const MAX_REDIRECTS = 5;

    public function __construct(
        private readonly SizeFormatFileRoleClassifier $roleClassifier = new SizeFormatFileRoleClassifier,
        private readonly SizeFormatDownloadTargetResolverService $targetResolver = new SizeFormatDownloadTargetResolverService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function probeDownloadUrl(string $url): array
    {
        $url = trim($url);

        if (! $this->isHttpUrl($url)) {
            return $this->skip($url, 'unsupported_protocol');
        }

        if (! $this->isAllowedDownloadUrl($url)) {
            return $this->skip($url, 'unsupported_source_url');
        }

        if ($this->isLikelyDirectFileUrl($url)) {
            return $this->inferMetadataFromFileUrl($url);
        }

        try {
            $response = $this->sendWithSafeRedirects(Http::timeout(10)
                ->connectTimeout(5)
                ->withoutRedirecting(), 'HEAD', $url);

            if ($response->successful() && $this->isNonHtmlContentType($response->header('Content-Type'))) {
                return $this->inferMetadataFromFileUrl($url, $response);
            }
        } catch (\Throwable) {
            // Directory listings often do not handle HEAD consistently. Fall
            // through to the HTML listing probe, which keeps its own timeout.
        }

        return $this->probeDirectoryListing($url);
    }

    /**
     * @return array<string, mixed>
     */
    public function probeDirectoryListing(string $url): array
    {
        $url = $this->canonicalDirectoryUrl(trim($url));

        if (! $this->isHttpUrl($url)) {
            return $this->skip($url, 'unsupported_protocol');
        }

        if (! $this->isAllowedDownloadUrl($url)) {
            return $this->skip($url, 'unsupported_source_url');
        }

        try {
            $response = $this->sendWithSafeRedirects(Http::timeout(10)
                ->connectTimeout(5)
                ->withoutRedirecting(), 'GET', $url);

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
            $traversalComplete = true;

            foreach ($this->extractSubdirectoryUrls($directoryUrl, $directoryUrl, $html) as $subdirectoryUrl) {
                $files = array_merge(
                    $files,
                    $this->probeSubdirectory(
                        $subdirectoryUrl,
                        $directoryUrl,
                        1,
                        $visitedDirectories,
                        $directoryCount,
                        $traversalComplete,
                    ),
                );
            }

            $files = $this->deduplicateFiles($files);
            $files = $this->inspectDirectoryZipFiles($files);
            $fileSizeRequestCount = 0;
            $fileSizeProbeBudgetExhausted = false;
            $files = $this->inspectDirectoryFileSizes(
                $files,
                $fileSizeRequestCount,
                $fileSizeProbeBudgetExhausted,
            );

            if (empty($files)) {
                return $this->skip($url, 'no_files_found');
            }

            $result = [
                'source_url' => $directoryUrl,
                'probe_method' => 'DIRECTORY_LISTING',
                'probe_complete' => $traversalComplete
                    && ! $fileSizeProbeBudgetExhausted
                    && $this->directoryProbeComplete($files),
                'http_status' => $response->status(),
                'raw_evidence' => [
                    'files' => $files,
                    'file_size_probe' => [
                        'max_requests' => self::MAX_DIRECTORY_FILE_SIZE_REQUESTS,
                        'requests_used' => $fileSizeRequestCount,
                        'budget_exhausted' => $fileSizeProbeBudgetExhausted,
                    ],
                ],
            ];

            $result['suggestions'] = $this->buildSuggestions([$result]);

            return $result;

        } catch (\Throwable $e) {
            return $this->skip($url, 'exception', $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function inferMetadataFromFileUrl(string $fileUrl, ?Response $headResponse = null): array
    {
        $fileUrl = trim($fileUrl);

        if (! $this->isHttpUrl($fileUrl)) {
            return $this->skip($fileUrl, 'unsupported_protocol');
        }

        if (! $this->isAllowedDownloadUrl($fileUrl)) {
            return $this->skip($fileUrl, 'unsupported_source_url');
        }

        $sourceRole = $this->roleClassifier->classify($this->filenameFromUrl($fileUrl));

        if ($sourceRole['role'] !== SizeFormatFileRoleClassifier::PRIMARY_DATA) {
            return $this->skip($fileUrl, 'data_description_file', metadata: [
                'excluded_role' => $sourceRole['role'],
                'exclusion_rule' => $sourceRole['rule'],
            ]);
        }

        try {
            $response = $headResponse ?? $this->sendWithSafeRedirects(Http::timeout(10)
                ->connectTimeout(5)
                ->withoutRedirecting(), 'HEAD', $fileUrl);

            $headResult = $this->buildHeadMetadataResult($fileUrl, $response);
            $contentLength = $this->contentLengthToBytes($response->header('Content-Length'));

            if ($this->isZipCandidate($fileUrl, $response->header('Content-Type'))) {
                $zipResult = $this->inspectZipDownload($fileUrl, $contentLength, $this->filenameFromUrl($fileUrl));

                if (($zipResult['probe_method'] ?? null) !== 'SKIP') {
                    return $zipResult;
                }

                if ($headResult !== null) {
                    $headResult['probe_complete'] = false;
                    $headResult['incomplete_reason'] = $zipResult['skip_reason'] ?? 'zip_inspection_failed';
                    $headResult['size_semantics'] = 'compressed_container';
                }
            }

            if ($headResult !== null) {
                return $headResult;
            }

            $rangeResult = $this->inferFromRangedGet($fileUrl);

            if (($rangeResult['probe_method'] ?? null) !== 'SKIP') {
                return $rangeResult;
            }

            return $this->inferFromFilenameFallback($fileUrl);

        } catch (\Throwable $e) {
            return $this->inferFromFilenameFallback($fileUrl, $e->getMessage());
        }
    }

    // Converts raw probe results into assistant suggestions.
    /**
     * @param  array<int, array<string, mixed>>  $probeResults
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

            $totalBytes = 0;
            $parsedSizeCount = 0;
            $totalFileCount = 0;
            $zipArchiveCount = 0;
            $zipEntryCount = 0;
            $excludedFiles = [];
            $containsUncompressedArchiveSize = false;
            $complete = ($probeResult['probe_complete'] ?? true) === true;

            foreach ($files as $file) {
                $fileUrl = $file['file_url'] ?? $sourceUrl;
                $format = $file['format'] ?? null;
                $role = (string) ($file['role'] ?? SizeFormatFileRoleClassifier::PRIMARY_DATA);

                if ($role !== SizeFormatFileRoleClassifier::PRIMARY_DATA) {
                    $excludedFiles[] = [
                        'filename' => $file['filename'] ?? null,
                        'source_url' => $fileUrl,
                        'role' => $role,
                        'rule' => $file['role_rule'] ?? null,
                    ];

                    continue;
                }

                $zipProbeResult = is_array($file) && is_array($file['zip_probe_result'] ?? null)
                    ? $file['zip_probe_result']
                    : null;

                if ($zipProbeResult !== null && ! empty($zipProbeResult['suggestions'])) {
                    $zipArchiveCount++;
                    $containsUncompressedArchiveSize = true;

                    foreach ($zipProbeResult['suggestions'] as $suggestion) {
                        if (($suggestion['type'] ?? null) === 'format') {
                            $suggestions[] = $suggestion;
                        }

                        if (($suggestion['type'] ?? null) !== 'size') {
                            continue;
                        }

                        $evidence = is_array($suggestion['evidence'] ?? null) ? $suggestion['evidence'] : [];
                        $uncompressedBytes = $evidence['uncompressed_bytes'] ?? null;

                        if (is_int($uncompressedBytes) && $uncompressedBytes >= 0 && $uncompressedBytes <= PHP_INT_MAX - $totalBytes) {
                            $totalBytes += $uncompressedBytes;
                            $parsedSizeCount += (int) ($evidence['parsed_file_count'] ?? 0);
                            $totalFileCount += (int) ($evidence['total_file_count'] ?? 0);
                            $zipEntryCount += (int) ($evidence['total_file_count'] ?? 0);
                        } else {
                            $complete = false;
                        }
                    }

                    $excludedFiles = array_merge(
                        $excludedFiles,
                        is_array($zipProbeResult['raw_evidence']['excluded_files'] ?? null)
                            ? $zipProbeResult['raw_evidence']['excluded_files']
                            : [],
                    );

                    continue;
                }

                $isUninspectedZip = $this->isZipCandidate(
                    (string) $fileUrl,
                    null,
                    is_string($format) ? $format : null,
                );

                if ($format !== null && $format !== '') {
                    $mimeType = $this->mimeTypeFromExtension((string) $format);

                    $suggestions[] = [
                        'type' => 'format',
                        'inferred_value' => $mimeType,
                        'source_url' => $fileUrl,
                        'probe_method' => 'FILENAME_EXTENSION',
                        'evidence' => [
                            'filename' => $file['filename'] ?? null,
                            'extension' => $format,
                            'mime_type' => $mimeType,
                        ],
                        'confidence' => $mimeType === 'application/zip' ? 'low' : 'medium',
                    ];
                }

                if (! is_array($file)) {
                    continue;
                }

                $totalFileCount++;

                if ($isUninspectedZip) {
                    $complete = false;

                    continue;
                }

                $bytes = $file['exact_size_bytes'] ?? null;

                if (! is_int($bytes) || $bytes < 0) {
                    $complete = false;

                    continue;
                }

                if ($bytes > PHP_INT_MAX - $totalBytes) {
                    $complete = false;

                    continue;
                }

                $totalBytes += $bytes;
                $parsedSizeCount++;
            }

            if ($parsedSizeCount > 0 && $complete && $parsedSizeCount === $totalFileCount) {
                $suggestions[] = [
                    'type' => 'size',
                    'inferred_value' => $this->sizeValue($totalBytes, $containsUncompressedArchiveSize),
                    'source_url' => $sourceUrl,
                    'probe_method' => 'DIRECTORY_LISTING',
                    'evidence' => [
                        'parsed_file_count' => $parsedSizeCount,
                        'total_file_count' => $totalFileCount,
                        'zip_archive_count' => $zipArchiveCount,
                        'zip_entry_count' => $zipEntryCount,
                        'total_bytes' => $totalBytes,
                        'size_semantics' => $containsUncompressedArchiveSize ? 'uncompressed_primary_data' : 'primary_data',
                        'excluded_files' => $excludedFiles,
                    ],
                    'confidence' => 'high',
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
            $response = $this->sendWithSafeRedirects(Http::timeout(10)
                ->connectTimeout(5)
                ->withoutRedirecting()
                ->withOptions([
                    'stream' => true,
                ])
                ->withHeaders([
                    'Range' => 'bytes=0-1023',
                ]), 'GET', $fileUrl);

            $status = $response->status();
            $successful = $response->successful();
            $contentType = $response->header('Content-Type');
            $contentRange = $response->header('Content-Range');

            if (! $successful) {
                return $this->skip($fileUrl, 'ranged_get_unreachable');
            }

            if ($status !== 206) {
                return $this->skip($fileUrl, 'ranged_get_unexpected_status');
            }

            $suggestions = [];

            if (trim((string) $contentType) !== '') {
                $normalizedContentType = $this->normalizedContentType($contentType);

                $suggestions[] = [
                    'type' => 'format',
                    'inferred_value' => trim(explode(';', $contentType)[0]),
                    'source_url' => $fileUrl,
                    'probe_method' => 'RANGED_GET_CONTENT_TYPE',
                    'evidence' => [
                        'content_type' => $contentType,
                        'range' => 'bytes=0-1023',
                    ],
                    'confidence' => $normalizedContentType === 'application/zip' ? 'low' : 'medium',
                ];
            }

            if (preg_match('/\/(\d+)$/', (string) $contentRange, $matches)) {
                $totalBytes = (int) $matches[1];
                $suggestions[] = [
                    'type' => 'size',
                    'inferred_value' => $this->sizeValue($totalBytes, false),
                    'source_url' => $fileUrl,
                    'probe_method' => 'RANGED_GET_CONTENT_RANGE',
                    'evidence' => [
                        'content_range' => $contentRange,
                        'range' => 'bytes=0-1023',
                        'total_bytes' => $totalBytes,
                        'size_semantics' => 'primary_data',
                    ],
                    'confidence' => 'medium',
                ];
            }

            if (! empty($suggestions)) {
                return [
                    'source_url' => $fileUrl,
                    'probe_method' => 'RANGED_GET',
                    'http_status' => $status,
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

        $mimeType = $this->mimeTypeFromExtension($extension);

        return [
            'source_url' => $fileUrl,
            'probe_method' => 'FILENAME_EXTENSION_FALLBACK',
            'probe_complete' => false,
            'http_status' => null,
            'raw_evidence' => [
                'filename' => $filename,
                'extension' => $extension,
                'mime_type' => $mimeType,
                'error' => $error,
            ],
            'suggestions' => [
                [
                    'type' => 'format',
                    'inferred_value' => $mimeType,
                    'source_url' => $fileUrl,
                    'probe_method' => 'FILENAME_EXTENSION_FALLBACK',
                    'evidence' => [
                        'filename' => $filename,
                        'extension' => $extension,
                        'mime_type' => $mimeType,
                    ],
                    'confidence' => $mimeType === 'application/zip' ? 'low' : 'medium',
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function extractFilesFromApacheIndex(string $baseUrl, string $html): array
    {
        preg_match_all(
            '/<a\s+href=["\']([^"\']+)["\']>([^<]+)<\/a>\s+(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2})\s+([0-9.]+[KMGTP]?|-)/i',
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

            $fileUrl = $this->resolveListingUrlReference($baseUrl, $href);

            if (! $this->isAllowedDownloadUrl($fileUrl)) {
                continue;
            }

            $role = $this->roleClassifier->classify($filename);
            $fileMetadata = $this->extractFileMetadata($filename);

            $files[] = [
                'file_url' => $fileUrl,
                'filename' => $filename,
                'format' => $fileMetadata,
                'last_modified' => $lastModified,
                'file-size' => $displayedSize,
                'role' => $role['role'],
                'role_rule' => $role['rule'],
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
        bool &$traversalComplete,
    ): array {
        if ($depth > self::MAX_DIRECTORY_DEPTH || $directoryCount >= self::MAX_DIRECTORY_COUNT) {
            $traversalComplete = false;

            return [];
        }

        $canonicalUrl = $this->canonicalDirectoryUrl($url);

        if (isset($visitedDirectories[$canonicalUrl])) {
            return [];
        }

        $visitedDirectories[$canonicalUrl] = true;
        $directoryCount++;

        try {
            $response = $this->sendWithSafeRedirects(Http::timeout(10)
                ->connectTimeout(5)
                ->withoutRedirecting(), 'GET', $canonicalUrl);

            if (! $response->successful()) {
                $traversalComplete = false;

                return [];
            }

            $effectiveUrl = $this->canonicalDirectoryUrl((string) $response->effectiveUri());
            $visitedDirectories[$effectiveUrl] = true;
            $html = $response->body();

            if ($this->containsBlockedAccess($html)) {
                $traversalComplete = false;

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
                        $traversalComplete,
                    ),
                );
            }

            return $files;
        } catch (\Throwable) {
            $traversalComplete = false;

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
                $this->resolveListingUrlReference($currentUrl, $href),
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
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return strtolower($parts['scheme']).'://'.strtolower($parts['host']).$port.$path.$query;
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

    /**
     * @param  array<int, array<string, mixed>>  $files
     * @return array<int, array<string, mixed>>
     */
    private function inspectDirectoryZipFiles(array $files): array
    {
        $inspectedZipCount = 0;

        foreach ($files as $index => $file) {
            $fileUrl = (string) ($file['file_url'] ?? '');

            if ($fileUrl === '') {
                continue;
            }

            $filename = (string) ($file['filename'] ?? $this->filenameFromUrl($fileUrl));
            $extension = is_string($file['format'] ?? null) ? (string) $file['format'] : null;

            if (! $this->isZipCandidate($fileUrl, null, $extension)) {
                continue;
            }

            if (! $this->isAllowedDownloadUrl($fileUrl)) {
                continue;
            }

            if ($inspectedZipCount >= self::MAX_DIRECTORY_ZIP_INSPECTIONS) {
                continue;
            }

            $inspectedZipCount++;
            $knownSizeBytes = $this->displayedSizeToBytes((string) ($file['file-size'] ?? ''));
            $zipResult = $this->inspectZipDownload($fileUrl, $knownSizeBytes, $filename);

            if (($zipResult['probe_method'] ?? null) === 'SKIP' || empty($zipResult['suggestions'])) {
                continue;
            }

            $files[$index]['zip_probe_result'] = $zipResult;
        }

        return $files;
    }

    /**
     * Apache index sizes are rounded display values. Resolve every primary,
     * non-archive file to an exact HTTP size before it can contribute to a
     * high-confidence aggregate.
     *
     * @param  array<int, array<string, mixed>>  $files
     * @return array<int, array<string, mixed>>
     */
    private function inspectDirectoryFileSizes(
        array $files,
        int &$requestCount,
        bool &$budgetExhausted,
    ): array
    {
        foreach ($files as $index => $file) {
            if (($file['role'] ?? SizeFormatFileRoleClassifier::PRIMARY_DATA) !== SizeFormatFileRoleClassifier::PRIMARY_DATA) {
                continue;
            }

            $fileUrl = (string) ($file['file_url'] ?? '');
            $format = is_string($file['format'] ?? null) ? $file['format'] : null;

            if ($fileUrl === '' || $this->isZipCandidate($fileUrl, null, $format)) {
                continue;
            }

            if ($requestCount >= self::MAX_DIRECTORY_FILE_SIZE_REQUESTS) {
                $budgetExhausted = true;

                continue;
            }

            $exactSize = $this->probeExactRemoteFileSize($fileUrl, $requestCount, $budgetExhausted);

            if ($exactSize === null) {
                continue;
            }

            $files[$index]['exact_size_bytes'] = $exactSize['bytes'];
            $files[$index]['exact_size_probe_method'] = $exactSize['probe_method'];
        }

        return $files;
    }

    /** @return array{bytes: int, probe_method: string}|null */
    private function probeExactRemoteFileSize(
        string $fileUrl,
        int &$requestCount,
        bool &$budgetExhausted,
    ): ?array
    {
        $consumeRequestBudget = function () use (&$requestCount, &$budgetExhausted): void {
            if ($requestCount >= self::MAX_DIRECTORY_FILE_SIZE_REQUESTS) {
                $budgetExhausted = true;

                throw new \RuntimeException('directory_file_size_request_budget_exhausted');
            }

            $requestCount++;
        };

        try {
            $headResponse = $this->sendWithSafeRedirects(Http::timeout(10)
                ->connectTimeout(5)
                ->withoutRedirecting(), 'HEAD', $fileUrl, $consumeRequestBudget);
            $contentLength = $this->contentLengthToBytes($headResponse->header('Content-Length'));

            if ($headResponse->successful() && $contentLength !== null) {
                return [
                    'bytes' => $contentLength,
                    'probe_method' => 'CONTENT_LENGTH_HEADER',
                ];
            }

            if ($requestCount >= self::MAX_DIRECTORY_FILE_SIZE_REQUESTS) {
                $budgetExhausted = true;

                return null;
            }

            $rangeResponse = $this->sendWithSafeRedirects(Http::timeout(10)
                ->connectTimeout(5)
                ->withoutRedirecting()
                ->withOptions(['stream' => true])
                ->withHeaders(['Range' => 'bytes=0-0']), 'GET', $fileUrl, $consumeRequestBudget);

            if (
                $rangeResponse->status() === 206
                && preg_match('/\/(\d+)$/', (string) $rangeResponse->header('Content-Range'), $matches) === 1
            ) {
                return [
                    'bytes' => (int) $matches[1],
                    'probe_method' => 'RANGED_GET_CONTENT_RANGE',
                ];
            }
        } catch (\Throwable) {
            // The caller marks the directory probe incomplete when no exact
            // byte count could be established.
        }

        return null;
    }

    /** @param array<int, array<string, mixed>> $files */
    private function directoryProbeComplete(array $files): bool
    {
        foreach ($files as $file) {
            if (($file['role'] ?? SizeFormatFileRoleClassifier::PRIMARY_DATA) !== SizeFormatFileRoleClassifier::PRIMARY_DATA) {
                continue;
            }

            $fileUrl = (string) ($file['file_url'] ?? '');
            $format = is_string($file['format'] ?? null) ? $file['format'] : null;

            if ($this->isZipCandidate($fileUrl, null, $format)) {
                $zipResult = is_array($file['zip_probe_result'] ?? null) ? $file['zip_probe_result'] : null;

                if ($zipResult === null || ($zipResult['probe_complete'] ?? false) !== true) {
                    return false;
                }

                continue;
            }

            if (! is_int($file['exact_size_bytes'] ?? null) || $file['exact_size_bytes'] < 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function inspectZipDownload(string $zipUrl, int|float|null $knownSizeBytes = null, ?string $archiveFilename = null): array
    {
        if (! $this->isAllowedDownloadUrl($zipUrl)) {
            return $this->skip($zipUrl, 'unsupported_source_url');
        }

        if (! class_exists(\ZipArchive::class)) {
            return $this->skip($zipUrl, 'zip_extension_unavailable');
        }

        if ($knownSizeBytes !== null && $knownSizeBytes > self::MAX_ZIP_DOWNLOAD_BYTES) {
            return $this->skip($zipUrl, 'zip_download_too_large');
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'ernie-zip-');

        if ($temporaryPath === false) {
            return $this->skip($zipUrl, 'zip_temporary_file_failed');
        }

        try {
            $response = $this->downloadZipToTemporaryFile($zipUrl, $temporaryPath);

            if (! $response->successful()) {
                return $this->skip($zipUrl, 'zip_download_unreachable');
            }

            if ($this->localFileSize($temporaryPath) > self::MAX_ZIP_DOWNLOAD_BYTES) {
                return $this->skip($zipUrl, 'zip_download_too_large');
            }

            return $this->inspectZipFile(
                zipPath: $temporaryPath,
                sourceUrl: $zipUrl,
                archiveFilename: $archiveFilename ?: $this->filenameFromUrl($zipUrl),
                httpStatus: $response->status(),
            );
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'zip_download_size_limit_exceeded') {
                return $this->skip($zipUrl, 'zip_download_too_large');
            }

            return $this->skip($zipUrl, 'zip_download_exception', $e->getMessage());
        } catch (\Throwable $e) {
            return $this->skip($zipUrl, 'zip_download_exception', $e->getMessage());
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    private function downloadZipToTemporaryFile(string $zipUrl, string $temporaryPath): Response
    {
        $request = Http::timeout(60)
            ->connectTimeout(5)
            ->withoutRedirecting()
            ->withOptions([
                'sink' => $temporaryPath,
                'progress' => function (mixed $downloadTotal, mixed $downloadedBytes, mixed $uploadTotal = null, mixed $uploadedBytes = null): void {
                    if ((float) $downloadedBytes > self::MAX_ZIP_DOWNLOAD_BYTES) {
                        throw new \RuntimeException('zip_download_size_limit_exceeded');
                    }
                },
            ]);

        $response = $this->sendWithSafeRedirects($request, 'GET', $zipUrl);

        if ($this->localFileSize($temporaryPath) === 0) {
            $body = $response->body();

            if ($body !== '') {
                $bodyBytes = strlen($body);

                if ($bodyBytes > self::MAX_ZIP_DOWNLOAD_BYTES) {
                    throw new \RuntimeException('zip_download_size_limit_exceeded');
                }

                file_put_contents($temporaryPath, $body);
            }
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function inspectZipFile(string $zipPath, string $sourceUrl, string $archiveFilename, int $httpStatus): array
    {
        $zip = new \ZipArchive;
        $openResult = $zip->open($zipPath);

        if ($openResult !== true) {
            return $this->skip($sourceUrl, 'zip_unreadable');
        }

        /** @var array<string, int> $formatCounts */
        $formatCounts = [];
        /** @var array<string, array{filename: string, extension: string}> $formatFirstEntries */
        $formatFirstEntries = [];
        $excludedFiles = [];
        $eligibleEntryCount = 0;
        $parsedSizeCount = 0;
        $skippedEntryCount = 0;
        $uncompressedBytes = 0.0;
        $rawEntryCount = $zip->numFiles;

        if ($rawEntryCount > self::MAX_ZIP_ENTRY_COUNT) {
            $zip->close();

            return $this->skip($sourceUrl, 'zip_entry_count_exceeded');
        }

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);

                if ($stat === false) {
                    $skippedEntryCount++;

                    continue;
                }

                $entryName = str_replace('\\', '/', $stat['name']);

                if ($entryName === '' || str_ends_with($entryName, '/')) {
                    $skippedEntryCount++;

                    continue;
                }

                $entryFilename = basename($entryName);

                $role = $this->roleClassifier->classify($entryFilename);

                if ($role['role'] !== SizeFormatFileRoleClassifier::PRIMARY_DATA) {
                    $skippedEntryCount++;
                    $excludedFiles[] = [
                        'filename' => $entryName,
                        'source_url' => $sourceUrl,
                        'role' => $role['role'],
                        'rule' => $role['rule'],
                    ];

                    continue;
                }

                $eligibleEntryCount++;
                $entrySize = $stat['size'];

                if ($entrySize >= 0) {
                    $uncompressedBytes += (float) $entrySize;

                    if (! is_finite($uncompressedBytes)) {
                        return $this->skip($sourceUrl, 'zip_uncompressed_size_overflow');
                    }

                    $parsedSizeCount++;
                }

                $extension = $this->extractFileMetadata($entryFilename);

                if ($extension === null) {
                    continue;
                }

                $mimeType = $this->mimeTypeFromExtension($extension);

                if ($mimeType === '') {
                    continue;
                }

                if (! isset($formatCounts[$mimeType])) {
                    $formatCounts[$mimeType] = 0;
                    $formatFirstEntries[$mimeType] = [
                        'filename' => $entryName,
                        'extension' => $extension,
                    ];
                }

                $formatCounts[$mimeType]++;
            }
        } finally {
            $zip->close();
        }

        if ($eligibleEntryCount === 0) {
            return $this->skip($sourceUrl, 'zip_no_eligible_entries');
        }

        $suggestions = [
            [
                'type' => 'format',
                'inferred_value' => 'application/zip',
                'source_url' => $sourceUrl,
                'probe_method' => 'ZIP_CONTAINER',
                'evidence' => [
                    'archive_filename' => $archiveFilename,
                    'format_role' => 'container',
                ],
                'confidence' => 'high',
            ],
        ];

        foreach ($formatCounts as $mimeType => $entryCount) {
            if ($mimeType === 'application/zip') {
                continue;
            }

            $firstEntry = $formatFirstEntries[$mimeType] ?? null;

            if ($firstEntry === null) {
                continue;
            }

            $suggestions[] = [
                'type' => 'format',
                'inferred_value' => $mimeType,
                'source_url' => $sourceUrl,
                'probe_method' => 'ZIP_CONTENT_LISTING',
                'evidence' => [
                    'archive_filename' => $archiveFilename,
                    'filename' => $firstEntry['filename'],
                    'extension' => $firstEntry['extension'],
                    'mime_type' => $mimeType,
                    'entry_count_for_format' => $entryCount,
                    'total_file_count' => $eligibleEntryCount,
                ],
                'confidence' => 'medium',
            ];
        }

        if ($parsedSizeCount > 0 && $parsedSizeCount === $eligibleEntryCount) {
            $uncompressedByteCount = (int) round($uncompressedBytes);
            $suggestions[] = [
                'type' => 'size',
                'inferred_value' => $this->sizeValue($uncompressedByteCount, true),
                'source_url' => $sourceUrl,
                'probe_method' => 'ZIP_CONTENT_LISTING',
                'evidence' => [
                    'archive_filename' => $archiveFilename,
                    'parsed_file_count' => $parsedSizeCount,
                    'total_file_count' => $eligibleEntryCount,
                    'raw_entry_count' => $rawEntryCount,
                    'skipped_entry_count' => $skippedEntryCount,
                    'uncompressed_bytes' => $uncompressedByteCount,
                    'total_bytes' => $uncompressedByteCount,
                    'size_semantics' => 'uncompressed_primary_data',
                    'excluded_files' => $excludedFiles,
                ],
                'confidence' => 'high',
            ];
        }

        return [
            'source_url' => $sourceUrl,
            'probe_method' => 'ZIP_CONTENT_LISTING',
            'probe_complete' => $parsedSizeCount === $eligibleEntryCount,
            'http_status' => $httpStatus,
            'raw_evidence' => [
                'archive_filename' => $archiveFilename,
                'entry_count' => $eligibleEntryCount,
                'raw_entry_count' => $rawEntryCount,
                'skipped_entry_count' => $skippedEntryCount,
                'excluded_files' => $excludedFiles,
            ],
            'suggestions' => $suggestions,
        ];
    }

    private function localFileSize(string $path): int
    {
        $size = @filesize($path);

        return is_int($size) ? $size : 0;
    }

    private function filenameFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return $this->decodeFilenameSegment(basename($url));
        }

        return $this->decodeFilenameSegment(basename($path));
    }

    private function decodeFilenameSegment(string $filename): string
    {
        $encodedSeparatorsPreserved = str_ireplace(['%2f', '%5c'], ['%252F', '%255C'], $filename);

        return rawurldecode($encodedSeparatorsPreserved);
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
            return $parts[count($parts) - 2].'.'.$last;
        }

        return $last;
    }

    private function mimeTypeFromExtension(string $extension): string
    {
        return SizeFormatFormatNormalizerService::normalize($extension);
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

    private function resolveUrlReference(string $baseUrl, string $reference): string
    {
        $baseUrl = trim($baseUrl);
        $reference = trim($reference);

        try {
            return (string) UriResolver::resolve(new Uri($baseUrl), new Uri($reference));
        } catch (\Throwable) {
            return $reference;
        }
    }

    private function resolveListingUrlReference(string $baseUrl, string $reference): string
    {
        $resolvedUrl = $this->resolveUrlReference($baseUrl, $reference);
        $baseQuery = parse_url($baseUrl, PHP_URL_QUERY);
        $resolvedQuery = parse_url($resolvedUrl, PHP_URL_QUERY);

        if (
            is_string($baseQuery)
            && $baseQuery !== ''
            && $resolvedQuery === null
            && $this->hasSameOrigin($baseUrl, $resolvedUrl)
        ) {
            try {
                return (string) (new Uri($resolvedUrl))->withQuery($baseQuery);
            } catch (\Throwable) {
                return $resolvedUrl;
            }
        }

        return $resolvedUrl;
    }

    private function hasSameOrigin(string $firstUrl, string $secondUrl): bool
    {
        $first = parse_url($firstUrl);
        $second = parse_url($secondUrl);

        if (! is_array($first) || ! is_array($second)) {
            return false;
        }

        $firstScheme = strtolower((string) ($first['scheme'] ?? ''));
        $secondScheme = strtolower((string) ($second['scheme'] ?? ''));

        return $firstScheme !== ''
            && $firstScheme === $secondScheme
            && strtolower((string) ($first['host'] ?? '')) === strtolower((string) ($second['host'] ?? ''))
            && $this->effectivePort($firstScheme, $first['port'] ?? null)
                === $this->effectivePort($secondScheme, $second['port'] ?? null);
    }

    private function effectivePort(string $scheme, mixed $port): ?int
    {
        if (is_int($port)) {
            return $port;
        }

        return match ($scheme) {
            'http' => 80,
            'https' => 443,
            default => null,
        };
    }

    private function isHttpUrl(string $url): bool
    {
        $url = trim($url);

        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://');
    }

    private function isAllowedDownloadUrl(string $url): bool
    {
        return $this->targetResolver->resolve($url) !== null;
    }

    private function sendWithSafeRedirects(
        PendingRequest $request,
        string $method,
        string $url,
        ?callable $beforeRequest = null,
    ): Response
    {
        $currentUrl = $url;

        for ($redirectCount = 0; $redirectCount <= self::MAX_REDIRECTS; $redirectCount++) {
            $target = $this->targetResolver->resolve($currentUrl);

            if ($target === null) {
                throw new \RuntimeException('unsafe_download_url');
            }

            if ($beforeRequest !== null) {
                $beforeRequest();
            }

            // Keep the hostname in the URL so cURL preserves the Host header
            // and TLS SNI, while pinning the connection to the validated IP.
            $response = $request->send($method, $currentUrl, [
                'curl' => [
                    CURLOPT_RESOLVE => [$target['curl_resolve']],
                ],
            ]);

            if (! in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                return $response;
            }

            $location = trim((string) $response->header('Location'));

            if ($location === '') {
                return $response;
            }

            $currentUrl = $this->resolveUrlReference($currentUrl, $location);
        }

        throw new \RuntimeException('too_many_download_redirects');
    }

    private function isLikelyDirectFileUrl(string $url): bool
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $extension !== '' && in_array($extension, self::DIRECT_FILE_EXTENSIONS, true);
    }

    private function isNonHtmlContentType(?string $contentType): bool
    {
        $normalized = strtolower(trim(explode(';', (string) $contentType)[0]));

        if ($normalized === '') {
            return false;
        }

        return ! in_array($normalized, ['text/html', 'application/xhtml+xml'], true);
    }

    private function isZipCandidate(string $url, ?string $contentType = null, ?string $extension = null): bool
    {
        if ($this->normalizedContentType($contentType) === 'application/zip') {
            return true;
        }

        if ($extension !== null && $this->mimeTypeFromExtension($extension) === 'application/zip') {
            return true;
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');

        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'zip';
    }

    private function normalizedContentType(?string $contentType): string
    {
        $normalized = trim(explode(';', (string) $contentType)[0]);

        return $normalized === '' ? '' : SizeFormatFormatNormalizerService::normalize($normalized);
    }

    private function contentLengthToBytes(?string $contentLength): ?int
    {
        $contentLength = trim((string) $contentLength);

        if ($contentLength === '' || ! ctype_digit($contentLength)) {
            return null;
        }

        return (int) $contentLength;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildHeadMetadataResult(string $fileUrl, Response $response): ?array
    {
        if (! $response->successful()) {
            return null;
        }

        $contentType = $response->header('Content-Type');
        $contentLength = $response->header('Content-Length');
        $suggestions = [];

        if (trim((string) $contentType) !== '') {
            $normalizedContentType = $this->normalizedContentType($contentType);

            $suggestions[] = [
                'type' => 'format',
                'inferred_value' => trim(explode(';', $contentType)[0]),
                'source_url' => $fileUrl,
                'probe_method' => 'CONTENT_TYPE_HEADER',
                'evidence' => [
                    'content_type' => $contentType,
                ],
                'confidence' => $normalizedContentType === 'application/zip' ? 'low' : 'high',
            ];
        }

        if (ctype_digit((string) $contentLength)) {
            $totalBytes = (int) $contentLength;
            $suggestions[] = [
                'type' => 'size',
                'inferred_value' => $this->sizeValue($totalBytes, false),
                'source_url' => $fileUrl,
                'probe_method' => 'CONTENT_LENGTH_HEADER',
                'evidence' => [
                    'content_length' => (int) $contentLength,
                    'total_bytes' => $totalBytes,
                    'size_semantics' => 'primary_data',
                ],
                'confidence' => 'high',
            ];
        }

        if (empty($suggestions)) {
            return null;
        }

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

    private function sizeValue(int $bytes, bool $uncompressed): string
    {
        $type = $uncompressed ? 'Uncompressed Primary Data Size' : 'Primary Data Size';

        return $bytes.' '.$type.' [bytes]';
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

    /**
     * @param  array<int, array<string, mixed>>  $suggestions
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
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function skip(string $url, string $reason, ?string $error = null, array $metadata = []): array
    {
        return [
            'source_url' => trim($url),
            'probe_method' => 'SKIP',
            'skip_reason' => $reason,
            'error' => $error,
            'raw_evidence' => [],
            'suggestions' => [],
            ...$metadata,
        ];
    }
}
