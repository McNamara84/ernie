<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OldDataset;

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
     *     subjects: list<array<string, string>>
     * }
     */
    public function importMetadataByDoi(string $doi): array
    {
        $resource = $this->findByDoi($doi);

        if ($resource === null) {
            return [
                'relatedIdentifiers' => [],
                'subjects' => [],
            ];
        }

        return [
            'relatedIdentifiers' => array_values($resource->getRelatedIdentifiers()),
            'subjects' => $this->keywordService()->dataCiteSubjects($resource),
        ];
    }

    private function findByDoi(string $doi): ?OldDataset
    {
        return OldDataset::query()
            ->whereRaw('LOWER(identifier) = ?', [mb_strtolower(trim($doi))])
            ->first();
    }

    private function keywordService(): LegacyKeywordService
    {
        return $this->legacyKeywordService ??= app(LegacyKeywordService::class);
    }
}
