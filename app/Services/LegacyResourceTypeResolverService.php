<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ResourceType;
use Illuminate\Support\Facades\Log;

/**
 * Resolve semantic legacy/DataCite resource-type names to local ERNIE IDs.
 *
 * Database IDs are installation-specific and must never be used as a mapping
 * between the SUMARIO and ERNIE vocabularies.
 */
class LegacyResourceTypeResolverService
{
    /** @var array<string, int>|null */
    private ?array $idsByNormalizedType = null;

    public function resolveId(?string $legacyType): int
    {
        $idsByNormalizedType = $this->idsByNormalizedType();
        $normalizedType = $this->normalize($legacyType);

        if ($normalizedType !== '' && isset($idsByNormalizedType[$normalizedType])) {
            return $idsByNormalizedType[$normalizedType];
        }

        $fallbackSlug = $normalizedType === '' ? 'dataset' : 'other';
        $fallbackId = $idsByNormalizedType[$this->normalize($fallbackSlug)] ?? null;

        if ($fallbackId === null) {
            throw new \RuntimeException(
                "The ERNIE resource type '{$fallbackSlug}' is required for legacy imports."
            );
        }

        if ($normalizedType !== '') {
            Log::warning('Unknown legacy resource type mapped to Other', [
                'legacy_resource_type' => $legacyType,
            ]);
        }

        return $fallbackId;
    }

    /** @return array<string, int> */
    private function idsByNormalizedType(): array
    {
        if ($this->idsByNormalizedType !== null) {
            return $this->idsByNormalizedType;
        }

        $idsByNormalizedType = [];

        foreach (ResourceType::query()->orderBy('id')->get(['id', 'name', 'slug']) as $resourceType) {
            $aliases = [
                $resourceType->name,
                $resourceType->slug,
                ResourceType::slugToDataciteResourceTypeGeneral($resourceType->slug),
            ];

            foreach ($aliases as $alias) {
                $normalizedAlias = $this->normalize($alias);

                if ($normalizedAlias !== '' && ! isset($idsByNormalizedType[$normalizedAlias])) {
                    $idsByNormalizedType[$normalizedAlias] = (int) $resourceType->id;
                }
            }
        }

        return $this->idsByNormalizedType = $idsByNormalizedType;
    }

    private function normalize(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return strtolower((string) preg_replace('/[^a-z0-9]+/i', '', trim($value)));
    }
}
