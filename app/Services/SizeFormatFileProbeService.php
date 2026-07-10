<?php

// Enforce strict scalar types to catch hidden type coercion bugs.
declare(strict_types=1);

namespace App\Services;

use App\Services\SizeFormat\SizeFormatFormatNormalizerService;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class SizeFormatFileProbeService
{
    private const MAX_DIRECTORY_DEPTH = 5;

    private const MAX_DIRECTORY_COUNT = 100;

    private const MAX_ZIP_DOWNLOAD_BYTES = 1073741824;

    private const MAX_ZIP_ENTRY_COUNT = 10000;

    private const ALLOWED_DOWNLOAD_HOSTS = [
        'datapub.gfz.de',
        'datapub.gfz-potsdam.de',
        'dataservices.gfz.de',
        'dataservices.gfz-potsdam.de',
    ];

    private const ALLOWED_LANDING_PAGE_HOSTS = [
        'dataservices.gfz.de',
        'dataservices.gfz-potsdam.de',
    ];

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
                    ->withoutRedirecting()
                    ->get($url);

                if ($response->redirect()) {
                    $location = trim((string) $response->header('Location'));

                    if ($location === '') {
                        return [$this->skip($url, 'doi_redirect_unreachable')];
                    }

                    $url = $this->absoluteUrl($url, $location);
                } elseif ($response->successful()) {
                    $url = (string) $response->effectiveUri();
                } else {
                    return [$this->skip($url, 'doi_redirect_unreachable')];
                }
            } catch (\Throwable $e) {
                return [$this->skip($url, 'doi_redirect_failed', $e->getMessage())];

            }

        }

        if (! $this->isAllowedLandingPageUrl($url)) {
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
                $results[] = $this->probeDownloadUrl($downloadUrl);
            }

            return $results;

        } catch (\Throwable $e) {
            return [$this->skip($url, 'exception', $e->getMessage())];
        }
    }

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
            $response = Http::timeout(10)
                ->connectTimeout(5)
                ->head($url);

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
        $url = trim($url);

        if (! $this->isHttpUrl($url)) {
            return $this->skip($url, 'unsupported_protocol');
        }

        if (! $this->isAllowedDownloadUrl($url)) {
            return $this->skip($url, 'unsupported_source_url');
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
            $files = $this->inspectDirectoryZipFiles($files);

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

        if ($this->isDataDescriptionFile($this->filenameFromUrl($fileUrl))) {
            return $this->skip($fileUrl, 'data_description_file');
        }

        try {
            $response = $headResponse ?? Http::timeout(10)
                ->connectTimeout(5)
                ->head($fileUrl);

            $headResult = $this->buildHeadMetadataResult($fileUrl, $response);
            $contentLength = $this->contentLengthToBytes($response->header('Content-Length'));

            if ($this->isZipCandidate($fileUrl, $response->header('Content-Type'))) {
                $zipResult = $this->inspectZipDownload($fileUrl, $contentLength, $this->filenameFromUrl($fileUrl));

                if (($zipResult['probe_method'] ?? null) !== 'SKIP') {
                    return $zipResult;
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

            $totalBytes = 0.0;
            $parsedSizeCount = 0;
            $totalFileCount = 0;
            $zipArchiveCount = 0;
            $zipEntryCount = 0;

            foreach ($files as $file) {
                $fileUrl = $file['file_url'] ?? $sourceUrl;
                $format = $file['format'] ?? null;
                $zipProbeResult = is_array($file) && is_array($file['zip_probe_result'] ?? null)
                    ? $file['zip_probe_result']
                    : null;

                if ($zipProbeResult !== null && ! empty($zipProbeResult['suggestions'])) {
                    $zipArchiveCount++;

                    foreach ($zipProbeResult['suggestions'] as $suggestion) {
                        if (($suggestion['type'] ?? null) === 'format') {
                            $suggestions[] = $suggestion;
                        }

                        if (($suggestion['type'] ?? null) !== 'size') {
                            continue;
                        }

                        $evidence = is_array($suggestion['evidence'] ?? null) ? $suggestion['evidence'] : [];
                        $uncompressedBytes = $evidence['uncompressed_bytes'] ?? null;

                        if (is_numeric($uncompressedBytes)) {
                            $totalBytes += (float) $uncompressedBytes;
                            $parsedSizeCount += (int) ($evidence['parsed_file_count'] ?? 0);
                            $totalFileCount += (int) ($evidence['total_file_count'] ?? 0);
                            $zipEntryCount += (int) ($evidence['total_file_count'] ?? 0);
                        }
                    }

                    continue;
                }

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
                    'inferred_value' => $this->formatBytes($totalBytes),
                    'source_url' => $sourceUrl,
                    'probe_method' => 'DIRECTORY_LISTING',
                    'evidence' => [
                        'parsed_file_count' => $parsedSizeCount,
                        'total_file_count' => $totalFileCount,
                        'zip_archive_count' => $zipArchiveCount,
                        'zip_entry_count' => $zipEntryCount,
                    ],
                    'confidence' => $parsedSizeCount === $totalFileCount ? 'high' : 'low',
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
                ->withOptions([
                    'stream' => true,
                ])
                ->withHeaders([
                    'Range' => 'bytes=0-1023',
                ])
                ->get($fileUrl);

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
     * @return array<int, string>
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

            $downloadUrl = $this->absoluteUrl($landingPageUrl, $href);

            if (! $this->isAllowedDownloadUrl($downloadUrl)) {
                continue;
            }

            $urls[] = $downloadUrl;
        }

        return array_values(array_unique($urls));
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

            if ($this->isDataDescriptionFile($filename)) {
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

    /**
     * @param  array<int, array<string, mixed>>  $files
     * @return array<int, array<string, mixed>>
     */
    private function inspectDirectoryZipFiles(array $files): array
    {
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
     * @return array<string, mixed>
     */
    private function inspectZipDownload(string $zipUrl, int|float|null $knownSizeBytes = null, ?string $archiveFilename = null): array
    {
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
        $response = Http::timeout(60)
            ->connectTimeout(5)
            ->withoutRedirecting()
            ->withOptions([
                'sink' => $temporaryPath,
                'progress' => function (mixed $downloadTotal, mixed $downloadedBytes, mixed $uploadTotal = null, mixed $uploadedBytes = null): void {
                    if ((float) $downloadedBytes > self::MAX_ZIP_DOWNLOAD_BYTES) {
                        throw new \RuntimeException('zip_download_size_limit_exceeded');
                    }
                },
            ])
            ->get($zipUrl);

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

        $formats = [];
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
                    continue;
                }

                $entryFilename = basename($entryName);

                if ($this->isDataDescriptionFile($entryFilename)) {
                    $skippedEntryCount++;

                    continue;
                }

                $eligibleEntryCount++;
                $entrySize = $stat['size'];

                if ($entrySize >= 0) {
                    $uncompressedBytes += (float) $entrySize;
                    $parsedSizeCount++;
                } else {
                    $skippedEntryCount++;
                }

                $extension = $this->extractFileMetadata($entryFilename);

                if ($extension === null) {
                    continue;
                }

                $mimeType = $this->mimeTypeFromExtension($extension);

                if ($mimeType === '') {
                    continue;
                }

                $formats[$mimeType][] = [
                    'filename' => $entryName,
                    'extension' => $extension,
                ];
            }
        } finally {
            $zip->close();
        }

        $suggestions = [];

        foreach ($formats as $mimeType => $entries) {
            $firstEntry = $entries[0];

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
                    'entry_count_for_format' => count($entries),
                    'total_file_count' => $eligibleEntryCount,
                ],
                'confidence' => 'medium',
            ];
        }

        if ($parsedSizeCount > 0) {
            $suggestions[] = [
                'type' => 'size',
                'inferred_value' => $this->formatBytes($uncompressedBytes),
                'source_url' => $sourceUrl,
                'probe_method' => 'ZIP_CONTENT_LISTING',
                'evidence' => [
                    'archive_filename' => $archiveFilename,
                    'parsed_file_count' => $parsedSizeCount,
                    'total_file_count' => $eligibleEntryCount,
                    'raw_entry_count' => $rawEntryCount,
                    'skipped_entry_count' => $skippedEntryCount,
                    'uncompressed_bytes' => $uncompressedBytes,
                ],
                'confidence' => $parsedSizeCount === $eligibleEntryCount ? 'high' : 'low',
            ];
        }

        if (empty($suggestions)) {
            return $this->skip($sourceUrl, 'zip_no_eligible_entries');
        }

        return [
            'source_url' => $sourceUrl,
            'probe_method' => 'ZIP_CONTENT_LISTING',
            'http_status' => $httpStatus,
            'raw_evidence' => [
                'archive_filename' => $archiveFilename,
                'entry_count' => $eligibleEntryCount,
                'raw_entry_count' => $rawEntryCount,
                'skipped_entry_count' => $skippedEntryCount,
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

    private function isDataDescriptionFile(string $filename): bool
    {
        $normalized = strtolower(html_entity_decode($this->decodeFilenameSegment(basename($filename)), ENT_QUOTES | ENT_HTML5));

        return preg_match('/(^|[^a-z0-9])data[-_]?description(?=$|[^a-z0-9])/', $normalized) === 1;
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

        $port = isset($parts['port']) ? ':'.(string) $parts['port'] : '';
        $origin = (string) $parts['scheme'].'://'.(string) $parts['host'].$port;

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

    private function isAllowedDownloadUrl(string $url): bool
    {
        if (! $this->isHttpUrl($url)) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return false;
        }

        return in_array(strtolower($host), self::ALLOWED_DOWNLOAD_HOSTS, true);
    }

    private function isAllowedLandingPageUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }

        $host = $parts['host'] ?? null;

        if (! is_string($host) || $host === '') {
            return false;
        }

        return in_array(strtolower($host), self::ALLOWED_LANDING_PAGE_HOSTS, true);
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

    private function formatBytes(float $bytes): string
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
