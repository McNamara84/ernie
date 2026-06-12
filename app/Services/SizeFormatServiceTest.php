<?php

// dadurch passieren weniger versteckte Fehler.
declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;

class SizeFormatFileProbeService
{
    // erlaubte Linkexte
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

    public function extractAndProbe(string $url): array
    {
        // Entfernt Leerzeichen am Anfang und am Ende
        $url = trim($url);

        // Wenn URL nicht mit https://dataservices.gfz-potsdam.de/ anfängt wird geskippt
        if (! str_starts_with($url, 'https://dataservices.gfz-potsdam.de/')) {
            return [$this->skip($url, 'unsupported_source_url')];
        }

        // prüft ob URL mit http:// oder https:// beginnt, wenn nicht -> skip
        if (! $this->isHttpUrl($url)) {
            return [$this->skip($url, 'unsupported_protocol')];
        }

        // "Sicherheitsblock": steht alles was schiefgehen kann drin
        // geht etwas hier schief -> springt zum catch-Block
        try {
            // Anfrage darf nur max. 10 Sekunden dauern
            $response = Http::timeout(10)
                // Verbindungsaufbau max. 5 Sekunden 
                ->connectTimeout(5)
                // dann wird per GET-Anfrage die URL geöffnet
                ->get($url);

            if (! $response->successful()) {
                return [$this->skip($url, 'landing_page_unreachable')];
            }

            // wenn Anfrage nicht erfolgreich -> speichert URL nach Redirects
            $landingPageUrl = (string) $response->effectiveUri();
            // speichert den HTML-Code der Seite 
            $html = $response->body();

            // prüft, ob Seite Hinweis auf Sperren enthält, wenn ja wird abgebrochen ->skip
            if ($this->containsBlockedAccess($html)) {
                return [$this->skip($landingPageUrl, 'blocked_access_or_form_required')];
            }

            // sucht im HTML nach erlaubten Download-Links wie piwik-Download
            // wenn nichts passendes gefunden, wird es übersprungen 
            $downloadUrls = $this->extractPiwikDownloadLinks($landingPageUrl, $html);

            if (empty($downloadUrls)) {
                return [$this->skip($landingPageUrl, 'no_eligible_file_links_found')];
            }

            // leere Ergebnisliste 
            $results = [];

            // jede gefunde Donwload-URL wird untersucht 
            // für jede URL wird die Methode probeDirectoryListing aufgerufen 
            foreach ($downloadUrls as $downloadUrl) {
                $results[] = $this->probeDirectoryListing($downloadUrl);
            }

            // gibt alle Ergebnisse zurück 
            return $results;

        // wenn im try-Block irgendein Fehler passiert, wird es hier abgefangen 
        // \Throwable-> jede Art von Fehler oder Ausnahme
        } catch (\Throwable $e) {
            return [$this->skip($url, 'exception', $e->getMessage())];
        }
    }

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

            $html = $response->body();

            if ($this->containsBlockedAccess($html)) {
                return $this->skip($url, 'blocked_access_or_form_required');
            }

            // automatische Verzeichnisauflistung
            // liest aus dem Verzeichnis -> filename, format, file-size
            $files = $this->extractFilesFromApacheIndex($url, $html);

            // wenn keine Dateien gefunden 
            if (empty($files)) {
                return $this->skip($url, 'no_files_found');
            }

            $result = [
                'source_url' => $url,
                'probe_method' => 'DIRECTORY_LISTING',
                'http_status' => $response->status(),
                'raw_evidence' => [
                    'files' => $files,
                ],
            ];

            // Vorschläge erzeugen
            // hier werden aus den gefundenen Dateien Format- und Size-Vorschläge gebaut
            /**
             * Beispiel: 
             * 'type' => 'xlsx'
             * 'inferred_value' => '12M'
             */
            $result['suggestions'] = $this->buildSuggestions([$result]);

            return $result;

        } catch (\Throwable $e) {
            return $this->skip($url, 'exception', $e->getMessage());
        }
    }

    // hier bekommt eine einzelne Datei 
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

            // Head: nur header holen
            // GET würde die datei herunterladen 
            if ($response->successful()) {
                // Header auslesen 
                $contentType = $response->header('Content-Type');
                // wenn kein Size vorhanden, wird durch Content-Length 'abgefragt' Beispiel: 1048576
                $contentLength = $response->header('Content-Length');

                // Sammlung für Vorschläge 
                $suggestions = [];

                // 
                if ($contentType !== null && trim($contentType) !== '') {
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

                // prüft, besteht Content-Length nur aus Zahlen
                if ($contentLength !== null && ctype_digit((string) $contentLength)) {
                    $suggestions[] = [
                        'type' => 'size',
                        // wandelt die Zahlen in MB/GB etc....
                        'inferred_value' => $this->formatBytes((int) $contentLength),
                        'source_url' => $fileUrl,
                        'probe_method' => 'CONTENT_LENGTH_HEADER',
                        'evidence' => [
                            'content_length' => (int) $contentLength,
                        ],
                        'confidence' => 'high',
                    ];
                }

                // wurden Vorschläge gefunden?
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

            // bei keinem Erfolg wird Datei an Namen erkannt 
            return $this->inferFromFilenameFallback($fileUrl);

        } catch (\Throwable $e) {
            return $this->inferFromFilenameFallback($fileUrl, $e->getMessage());
        }
    }

    // macht aus den Rohdaten-Ergebnissen echte Suggestions
    public function buildSuggestions(array $probeResults): array
    {
        $suggestions = [];

        //geht jedes probe-Ergebnis einzeln durch
        foreach ($probeResults as $probeResult) {
            // wenn ein Ergebnis nur ein Skip ist, wird es ignoriert
            if (($probeResult['probe_method'] ?? null) === 'SKIP') {
                continue;
            }

            // holt source_url und raw_evidence 
            $sourceUrl = $probeResult['source_url'] ?? null;
            $files = $probeResult['raw_evidence']['files'] ?? [];

            // geht jede gefundene Datei einzeln durch 
            foreach ($files as $file) {
                $fileUrl = $file['file_url'] ?? $sourceUrl;
                $extension = $file['extension'] ?? null;
                $displayedSize = $file['displayed_size'] ?? null;

                // wenn eine Datei vorhanden ist, wird ein Format-Vorschlag erstellt 
                if ($extension !== null && $extension !== '') {
                    $suggestions[] = [
                        'type' => 'format',
                        'inferred_value' => $extension,
                        'source_url' => $fileUrl,
                        'probe_method' => 'FILENAME_EXTENSION',
                        'evidence' => [
                            'filename' => $file['filename'] ?? null,
                            'extension' => $extension,
                        ],
                        // bei zip weiß man nicht was drin ist, deswegen confidence: low
                        'confidence' => $extension === 'zip' ? 'low' : 'medium',
                    ];
                }

                // Size-Vorschlag
                if ($displayedSize !== null && $displayedSize !== '') {
                    // wenn eine Größe vorhanden ist...
                    $suggestions[] = [
                        'type' => 'size',
                        'inferred_value' => $displayedSize,
                        'source_url' => $fileUrl,
                        'probe_method' => 'DIRECTORY_LISTING',
                        'evidence' => [
                            'filename' => $file['filename'] ?? null,
                            'displayed_size' => $displayedSize,
                        ],
                        //... confidence high, weil Größe direkt aus dem Directory Listing
                        'confidence' => 'high',
                    ];
                }
            }
        }

        return $this->deduplicateSuggestions($suggestions);
    }

    private function inferFromFilenameFallback(string $fileUrl, ?string $error = null): array
    {
        $filename = basename(parse_url($fileUrl, PHP_URL_PATH) ?? $fileUrl);
        $extension = $this->extractExtension($filename);

        if ($extension === null) {
            return $this->skip($fileUrl, 'no_header_or_filename_evidence', $error);
        }

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
            $attributes = $match[1] . ' ' . $match[3];
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

            $files[] = [
                'file_url' => $this->absoluteUrl($baseUrl, $href),
                'filename' => $filename,
                'extension' => $this->extractExtension($filename),
                'last_modified' => $lastModified,
                'displayed_size' => $displayedSize,
            ];
        }

        return $files;
    }

    private function extractExtension(string $filename): ?string
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        return $extension !== '' ? strtolower($extension) : null;
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

        $origin = $parts['scheme'] . '://' . $parts['host'];

        if (str_starts_with($href, '/')) {
            return $origin . $href;
        }

        $basePath = $parts['path'] ?? '';

        if ($basePath === '' || str_ends_with($basePath, '/')) {
            $directory = $basePath;
        } else {
            $directory = dirname($basePath) . '/';
        }

        return $origin . rtrim($directory, '/') . '/' . ltrim($href, '/');
    }

    private function isHttpUrl(string $url): bool
    {
        $url = trim($url);

        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://');
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024 * 1024), 2) . ' GB';
        }

        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 2) . ' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }

        return $bytes . ' B';
    }

    private function deduplicateSuggestions(array $suggestions): array
    {
        $seen = [];
        $unique = [];

        foreach ($suggestions as $suggestion) {
            $key = ($suggestion['type'] ?? '') . '|' . ($suggestion['inferred_value'] ?? '') . '|' . ($suggestion['source_url'] ?? '');

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $suggestion;
        }

        return $unique;
    }

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