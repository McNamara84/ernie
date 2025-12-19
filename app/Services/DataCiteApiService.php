<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service für den Abruf von DOI-Metadaten über die doi.org Content Negotiation API.
 *
 * Funktioniert registrarunabhängig mit allen DOI-Registraren (DataCite, Crossref, mEDRA, etc.)
 *
 * API-Dokumentation: https://citation.crosscite.org/docs.html
 */
class DataCiteApiService
{
    /**
     * Ruft Metadaten für eine DOI über Content Negotiation ab.
     *
     * Funktioniert mit DOIs von allen Registraren (DataCite, Crossref, etc.)
     *
     * @param  string  $doi  Die DOI, für die Metadaten abgerufen werden sollen
     * @return array<string, mixed>|null Die Metadaten als Array oder null bei Fehler
     */
    public function getMetadata(string $doi): ?array
    {
        try {
            // DOI bereinigen (https://doi.org/ Prefix entfernen falls vorhanden)
            $cleanDoi = str_replace(['https://doi.org/', 'http://doi.org/'], '', $doi);
            $url = "https://doi.org/{$cleanDoi}";

            // JSON-LD Format anfordern (CSL JSON für Zitationsdaten)
            $response = Http::timeout(10)
                ->withHeaders([
                    'Accept' => 'application/vnd.citationstyles.csl+json',
                ])
                ->get($url);

            if ($response->successful()) {
                return $response->json();
            }

            if ($response->status() === 404) {
                Log::info("DOI nicht gefunden: {$doi}");

                return null;
            }

            Log::warning("DOI-Auflösungsfehler für {$doi}", [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error("Fehler beim Abrufen der DOI-Metadaten für {$doi}", [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Erstellt einen Zitationsstring aus CSL JSON Metadaten.
     *
     * CSL JSON ist das Standardformat der doi.org Content Negotiation API.
     *
     * @param  array<string, mixed>  $metadata  Die Metadaten von doi.org
     * @return string Die formatierte Zitation
     */
    public function buildCitationFromMetadata(array $metadata): string
    {
        // Autoren aus CSL JSON Format extrahieren
        $authors = $metadata['author'] ?? [];
        $authorStrings = [];
        foreach ($authors as $author) {
            if (isset($author['family']) && isset($author['given'])) {
                $authorStrings[] = $author['family'].', '.$author['given'];
            } elseif (isset($author['literal'])) {
                $authorStrings[] = $author['literal'];
            } elseif (isset($author['family'])) {
                $authorStrings[] = $author['family'];
            }
        }
        $authorsString = ! empty($authorStrings) ? implode('; ', $authorStrings) : 'Unknown Author';

        // Jahr extrahieren - verschiedene mögliche Felder prüfen
        $year = $metadata['issued']['date-parts'][0][0] ??
                $metadata['published']['date-parts'][0][0] ??
                $metadata['created']['date-parts'][0][0] ??
                'n.d.';

        // Titel extrahieren
        $title = $metadata['title'] ?? 'Untitled';

        // Verlag extrahieren
        $publisher = $metadata['publisher'] ?? 'Unknown Publisher';

        // DOI extrahieren
        $doi = $metadata['DOI'] ?? '';
        $doiUrl = $doi ? "https://doi.org/{$doi}" : '';

        // Zitation aufbauen: [Autoren] ([Jahr]): [Titel]. [Verlag]. [DOI URL]
        return trim("{$authorsString} ({$year}): {$title}. {$publisher}. {$doiUrl}");
    }
}
