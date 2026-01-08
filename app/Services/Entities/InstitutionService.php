<?php

declare(strict_types=1);

namespace App\Services\Entities;

use App\Models\Institution;

/**
 * Service for finding or creating Institution entities.
 *
 * Centralizes the logic for Institution lookup and creation that was previously
 * duplicated across ResourceController methods (storeInstitutionCreator, storeInstitutionContributor).
 *
 * Supports multiple identifier schemes:
 * - ROR (Research Organization Registry) - primary for affiliations
 * - labid (MSL Laboratory identifier)
 * - Other custom schemes
 *
 * Search priority:
 * 1. By identifier (name_identifier) if provided
 * 2. By name without identifier
 */
class InstitutionService
{
    /**
     * Find an existing institution or create a new one from the provided data.
     *
     * This method handles the common case for authors/creators with ROR identifiers.
     *
     * @param  array<string, mixed>  $data  Expected keys: institutionName (or name), rorId (optional)
     */
    public function findOrCreate(array $data): Institution
    {
        $name = $data['institutionName'] ?? $data['name'] ?? '';
        $identifier = $data['rorId'] ?? $data['identifier'] ?? null;
        $scheme = $data['identifierScheme'] ?? $data['identifierType'] ?? ($identifier ? 'ROR' : null);

        return $this->findOrCreateWithIdentifier($name, $identifier, $scheme);
    }

    /**
     * Find an existing institution or create a new one with explicit identifier parameters.
     *
     * @param  string  $name  The institution name
     * @param  string|null  $identifier  The identifier value (e.g., ROR ID, lab ID)
     * @param  string|null  $scheme  The identifier scheme (e.g., 'ROR', 'labid')
     */
    public function findOrCreateWithIdentifier(string $name, ?string $identifier, ?string $scheme): Institution
    {
        $institution = $this->findByIdentifierOrName($identifier, $scheme, $name);

        if ($institution === null) {
            $institution = new Institution;
        }

        $this->updateInstitution($institution, $name, $identifier, $scheme);

        return $institution;
    }

    /**
     * Find an institution by identifier or name.
     *
     * Search priority:
     * 1. By identifier + scheme if both provided
     * 2. By identifier only if provided (without scheme)
     * 3. By name without any identifier
     *
     * @param  string|null  $identifier  The identifier to search for
     * @param  string|null  $scheme  The identifier scheme
     * @param  string  $name  The institution name as fallback
     */
    public function findByIdentifierOrName(?string $identifier, ?string $scheme, string $name): ?Institution
    {
        // Priority 1: Search by identifier + scheme
        if ($identifier !== null && $scheme !== null) {
            $byIdAndScheme = Institution::query()
                ->where('name_identifier', $identifier)
                ->where('name_identifier_scheme', $scheme)
                ->first();

            if ($byIdAndScheme !== null) {
                return $byIdAndScheme;
            }
        }

        // Priority 2: Search by identifier only (without scheme constraint)
        if ($identifier !== null) {
            $byId = Institution::query()
                ->where('name_identifier', $identifier)
                ->first();

            if ($byId !== null) {
                return $byId;
            }
        }

        // Priority 3: Search by name without identifier
        return Institution::query()
            ->where('name', $name)
            ->whereNull('name_identifier')
            ->first();
    }

    /**
     * Find an institution by identifier only.
     *
     * @param  string  $identifier  The identifier to search for
     * @param  string|null  $scheme  Optional scheme to narrow search
     */
    public function findByIdentifier(string $identifier, ?string $scheme = null): ?Institution
    {
        $query = Institution::query()->where('name_identifier', $identifier);

        if ($scheme !== null) {
            $query->where('name_identifier_scheme', $scheme);
        }

        return $query->first();
    }

    /**
     * Update institution data, only if values have changed.
     *
     * @param  Institution  $institution  The institution to update
     * @param  string  $name  The new name
     * @param  string|null  $identifier  The new identifier
     * @param  string|null  $scheme  The new scheme
     */
    private function updateInstitution(
        Institution $institution,
        string $name,
        ?string $identifier,
        ?string $scheme
    ): void {
        $needsUpdate = false;

        // Update name if different
        if ($institution->name !== $name) {
            $institution->name = $name;
            $needsUpdate = true;
        }

        // Update identifier if provided and different
        if ($identifier !== null) {
            if ($institution->name_identifier !== $identifier) {
                $institution->name_identifier = $identifier;
                $needsUpdate = true;
            }

            if ($scheme !== null && $institution->name_identifier_scheme !== $scheme) {
                $institution->name_identifier_scheme = $scheme;
                $needsUpdate = true;
            }
        }

        // Save only if changes were made or institution is new
        if ($needsUpdate || ! $institution->exists) {
            $institution->save();
        }
    }

    /**
     * Find or create an MSL Laboratory institution.
     *
     * MSL Laboratories use 'labid' as their identifier scheme.
     *
     * @param  array<string, mixed>  $data  Expected keys: identifier, name
     */
    public function findOrCreateMslLaboratory(array $data): Institution
    {
        $identifier = $data['identifier'];
        $name = $data['name'];

        return $this->findOrCreateWithIdentifier($name, $identifier, 'labid');
    }
}
