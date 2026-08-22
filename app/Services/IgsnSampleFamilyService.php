<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AlternateIdentifier;
use App\Models\IgsnMetadata;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Support\IgsnIdentifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

final class IgsnSampleFamilyService
{
    /**
     * Build the complete locally known sample family for an IGSN resource.
     *
     * @return array{
     *     root: array{
     *         resource_id: int,
     *         name: string|null,
     *         igsn: string|null,
     *         sample_type: string|null,
     *         landing_page: array{public_url: string}|null,
     *         children: array<mixed>
     *     },
     *     member_count: int
     * }|null
     */
    public function forResource(Resource $resource): ?array
    {
        $metadata = $this->metadataForResource($resource);
        if ($metadata === null) {
            return null;
        }

        $family = $this->loadFamily($metadata);
        if ($family->count() <= 1) {
            return null;
        }

        $root = $family->first();
        if (! $root instanceof IgsnMetadata) {
            return null;
        }

        /** @var array<int, true> $rendered */
        $rendered = [];
        $childrenByParent = $this->childrenByParent($family);

        return [
            'root' => $this->buildNode($root, $childrenByParent, $rendered),
            'member_count' => $family->count(),
        ];
    }

    /**
     * Return all resource IDs in the locally known family containing the seed.
     *
     * @return list<int>
     */
    public function resourceIdsForResourceId(int $resourceId): array
    {
        $metadata = $this->queryMetadataForResource($resourceId);
        if ($metadata === null) {
            return [];
        }

        return array_values($this->loadFamily($metadata)
            ->pluck('resource_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all());
    }

    private function metadataForResource(Resource $resource): ?IgsnMetadata
    {
        if (! $resource->exists) {
            return null;
        }

        if ($resource->relationLoaded('igsnMetadata')) {
            $metadata = $resource->getRelation('igsnMetadata');

            if (! $metadata instanceof IgsnMetadata || ! $metadata->exists) {
                return null;
            }

            return $this->queryMetadataForResource((int) $resource->getKey());
        }

        return $this->queryMetadataForResource((int) $resource->getKey());
    }

    private function queryMetadataForResource(int $resourceId): ?IgsnMetadata
    {
        return $this->metadataQuery()
            ->where('resource_id', $resourceId)
            ->first();
    }

    /**
     * @return Collection<int, IgsnMetadata>
     */
    private function loadFamily(IgsnMetadata $seed): Collection
    {
        $root = $this->findRoot($seed);

        /** @var Collection<int, IgsnMetadata> $family */
        $family = new Collection([$root]);
        /** @var array<int, true> $visited */
        $visited = [(int) $root->resource_id => true];
        $frontier = [(int) $root->resource_id];

        while ($frontier !== []) {
            /** @var Collection<int, IgsnMetadata> $children */
            $children = $this->metadataQuery()
                ->whereIn('parent_resource_id', $frontier)
                ->orderBy('resource_id')
                ->get();

            $nextFrontier = [];
            foreach ($children as $child) {
                $resourceId = (int) $child->resource_id;

                if (isset($visited[$resourceId])) {
                    $this->logCycle($seed->resource_id, $resourceId);

                    continue;
                }

                $visited[$resourceId] = true;
                $nextFrontier[] = $resourceId;
                $family->push($child);
            }

            $frontier = $nextFrontier;
        }

        return $family;
    }

    private function findRoot(IgsnMetadata $seed): IgsnMetadata
    {
        $current = $seed;
        /** @var array<int, IgsnMetadata> $visited */
        $visited = [];

        while (true) {
            $resourceId = (int) $current->resource_id;
            $visited[$resourceId] = $current;

            $parentResourceId = $current->parent_resource_id;
            if ($parentResourceId === null) {
                return $current;
            }

            $parentResourceId = (int) $parentResourceId;
            if (isset($visited[$parentResourceId])) {
                $this->logCycle($seed->resource_id, $parentResourceId);
                $stableRootId = min(array_keys($visited));

                return $visited[$stableRootId];
            }

            $parent = $this->queryMetadataForResource($parentResourceId);
            if ($parent === null) {
                return $current;
            }

            $current = $parent;
        }
    }

    /**
     * Index and sort every child once so serialization stays O(n) after sorting.
     *
     * @param  Collection<int, IgsnMetadata>  $family
     * @return array<int, list<IgsnMetadata>>
     */
    private function childrenByParent(Collection $family): array
    {
        /** @var array<int, list<IgsnMetadata>> $childrenByParent */
        $childrenByParent = [];

        foreach ($family as $member) {
            if ($member->parent_resource_id === null) {
                continue;
            }

            $childrenByParent[(int) $member->parent_resource_id][] = $member;
        }

        foreach ($childrenByParent as $parentResourceId => $children) {
            usort($children, fn (IgsnMetadata $left, IgsnMetadata $right): int => $this->compareNodes($left, $right));
            $childrenByParent[$parentResourceId] = $children;
        }

        return $childrenByParent;
    }

    /**
     * @param  array<int, list<IgsnMetadata>>  $childrenByParent
     * @param  array<int, true>  $rendered
     * @return array{
     *     resource_id: int,
     *     name: string|null,
     *     igsn: string|null,
     *     sample_type: string|null,
     *     landing_page: array{public_url: string}|null,
     *     children: array<mixed>
     * }
     */
    private function buildNode(IgsnMetadata $metadata, array $childrenByParent, array &$rendered): array
    {
        $rendered[(int) $metadata->resource_id] = true;

        $children = [];
        foreach ($childrenByParent[(int) $metadata->resource_id] ?? [] as $child) {
            if (isset($rendered[(int) $child->resource_id])) {
                continue;
            }

            $children[] = $this->buildNode($child, $childrenByParent, $rendered);
        }

        $resource = $metadata->resource;
        $landingPage = $resource->landingPage;

        return [
            'resource_id' => (int) $resource->id,
            'name' => $this->sampleName($resource),
            'igsn' => is_string($resource->doi) ? IgsnIdentifier::handleFromDoi($resource->doi) : null,
            'sample_type' => is_string($metadata->sample_type) && trim($metadata->sample_type) !== ''
                ? trim($metadata->sample_type)
                : null,
            'landing_page' => $landingPage instanceof LandingPage && $landingPage->isPublished()
                ? ['public_url' => $landingPage->public_url]
                : null,
            'children' => $children,
        ];
    }

    private function compareNodes(IgsnMetadata $left, IgsnMetadata $right): int
    {
        $leftResource = $left->resource;
        $rightResource = $right->resource;
        $leftName = $this->sampleName($leftResource) ?? '';
        $rightName = $this->sampleName($rightResource) ?? '';
        $nameComparison = strnatcasecmp($leftName, $rightName);

        if ($nameComparison !== 0) {
            return $nameComparison;
        }

        $leftIgsn = is_string($leftResource->doi) ? (IgsnIdentifier::handleFromDoi($leftResource->doi) ?? '') : '';
        $rightIgsn = is_string($rightResource->doi) ? (IgsnIdentifier::handleFromDoi($rightResource->doi) ?? '') : '';
        $igsnComparison = strnatcasecmp($leftIgsn, $rightIgsn);

        return $igsnComparison !== 0
            ? $igsnComparison
            : ((int) $leftResource->id <=> (int) $rightResource->id);
    }

    private function sampleName(Resource $resource): ?string
    {
        $alternateIdentifiers = $resource->relationLoaded('alternateIdentifiers')
            ? $resource->alternateIdentifiers
            : new Collection;

        $name = $alternateIdentifiers
            ->sortBy('position')
            ->first(static fn (AlternateIdentifier $identifier): bool => strcasecmp($identifier->type, 'Local accession number') === 0)
            ?->value;

        if (! is_string($name)) {
            return null;
        }

        $name = trim($name);

        return $name !== '' ? $name : null;
    }

    /**
     * @return Builder<IgsnMetadata>
     */
    private function metadataQuery(): Builder
    {
        return IgsnMetadata::query()->with([
            'resource.alternateIdentifiers',
            'resource.landingPage.externalDomain',
        ]);
    }

    private function logCycle(int $seedResourceId, int $repeatedResourceId): void
    {
        Log::warning('Cycle detected while resolving IGSN sample family', [
            'seed_resource_id' => $seedResourceId,
            'repeated_resource_id' => $repeatedResourceId,
        ]);
    }
}
