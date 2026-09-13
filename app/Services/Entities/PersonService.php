<?php

declare(strict_types=1);

namespace App\Services\Entities;

use App\Models\Person;
use App\Support\OrcidNormalizer;

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
 * Existing names are not updated to preserve data integrity. A legacy ORCID
 * with a null identifier scheme is classified as ORCID when it is reused.
 */
class PersonService
{
    private const ORCID_SCHEME = 'ORCID';

    /**
     * Find an existing person or create a new one from the provided data.
     *
     * @param  array<string, mixed>  $data  Expected keys: orcid, firstName, lastName
     */
    public function findOrCreate(array $data): Person
    {
        if (! empty($data['orcid'])) {
            $orcid = trim((string) $data['orcid']);
            $existing = $this->findCompatibleOrcidPerson($orcid);

            if ($existing instanceof Person) {
                return $this->promoteLegacyOrcidScheme($existing);
            }

            $data['orcid'] = $this->availableOrcidStorageValue($orcid);
        }

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
     * Find or create the identity backing an unstructured creator snapshot.
     *
     * The resource-specific display name must not be promoted to structured,
     * globally shared Person fields. An ORCID may still identify an existing
     * person; otherwise a nameless Person row satisfies the creator relation.
     */
    public function findOrCreateWithoutStructuredName(?string $orcid = null): Person
    {
        $orcid = $orcid !== null ? trim($orcid) : null;
        $orcid = $orcid !== '' ? $orcid : null;

        if ($orcid !== null) {
            $bareOrcid = strtoupper(OrcidNormalizer::extractBareId($orcid));
            $existing = $this->findCompatibleOrcidPerson($orcid);

            if ($existing instanceof Person) {
                return $this->promoteLegacyOrcidScheme($existing);
            }

            if (OrcidNormalizer::isValid($bareOrcid)) {
                $orcid = $this->availableOrcidStorageValue(
                    OrcidNormalizer::toUrl($bareOrcid),
                );
            }

            return Person::query()->firstOrCreate(
                [
                    'name_identifier' => $orcid,
                    'name_identifier_scheme' => self::ORCID_SCHEME,
                ],
                [
                    'given_name' => null,
                    'family_name' => '',
                ],
            );
        }

        return Person::query()->create([
            'given_name' => null,
            'family_name' => '',
        ]);
    }

    /** @return list<string> */
    private function orcidStorageVariants(string $bareOrcid): array
    {
        $bareVariants = array_values(array_unique([
            strtoupper($bareOrcid),
            strtolower($bareOrcid),
        ]));
        $prefixes = [
            'https://orcid.org/',
            'http://orcid.org/',
            'https://www.orcid.org/',
            'http://www.orcid.org/',
            'orcid.org/',
            'www.orcid.org/',
            '',
        ];
        $variants = [];

        foreach ($prefixes as $prefix) {
            foreach ($bareVariants as $bareVariant) {
                $variants[] = $prefix.$bareVariant;
            }
        }

        return array_values(array_unique($variants));
    }

    private function findCompatibleOrcidPerson(string $orcid): ?Person
    {
        $bareOrcid = strtoupper(OrcidNormalizer::extractBareId($orcid));
        $variants = OrcidNormalizer::isValidFormat($bareOrcid)
            ? $this->orcidStorageVariants($bareOrcid)
            : [$orcid];

        $person = Person::query()
            ->whereIn('name_identifier', $variants)
            ->where('name_identifier_scheme', self::ORCID_SCHEME)
            ->first();

        if ($person instanceof Person) {
            return $person;
        }

        // Legacy imports sometimes omitted the scheme for otherwise valid ORCIDs.
        // They remain compatible, unlike identifiers explicitly assigned to ISNI/ROR.
        return Person::query()
            ->whereIn('name_identifier', $variants)
            ->whereNull('name_identifier_scheme')
            ->first();
    }

    private function promoteLegacyOrcidScheme(Person $person): Person
    {
        if ($person->name_identifier_scheme === null) {
            $person->name_identifier_scheme = self::ORCID_SCHEME;
            $person->save();
        }

        return $person;
    }

    private function availableOrcidStorageValue(string $orcid): string
    {
        if (Person::query()->where('name_identifier', $orcid)->doesntExist()) {
            return $orcid;
        }

        $bareOrcid = strtoupper(OrcidNormalizer::extractBareId($orcid));

        if (OrcidNormalizer::isValid($bareOrcid)) {
            foreach ($this->orcidStorageVariants($bareOrcid) as $variant) {
                if (Person::query()->where('name_identifier', $variant)->doesntExist()) {
                    return $variant;
                }
            }
        }

        return $orcid;
    }

    /**
     * Create a person without carrying over or rediscovering an ORCID identity.
     *
     * This is used when an editor explicitly removes an existing creator's
     * ORCID. A name-based lookup could otherwise relink the same ORCID person.
     */
    public function createWithoutOrcid(?string $givenName, ?string $familyName): Person
    {
        return Person::query()->create([
            'given_name' => $givenName,
            'family_name' => $familyName ?? '',
        ]);
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
            return [
                'name_identifier' => $data['orcid'],
                'name_identifier_scheme' => self::ORCID_SCHEME,
            ];
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
            $person->name_identifier_scheme = self::ORCID_SCHEME;
        }
    }
}
