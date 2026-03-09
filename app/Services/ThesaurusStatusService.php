<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ThesaurusSetting;
use App\Support\ChronostratVocabularyParser;
use App\Support\GcmdVocabularyParser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Service for checking thesaurus status and comparing with remote APIs.
 *
 * Supports NASA KMS API (for GCMD vocabularies) and ARDC Linked Data API
 * (for ICS Chronostratigraphic Timescale).
 */
class ThesaurusStatusService
{
    private const NASA_KMS_BASE_URL = 'https://cmr.earthdata.nasa.gov/kms/concepts/concept_scheme/';

    private const ARDC_API_BASE_URL = 'https://vocabs.ardc.edu.au/repository/api/lda/csiro/international-chronostratigraphic-chart/geologic-time-scale-2020/concept.json';

    /**
     * Get the local status of a thesaurus (from stored JSON file).
     *
     * @return array{exists: bool, conceptCount: int, lastUpdated: string|null}
     */
    public function getLocalStatus(ThesaurusSetting $thesaurus): array
    {
        $filePath = $thesaurus->getFilePath();

        if (! Storage::exists($filePath)) {
            return [
                'exists' => false,
                'conceptCount' => 0,
                'lastUpdated' => null,
            ];
        }

        $content = Storage::get($filePath);

        // Storage::get() can return null in edge cases (e.g., file deleted between exists() and get())
        // Handle both null and empty string cases
        if ($content === null || $content === '') {
            return [
                'exists' => false,
                'conceptCount' => 0,
                'lastUpdated' => null,
            ];
        }

        /** @var array{lastUpdated?: string, data?: array<int, array<string, mixed>>}|null $data */
        $data = json_decode($content, true);

        if ($data === null) {
            return [
                'exists' => false,
                'conceptCount' => 0,
                'lastUpdated' => null,
            ];
        }

        return [
            'exists' => true,
            'conceptCount' => $this->countConcepts($data['data'] ?? []),
            'lastUpdated' => $data['lastUpdated'] ?? null,
        ];
    }

    /**
     * Get the concept count from the remote API.
     *
     * For GCMD thesauri, queries NASA KMS API.
     * For Chronostratigraphy, queries ARDC Linked Data API.
     *
     * @throws \RuntimeException If the API request fails
     */
    public function getRemoteConceptCount(ThesaurusSetting $thesaurus): int
    {
        if ($thesaurus->isGcmd()) {
            return $this->getGcmdRemoteCount($thesaurus);
        }

        if ($thesaurus->type === ThesaurusSetting::TYPE_CHRONOSTRAT) {
            return $this->getChronostratRemoteCount();
        }

        throw new \RuntimeException("Unsupported thesaurus type for remote check: {$thesaurus->type}");
    }

    /**
     * Get concept count from NASA KMS API.
     *
     * @throws \RuntimeException If the API request fails
     */
    private function getGcmdRemoteCount(ThesaurusSetting $thesaurus): int
    {
        $vocabularyType = $thesaurus->getVocabularyType();
        $url = self::NASA_KMS_BASE_URL.$vocabularyType.'?format=rdf&page_num=1&page_size=1';

        $response = Http::timeout(30)
            ->accept('application/rdf+xml')
            ->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Failed to fetch from NASA KMS API: HTTP {$response->status()}"
            );
        }

        $parser = new GcmdVocabularyParser;

        return $parser->extractTotalHits($response->body());
    }

    /**
     * Get concept count from ARDC Linked Data API for Chronostratigraphy.
     *
     * Fetches the first page with minimal page size to count total items.
     *
     * @throws \RuntimeException If the API request fails
     */
    private function getChronostratRemoteCount(): int
    {
        $parser = new ChronostratVocabularyParser;
        $page = 0;
        $count = 0;

        do {
            $url = self::ARDC_API_BASE_URL."?_pageSize=200&_page={$page}";

            $response = Http::timeout(30)
                ->accept('application/json')
                ->get($url);

            if (! $response->successful()) {
                throw new \RuntimeException(
                    "Failed to fetch from ARDC API: HTTP {$response->status()}"
                );
            }

            /** @var array{result?: array{items?: array<int, mixed>}} $data */
            $data = $response->json();
            $items = $data['result']['items'] ?? [];

            // Use the same filter as ChronostratVocabularyParser::extractConcepts
            $count += count($parser->extractConcepts($items));

            $page++;
        } while (count($items) === 200);

        return $count;
    }

    /**
     * Compare local and remote concept counts.
     *
     * An update is considered available when the remote concept count is greater
     * than the local count. This indicates new concepts have been added to the
     * NASA GCMD thesaurus. We don't trigger updates when remote < local because
     * concept deletions in NASA's vocabulary are rare and may indicate API issues.
     *
     * @return array{localCount: int, remoteCount: int, updateAvailable: bool, lastUpdated: string|null}
     *
     * @throws \RuntimeException If the API request fails
     */
    public function compareWithRemote(ThesaurusSetting $thesaurus): array
    {
        $localStatus = $this->getLocalStatus($thesaurus);
        $remoteCount = $this->getRemoteConceptCount($thesaurus);

        return [
            'localCount' => $localStatus['conceptCount'],
            'remoteCount' => $remoteCount,
            'updateAvailable' => $remoteCount > $localStatus['conceptCount'],
            'lastUpdated' => $localStatus['lastUpdated'],
        ];
    }

    /**
     * Recursively count all concepts in the hierarchy.
     *
     * @param  array<int, array<string, mixed>>  $data
     */
    private function countConcepts(array $data): int
    {
        $count = count($data);

        foreach ($data as $item) {
            if (isset($item['children']) && is_array($item['children']) && count($item['children']) > 0) {
                $count += $this->countConcepts($item['children']);
            }
        }

        return $count;
    }
}
