<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Support\PersonIdentityMatcher;
use Illuminate\Support\Str;

/** Preserve authorship while projecting contributor contact roles into the editor. */
final class ContactPersonImportService
{
    /**
     * @param  array<int, array<string, mixed>>  $authors
     * @param  array<int, array<string, mixed>>  $contributors
     * @param  array<string, array{email: string, website: string}>  $isoContactInfo
     * @return array{authors: array<int, array<string, mixed>>, contributors: array<int, array<string, mixed>>}
     */
    public function resolve(array $authors, array $contributors, array $isoContactInfo = []): array
    {
        $identities = new PersonIdentityMatcher([...$authors, ...$contributors]);
        $identitiesByName = [];
        foreach ([...$authors, ...$contributors] as $person) {
            $name = PersonIdentityMatcher::nameKey($person);
            $key = $identities->key($person);
            if ($name !== null && $key !== null) {
                $identitiesByName[$name][$key] = true;
            }
        }

        $authorsByIdentity = [];
        foreach ($authors as $index => &$author) {
            $key = $identities->key($author);
            if ($key !== null) {
                $authorsByIdentity[$key][] = $index;
            }
            $orcid = PersonIdentityMatcher::orcid($author);
            if ($orcid !== null) {
                $author['orcid'] = $orcid;
            }
        }
        unset($author);

        foreach ($contributors as &$contributor) {
            $orcid = $identities->resolvedOrcid($contributor);
            if ($orcid !== null) {
                $contributor['orcid'] = $orcid;
            }
            if (! self::hasContactRole($contributor)) {
                continue;
            }
            $name = PersonIdentityMatcher::nameKey($contributor);
            if ($name !== null && count($identitiesByName[$name] ?? []) === 1 && isset($isoContactInfo[$name])) {
                foreach (['email', 'website'] as $field) {
                    if (empty($contributor[$field])) {
                        $contributor[$field] = $isoContactInfo[$name][$field];
                    }
                }
            }
        }
        unset($contributor);

        $contributors = $this->aggregate($contributors, $identities);

        $visibleContributors = [];
        foreach ($contributors as $contributor) {
            $key = $identities->key($contributor);
            $matches = $key !== null ? ($authorsByIdentity[$key] ?? []) : [];
            if (! self::hasContactRole($contributor) || count($matches) !== 1) {
                $visibleContributors[] = $contributor;

                continue;
            }

            $authorIndex = $matches[0];
            $authors[$authorIndex]['isContact'] = true;
            $contactOrcid = $identities->resolvedOrcid($contributor);
            if ($contactOrcid !== null) {
                $authors[$authorIndex]['orcid'] = $contactOrcid;
            }
            foreach (['email', 'website'] as $field) {
                if (empty($authors[$authorIndex][$field])) {
                    $authors[$authorIndex][$field] = $contributor[$field] ?? '';
                }
            }
            $authors[$authorIndex]['affiliations'] = $this->mergeAffiliations(
                is_array($authors[$authorIndex]['affiliations'] ?? null) ? $authors[$authorIndex]['affiliations'] : [],
                is_array($contributor['affiliations'] ?? null) ? $contributor['affiliations'] : [],
            );
            $contributor['roles'] = array_values(array_filter(
                is_array($contributor['roles'] ?? null) ? $contributor['roles'] : [],
                fn (mixed $role): bool => ! self::isContactRole($role),
            ));
            if ($contributor['roles'] !== []) {
                $contributor['email'] = '';
                $contributor['website'] = '';
                $visibleContributors[] = $contributor;
            }
        }

        return ['authors' => array_values($authors), 'contributors' => $visibleContributors];
    }

    /** @param array<string, mixed> $contributor */
    public static function hasContactRole(array $contributor): bool
    {
        return is_array($contributor['roles'] ?? null)
            && array_any($contributor['roles'], self::isContactRole(...));
    }

    private static function isContactRole(mixed $role): bool
    {
        return is_string($role) && strtolower(str_replace([' ', '-'], '', trim($role))) === 'contactperson';
    }

    /**
     * @param  array<int, array<string, mixed>>  $contributors
     * @return array<int, array<string, mixed>>
     */
    private function aggregate(array $contributors, PersonIdentityMatcher $identities): array
    {
        $result = [];
        $indexes = [];
        foreach ($contributors as $contributor) {
            $keys = ($contributor['type'] ?? '') === 'person'
                ? array_filter([$identities->key($contributor)])
                : $this->buildContributorAggregationKeys($contributor);
            $index = null;
            foreach ($keys as $key) {
                if (isset($indexes[$key])) {
                    $index = $indexes[$key];
                    break;
                }
            }
            if ($index === null) {
                $index = count($result);
                $result[] = $contributor;
            } else {
                $result[$index] = $this->mergeContributorEntries($result[$index], $contributor);
            }
            foreach ($keys as $key) {
                $indexes[$key] = $index;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $primary
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeContributorEntries(array $primary, array $incoming): array
    {
        if (($primary['type'] ?? null) !== ($incoming['type'] ?? null)) {
            return $primary;
        }

        foreach (['email', 'website'] as $field) {
            if (empty($primary[$field]) && ! empty($incoming[$field])) {
                $primary[$field] = $incoming[$field];
            }
        }

        $primary['roles'] = $this->mergeContributorRoles(
            is_array($primary['roles'] ?? null) ? $primary['roles'] : [],
            is_array($incoming['roles'] ?? null) ? $incoming['roles'] : [],
        );

        if (($primary['type'] ?? null) === 'person') {
            return $this->mergePersonContributor($primary, $incoming);
        }

        if (($primary['type'] ?? null) === 'institution') {
            return $this->mergeInstitutionContributor($primary, $incoming);
        }

        return $primary;
    }

    /**
     * @param  array<int, mixed>  $existing
     * @param  array<int, mixed>  $incoming
     * @return array<int, string>
     */
    private function mergeContributorRoles(array $existing, array $incoming): array
    {
        foreach ($incoming as $role) {
            if (! is_string($role) || $role === '') {
                continue;
            }

            if (! in_array($role, $existing, true)) {
                $existing[] = $role;
            }
        }

        return $existing;
    }

    /**
     * @param  array<string, mixed>  $primary
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergePersonContributor(array $primary, array $incoming): array
    {
        if (($primary['orcid'] ?? '') === '' && isset($incoming['orcid']) && is_string($incoming['orcid'])) {
            $primary['orcid'] = $incoming['orcid'];
        }

        foreach (['firstName', 'lastName'] as $field) {
            if (
                (! isset($primary[$field]) || ! is_string($primary[$field]) || trim($primary[$field]) === '')
                && isset($incoming[$field])
                && is_string($incoming[$field])
                && trim($incoming[$field]) !== ''
            ) {
                $primary[$field] = $incoming[$field];
            }
        }

        $primary['affiliations'] = $this->mergeAffiliations(
            is_array($primary['affiliations'] ?? null) ? $primary['affiliations'] : [],
            is_array($incoming['affiliations'] ?? null) ? $incoming['affiliations'] : [],
        );

        return $primary;
    }

    /**
     * @param  array<string, mixed>  $primary
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeInstitutionContributor(array $primary, array $incoming): array
    {
        if (
            (! isset($primary['institutionName'])
                || ! is_string($primary['institutionName'])
                || trim($primary['institutionName']) === '')
            && isset($incoming['institutionName'])
            && is_string($incoming['institutionName'])
            && trim($incoming['institutionName']) !== ''
        ) {
            $primary['institutionName'] = $incoming['institutionName'];
        }

        $primary['affiliations'] = $this->mergeAffiliations(
            is_array($primary['affiliations'] ?? null) ? $primary['affiliations'] : [],
            is_array($incoming['affiliations'] ?? null) ? $incoming['affiliations'] : [],
        );

        return $primary;
    }

    /**
     * @param  array<int, mixed>  $existing
     * @param  array<int, mixed>  $incoming
     * @return array<int, array{value: string, rorId: ?string}>
     */
    private function mergeAffiliations(array $existing, array $incoming): array
    {
        $merged = [];
        $seen = [];

        foreach ([$existing, $incoming] as $group) {
            foreach ($group as $affiliation) {
                if (! is_array($affiliation)) {
                    continue;
                }

                $value = isset($affiliation['value']) && is_string($affiliation['value'])
                    ? trim($affiliation['value'])
                    : '';
                $rorId = isset($affiliation['rorId']) && is_string($affiliation['rorId'])
                    ? trim($affiliation['rorId'])
                    : null;

                if ($rorId === '') {
                    $rorId = null;
                }

                if ($value === '' && $rorId === null) {
                    continue;
                }

                if ($rorId !== null) {
                    $key = 'ror:'.Str::lower($rorId);
                } else {
                    $key = 'value:'.Str::lower($value);
                }

                if (isset($seen[$key])) {
                    $existingIndex = $seen[$key];

                    if ($merged[$existingIndex]['value'] === '' && $value !== '') {
                        $merged[$existingIndex]['value'] = $value;
                    }

                    if ($merged[$existingIndex]['rorId'] === null && $rorId !== null) {
                        $merged[$existingIndex]['rorId'] = $rorId;
                    }

                    continue;
                }

                $seen[$key] = count($merged);

                $merged[] = [
                    'value' => $value,
                    'rorId' => $rorId,
                ];
            }
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $contributor
     * @return string[]
     */
    private function buildContributorAggregationKeys(array $contributor): array
    {
        $type = $contributor['type'] ?? null;

        if (! is_string($type)) {
            return [];
        }

        if ($type === 'institution') {
            $keys = [];
            $affiliations = is_array($contributor['affiliations'] ?? null)
                ? $contributor['affiliations']
                : [];

            foreach ($affiliations as $affiliation) {
                if (! is_array($affiliation)) {
                    continue;
                }

                $rorId = $this->normaliseIdentifier($affiliation['rorId'] ?? null);

                if ($rorId !== null) {
                    $keys[] = 'institution:ror:'.$rorId;
                }
            }

            $institutionName = $this->normaliseKeyString($contributor['institutionName'] ?? null);

            if ($institutionName !== null) {
                $keys[] = 'institution:name:'.$institutionName;
            }

            return $keys;
        }

        return [];
    }

    private function normaliseIdentifier(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : Str::lower($trimmed);
    }

    private function normaliseKeyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        $normalised = preg_replace('/\s+/u', ' ', $trimmed);

        if (! is_string($normalised)) {
            $normalised = $trimmed;
        }

        return Str::lower($normalised);
    }
}
