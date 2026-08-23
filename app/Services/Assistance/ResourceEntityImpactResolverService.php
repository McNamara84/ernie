<?php

declare(strict_types=1);

namespace App\Services\Assistance;

use App\Models\Affiliation;
use App\Models\Institution;
use App\Models\Person;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;

final class ResourceEntityImpactResolverService
{
    /**
     * @param  list<int>  $personIds
     * @return array<int, list<int>>
     */
    public function forPersons(array $personIds): array
    {
        return $this->forPartyEntities($personIds, Person::class);
    }

    /**
     * @param  list<int>  $institutionIds
     * @return array<int, list<int>>
     */
    public function forInstitutions(array $institutionIds): array
    {
        return $this->forPartyEntities($institutionIds, Institution::class);
    }

    /**
     * @param  list<int>  $affiliationIds
     * @return array<int, list<int>>
     */
    public function forAffiliations(array $affiliationIds): array
    {
        $ids = $this->uniquePositiveIds($affiliationIds);

        if ($ids === []) {
            return [];
        }

        $affiliations = Affiliation::query()
            ->whereIn('id', $ids)
            ->get(['id', 'affiliatable_type', 'affiliatable_id']);

        $creatorIds = [];
        $contributorIds = [];

        foreach ($affiliations as $affiliation) {
            if ($affiliation->affiliatable_type === ResourceCreator::class) {
                $creatorIds[] = (int) $affiliation->affiliatable_id;
            } elseif ($affiliation->affiliatable_type === ResourceContributor::class) {
                $contributorIds[] = (int) $affiliation->affiliatable_id;
            }
        }

        $creatorResources = ResourceCreator::query()
            ->whereIn('id', $this->uniquePositiveIds($creatorIds))
            ->pluck('resource_id', 'id');
        $contributorResources = ResourceContributor::query()
            ->whereIn('id', $this->uniquePositiveIds($contributorIds))
            ->pluck('resource_id', 'id');
        $result = [];

        foreach ($affiliations as $affiliation) {
            $resourceId = match ($affiliation->affiliatable_type) {
                ResourceCreator::class => $creatorResources->get($affiliation->affiliatable_id),
                ResourceContributor::class => $contributorResources->get($affiliation->affiliatable_id),
                default => null,
            };

            $result[(int) $affiliation->id] = is_numeric($resourceId) ? [(int) $resourceId] : [];
        }

        return $result;
    }

    /**
     * @param  list<int>  $entityIds
     * @param  class-string<Person|Institution>  $entityType
     * @return array<int, list<int>>
     */
    private function forPartyEntities(array $entityIds, string $entityType): array
    {
        $ids = $this->uniquePositiveIds($entityIds);
        $result = array_fill_keys($ids, []);

        if ($ids === []) {
            return $result;
        }

        $creators = ResourceCreator::query()
            ->where('creatorable_type', $entityType)
            ->whereIn('creatorable_id', $ids)
            ->get(['creatorable_id', 'resource_id']);
        $contributors = ResourceContributor::query()
            ->where('contributorable_type', $entityType)
            ->whereIn('contributorable_id', $ids)
            ->get(['contributorable_id', 'resource_id']);

        foreach ($creators as $creator) {
            $result[(int) $creator->creatorable_id][] = (int) $creator->resource_id;
        }

        foreach ($contributors as $contributor) {
            $result[(int) $contributor->contributorable_id][] = (int) $contributor->resource_id;
        }

        foreach ($result as $entityId => $resourceIds) {
            $result[$entityId] = $this->uniquePositiveIds($resourceIds);
        }

        return $result;
    }

    /**
     * @param  array<int, int>  $ids
     * @return list<int>
     */
    private function uniquePositiveIds(array $ids): array
    {
        $normalized = array_values(array_unique(array_map('intval', $ids)));

        return array_values(array_filter($normalized, static fn (int $id): bool => $id > 0));
    }
}
