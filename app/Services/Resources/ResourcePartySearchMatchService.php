<?php

declare(strict_types=1);

namespace App\Services\Resources;

use App\Models\ContributorType;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Resource;
use App\Services\LandingPagePersonIdentityResolverService;
use Illuminate\Database\Eloquent\Collection;

final readonly class ResourcePartySearchMatchService
{
    private const ROLE_ORDER = ['contact_person', 'author', 'contributor'];

    public function __construct(
        private ResourcePartySearchNormalizerService $normalizer,
        private LandingPagePersonIdentityResolverService $identityResolver,
    ) {}

    /**
     * @param  Collection<int, Resource>  $resources
     * @return array<int, list<array{display_value:string, matched_field:'name'|'email', roles:list<string>}>>
     */
    public function resolve(Collection $resources, ?string $query): array
    {
        $query = trim((string) $query);
        if ($query === '' || $resources->isEmpty()) {
            return [];
        }

        $resources->load([
            'contributors' => fn ($contributorQuery) => $contributorQuery
                ->with([
                    'contributorable',
                    'contributorTypes:id,slug',
                ])
                ->orderBy('position')
                ->orderBy('id'),
        ]);

        $matches = [];
        foreach ($resources as $resource) {
            $matches[$resource->id] = $this->resourceMatches($resource, $query);
        }

        return $matches;
    }

    /** @return list<array{display_value:string, matched_field:'name'|'email', roles:list<string>}> */
    private function resourceMatches(Resource $resource, string $query): array
    {
        $identityGroups = $this->identityResolver->resolve($resource);

        /**
         * @var array<string, array{
         *     display_value:string,
         *     name_matched:bool,
         *     matched_email:?string,
         *     roles:array<string, bool>,
         *     sort:list<int>
         * }> $groups
         */
        $groups = [];

        foreach ($resource->creators as $creator) {
            $groupKey = $identityGroups['creators'][$creator->id] ?? "creator-row:{$creator->id}";
            $party = $this->searchableParty($creator->creatorable);
            $sort = [(int) $creator->position, 0, (int) $creator->id];
            $groups[$groupKey] ??= $this->emptyGroup($sort);
            $groups[$groupKey]['sort'] = min($groups[$groupKey]['sort'], $sort);
            $groups[$groupKey]['roles']['author'] = true;

            if ($creator->is_contact) {
                $groups[$groupKey]['roles']['contact_person'] = true;
            }

            if ($party !== null) {
                $this->addPartyMatch($groups[$groupKey], $party, $query);
            }
            $this->addEmailMatch($groups[$groupKey], $creator->email, $query);
        }

        foreach ($resource->contributors as $contributor) {
            $groupKey = $identityGroups['contributors'][$contributor->id] ?? "contributor-row:{$contributor->id}";
            $party = $this->searchableParty($contributor->contributorable);
            $sort = [(int) $contributor->position, 1, (int) $contributor->id];
            $groups[$groupKey] ??= $this->emptyGroup($sort);
            $groups[$groupKey]['sort'] = min($groups[$groupKey]['sort'], $sort);

            $hasContactPersonRole = $contributor->contributorTypes->contains(
                static fn (ContributorType $type): bool => $type->slug === 'ContactPerson',
            );
            $hasContributorRole = $contributor->contributorTypes->contains(
                static fn (ContributorType $type): bool => $type->slug !== 'ContactPerson',
            );
            if ($hasContactPersonRole) {
                $groups[$groupKey]['roles']['contact_person'] = true;
            }
            if ($hasContributorRole) {
                $groups[$groupKey]['roles']['contributor'] = true;
            }

            if ($party !== null) {
                $this->addPartyMatch($groups[$groupKey], $party, $query);
            }
            $this->addEmailMatch($groups[$groupKey], $contributor->email, $query);
        }

        uasort($groups, static fn (array $left, array $right): int => $left['sort'] <=> $right['sort']);

        $matches = [];
        foreach ($groups as $group) {
            if (! $group['name_matched'] && $group['matched_email'] === null) {
                continue;
            }

            $roles = array_values(array_filter(
                self::ROLE_ORDER,
                static fn (string $role): bool => isset($group['roles'][$role]),
            ));
            if ($roles === []) {
                continue;
            }

            $matchedField = $group['name_matched'] ? 'name' : 'email';
            $displayValue = $group['name_matched']
                ? $group['display_value']
                : (string) $group['matched_email'];

            if ($displayValue !== '') {
                $matches[] = [
                    'display_value' => $displayValue,
                    'matched_field' => $matchedField,
                    'roles' => $roles,
                ];
            }
        }

        return $matches;
    }

    /**
     * @param  list<int>  $sort
     * @return array{display_value:string, name_matched:bool, matched_email:?string, roles:array<string, bool>, sort:list<int>}
     */
    private function emptyGroup(array $sort): array
    {
        return [
            'display_value' => '',
            'name_matched' => false,
            'matched_email' => null,
            'roles' => [],
            'sort' => $sort,
        ];
    }

    /**
     * @param  array{display_value:string, name_matched:bool, matched_email:?string, roles:array<string, bool>, sort:list<int>}  $group
     */
    private function addPartyMatch(array &$group, Person|Institution $party, string $query): void
    {
        $displayName = $party instanceof Person ? $party->full_name : $party->name;
        if ($group['display_value'] === '') {
            $group['display_value'] = $displayName;
        }

        if (! $group['name_matched'] && $this->normalizer->partyMatches($party, $query)) {
            $group['name_matched'] = true;
            $group['display_value'] = $displayName;
        }
    }

    /**
     * @param  array{display_value:string, name_matched:bool, matched_email:?string, roles:array<string, bool>, sort:list<int>}  $group
     */
    private function addEmailMatch(array &$group, ?string $email, string $query): void
    {
        if ($group['matched_email'] === null && $this->normalizer->emailMatches($email, $query)) {
            $group['matched_email'] = trim((string) $email);
        }
    }

    private function searchableParty(mixed $party): Person|Institution|null
    {
        return $party instanceof Person || $party instanceof Institution ? $party : null;
    }
}
