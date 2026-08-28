<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ContributorType;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Support\OrcidNormalizer;
use Illuminate\Support\Str;

/**
 * Resolves display-only identity groups for landing-page credits and contacts.
 *
 * The returned keys are scoped to one resource representation. They must never
 * be persisted or used to merge Person records.
 */
final class LandingPagePersonIdentityResolverService
{
    private const MINIMUM_LEGACY_NAME_TOKENS = 3;

    /**
     * @return array{creators: array<int, string>, contributors: array<int, string>}
     */
    public function resolve(Resource $resource): array
    {
        /** @var array<int, string> $creatorGroups */
        $creatorGroups = [];
        /** @var array<int, string> $contributorGroups */
        $contributorGroups = [];
        /** @var array<string, string> $groupsByEntity */
        $groupsByEntity = [];
        /** @var array<string, string> $groupsByOrcid */
        $groupsByOrcid = [];
        /** @var array<string, array<string, ResourceCreator>> $creatorsByStructuredName */
        $creatorsByStructuredName = [];
        /** @var array<string, array<string, ResourceCreator>> $creatorsByLegacyTokens */
        $creatorsByLegacyTokens = [];

        foreach ($resource->creators as $creator) {
            $entityKey = $this->entityKey($creator->creatorable_type, $creator->creatorable_id);
            $orcidKey = $creator->creatorable instanceof Person
                ? $this->orcidKey($creator->creatorable)
                : null;
            $group = $this->firstKnownGroup($groupsByEntity, $groupsByOrcid, $entityKey, $orcidKey)
                ?? $entityKey
                ?? "creator-row:{$creator->id}";

            $creatorGroups[$creator->id] = $group;
            $this->indexStrongIdentifiers($groupsByEntity, $groupsByOrcid, $group, $entityKey, $orcidKey);

            if (! $creator->creatorable instanceof Person) {
                continue;
            }

            $structuredName = $this->structuredNameKey($creator->creatorable);
            if ($structuredName !== null) {
                $creatorsByStructuredName[$structuredName][$group] ??= $creator;
            }

            $legacyTokens = $this->legacyTokenKey($creator->creatorable);
            if ($legacyTokens !== null) {
                $creatorsByLegacyTokens[$legacyTokens][$group] ??= $creator;
            }
        }

        foreach ($resource->contributors as $contributor) {
            $entityKey = $this->entityKey($contributor->contributorable_type, $contributor->contributorable_id);
            $person = $contributor->contributorable instanceof Person
                ? $contributor->contributorable
                : null;
            $orcidKey = $person !== null ? $this->orcidKey($person) : null;
            $group = $this->firstKnownGroup($groupsByEntity, $groupsByOrcid, $entityKey, $orcidKey);

            if ($group === null && $person !== null) {
                $group = $this->uniqueCreatorGroup(
                    $person,
                    $this->structuredNameKey($person),
                    $creatorsByStructuredName,
                );
            }

            if ($group === null && $person !== null && $this->isContactPerson($contributor)) {
                $group = $this->uniqueCreatorGroup(
                    $person,
                    $this->legacyTokenKey($person),
                    $creatorsByLegacyTokens,
                );
            }

            $group ??= $entityKey ?? "contributor-row:{$contributor->id}";

            $contributorGroups[$contributor->id] = $group;
            $this->indexStrongIdentifiers($groupsByEntity, $groupsByOrcid, $group, $entityKey, $orcidKey);
        }

        return [
            'creators' => $creatorGroups,
            'contributors' => $contributorGroups,
        ];
    }

    /**
     * @param  array<string, string>  $groupsByEntity
     * @param  array<string, string>  $groupsByOrcid
     */
    private function firstKnownGroup(
        array $groupsByEntity,
        array $groupsByOrcid,
        ?string $entityKey,
        ?string $orcidKey,
    ): ?string {
        if ($entityKey !== null && isset($groupsByEntity[$entityKey])) {
            return $groupsByEntity[$entityKey];
        }

        if ($orcidKey !== null && isset($groupsByOrcid[$orcidKey])) {
            return $groupsByOrcid[$orcidKey];
        }

        return null;
    }

    /**
     * @param  array<string, string>  $groupsByEntity
     * @param  array<string, string>  $groupsByOrcid
     */
    private function indexStrongIdentifiers(
        array &$groupsByEntity,
        array &$groupsByOrcid,
        string $group,
        ?string $entityKey,
        ?string $orcidKey,
    ): void {
        if ($entityKey !== null) {
            $groupsByEntity[$entityKey] ??= $group;
        }

        if ($orcidKey !== null) {
            $groupsByOrcid[$orcidKey] ??= $group;
        }
    }

    private function entityKey(string $morphType, int|string|null $entityId): ?string
    {
        if (! is_numeric($entityId) || (int) $entityId <= 0) {
            return null;
        }

        $type = match ($morphType) {
            Person::class => 'person',
            Institution::class => 'institution',
            default => trim(mb_strtolower($morphType, 'UTF-8')),
        };

        if ($type === '') {
            return null;
        }

        return "entity:{$type}:".(int) $entityId;
    }

    private function orcidKey(Person $person): ?string
    {
        $identifier = trim((string) ($person->name_identifier ?? ''));
        $scheme = trim((string) ($person->name_identifier_scheme ?? ''));

        if ($identifier === '' || ($scheme !== '' && strcasecmp($scheme, 'ORCID') !== 0)) {
            return null;
        }

        $bareOrcid = OrcidNormalizer::extractBareId($identifier);

        if (! OrcidNormalizer::isValid($bareOrcid)) {
            return null;
        }

        return 'orcid:'.strtolower($bareOrcid);
    }

    private function structuredNameKey(Person $person): ?string
    {
        $givenName = $this->normalizeNamePart($person->given_name);
        $familyName = $this->normalizeNamePart($person->family_name);

        if ($givenName === null || $familyName === null) {
            return null;
        }

        return "given:{$givenName}|family:{$familyName}";
    }

    private function legacyTokenKey(Person $person): ?string
    {
        $givenName = $this->normalizeNamePart($person->given_name);
        $familyName = $this->normalizeNamePart($person->family_name);

        if ($givenName === null || $familyName === null) {
            return null;
        }

        $tokens = array_values(array_filter(
            explode(' ', $givenName.' '.$familyName),
            static fn (string $token): bool => $token !== '',
        ));

        if (count($tokens) < self::MINIMUM_LEGACY_NAME_TOKENS) {
            return null;
        }

        sort($tokens, SORT_STRING);

        return implode('|', $tokens);
    }

    private function normalizeNamePart(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $normalized = mb_strtolower(Str::ascii(trim($value)), 'UTF-8');
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? '';
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? '';
        $normalized = trim($normalized);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @param  array<string, array<string, ResourceCreator>>  $candidateIndex
     */
    private function uniqueCreatorGroup(Person $contributor, ?string $identityKey, array $candidateIndex): ?string
    {
        if ($identityKey === null || ! isset($candidateIndex[$identityKey])) {
            return null;
        }

        $eligibleGroups = [];

        foreach ($candidateIndex[$identityKey] as $group => $creator) {
            if (! $creator->creatorable instanceof Person
                || $this->hasConflictingOrcids($creator->creatorable, $contributor)) {
                continue;
            }

            $eligibleGroups[$group] = true;
        }

        if (count($eligibleGroups) !== 1) {
            return null;
        }

        return array_key_first($eligibleGroups);
    }

    private function hasConflictingOrcids(Person $first, Person $second): bool
    {
        $firstOrcid = $this->orcidKey($first);
        $secondOrcid = $this->orcidKey($second);

        return $firstOrcid !== null && $secondOrcid !== null && $firstOrcid !== $secondOrcid;
    }

    private function isContactPerson(ResourceContributor $contributor): bool
    {
        return $contributor->contributorTypes
            ->contains(static fn (ContributorType $type): bool => $type->slug === 'ContactPerson');
    }
}
