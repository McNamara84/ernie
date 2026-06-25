<?php

declare(strict_types=1);

namespace App\Services\CrossrefFunderRor;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use JsonException;

/**
 * Reads exact FundRef-to-ROR candidates from the locally generated ROR index.
 */
final class CrossrefFunderRorFundrefIndexMappingSource implements CrossrefFunderRorMappingSource
{
    public const string INDEX_PATH = 'ror/ror-fundref-index.json';

    /** @var array<array-key, list<array<string, mixed>>>|null */
    private ?array $index = null;

    /**
     * @return list<array<string, mixed>>
     */
    public function candidatesForCrossrefFunderId(string $normalizedFundrefId): array
    {
        $this->ensureLoaded();

        return $this->index[$normalizedFundrefId] ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function candidatesForFundref(string $normalizedFundrefId): array
    {
        return $this->candidatesForCrossrefFunderId($normalizedFundrefId);
    }

    private function ensureLoaded(): void
    {
        if ($this->index !== null) {
            return;
        }

        $this->index = [];
        $disk = Storage::disk('local');

        if (! $disk->exists(self::INDEX_PATH)) {
            return;
        }

        $contents = $disk->get(self::INDEX_PATH);

        if (! is_string($contents) || trim($contents) === '') {
            return;
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return;
        }

        if (! is_array($decoded)) {
            return;
        }

        $entries = isset($decoded['data']) && is_array($decoded['data'])
            ? $decoded['data']
            : $decoded;

        $fallbackSource = is_array($decoded['source'] ?? null) ? $decoded['source'] : [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $this->addEntry($entry, $fallbackSource);
        }
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $fallbackSource
     */
    private function addEntry(array $entry, array $fallbackSource): void
    {
        $fundref = $this->stringValue($entry['fundref'] ?? $entry['fundref_id'] ?? null);

        if ($fundref !== null && is_array($entry['candidates'] ?? null)) {
            foreach ($entry['candidates'] as $candidate) {
                if (is_array($candidate)) {
                    $this->appendCandidate($fundref, $this->withSource($candidate, $fallbackSource));
                }
            }

            return;
        }

        foreach ($this->fundrefValues($entry) as $candidateFundref) {
            $this->appendCandidate($candidateFundref, $this->withSource($entry, $fallbackSource));
        }
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $fallbackSource
     * @return array<string, mixed>
     */
    private function withSource(array $candidate, array $fallbackSource): array
    {
        if (! is_array($candidate['source'] ?? null) && $fallbackSource !== []) {
            $candidate['source'] = $fallbackSource;
        }

        return $candidate;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return list<string>
     */
    private function fundrefValues(array $candidate): array
    {
        $values = Arr::get($candidate, 'external_ids.fundref.all');

        if (is_array($values)) {
            return array_values(array_filter(
                array_map(fn (mixed $value): ?string => $this->stringValue($value), $values),
                fn (?string $value): bool => $value !== null,
            ));
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function appendCandidate(string $fundref, array $candidate): void
    {
        if (! preg_match('/^[0-9]+$/', $fundref)) {
            return;
        }

        if ($this->index === null) {
            $this->index = [];
        }

        $candidates = $this->index[$fundref] ?? [];
        $candidates[] = $candidate;
        $this->index[$fundref] = $candidates;
    }

    private function stringValue(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
