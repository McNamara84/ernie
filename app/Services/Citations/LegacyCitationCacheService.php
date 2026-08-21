<?php

declare(strict_types=1);

namespace App\Services\Citations;

use App\Services\DataCiteApiService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LegacyCitationCacheService
{
    private const CONNECTION = 'legacy_metaworks';

    private const LOOKUP_CHUNK_SIZE = 500;

    /** @var list<string> */
    private const DOI_URL_PREFIXES = [
        'http://doi.org/',
        'https://doi.org/',
        'http://dx.doi.org/',
        'https://dx.doi.org/',
        '',
        'doi:',
    ];

    private bool $legacyDatabaseUnavailable = false;

    public function __construct(
        private readonly DataCiteApiService $dataCite,
    ) {}

    public function find(string $doi): ?string
    {
        $normalizedDoi = $this->normalizeDoi($doi);

        if ($normalizedDoi === null) {
            return null;
        }

        return $this->findMany([$normalizedDoi])[$normalizedDoi] ?? null;
    }

    /**
     * @param  list<string>  $dois
     * @return array<string, string>
     */
    public function findMany(array $dois): array
    {
        if ($this->legacyDatabaseUnavailable) {
            return [];
        }

        /** @var array<string, true> $normalizedDois */
        $normalizedDois = [];

        foreach ($dois as $doi) {
            $normalizedDoi = $this->normalizeDoi($doi);

            if ($normalizedDoi !== null) {
                $normalizedDois[$normalizedDoi] = true;
            }
        }

        if ($normalizedDois === []) {
            return [];
        }

        $candidateUrls = $this->candidateUrls(array_keys($normalizedDois));

        try {
            $rows = $this->queryRows($candidateUrls, false);
            $citations = $this->selectCitations($rows);
            $rankZeroDois = $this->rankZeroDois($rows);
            $fallbackDois = array_values(array_filter(
                array_keys($normalizedDois),
                static fn (string $doi): bool => ! isset($citations[$doi]) || ! isset($rankZeroDois[$doi]),
            ));

            // The production column uses a case-insensitive collation, so the
            // indexed lookup above normally covers mixed-case DOI suffixes. The
            // fallback also keeps differently configured replicas safe. Even a
            // lower-ranked exact hit must not hide a mixed-case canonical row.
            if ($fallbackDois !== []) {
                $rows = [
                    ...$rows,
                    ...$this->queryRows($this->candidateUrls($fallbackDois), true),
                ];
                $citations = $this->selectCitations($rows);
            }

            $orderedCitations = [];

            foreach (array_keys($normalizedDois) as $doi) {
                if (isset($citations[$doi])) {
                    $orderedCitations[$doi] = $citations[$doi];
                }
            }

            return $orderedCitations;
        } catch (\Throwable $exception) {
            $this->legacyDatabaseUnavailable = true;

            Log::warning('Legacy citation cache lookup failed; falling back to DOI metadata.', [
                'doi_count' => count($normalizedDois),
                'error' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    private function normalizeDoi(string $doi): ?string
    {
        $doi = preg_replace('/^doi:\s*/i', '', trim($doi)) ?? trim($doi);
        $normalizedDoi = $this->dataCite->normalizeDoi($doi);

        if ($normalizedDoi === null || preg_match('#^10\.\d{4,9}/\S+$#i', $normalizedDoi) !== 1) {
            return null;
        }

        return mb_strtolower($normalizedDoi);
    }

    /**
     * @param  list<string>  $dois
     * @return list<string>
     */
    private function candidateUrls(array $dois): array
    {
        $urls = [];

        foreach ($dois as $doi) {
            foreach (self::DOI_URL_PREFIXES as $prefix) {
                $urls[] = $prefix.$doi;
            }
        }

        return $urls;
    }

    /**
     * @param  list<string>  $candidateUrls
     * @return list<array{url: string, citation: string}>
     */
    private function queryRows(array $candidateUrls, bool $caseInsensitive): array
    {
        $rows = [];

        foreach (array_chunk($candidateUrls, self::LOOKUP_CHUNK_SIZE) as $chunk) {
            $query = DB::connection(self::CONNECTION)
                ->table('citationcache')
                ->select(['url', 'citation']);

            if ($caseInsensitive) {
                $query->whereIn(DB::raw('LOWER(url)'), array_map(mb_strtolower(...), $chunk));
            } else {
                $query->whereIn('url', $chunk);
            }

            foreach ($query->get() as $row) {
                $url = $row->url ?? null;
                $citation = $row->citation ?? null;

                if (! is_string($url) || ! is_string($citation)) {
                    continue;
                }

                $rows[] = [
                    'url' => $url,
                    'citation' => $citation,
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  list<array{url: string, citation: string}>  $rows
     * @return array<string, true>
     */
    private function rankZeroDois(array $rows): array
    {
        $dois = [];

        foreach ($rows as $row) {
            $doi = $this->normalizeDoi($row['url']);

            if ($doi !== null && $this->urlRank($row['url']) === 0) {
                $dois[$doi] = true;
            }
        }

        return $dois;
    }

    /**
     * @param  list<array{url: string, citation: string}>  $rows
     * @return array<string, string>
     */
    private function selectCitations(array $rows): array
    {
        /** @var array<string, list<array{rank: int, url: string, citation: string}>> $candidatesByDoi */
        $candidatesByDoi = [];

        foreach ($rows as $row) {
            $doi = $this->normalizeDoi($row['url']);
            $citation = $this->normalizeCitation($row['citation']);

            if ($doi === null || $citation === null) {
                continue;
            }

            $candidatesByDoi[$doi][] = [
                'rank' => $this->urlRank($row['url']),
                'url' => mb_strtolower(trim($row['url'])),
                'citation' => $citation,
            ];
        }

        $citations = [];

        foreach ($candidatesByDoi as $doi => $candidates) {
            usort($candidates, static fn (array $left, array $right): int => [
                $left['rank'],
                $left['url'],
                $left['citation'],
            ] <=> [
                $right['rank'],
                $right['url'],
                $right['citation'],
            ]);

            $citations[$doi] = $candidates[0]['citation'];
        }

        return $citations;
    }

    private function normalizeCitation(string $citation): ?string
    {
        $citation = html_entity_decode(trim($citation), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $citation = preg_replace(
            '#<(script|style|iframe|object|embed|svg|math|template)\b[^>]*>.*?</\1>#isu',
            ' ',
            $citation,
        ) ?? $citation;
        $citation = strip_tags($citation);
        $citation = preg_replace('/[\s\p{Z}]+/u', ' ', $citation) ?? $citation;
        $citation = trim($citation);

        if ($citation === '') {
            return null;
        }

        if (preg_match('/^invalid\s+url$/i', $citation) === 1) {
            return null;
        }

        if (preg_match('/^error\s+code\s*:\s*\d+\b/i', $citation) === 1) {
            return null;
        }

        return $citation;
    }

    private function urlRank(string $url): int
    {
        $normalizedUrl = mb_strtolower(trim($url));

        foreach (self::DOI_URL_PREFIXES as $rank => $prefix) {
            if ($prefix === '') {
                if (preg_match('#^10\.\d{4,9}/#', $normalizedUrl) === 1) {
                    return $rank;
                }

                continue;
            }

            if (str_starts_with($normalizedUrl, $prefix)) {
                return $rank;
            }
        }

        return count(self::DOI_URL_PREFIXES);
    }
}
