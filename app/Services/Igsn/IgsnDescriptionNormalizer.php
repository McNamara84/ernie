<?php

declare(strict_types=1);

namespace App\Services\Igsn;

/**
 * Normalizes legacy IGSN description payloads without changing their order.
 */
class IgsnDescriptionNormalizer
{
    /**
     * @param  array<mixed>  $groups
     * @return list<array{entries: list<array{value: string, scheme: string|null}>}>
     */
    public function normalizeGroups(array $groups): array
    {
        $normalized = [];

        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $entries = $group['entries'] ?? $group['descriptions'] ?? null;
            if (! is_array($entries)) {
                continue;
            }

            $normalizedEntries = $this->normalizeEntries($entries);
            if ($normalizedEntries !== []) {
                $normalized[] = ['entries' => $normalizedEntries];
            }
        }

        return $normalized;
    }

    /**
     * Normalize the description JSON variants found in legacy CSV exports.
     *
     * @return list<array{entries: list<array{value: string, scheme: string|null}>}>
     */
    public function normalizeCsvPayload(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        if (isset($payload['description_groups']) && is_array($payload['description_groups'])) {
            return $this->normalizeGroups($payload['description_groups']);
        }

        if (isset($payload['descriptions']) && is_array($payload['descriptions'])) {
            return $this->normalizeGroups([['descriptions' => $payload['descriptions']]]);
        }

        if (array_is_list($payload)) {
            $groups = $this->normalizeGroups($payload);
            if ($groups !== []) {
                return $groups;
            }

            $entries = $this->normalizeEntries($payload);

            return $entries !== [] ? [['entries' => $entries]] : [];
        }

        return [];
    }

    /**
     * Produce the transitional flat representation used by older deployments.
     *
     * @param  list<array{entries: list<array{value: string, scheme: string|null}>}>  $groups
     * @return list<string>
     */
    public function legacyValues(array $groups): array
    {
        $values = [];
        $seen = [];

        foreach ($groups as $group) {
            foreach ($group['entries'] as $entry) {
                $key = mb_strtolower($entry['value']);
                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $values[] = $entry['value'];
                }
            }
        }

        return $values;
    }

    /**
     * @param  array<mixed>  $entries
     * @return list<array{value: string, scheme: string|null}>
     */
    private function normalizeEntries(array $entries): array
    {
        $normalized = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $value = $this->normalizeText($entry['value'] ?? $entry['description'] ?? null);
            if ($value === null) {
                continue;
            }

            $normalized[] = [
                'value' => $value,
                'scheme' => $this->normalizeText($entry['scheme'] ?? $entry['descriptionScheme'] ?? null),
            ];
        }

        return $normalized;
    }

    private function normalizeText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' && strcasecmp($value, 'N/A') !== 0 ? $value : null;
    }
}
