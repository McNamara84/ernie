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
    // erlaubte Download-Linktexte
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

    public function probeDirectoryListing(string $url): array
    {
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

            $files = $this->extractFilesFromApacheIndex($url, $html);

            if (empty($files)) {
                return $this->skip($url, 'no_files_found');
            }

            return [
                'source_url' => $url,
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
            'login',
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
        
        // Holt den aktuellen Pfad
        $path = isset($parts['path']) ? dirname($parts['path']) : '';

        // baut daraus die vollständige URL
        return rtrim($origin . '/' . trim($path, '/'), '/') . '/' . ltrim($href, '/');
    }

    // prüft, ob eine URL mit http:// oder https:// beginnt
    private function isHttpUrl(string $url): bool
    {
        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://');
    }

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