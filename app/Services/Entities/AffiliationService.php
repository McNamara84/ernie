<?php

declare(strict_types=1);

namespace App\Services\Entities;

use App\Models\ResourceContributor;
use App\Models\ResourceCreator;

/**
 * Service for handling affiliation data on creators and contributors.
 *
 * Centralizes the logic for parsing and creating affiliations that was previously
 * duplicated in ResourceController (syncCreatorAffiliations, syncContributorAffiliations).
 *
 * Affiliations support ROR (Research Organization Registry) identifiers for
 * standardized institution identification.
 */
class AffiliationService
{
    /**
     * Sync affiliations for a ResourceCreator.
     *
     * @param  ResourceCreator  $creator  The creator to add affiliations to
     * @param  array<string, mixed>  $data  Request data containing 'affiliations' key
     */
    public function syncForCreator(ResourceCreator $creator, array $data): void
    {
        $payload = $this->parseAffiliationsFromData($data);

        if ($payload === []) {
            return;
        }

        $creator->affiliations()->createMany($payload);
    }

    /**
     * Sync affiliations for a ResourceContributor.
     *
     * @param  ResourceContributor  $contributor  The contributor to add affiliations to
     * @param  array<string, mixed>  $data  Request data containing 'affiliations' key
     */
    public function syncForContributor(ResourceContributor $contributor, array $data): void
    {
        $payload = $this->parseAffiliationsFromData($data);

        if ($payload === []) {
            return;
        }

        $contributor->affiliations()->createMany($payload);
    }

    /**
     * Parse affiliations from request data into database-ready format.
     *
     * Filters out invalid entries and normalizes ROR identifiers.
     *
     * @param  array<string, mixed>  $data  Request data containing 'affiliations' key
     * @return array<int, array{name: string, identifier: string|null, identifier_scheme: string|null}>
     */
    public function parseAffiliationsFromData(array $data): array
    {
        $affiliations = $data['affiliations'] ?? [];

        if (! is_array($affiliations) || $affiliations === []) {
            return [];
        }

        $payload = [];

        foreach ($affiliations as $affiliation) {
            $parsed = $this->parseAffiliation($affiliation);

            if ($parsed !== null) {
                $payload[] = $parsed;
            }
        }

        return $payload;
    }

    /**
     * Parse a single affiliation entry.
     *
     * @param  mixed  $affiliation  The affiliation data
     * @return array{name: string, identifier: string|null, identifier_scheme: string|null}|null
     */
    private function parseAffiliation(mixed $affiliation): ?array
    {
        if (! is_array($affiliation)) {
            return null;
        }

        $value = isset($affiliation['value']) ? trim((string) $affiliation['value']) : '';

        if ($value === '') {
            return null;
        }

        $rorId = $this->extractRorId($affiliation);

        return [
            'name' => $value,
            'identifier' => $rorId,
            'identifier_scheme' => $rorId !== null ? 'ROR' : null,
        ];
    }

    /**
     * Extract and normalize ROR ID from affiliation data.
     *
     * @param  array<string, mixed>  $affiliation
     */
    private function extractRorId(array $affiliation): ?string
    {
        if (! array_key_exists('rorId', $affiliation)) {
            return null;
        }

        $rawRorId = $affiliation['rorId'];

        if ($rawRorId === null) {
            return null;
        }

        $trimmedRorId = trim((string) $rawRorId);

        return $trimmedRorId === '' ? null : $trimmedRorId;
    }
}
