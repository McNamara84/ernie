<?php

declare(strict_types=1);

namespace App\Services\Entities;

use App\Models\Person;

/**
 * Service for finding or creating Person entities.
 *
 * Centralizes the logic for Person lookup and creation that was previously
 * duplicated across ResourceController methods (storePersonCreator, storePersonContributor).
 *
 * Search priority:
 * 1. By ORCID (name_identifier) if provided
 * 2. By given_name + family_name combination
 *
 * New persons are created with ORCID identifier if provided.
 * Existing persons are NOT updated to preserve data integrity.
 */
class PersonService
{
    /**
     * Find an existing person or create a new one from the provided data.
     *
     * @param  array<string, mixed>  $data  Expected keys: orcid, firstName, lastName
     */
    public function findOrCreate(array $data): Person
    {
        $searchCriteria = $this->buildSearchCriteria($data);
        $person = Person::query()->firstOrNew($searchCriteria);

        // Only populate data for new persons (not yet saved to database)
        if (! $person->exists) {
            $this->populateNewPerson($person, $data);
        }

        $person->save();

        return $person;
    }

    /**
     * Build search criteria based on provided data.
     *
     * Prioritizes ORCID search if available, falls back to name-based search.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function buildSearchCriteria(array $data): array
    {
        // Priority 1: Search by ORCID if provided
        if (! empty($data['orcid'])) {
            return ['name_identifier' => $data['orcid']];
        }

        // Priority 2: Search by name combination
        return [
            'given_name' => $data['firstName'] ?? null,
            'family_name' => $data['lastName'] ?? null,
        ];
    }

    /**
     * Populate a new Person entity with data.
     *
     * @param  array<string, mixed>  $data
     */
    private function populateNewPerson(Person $person, array $data): void
    {
        $person->fill([
            'given_name' => $data['firstName'] ?? $person->given_name,
            'family_name' => $data['lastName'] ?? $person->family_name,
        ]);

        // Set ORCID identifier if provided
        if (! empty($data['orcid'])) {
            $person->name_identifier = $data['orcid'];
            $person->name_identifier_scheme = 'ORCID';
        }
    }
}
