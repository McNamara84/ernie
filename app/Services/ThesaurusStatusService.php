<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ThesaurusSetting;
use App\Support\GcmdVocabularyParser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Service for checking thesaurus status and comparing with NASA KMS API.
 */
class ThesaurusStatusService
{
    private const NASA_KMS_BASE_URL = 'https://cmr.earthdata.nasa.gov/kms/concepts/concept_scheme/';

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
     * Get the concept count from the NASA KMS API.
     *
     * This makes a lightweight request (page_size=1) to get the total hits count
     * without downloading all concepts.
     *
     * @throws \RuntimeException If the API request fails
     */
    public function getRemoteConceptCount(ThesaurusSetting $thesaurus): int
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
     * Compare local and remote concept counts.
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
            'updateAvailable' => $remoteCount !== $localStatus['conceptCount'],
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
