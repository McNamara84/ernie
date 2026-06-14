<?php

// es soll srteng auf Datentypen geachtet werden
declare(strict_types=1);

// in welchem Ordner die Datei liegt 
namespace App\Services;

// damit PHP/Laravel Webseiten aufrufen kann
use Illuminate\Support\Facades\Http;


// Klasse SizeFormatFileProbeService wird erstellt 
class SizeFormatFileProbeService
{
    // Limits recursive traversal so an unexpectedly large directory tree cannot be crawled indefinitely.
    private const MAX_DIRECTORY_DEPTH = 5;

    private const MAX_DIRECTORY_COUNT = 100;

    // erlaubte Download-Linktexte
    // private: Methode darf nur innerhalb dieser eigenen Code-Klasse benutzt werden; Andere Teile des Programms können nicht direkt darauf zugreifen
    // Beispiel: <a href="...">Download data</a>
    /** Das macht die Klasse: 
     * 
     * Landingpage öffnen
     * ↓
     * Download-Links finden
     * ↓
     * Download-Seite öffnen
     * ↓
     * Dateien, Endungen und Größen sammeln
     */
    // wenn auf der Seite <a href="...">Link to DEUS on GitHub</a> steht, wird dies ignoriert
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

    // Diese Funkton bekommt (eigentlich Datensatz-Landingpages), für die Testung eine vorgebene URL 
    // array bedeutet geordnete Liste 
    public function extractAndProbe(string $url): array
    {
        // prüft, ob die URL mit http:// pder https:// beginnt, wenn nicht wird abgebrochen
        if (! $this->isHttpUrl($url)) {
            return [$this->skip($url, 'unsupported_protocol')];
        }
        // alles was hier drin ist kann fehlschlagen (Timeout und Netzwerkfehler)
        try {
            // die Anfrage darf max. 10 sekunden dauern 
            // Http: Fassade für den Laravel HTTP Client
            // ::timeout(10): Überschreibt das Standard-Zeitlimit von 30 Sekunden auf exakt 10 Sekunden
            /**EXTRA Info: Wird der Timeout überschritten, ohne dass der Server antwortet, wirft Laravel 
             * eine ConnectionException. Diese kannst du beispielsweise abfangen oder mit der Methode ->retry(3, 1000) 
             * automatisch bis zu 3-mal neu versuchen lasse */
            $response = Http::timeout(10)
                // die Verbindung muss innerhalb von 5 Sekunden aufgebaut sein
                ->connectTimeout(5)
                // öffnet die Landingpage mit get-Methode
                // Webseite wird geladen 
                ->get($url);

            // wenn (!= NOT) Anfrage nicht erfolgreich war...
            // $response:  Variable, die das Ergebnis einer Anfrage (z. B. an einen Server oder eine Datenbank) speichert.
            // successful(): Methode (oft in Frameworks wie Laravel genutzt), die true (wahr) zurückgibt, wenn die Anfrage 
            //               fehlerfrei war, und false (falsch), wenn ein Fehler aufgetreten ist.
            if (! $response->successful()) {
                return [$this->skip($url, 'landing_page_unreachable')];
            }

            // speichert die tatsächliche URL nach Weiterleitung als Text in der Variablen $landingPageUrl
            $landingPageUrl = (string) $response->effectiveUri();
            // speichert den httml-Code
            $html = $response->body();

            // Wenn Hinweise auf Formular, CAPTCHA oder Zugriffsbeschränkung gefunden werden, wird abgebrochen
            if ($this->containsBlockedAccess($html)) {
                return [$this->skip($landingPageUrl, 'blocked_access_or_form_required')];
            }

            // sucht im HTML nach passenden Download-Links
            // Methode sucht im HTML-Code nach speziellen Download-Links („Piwik“)
            $downloadUrls = $this->extractPiwikDownloadLinks($landingPageUrl, $html);

            // wenn kein passender Download-Link gefunden wird, wird übersprungen 
            if (empty($downloadUrls)) {
                return [$this->skip($landingPageUrl, 'no_eligible_file_links_found')];
            }

            // Leere Ergebnisliste
            $results = [];

            // jede gefundene Download-Link wird geöffnet und untersucht
            foreach ($downloadUrls as $downloadUrl) {
                $results[] = $this->probeDirectoryListing($downloadUrl);
            }

            // alle Ergebnisse werden zurückgegeben
            return $results;

        // catch: abfangen -> wenn etwas im try-Block schiefläuft, springt der Code zu dieser Zeile 
        // \Throwable: jede Art von Fehler oder Problem abgefangen
        // Fehler steht in der Variable $e
        } catch (\Throwable $e) {
            // $url: merkt sich, bei welcher Webadresse der Fehler passiert ist
            // 'exception': Grund für den Abbruch
            // $e->getMessage: holt den Text des Fehlers
            return [$this->skip($url, 'exception', $e->getMessage())];
        }
    }

    // Untersuchung der Download-Seite
    public function probeDirectoryListing(string $url): array
    {
        // Protokoll-Prüfung
        if (! $this->isHttpUrl($url)) {
            return $this->skip($url, 'unsupported_protocol');
        }

        // Dateien-Seite aufrufen/ Download-Verzeichnis wird geladen
        try {
            $response = Http::timeout(10)
                ->connectTimeout(5)
                ->get($url);

            if (! $response->successful()) {
                return $this->skip($url, 'directory_listing_unreachable');
            }

            // Use the final URL after redirects. Directory URLs often gain a trailing
            // slash, which is required to resolve relative file and subfolder links.
            $directoryUrl = (string) $response->effectiveUri();
            $html = $response->body();

            // wird wieder mit Methode geprüft, ob die Seite durch ein Login oder Captcha gesperrt ist.
            if ($this->containsBlockedAccess($html)) {
                return $this->skip($url, 'blocked_access_or_form_required');
            }

            // Dateien im aktuellen Verzeichnis auslesen.
            $files = $this->extractFilesFromApacheIndex($directoryUrl, $html);

            // Besuchte URLs verhindern Schleifen, zum Beispiel durch Links auf übergeordnete Verzeichnisse.
            $visitedDirectories = [
                $this->canonicalDirectoryUrl($directoryUrl) => true,
            ];
            $directoryCount = 1;

            // Unterordner rekursiv durchsuchen und deren Dateien in dieselbe Ergebnisliste aufnehmen.
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

            // Nur abbrechen, wenn weder im Startverzeichnis noch in Unterordnern Dateien gefunden wurden.
            if (empty($files)) {
                return $this->skip($url, 'no_files_found');
            }

            // Ergebnis
            // wenn bisher keine Fehler aufgetreten und Dateien gefunden überspringt das Programm den Fehler-Block und gibt Ergebnis als Array
            return [
                'source_url' => $directoryUrl,
                'probe_method' => 'DIRECTORY_LISTING',
                'http_status' => $response->status(),
                'raw_evidence' => [
                    'files' => $files,
                ],
            ];
        } catch (\Throwable $e) {
            return $this->skip($url, 'exception', $e->getMessage());
        }
    }

    /**
     * Convert probe results into the payload expected by the assistant.
     *
     * One format suggestion is returned for each distinct extension. File sizes
     * are converted to bytes, added together, and returned as one total size.
     *
     * @param  array<int, array<string, mixed>>  $results
     * @return array<int, array<string, mixed>>
     */
    public function buildSuggestions(array $results): array
    {
        $filesByUrl = [];
        $sourceUrls = [];
        $probeMethods = [];

        foreach ($results as $result) {
            if (($result['probe_method'] ?? null) === 'SKIP') {
                continue;
            }

            $sourceUrl = $result['source_url'] ?? null;
            $probeMethod = $result['probe_method'] ?? null;

            if (is_string($sourceUrl) && $sourceUrl !== '') {
                $sourceUrls[$sourceUrl] = true;
            }

            if (is_string($probeMethod) && $probeMethod !== '') {
                $probeMethods[$probeMethod] = true;
            }

            foreach ($result['raw_evidence']['files'] ?? [] as $file) {
                if (! is_array($file)) {
                    continue;
                }

                // A file may be linked by more than one directory page.
                $fileKey = (string) ($file['file_url'] ?? $file['filename'] ?? count($filesByUrl));
                $filesByUrl[$fileKey] = $file;
            }
        }

        $files = array_values($filesByUrl);

        if ($files === []) {
            return [];
        }

        $suggestions = [];
        $filesByFormat = [];

        foreach ($files as $file) {
            $format = strtolower(trim((string) ($file['format'] ?? '')));

            if ($format !== '') {
                $filesByFormat[$format][] = $file;
            }
        }

        foreach ($filesByFormat as $format => $formatFiles) {
            $suggestions[] = [
                'type' => 'format',
                'inferred_value' => $format,
                'source_url' => array_key_first($sourceUrls),
                'source_urls' => array_keys($sourceUrls),
                'probe_method' => array_key_first($probeMethods),
                // ZIP describes the container, not necessarily the contained formats.
                'confidence' => $format === 'zip' ? 'low' : 'high',
                'evidence' => $formatFiles,
            ];
        }

        $totalBytes = 0.0;
        $parsedSizeCount = 0;

        foreach ($files as $file) {
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
                'source_url' => array_key_first($sourceUrls),
                'source_urls' => array_keys($sourceUrls),
                'probe_method' => array_key_first($probeMethods),
                'confidence' => $parsedSizeCount === count($files) ? 'high' : 'low',
                'evidence' => $files,
            ];
        }

        return $suggestions;
    }

    /**
     * Convert Apache directory sizes such as 14M or 512K to bytes.
     */
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
     * Format the aggregate byte count using the compact units from Apache indexes.
     */
    private function formatByteSize(float $bytes): string
    {
        $units = ['B', 'K', 'M', 'G', 'T', 'P'];
        $unitIndex = 0;

        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }

        $value = rtrim(rtrim(number_format($bytes, 2, '.', ''), '0'), '.');

        return $value . $units[$unitIndex];
    }

    /**
     * Durchsucht einen Unterordner und anschließend dessen Unterordner.
     *
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
        // Tiefe und Gesamtanzahl begrenzen die Netzwerkanfragen bei großen Verzeichnisstrukturen.
        if ($depth > self::MAX_DIRECTORY_DEPTH || $directoryCount >= self::MAX_DIRECTORY_COUNT) {
            return [];
        }

        $canonicalUrl = $this->canonicalDirectoryUrl($url);

        // Bereits besuchte Verzeichnisse nicht erneut laden.
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

            // Resolve nested links against the final directory URL after redirects.
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
            // Ein nicht erreichbarer Unterordner soll die Ergebnisse anderer Ordner nicht verwerfen.
            return [];
        }
    }

    /**
     * Liest Unterordner-Links aus, die innerhalb des ursprünglichen Download-Verzeichnisses liegen.
     *
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

            // Nur Verzeichnis-Links akzeptieren; Parent Directory und Seitenanker werden ignoriert.
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

            // Externe Hosts und Verzeichnisse oberhalb des Startpfads dürfen nicht gecrawlt werden.
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

    /**
     * Vereinheitlicht Verzeichnis-URLs, damit dieselbe URL zuverlässig als bereits besucht erkannt wird.
     */
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

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = '/' . implode('/', $pathSegments) . '/';

        return strtolower($parts['scheme']) . '://' . strtolower($parts['host']) . $port . $path;
    }

    // HTML-Code einer Webseite komplett zu durchsuchen
    // filtert gezielt alle Links (<a>-Tags) heraus, die für ein Piwik-Download-System gedacht sind
    // am Ende liefert sie eine saubere Liste mit den fertigen Webadressen 
    private function extractPiwikDownloadLinks(string $landingPageUrl, string $html): array
    {
            // hier wird alle html-code durchsucht
            // preg_match_all: Führt eine vollständige Suche mit einem regulären Ausdruck durch (sucht auf der kompletten Seite)
            /** Flags: (übersetzt: Schalter oder Markierungen) spezielle Parameter oder Variablen, die das Verhalten von Funktionen steuern 
             * oder bestimmte Zustände (wahr/falsch, an/aus) repräsentieren */ 
            // sucht nach allen klassischen Text-Links, die so aufgebaut sind: <a href="adresse">Text</a>.
        preg_match_all(
            '/<a\b([^>]*)href=["\']([^"\']+)["\']([^>]*)>(.*?)<\/a>/is',
            $html,
            // $matches: mehrdimensionales Array mit allen gefundenen Übereinstimmungen
            $matches,
            /**
            *Sortier-Befehl für PHP. Er sorgt dafür, dass jeder gefundene Link als ein eigenes, ordentliches Paket im Array 
            *abgelegt wird. ($matches[0] ist der erste Link, $matches[1] der zweite usw..
            */   
            PREG_SET_ORDER
        );

        $urls = [];

        // jeder gefundene Link wird geprüft
        // "Gehe die Liste aller gefundenen Links nacheinander durch"
        foreach ($matches as $match) {
            // innerhalb der Schleife zerlegt das Programm den Link in seine Einzelteile
            //  $attributes: alle zusätzlichen Angaben im Link (z. B. class="..." oder id="..."
            $attributes = $match[1] . ' ' . $match[3];
            // das ist die Ziel-URL aus href
            // "Suche das eigentliche Ziel des Links (die URL) und schneide unsaubere Leerzeichen am Anfang und Ende ab"
            $href = trim($match[2]);
            // Lies den Text, auf den man auf der Webseite klicken kann. Lösche dabei alle störenden HTML-Code-Reste (strip_tags) 
            // und mache auch hier die Leerzeichen weg (trim).
            $linkText = trim(strip_tags($match[4]));

            // strenge Prüfung
            /**
             * Regel 1: Das Programm schaut, ob im Link irgendwo das Wort piwik_download steht (meistens als CSS-Klasse). 
             * Wenn dieses Wort nicht da ist (! str_contains), bricht das Programm die Prüfung für diesen Link sofort ab 
             * (continue) und springt zum nächsten Link
             */
            if (! str_contains($attributes, 'piwik_download')) {
                continue;
            }

            /**
             * Regel 2: Hier wird der sichtbare Text des Links mit einer anderen Methode (isAllowedLinkText) geprüft. Wenn 
             * der Text nicht erlaubt ist, wird der Link ebenfalls ignoriert.
             */
            if (! $this->isAllowedLinkText($linkText)) {
                continue;
            }

            /**
             * Wenn ein Link beide Prüfungen besteht, wird er behalten. Da Links auf Webseiten oft unvollständig eingetragen 
             * sind (z. B. nur /downloads/datei.zip), macht die Methode absoluteUrl daraus eine echte, vollständige Internetadresse 
             * (z. B. https://beispiel.de). Diese wird in die Liste $urls gepackt.
             */

            // Adresse vervollständigen und einsammeln
            $urls[] = $this->absoluteUrl($landingPageUrl, $href);
        }

        // array_values: gibt alle Werte eines Arrays zurück
        //array_unique: entfernt doppelte Werte aus einem Array
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

        // geht jede gefundene Datei einzeln durch.
        foreach ($matches as $match) {
            /**
             * Speichert die gefundenen Teile:
             * $href = Linkziel
             * $filename = Dateiname
             * $lastModified = Änderungsdatum
             * $displayedSize = angezeigte Größe
             */
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

            // Fügt eine Datei zur Ergebnisliste hinzu
            $files[] = [
                // macht aus dem relativen Dateilink eine vollständige URL
                'file_url' => $this->absoluteUrl($baseUrl, $href),
                // speichert den Dateinamen
                'filename' => $filename,
                // leitet das Format aus der Dateiendung ab, z. B. csv, pdf, zip.
                'format' => $this->extractExtension($filename),
                // speichert das Änderungsdatum der Datei
                'last_modified' => $lastModified,
                // speichert die angezeigte Dateigröße, z. B. 14M
                'file-size' => $displayedSize,
            ];
        }

        // gibt die Liste aller gefundenen Dateien zurück.
        return $files;
    }

    // "Hilfsmethoden"
    private function extractExtension(string $filename): ?string
    {
        // Holt die Dateiendung
        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        // Wenn eine Endung existiert, wird sie klein geschrieben zurückgegeben. Sonst null
        return $extension !== '' ? strtolower($extension) : null;
    }

    // prüft, ob ein Linktext erlaubt ist.
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

        // wenn nichts passt, ist der Link nicht erlaubt
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
            'login',
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
        $origin = $parts['scheme'] . '://' . $parts['host'];

        // wenn der Link mit / beginnt, ist er relativ zur Domain.
        if (str_starts_with($href, '/')) {

            return $origin . $href;
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

            $directory = dirname($basePath) . '/';
        }

        // baut finale URL zusammen 
        return $origin . rtrim($directory, '/') . '/' . ltrim($href, '/');

    }

    // prüft, ob eine URL mit http:// oder https:// beginnt
    private function isHttpUrl(string $url): bool
    {
        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://');
    }

    // erzeugt ein einheitliches Abbruch-Ergebnis
    private function skip(string $url, string $reason, ?string $error = null): array
    {
        return [
            'source_url' => $url,
            'probe_method' => 'SKIP',
            'skip_reason' => $reason,
            'error' => $error,
            'raw_evidence' => [],
        ];
    }
}
