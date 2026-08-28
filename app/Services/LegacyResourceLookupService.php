<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OldDataset;
use RuntimeException;

class LegacyResourceLookupService
{
    public function __construct(
        private ?LegacyKeywordService $legacyKeywordService = null,
    ) {}

    public function existsByDoi(string $doi): bool
    {
        return OldDataset::query()
            ->whereRaw('LOWER(identifier) = ?', [strtolower(trim($doi))])
            ->exists();
    }

    /**
     * @return array<int, array{identifier: string, identifierType: string, relationType: string, position: int}>
     */
    public function relatedIdentifiersByDoi(string $doi): array
    {
        $resource = $this->findByDoi($doi);

        return $resource?->getRelatedIdentifiers() ?? [];
    }

    /**
     * @return array{
     *     relatedIdentifiers: list<array{identifier: string, identifierType: string, relationType: string, position: int}>,
     *     subjects: list<array<string, string>>,
     *     legacyResourceId: int|null,
     *     legacyResourceStatus: string|null
     * }
     */
    public function importMetadataByDoi(string $doi): array
    {
        $resource = $this->findByDoi($doi);

        if ($resource === null) {
            return [
                'relatedIdentifiers' => [],
                'subjects' => [],
                'legacyResourceId' => null,
                'legacyResourceStatus' => null,
            ];
        }

        return [
            'relatedIdentifiers' => array_values($resource->getRelatedIdentifiers()),
            'subjects' => $this->keywordService()->dataCiteSubjects($resource),
            'legacyResourceId' => (int) $resource->id,
            'legacyResourceStatus' => is_string($resource->publicstatus) ? $resource->publicstatus : null,
        ];
    }

    private function findByDoi(string $doi): ?OldDataset
    {
        $matches = OldDataset::query()
            ->whereRaw('LOWER(identifier) = ?', [mb_strtolower(trim($doi))])
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($matches->count() > 1) {
            throw new RuntimeException('Multiple SUMARIO resources have the same DOI.');
        }

        return $matches->first();
    }

    private function keywordService(): LegacyKeywordService
    {
        return $this->legacyKeywordService ??= app(LegacyKeywordService::class);
    }
}
