<?php

// dadurch passieren weniger versteckte Fehler.
declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;

class SizeFormatFileProbeService
{
    private const MAX_DIRECTORY_DEPTH = 5;

    private const MAX_DIRECTORY_COUNT = 100;

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

        // Prüft ob URL mit http:// oder https:// beginnt
        if (! $this->isHttpUrl($url)) {

            return [$this->skip($url, 'unsupported_protocol')];

        }

        // DOI-URLs zuerst auflösen
        if (str_starts_with($url, 'https://doi.org/')) {

            try {
                $response = Http::timeout(10)
                    ->connectTimeout(5)
                    ->get($url);
                if (! $response->successful()) {
                    return [$this->skip($url, 'doi_redirect_unreachable')];
                }

                // Nach Redirects die echte Ziel-URL speichern
                $url = (string) $response->effectiveUri();
            } catch (\Throwable $e) {
                return [$this->skip($url, 'doi_redirect_failed', $e->getMessage())];

            }

        }

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

            $directoryUrl = (string) $response->effectiveUri();
            $html = $response->body();

            if ($this->containsBlockedAccess($html)) {
                return $this->skip($directoryUrl, 'blocked_access_or_form_required');
            }

            // automatische Verzeichnisauflistung
            // liest aus dem Verzeichnis -> filename, format, file-size
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

            // wenn keine Dateien gefunden
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

            // Wenn HEAD keine vollständigen Metadaten liefert oder nicht unterstützt wird,
            // wird einmalig ein begrenzter GET-Request mit den ersten 1024 Bytes gemacht.
            if (in_array($response->status(), [405, 501], true) || empty($suggestions)) {
                $rangeResult = $this->inferFromRangedGet($fileUrl);
                if (($rangeResult['probe_method'] ?? null) !== 'SKIP') {
                    return $rangeResult;
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

        // geht jedes probe-Ergebnis einzeln durch
        foreach ($probeResults as $probeResult) {
            // wenn ein Ergebnis nur ein Skip ist, wird es ignoriert
            if (($probeResult['probe_method'] ?? null) === 'SKIP') {
                continue;
            }

            // sorgt dafür, dass eine Liste von Vorschlägen nur dann angezeigt wird, wenn auch wirklich Daten vorhanden sind
            // prüft: „Gibt es keine Vorschläge (suggestions) im Testergebnis (probeResult)?“
            if (! empty($probeResult['suggestions'])) {
                // wenn die Liste doch nicht leer ist, geht der Code jeden einzelnen Vorschlag nacheinander durch
                foreach ($probeResult['suggestions'] as $suggestion) {
                    // wird der aktuelle Vorschlag auf dem Bildschirm angezeigt
                    $suggestions[] = $suggestion;
                }

                // mit dem nächsten Eintrag weitermachen
                continue;
            }

            // holt source_url und raw_evidence
            $sourceUrl = $probeResult['source_url'] ?? null;
            $files = $probeResult['raw_evidence']['files'] ?? [];

            $directoryFiles = [];

            // geht jede gefundene Datei einzeln durch
            foreach ($files as $file) {
                $fileUrl = $file['file_url'] ?? $sourceUrl;
                $format = $file['format'] ?? null;

                // wenn eine Datei vorhanden ist, wird ein Format-Vorschlag erstellt
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
                        // bei zip weiß man nicht was drin ist, deswegen confidence: low
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

        // doppelte Vorschläge werden entfernt
        return $this->deduplicateSuggestions($suggestions);
    }

    /**
     * HEAD hat nicht gereicht
     * ↓
     * GET mit Range: bytes=0-1023
     * ↓
     * nur 1 KB anfragen
     * ↓
     * Content-Type und Content-Range auslesen
     * ↓
     * Format/Size-Suggestion bauen
     * ↓
     * sonst skippen
     */
    private function inferFromRangedGet(string $fileUrl): array
    {
        try {
            $response = Http::timeout(10)
                ->connectTimeout(5)
                // Safety-Teil: Es wird nur der Bereich von Byte 0 bis 1023 angefragt, also maximal 1 KB
                ->withHeaders([
                    'Range' => 'bytes=0-1023',
                ])
                // Get Request mit Range Header
                ->get($fileUrl);

            // wenn der Request nicht erfolgreich ist, wird sauber abgebrochen
            if (! $response->successful()) {
                return $this->skip($fileUrl, 'ranged_get_unreachable');
            }

            // Content-Type sagt etwas über das Format,
            $contentType = $response->header('Content-Type');
            // Content-Range kann die Gesamtgröße enthalten z.B. bytes 0-1023/1048576
            $contentRange = $response->header('Content-Range');

            $suggestions = [];

            // Prüft, ob ein Content-Type vorhanden ist
            if ($contentType !== null && trim($contentType) !== '') {
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

            // prüft, ob Content-Range eine Gesamtgröße enthält
            if ($contentRange !== null && preg_match('/\/(\d+)$/', $contentRange, $matches)) {
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

            // wenn mindestens eine Suggestion gefunden wurde, wird ein normales Ergebnis zurückgegeben
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

    // "Notfallplan"
    /**
     * Bedeutung:
     * Head-Request fehlgeschlagen
     * oder
     * kein Content-Length vorgeschlagen
     * oder kein Conten-Length vorhanden
     *
     * => versuche etwas aus dem Dateinamen abzuleiten
     */
    private function inferFromFilenameFallback(string $fileUrl, ?string $error = null): array
    {
        // liefert den Pfad zur Datei
        // Beispiel: https://datapub.gfz.de/download/data/report.pdf
        // basename nimmt nur den letzten Teil = report.pdf
        $filename = basename(parse_url($fileUrl, PHP_URL_PATH) ?? $fileUrl);
        // Dateiendungen ermitteln
        // aus report.pdf wird pdf
        $extension = $this->extractFileMetadata($filename);

        // Fall: keine Endung gefunden
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
            // wenn keine Endung gefunden, werden Vorschläge gemacht
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

    // Funktion sucht nach den erlaubten Piwik-Download-Links
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

            // ist der Linktext erlaubt? Beispiel: Download data
            if (! $this->isAllowedLinkText($linkText)) {
                continue;
            }

            $urls[] = $this->absoluteUrl($landingPageUrl, $href);
        }

        return array_values(array_unique($urls));
    }

    // bekommt die Downloadseite als URL und den HTML-Code dieser Seite
    private function extractFilesFromApacheIndex(string $baseUrl, string $html): array
    {
        // hier wird alle html-code durchsucht
        // preg_match_all: Führt eine vollständige Suche mit einem regulären Ausdruck durch (sucht auf der kompletten Seite)
        /** Flags: (übersetzt: Schalter oder Markierungen) spezielle Parameter oder Variablen, die das Verhalten von Funktionen steuern
         * oder bestimmte Zustände (wahr/falsch, an/aus) repräsentieren */
        // sucht nach allen klassischen Text-Links, die so aufgebaut sind: <a href="adresse">Text</a>.
        /**
         * sucht im HTML nach Dateizeilen mit diesem Muster:
         * Link + Dateiname + Änderungsdatum + Größe
         */
        preg_match_all(
            '/<a\s+href=["\']([^"\']+)["\']>([^<]+)<\/a>\s+(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2})\s+([0-9.]+[KMGTP]?)/i',
            $html,
            // $matches: mehrdimensionales Array mit allen gefundenen Übereinstimmungen/ gefundene Dateien
            $matches,
            /**
             *Sortier-Befehl für PHP. Er sorgt dafür, dass jeder gefundene Link als ein eigenes, ordentliches Paket im Array
             *abgelegt wird. ($matches[0] ist der erste Link, $matches[1] der zweite usw..
             */
            PREG_SET_ORDER
        );

        // leere Liste für die fertigen Dateiergebnisse
        $files = [];

        // geht jede gefundene Datei einzeln durch
        foreach ($matches as $match) {
            $href = trim($match[1]);
            $filename = trim($match[2]);
            $lastModified = trim($match[3]);
            $displayedSize = trim($match[4]);

            // leere Einträge und „Parent Directory“ werden ignoriert
            if ($filename === '' || str_contains(strtolower($filename), 'parent directory')) {
                continue;
            }

            // Ordner werden ignoriert. Es sollen nur Dateien gespeichert werden
            if (str_ends_with($href, '/')) {
                continue;
            }

            // ruft die neue Funktion zur Erkennung der Dateiendung auf
            $fileMetadata = $this->extractFileMetadata($filename);

            // Fügt eine Datei zur Ergebnisliste hinzu
            $files[] = [
                // macht aus dem relativen Dateilink eine vollständige URL
                'file_url' => $this->absoluteUrl($baseUrl, $href),
                // speichert den Dateinamen
                'filename' => $filename,
                // leitet das Format aus der Dateiendung ab, z. B. csv, pdf, zip
                'format' => $fileMetadata,
                // speichert das Änderungsdatum der Datei
                'last_modified' => $lastModified,
                // speichert die angezeigte Dateigröße, z. B. 14M
                'file-size' => $displayedSize,
            ];
        }

        return $files;
    }

    /**
     * @param  array<string, bool>  $visitedDirectories
     * @return array<int, array<string, string|null>>
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

        foreach ($matches[1] ?? [] as $matchedHref) {
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

    // "Hilfsmethoden"
    private function extractExtension(string $filename): ?string
    {
        // Holt die Dateiendung
        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        // Wenn eine Endung existiert, wird sie klein geschrieben zurückgegeben. Sonst null
        return $extension !== '' ? strtolower($extension) : null;
    }

    // Funktion, um das Problem mit zwei Formaten im Namen zu bereinigen: z.B.: GFZOP_RSO_L06_G_20051231_220000_20060101_120000_v01.sp3.gz
    private function extractFileMetadata(string $filename): ?string
    {
        // Dateiname wird klein geschrieben und an Punkten getrennt
        $parts = explode('.', strtolower($filename));

        // bei keinem vorhandenen Punkt -> gibt es keine Dateiendung = Null
        if (count($parts) < 2) {
            return null;
        }
        
        // Endungen die als Komprimierung erkannt werden
        $compressionFormats = ['gz', 'bz2', 'xz'];

        // holt die letzte Endung 
        $last = end($parts);

        // prüft, ist die letzte Endung eine Komprimierung und gibt es davor noch eine weitere Endung?
        if (in_array($last, $compressionFormats, true) && count($parts) >= 3) {
            // wird die vorletzte und letzte Endung zurückgegeben
            return $parts[count($parts) - 2] . '.' . $last;
        }

        // wenn keine Komprimierung erkannt, wird letzte Endung zurückgegeben
        return $last;
    }

    // prüft, ob ein Linktext erlaubt ist
    private function isAllowedLinkText(string $text): bool
    {
        // mehrere Leerzeichen werden zu einem Leerzeichen gemacht
        $normalizedText = trim(preg_replace('/\s+/', ' ', $text) ?? '');

        // geht alle erlaubten Texte durch
        foreach (self::ALLOWED_LINK_TEXTS as $allowedText) {
            // vergleicht ohne Groß-/Kleinschreibung
            if (strcasecmp($normalizedText, $allowedText) === 0) {
                return true;
            }
        }

        return false;
    }

    // sucht Hinweise auf blockierten Zugriff
    private function containsBlockedAccess(string $html): bool
    {
        // Liste mit Wörtern, die auf Formular/CAPTCHA/Registrierung hindeuten
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
                // wenn eines dieser Wörter im HTML gefunden wird, wird true zurückgegeben
                return true;
            }
        }

        return false;
    }

    // macht aus relativen Links vollständige Links
    // $baseUrl = die aktuelle Seite
    // $href = der Link aus dem HTML
    private function absoluteUrl(string $baseUrl, string $href): string
    {
        $baseUrl = trim($baseUrl);
        $href = trim($href);

        // wenn $href schon vollständig ist = wird er zurückgegeben
        if ($this->isHttpUrl($href)) {
            return $href;
        }

        // zerlegt die Basis-URL in Teile.
        $parts = parse_url($baseUrl);

        // wenn die Basis-URL keine gültige Domain hat, wird einfach $href zurückgegeben
        if (! isset($parts['scheme'], $parts['host'])) {
            return $href;
        }

        // baut die Domain zusammen
        $origin = $parts['scheme'].'://'.$parts['host'];

        // wenn der Link mit / beginnt, ist er relativ zur Domain.
        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }

        // holt den Pfad aus der Basis-URL
        $basePath = $parts['path'] ?? '';

        /**
         * Hier wird entschieden, welcher Ordner als Grundlage benutzt wird
         * Wenn $basePath mit / endet, gilt er schon als Ordner
         * Wenn nicht, nimmt dirname() den übergeordneten Ordner
         */
        if ($basePath === '' || str_ends_with($basePath, '/')) {
            $directory = $basePath;
        } else {
            $directory = dirname($basePath).'/';
        }

        // baut finale URL zusammen
        return $origin.rtrim($directory, '/').'/'.ltrim($href, '/');
    }

    private function isHttpUrl(string $url): bool
    {
        $url = trim($url);

        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://');
    }

    // wandelt die Dateigröße in Bytes in eine lesbare Größe um
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

    // Funktion entfernt doppelte Suggestions
    /**
     * mehrere Dateien werden untersucht -> dabei können identische Vorschläge entstehen -> nur eindeutige sollen übrig bleiben
     */
    private function deduplicateSuggestions(array $suggestions): array
    {
        // welche suggestions habe ich bereits gesehen?
        $seen = [];
        // speicherung der eindeutigen entgüligen hier
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

    // Skip-Methode
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
