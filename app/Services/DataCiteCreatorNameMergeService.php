<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\OrcidNormalizer;
use Illuminate\Support\Str;

/**
 * Enriches DataCite creator names with unambiguous, richer SUMARIO spellings.
 * All non-name DataCite metadata remains authoritative.
 */
final class DataCiteCreatorNameMergeService
{
    /**
     * @param  array<string, mixed>  $doiRecord
     * @param  list<array<string, mixed>>  $legacyCreators
     * @return array<string, mixed>
     */
    public function mergeIntoDoiRecord(array $doiRecord, array $legacyCreators): array
    {
        if ($legacyCreators === []) {
            return $doiRecord;
        }

        if (is_array($doiRecord['attributes'] ?? null)) {
            $creators = is_array($doiRecord['attributes']['creators'] ?? null)
                ? $doiRecord['attributes']['creators']
                : [];
            $doiRecord['attributes']['creators'] = $this->merge($creators, $legacyCreators);

            return $doiRecord;
        }

        $creators = is_array($doiRecord['creators'] ?? null) ? $doiRecord['creators'] : [];
        $doiRecord['creators'] = $this->merge($creators, $legacyCreators);

        return $doiRecord;
    }

    /**
     * @param  array<int, mixed>  $dataCiteCreators
     * @param  list<array<string, mixed>>  $legacyCreators
     * @return list<mixed>
     */
    public function merge(array $dataCiteCreators, array $legacyCreators): array
    {
        return $this->mergeWithReport($dataCiteCreators, $legacyCreators)['creators'];
    }

    /**
     * Merge creators and expose the conservative match decisions for backfills.
     *
     * @param  array<int, mixed>  $dataCiteCreators
     * @param  list<array<string, mixed>>  $legacyCreators
     * @return array{
     *     creators: list<mixed>,
     *     matches: list<array{current_index: int, legacy_index: int|null, method: string, status: string}>
     * }
     */
    public function mergeWithReport(array $dataCiteCreators, array $legacyCreators): array
    {
        $merged = array_values($dataCiteCreators);
        $usedLegacyIndexes = [];
        $matches = [];

        foreach ($merged as $index => $creator) {
            if (! is_array($creator) || $this->nameType($creator) === 'Organizational') {
                continue;
            }

            $match = $this->matchingLegacyCreator(
                $creator,
                $index,
                $merged,
                $legacyCreators,
                $usedLegacyIndexes,
            );
            if ($match['legacy_index'] === null) {
                $matches[] = [
                    'current_index' => $index,
                    ...$match,
                ];

                continue;
            }

            $legacyIndex = $match['legacy_index'];
            $usedLegacyIndexes[$legacyIndex] = true;
            $legacy = $legacyCreators[$legacyIndex];
            if (! $this->isSameOrRicherName($creator, $legacy)) {
                $matches[] = [
                    'current_index' => $index,
                    'legacy_index' => $legacyIndex,
                    'method' => $match['method'],
                    'status' => 'not_richer',
                ];

                continue;
            }

            $changed = false;
            foreach (['name', 'givenName', 'familyName'] as $field) {
                $value = $this->filled($legacy[$field] ?? null);
                if ($value !== null) {
                    $changed = $changed || $this->filled($creator[$field] ?? null) !== $value;
                    $creator[$field] = $value;
                }
            }

            $merged[$index] = $creator;
            $matches[] = [
                'current_index' => $index,
                'legacy_index' => $legacyIndex,
                'method' => $match['method'],
                'status' => $changed ? 'merged' : 'identical',
            ];
        }

        return ['creators' => $merged, 'matches' => $matches];
    }

    /**
     * @param  array<string, mixed>  $creator
     * @param  list<mixed>  $dataCiteCreators
     * @param  list<array<string, mixed>>  $legacyCreators
     * @param  array<int, bool>  $usedLegacyIndexes
     * @return array{legacy_index: int|null, method: string, status: string}
     */
    private function matchingLegacyCreator(
        array $creator,
        int $position,
        array $dataCiteCreators,
        array $legacyCreators,
        array $usedLegacyIndexes,
    ): array {
        $orcid = $this->orcid($creator);
        if ($orcid !== null) {
            $currentMatches = array_filter(
                $dataCiteCreators,
                fn (mixed $candidate): bool => is_array($candidate) && $this->orcid($candidate) === $orcid,
            );
            $legacyMatches = [];
            foreach ($legacyCreators as $legacyIndex => $legacy) {
                if ($this->orcid($legacy) === $orcid) {
                    $legacyMatches[] = $legacyIndex;
                }
            }
            if (count($currentMatches) === 1 && count($legacyMatches) === 1) {
                $legacyIndex = $legacyMatches[0];

                return isset($usedLegacyIndexes[$legacyIndex])
                    ? ['legacy_index' => null, 'method' => 'orcid', 'status' => 'ambiguous']
                    : ['legacy_index' => $legacyIndex, 'method' => 'orcid', 'status' => 'matched'];
            }
            if (count($currentMatches) > 1 || count($legacyMatches) > 1) {
                return ['legacy_index' => null, 'method' => 'orcid', 'status' => 'ambiguous'];
            }
        }

        $positionCandidate = $legacyCreators[$position] ?? null;
        if (is_array($positionCandidate)
            && ! isset($usedLegacyIndexes[$position])
            && $this->namesAreCompatible($creator, $positionCandidate)
            && ! $this->hasConflictingOrcids($creator, $positionCandidate)
        ) {
            return ['legacy_index' => $position, 'method' => 'position_and_name', 'status' => 'matched'];
        }

        $normalizedName = $this->normalizedFullName($creator);
        if ($normalizedName === null) {
            return ['legacy_index' => null, 'method' => 'none', 'status' => 'unmatched'];
        }

        $matches = [];
        foreach ($legacyCreators as $legacyIndex => $legacy) {
            if (! isset($usedLegacyIndexes[$legacyIndex])
                && $this->normalizedFullName($legacy) === $normalizedName
                && ! $this->hasConflictingOrcids($creator, $legacy)
            ) {
                $matches[] = $legacyIndex;
            }
        }

        if (count($matches) === 1) {
            return ['legacy_index' => $matches[0], 'method' => 'name', 'status' => 'matched'];
        }

        return [
            'legacy_index' => null,
            'method' => $matches === [] ? 'none' : 'name',
            'status' => $matches === [] ? 'unmatched' : 'ambiguous',
        ];
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $legacy
     */
    private function isSameOrRicherName(array $current, array $legacy): bool
    {
        $currentFamily = $this->normalize($current['familyName'] ?? null);
        $legacyFamily = $this->normalize($legacy['familyName'] ?? null);
        if ($currentFamily !== null && $legacyFamily !== null && $currentFamily !== $legacyFamily) {
            return false;
        }

        $currentGiven = $this->tokens($current['givenName'] ?? null);
        $legacyGiven = $this->tokens($legacy['givenName'] ?? null);
        if ($currentGiven !== [] && $legacyGiven === []) {
            return false;
        }

        return array_diff($currentGiven, $legacyGiven) === [];
    }

    /**
     * @param  array<string, mixed>  $first
     * @param  array<string, mixed>  $second
     */
    private function namesAreCompatible(array $first, array $second): bool
    {
        $firstFamily = $this->normalize($first['familyName'] ?? null);
        $secondFamily = $this->normalize($second['familyName'] ?? null);
        if ($firstFamily === null || $secondFamily === null || $firstFamily !== $secondFamily) {
            return false;
        }

        $firstGiven = $this->tokens($first['givenName'] ?? null);
        $secondGiven = $this->tokens($second['givenName'] ?? null);

        return $firstGiven === []
            || $secondGiven === []
            || array_diff($firstGiven, $secondGiven) === []
            || array_diff($secondGiven, $firstGiven) === [];
    }

    /**
     * @param  array<string, mixed>  $first
     * @param  array<string, mixed>  $second
     */
    private function hasConflictingOrcids(array $first, array $second): bool
    {
        $firstOrcid = $this->orcid($first);
        $secondOrcid = $this->orcid($second);

        return $firstOrcid !== null && $secondOrcid !== null && $firstOrcid !== $secondOrcid;
    }

    /** @param array<string, mixed> $creator */
    private function orcid(array $creator): ?string
    {
        $identifiers = $creator['nameIdentifiers'] ?? [];
        if (! is_array($identifiers)) {
            return null;
        }

        foreach ($identifiers as $identifier) {
            if (! is_array($identifier)) {
                continue;
            }

            $scheme = strtoupper(trim((string) ($identifier['nameIdentifierScheme'] ?? '')));
            if ($scheme !== 'ORCID') {
                continue;
            }

            $orcid = OrcidNormalizer::extractBareId((string) ($identifier['nameIdentifier'] ?? ''));

            return OrcidNormalizer::isValid($orcid) ? strtolower($orcid) : null;
        }

        return null;
    }

    /** @param array<string, mixed> $creator */
    private function normalizedFullName(array $creator): ?string
    {
        $family = $this->normalize($creator['familyName'] ?? null);
        $given = $this->normalize($creator['givenName'] ?? null);
        if ($family !== null || $given !== null) {
            return trim(($family ?? '').'|'.($given ?? ''), '|');
        }

        return $this->normalize($creator['name'] ?? null);
    }

    /** @return list<string> */
    private function tokens(mixed $value): array
    {
        $normalized = $this->normalize($value);

        return $normalized !== null ? explode(' ', $normalized) : [];
    }

    private function normalize(mixed $value): ?string
    {
        $value = $this->filled($value);
        if ($value === null) {
            return null;
        }

        $value = mb_strtolower(Str::ascii($value), 'UTF-8');
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? '';
        $value = trim((string) preg_replace('/\s+/', ' ', $value));

        return $value !== '' ? $value : null;
    }

    /** @param array<string, mixed> $creator */
    private function nameType(array $creator): string
    {
        return ucfirst(strtolower(trim((string) ($creator['nameType'] ?? 'Personal'))));
    }

    private function filled(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
