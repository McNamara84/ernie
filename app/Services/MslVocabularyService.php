<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class MslVocabularyService
{
    private const VOCABULARY_URL = 'https://raw.githubusercontent.com/UtrechtUniversity/msl_vocabularies/main/vocabularies/combined/editor/1.3/editor_1-3.json';
    private const STORAGE_PATH = 'msl-vocabulary.json';
    private const SCHEME = 'EPOS MSL vocabulary';
    private const SCHEME_URI = 'https://epos-msl.uu.nl/voc';
    private const LANGUAGE = 'en';

    /**
     * Download and transform MSL vocabulary from GitHub
     */
    public function downloadAndTransformVocabulary(): bool
    {
        try {
            Log::info('Downloading MSL vocabulary', ['url' => self::VOCABULARY_URL]);

            $response = Http::timeout(30)->get(self::VOCABULARY_URL);

            if (!$response->successful()) {
                Log::error('Failed to download MSL vocabulary', [
                    'status' => $response->status(),
                    'url' => self::VOCABULARY_URL,
                ]);
                return false;
            }

            $sourceData = $response->json();

            if (!is_array($sourceData)) {
                Log::error('MSL vocabulary is not a valid JSON array');
                return false;
            }

            Log::info('Transforming MSL vocabulary tree structure');

            $transformedData = $this->transformVocabularyTree($sourceData);

            $jsonEncoded = json_encode($transformedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            
            if ($jsonEncoded === false) {
                Log::error('Failed to encode MSL vocabulary to JSON');
                return false;
            }

            Storage::put(self::STORAGE_PATH, $jsonEncoded);

            $totalConcepts = $this->countConcepts($transformedData);

            Log::info('MSL vocabulary downloaded and transformed successfully', [
                'concepts_count' => $totalConcepts,
                'storage_path' => self::STORAGE_PATH,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Error downloading MSL vocabulary', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * Get vocabulary from storage
     *
     * @return array<int, array{id: string, text: string, language: string, scheme: string, schemeURI: string, description: string}>
     */
    public function getVocabulary(): array
    {
        if (!Storage::exists(self::STORAGE_PATH)) {
            Log::warning('MSL vocabulary file not found', ['path' => self::STORAGE_PATH]);
            return [];
        }

        $content = Storage::get(self::STORAGE_PATH);
        
        if ($content === null) {
            Log::error('MSL vocabulary file is empty');
            return [];
        }

        $data = json_decode($content, true);

        if (!is_array($data)) {
            Log::error('Invalid MSL vocabulary JSON');
            return [];
        }

        return $data;
    }

    /**
     * Count total number of concepts in the tree (including all children)
     *
     * @param array<int, array{children?: array<int, mixed>}> $tree
     * @return int
     */
    private function countConcepts(array $tree): int
    {
        $count = count($tree);

        foreach ($tree as $node) {
            if (isset($node['children']) && is_array($node['children']) && count($node['children']) > 0) {
                $count += $this->countConcepts($node['children']);
            }
        }

        return $count;
    }

    /**
     * Transform the hierarchical tree structure while preserving the tree
     *
     * @param array<int|string, mixed> $tree
     * @return array<int, array{id: string, text: string, language: string, scheme: string, schemeURI: string, description: string, children?: array<int, mixed>}>
     */
    private function transformVocabularyTree(array $tree): array
    {
        $transformed = [];

        foreach ($tree as $node) {
            if (!is_array($node)) {
                continue;
            }

            $transformedNode = $this->transformNode($node);
            if ($transformedNode !== null) {
                $transformed[] = $transformedNode;
            }
        }

        return $transformed;
    }

    /**
     * Transform a single node recursively
     *
     * @param array<string, mixed> $node
     * @return array{id: string, text: string, language: string, scheme: string, schemeURI: string, description: string, children?: array<int, mixed>}|null
     */
    private function transformNode(array $node): ?array
    {
        // Get the text label
        $text = $node['text'] ?? null;

        if ($text === null || !is_string($text)) {
            return null;
        }

        // Get URI from extra field
        $uri = $node['extra']['uri'] ?? null;
        $description = $node['extra']['description'] ?? '';

        // Build the transformed node
        $transformed = [
            'id' => is_string($uri) ? $uri : '',
            'text' => $text,
            'language' => self::LANGUAGE,
            'scheme' => self::SCHEME,
            'schemeURI' => self::SCHEME_URI,
            'description' => is_string($description) ? $description : '',
        ];

        // Process children recursively
        if (isset($node['children']) && is_array($node['children']) && !empty($node['children'])) {
            $transformed['children'] = [];
            
            foreach ($node['children'] as $child) {
                if (is_array($child)) {
                    $transformedChild = $this->transformNode($child);
                    if ($transformedChild !== null) {
                        $transformed['children'][] = $transformedChild;
                    }
                }
            }
        }

        return $transformed;
    }
}
