<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\IdentifierType;
use App\Models\IdentifierTypePattern;
use Illuminate\Database\Seeder;

/**
 * Seeder for Identifier Type Patterns (validation & detection)
 *
 * Seeds API patterns for all 23 DataCite identifier types. The dated correction
 * snapshot intentionally evolves independently of the editor detector in
 * resources/js/lib/identifier-type-detection.ts.
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.7/appendices/appendix-1/relatedIdentifierType/
 */
class IdentifierTypePatternSeeder extends Seeder
{
    public function run(): void
    {
        $patterns = $this->getPatterns();

        foreach ($patterns as $slug => $typePatterns) {
            $identifierType = IdentifierType::where('slug', $slug)->first();

            if (! $identifierType) {
                continue;
            }

            foreach ($typePatterns as $patternData) {
                IdentifierTypePattern::firstOrCreate(
                    [
                        'identifier_type_id' => $identifierType->id,
                        'type' => $patternData['type'],
                        'pattern' => $patternData['pattern'],
                    ],
                    [
                        'is_active' => true,
                        'priority' => $patternData['priority'],
                    ]
                );
            }
        }
    }

    /**
     * @return array<string, list<array{type: string, pattern: string, priority: int}>>
     */
    private function getPatterns(): array
    {
        /** @var array<string, list<array{type: string, pattern: string, priority: int}>> $corrections */
        $corrections = require database_path('data/identifier-patterns/2026-10-16.php');

        $patterns = [
            'DOI' => $corrections['DOI'],

            'arXiv' => $corrections['arXiv'],

            'bibcode' => [
                // Validation
                ['type' => 'validation', 'pattern' => '^\\d{4}[A-Za-z&.]{5}[A-Za-z0-9.]{4}[A-Za-z.][A-Za-z0-9.]{4}[A-Za-z]$', 'priority' => 10],
                // Detection
                ['type' => 'detection', 'pattern' => '^https?://(?:ui\\.)?adsabs\\.harvard\\.edu/abs/\\S+', 'priority' => 30],
                ['type' => 'detection', 'pattern' => '^\\d{4}[A-Za-z&.]{5}[A-Za-z0-9.]{4}[A-Za-z.][A-Za-z0-9.]{4}[A-Za-z]$', 'priority' => 10],
                ['type' => 'detection', 'pattern' => '^\\d{4}(?:arXiv|jwst\\.prop|PhDT|Sci|Natur)\\S+[A-Za-z]$', 'priority' => 5],
            ],

            'CSTR' => [
                // Validation
                ['type' => 'validation', 'pattern' => '^(?:cstr:)?\\d{5}\\.\\d{2}\\.\\S+$', 'priority' => 10],
                // Detection
                ['type' => 'detection', 'pattern' => '^https?://(?:identifiers\\.org|bioregistry\\.io)/cstr:', 'priority' => 30],
                ['type' => 'detection', 'pattern' => '^cstr:\\d{5}\\.\\d{2}\\.\\S+', 'priority' => 20],
                ['type' => 'detection', 'pattern' => '^\\d{5}\\.\\d{2}\\.[A-Za-z_][A-Za-z0-9_.~-]*\\.\\S+$', 'priority' => 10],
            ],

            'EAN13' => $corrections['EAN13'],

            'EISSN' => [
                // Validation
                ['type' => 'validation', 'pattern' => '^\\d{4}-?\\d{3}[\\dXx]$', 'priority' => 10],
                // Detection
                ['type' => 'detection', 'pattern' => '^https?://(?:portal\\.issn\\.org/resource/ISSN/|identifiers\\.org/issn:|www\\.worldcat\\.org/issn/)\\d{4}-?\\d{3}[\\dXx]$', 'priority' => 30],
                ['type' => 'detection', 'pattern' => '^urn:issn:\\d{4}-?\\d{3}[\\dXx]$', 'priority' => 25],
                ['type' => 'detection', 'pattern' => '^(?:e-?issn|p-?issn|issn):?\\s*\\d{4}-?\\d{3}[\\dXx]$', 'priority' => 20],
                ['type' => 'detection', 'pattern' => '^\\d{4}-\\d{3}[\\dXx]$', 'priority' => 10],
            ],

            'Handle' => [
                // Validation
                ['type' => 'validation', 'pattern' => '^\\d+(?:\\.\\w+)?/\\S+$', 'priority' => 10],
                // Detection
                ['type' => 'detection', 'pattern' => '^https?://hdl\\.handle\\.net/(?:api/handles/)?\\S+', 'priority' => 30],
                ['type' => 'detection', 'pattern' => '^hdl://\\S+', 'priority' => 25],
                ['type' => 'detection', 'pattern' => '^urn:handle:\\S+', 'priority' => 20],
                ['type' => 'detection', 'pattern' => '^10\\.1594/\\S+$', 'priority' => 15],
                ['type' => 'detection', 'pattern' => '^\\d+(?:\\.\\w+)?/\\S+$', 'priority' => 5],
            ],

            'IGSN' => $corrections['IGSN'],

            'ISBN' => $corrections['ISBN'],

            'ISSN' => [
                // Validation
                ['type' => 'validation', 'pattern' => '^\\d{4}-?\\d{3}[\\dXx]$', 'priority' => 10],
                // Detection
                ['type' => 'detection', 'pattern' => '^\\d{4}-\\d{3}[\\dXx]$', 'priority' => 10],
            ],

            'ISTC' => [
                // Validation
                ['type' => 'validation', 'pattern' => '^[0-9A-Ja-j]{3}-?[0-9]{4}-?[0-9A-Ja-j]{4}-?[0-9A-Ja-j]{4}-?[0-9A-Ja-j]$', 'priority' => 10],
                // Detection
                ['type' => 'detection', 'pattern' => '^urn:istc:[0-9A-Ja-j-]+$', 'priority' => 25],
                ['type' => 'detection', 'pattern' => '^istc\\s*(?:\\([^)]+\\))?:?\\s*[0-9A-Ja-j]{3}-?[0-9]{4}-?[0-9A-Ja-j]{4}-?[0-9A-Ja-j]{4}-?[0-9A-Ja-j]$', 'priority' => 20],
                ['type' => 'detection', 'pattern' => '^[0-9A-Ja-j]{3}-[0-9]{4}-[0-9A-Ja-j]{4}-[0-9A-Ja-j]{4}-[0-9A-Ja-j]$', 'priority' => 10],
                ['type' => 'detection', 'pattern' => '^[0-9A-Ja-j]{3}[0-9]{4}[0-9A-Ja-j]{9}$', 'priority' => 5],
            ],

            'LISSN' => [
                // Validation
                ['type' => 'validation', 'pattern' => '^\\d{4}-?\\d{3}[\\dXx]$', 'priority' => 10],
                // Detection
                ['type' => 'detection', 'pattern' => '^https?://portal\\.issn\\.org/resource/ISSN-L/\\d{4}-?\\d{3}[\\dXx]$', 'priority' => 30],
                ['type' => 'detection', 'pattern' => '^(?:lissn|issn-l):?\\s*\\d{4}-?\\d{3}[\\dXx]$', 'priority' => 20],
            ],

            'LSID' => [
                // Validation
                ['type' => 'validation', 'pattern' => '^urn:lsid:[a-z0-9.-]+:[a-z0-9._-]+:[a-z0-9._-]+(?:\\:\\d+)?$', 'priority' => 10],
                // Detection
                ['type' => 'detection', 'pattern' => '^https?://lsid\\.io/urn:lsid:[a-z0-9.-]+:[a-z0-9._-]+:[a-z0-9._-]+(?:\\:\\d+)?$', 'priority' => 30],
                ['type' => 'detection', 'pattern' => '^https?://[a-z0-9.-]+/ws/services/ServiceLocator\\?lsid=urn:lsid:[a-z0-9.-]+:[a-z0-9._-]+:[a-z0-9._-]+(?:\\:\\d+)?$', 'priority' => 30],
                ['type' => 'detection', 'pattern' => '^https?://zoobank\\.org/urn:lsid:zoobank\\.org:[a-z0-9._-]+:[a-z0-9._-]+$', 'priority' => 30],
                ['type' => 'detection', 'pattern' => '^urn:lsid:[a-z0-9.-]+:[a-z0-9._-]+:[a-z0-9._-]+(?:\\:\\d+)?$', 'priority' => 20],
            ],

            'PMID' => [
                // Validation
                ['type' => 'validation', 'pattern' => '^\\d{1,9}$', 'priority' => 10],
                // Detection
                ['type' => 'detection', 'pattern' => '^https?://pubmed\\.ncbi\\.nlm\\.nih\\.gov/\\d{1,9}$', 'priority' => 30],
                ['type' => 'detection', 'pattern' => '^https?://(?:www\\.)?ncbi\\.nlm\\.nih\\.gov/pubmed/\\d{1,9}$', 'priority' => 30],
                ['type' => 'detection', 'pattern' => '^(?:pmid|pubmed\\s*id):?\\s*\\d{1,9}$', 'priority' => 20],
                ['type' => 'detection', 'pattern' => '^\\d{1,9}\\s*\\[(?:pmid|uid)\\]$', 'priority' => 15],
            ],

            'PURL' => [
                // Validation
                ['type' => 'validation', 'pattern' => '^https?://purl\\.[a-z0-9.-]+/[a-z0-9._/-]+$', 'priority' => 10],
                // Detection
                ['type' => 'detection', 'pattern' => '^https?://purl\\.org/[a-z0-9._/-]+$', 'priority' => 30],
                ['type' => 'detection', 'pattern' => '^https?://purl\\.oclc\\.org/[a-z0-9._/-]+$', 'priority' => 30],
                ['type' => 'detection', 'pattern' => '^https?://purl\\.lib\\.[a-z0-9.-]+/[a-z0-9._/?=&-]+$', 'priority' => 25],
                ['type' => 'detection', 'pattern' => '^https?://purl\\.[a-z0-9.-]+\\.(?:org|edu)/[a-z0-9._/-]+$', 'priority' => 20],
            ],

            'RAiD' => $corrections['RAiD'],

            'RRID' => [
                // Validation
                ['type' => 'validation', 'pattern' => '^(?:RRID:)?[a-z]+[_:][a-z0-9_:-]+$', 'priority' => 10],
                // Detection
                ['type' => 'detection', 'pattern' => '^https?://scicrunch\\.org/resolver/RRID:[a-z]+[_:]?[a-z0-9_:-]+$', 'priority' => 30],
                ['type' => 'detection', 'pattern' => '^https?://rrid\\.site/RRID:[a-z]+[_:]?[a-z0-9_:-]+$', 'priority' => 30],
                ['type' => 'detection', 'pattern' => '^rrid:?\\s*[a-z]+[_:]?[a-z0-9_:-]+$', 'priority' => 20],
            ],

            'UPC' => [
                // Validation
                ['type' => 'validation', 'pattern' => '^\\d{12}$', 'priority' => 10],
                // Detection
                ['type' => 'detection', 'pattern' => '^(?:upc-?a?|gtin-?12):?\\s*(\\d[\\d\\s-]{10,14}\\d)$', 'priority' => 20],
                ['type' => 'detection', 'pattern' => '^upc-?e:?\\s*\\d{8}$', 'priority' => 15],
            ],

            'URL' => [
                // Validation
                ['type' => 'validation', 'pattern' => '^https?://\\S+$', 'priority' => 10],
                // Detection
                ['type' => 'detection', 'pattern' => '^https?://', 'priority' => 1],
            ],

            'URN' => [
                // Validation
                ['type' => 'validation', 'pattern' => '^urn:[a-z0-9][a-z0-9-]{0,31}:\\S+$', 'priority' => 10],
                // Detection
                ['type' => 'detection', 'pattern' => '^https?://nbn-resolving\\.(?:de|org)/urn:[a-z0-9][a-z0-9-]{0,31}:\\S+$', 'priority' => 30],
                ['type' => 'detection', 'pattern' => '^https?://urn\\.fi/urn:[a-z0-9][a-z0-9-]{0,31}:\\S+$', 'priority' => 30],
                ['type' => 'detection', 'pattern' => '^https?://urn\\.kb\\.se/resolve\\?urn=urn:[a-z0-9][a-z0-9-]{0,31}:\\S+$', 'priority' => 30],
                ['type' => 'detection', 'pattern' => '^https?://persistent-identifier\\.nl/urn:[a-z0-9][a-z0-9-]{0,31}:\\S+$', 'priority' => 30],
                ['type' => 'detection', 'pattern' => '^https?://n2t\\.net/urn:[a-z0-9][a-z0-9-]{0,31}:\\S+$', 'priority' => 30],
                ['type' => 'detection', 'pattern' => '^urn:(?!isbn:|lsid:|igsn:|issn:|istc:|handle:)[a-z0-9][a-z0-9-]{0,31}:\\S+$', 'priority' => 10],
            ],

            'w3id' => [
                // Validation
                ['type' => 'validation', 'pattern' => '^https?://w3id\\.org/[a-z0-9._/-]+(?:#[a-z0-9._-]*)?$', 'priority' => 10],
                // Detection
                ['type' => 'detection', 'pattern' => '^https?://w3id\\.org/[a-z0-9._/-]+(?:#[a-z0-9._-]*)?$', 'priority' => 30],
            ],

            'ARK' => [
                // Validation
                ['type' => 'validation', 'pattern' => '^(?:ark:/?)?\\d{5,}/\\S+$', 'priority' => 10],
                // Detection
                ['type' => 'detection', 'pattern' => '^https?://[^/]+(?:/[^/]+)*/ark:/?\\d{5,}/\\S+', 'priority' => 30],
                ['type' => 'detection', 'pattern' => '^ark:/?\\d{5,}/\\S+', 'priority' => 20],
            ],
        ];

        /** @var array<string, list<array{type: string, previous: string, pattern: string, priority: int}>> $htmlCorrections */
        $htmlCorrections = require database_path('data/identifier-patterns/2026-10-16-html-patterns.php');

        // Apply the follow-up snapshot without changing the historical one.
        foreach ($htmlCorrections as $slug => $replacements) {
            foreach ($replacements as $replacement) {
                foreach ($patterns[$slug] as $index => $pattern) {
                    if ($pattern['type'] === $replacement['type'] && $pattern['pattern'] === $replacement['previous']) {
                        $patterns[$slug][$index] = [
                            'type' => $replacement['type'],
                            'pattern' => $replacement['pattern'],
                            'priority' => $replacement['priority'],
                        ];
                    }
                }
            }
        }

        /** @var list<array{type: string, patterns: list<array{pattern: string, priority: int}>}> $swhidGroups */
        $swhidGroups = require database_path('data/identifier-patterns/2026-10-30-swhid.php');
        $patterns['SWHID'] = [];
        foreach ($swhidGroups as $group) {
            foreach ($group['patterns'] as $pattern) {
                $patterns['SWHID'][] = ['type' => $group['type'], ...$pattern];
            }
        }

        return $patterns;
    }
}
