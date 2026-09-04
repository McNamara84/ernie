<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\PortalCacheArea;
use App\Enums\PortalScope;
use App\Models\Datacenter;
use App\Models\DescriptionType;
use App\Models\Institution;
use App\Models\LandingPageDomain;
use App\Models\LandingPageTemplate;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\TitleType;
use App\Services\PortalCacheInvalidationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Invalidates each affected portal scope once for shared lookup changes. */
final class PortalSharedDependencyObserver
{
    public function __construct(
        private readonly PortalCacheInvalidationService $cacheInvalidationService,
    ) {}

    public function saved(Model $model): void
    {
        if (! $this->savedChangeAffectsPortal($model)) {
            return;
        }

        $this->schedule($model);
    }

    public function deleted(Model $model): void
    {
        $this->schedule($model);
    }

    private function schedule(Model $model): void
    {
        [$scopes, $areas] = $this->impact($model);
        if ($scopes === []) {
            return;
        }

        $this->cacheInvalidationService->schedule($scopes, $areas);
    }

    private function savedChangeAffectsPortal(Model $model): bool
    {
        if ($model->wasRecentlyCreated) {
            return true;
        }

        return match (true) {
            $model instanceof LandingPageTemplate => $model->wasChanged('citation_author_display_limit'),
            $model instanceof LandingPageDomain => $model->wasChanged('domain'),
            $model instanceof ResourceType => $model->wasChanged(['name', 'slug']),
            $model instanceof Datacenter => $model->wasChanged('name'),
            $model instanceof Person => $model->wasChanged(['family_name', 'given_name']),
            $model instanceof Institution => $model->wasChanged('name'),
            $model instanceof TitleType,
            $model instanceof DescriptionType => $model->wasChanged('slug'),
            default => false,
        };
    }

    /**
     * @return array{0: list<PortalScope>, 1: list<PortalCacheArea>}
     */
    private function impact(Model $model): array
    {
        if ($model instanceof LandingPageTemplate) {
            return [PortalScope::cases(), [PortalCacheArea::PAGE]];
        }

        if ($model instanceof LandingPageDomain) {
            return [PortalScope::cases(), [PortalCacheArea::PAGE, PortalCacheArea::MAP_PAYLOAD]];
        }

        if ($model instanceof ResourceType) {
            $slugChanged = $model->wasChanged('slug');

            return [
                $slugChanged
                    ? PortalScope::cases()
                    : [$model->slug === PortalScope::PHYSICAL_SAMPLE_RESOURCE_TYPE ? PortalScope::IGSN : PortalScope::DOI],
                $slugChanged
                    ? PortalCacheArea::all()
                    : [
                        PortalCacheArea::PAGE,
                        PortalCacheArea::RESOURCE_TYPE_FACETS,
                        PortalCacheArea::IGSN_FACETS,
                        PortalCacheArea::MAP_PAYLOAD,
                    ],
            ];
        }

        $resourceQuery = Resource::query()->whereHas(
            'landingPage',
            static fn (Builder $query): Builder => $query->where('is_published', true),
        );

        if ($model instanceof Datacenter) {
            $resourceQuery->where('datacenter_id', $model->getKey());

            return [
                $this->scopesFor($resourceQuery),
                [
                    PortalCacheArea::PAGE,
                    PortalCacheArea::COUNT,
                    PortalCacheArea::DATACENTER_FACETS,
                    PortalCacheArea::IGSN_FACETS,
                    PortalCacheArea::MAP_PAYLOAD,
                    PortalCacheArea::MAP_EXTENT,
                ],
            ];
        }

        if ($model instanceof Person || $model instanceof Institution) {
            $resourceQuery->whereHas('creators', static function (Builder $query) use ($model): void {
                $query
                    ->where('creatorable_type', $model::class)
                    ->where('creatorable_id', $model->getKey());
            });

            return [
                $this->scopesFor($resourceQuery),
                [
                    PortalCacheArea::PAGE,
                    PortalCacheArea::COUNT,
                    PortalCacheArea::MAP_PAYLOAD,
                    PortalCacheArea::MAP_EXTENT,
                ],
            ];
        }

        if ($model instanceof TitleType) {
            return [PortalScope::cases(), [
                PortalCacheArea::PAGE,
                PortalCacheArea::COUNT,
                PortalCacheArea::MAP_PAYLOAD,
            ]];
        }

        if ($model instanceof DescriptionType) {
            return [PortalScope::cases(), [PortalCacheArea::PAGE, PortalCacheArea::COUNT]];
        }

        return [[], []];
    }

    /**
     * @param  Builder<Resource>  $query
     * @return list<PortalScope>
     */
    private function scopesFor(Builder $query, bool $includeBothScopes = false): array
    {
        if (! $query->exists()) {
            return [];
        }

        if ($includeBothScopes) {
            return PortalScope::cases();
        }

        /** @var list<PortalScope> $scopes */
        $scopes = $query
            ->distinct()
            ->pluck('resource_type_id')
            ->map(fn (mixed $resourceTypeId): PortalScope => $this->cacheInvalidationService->scopeForResourceTypeId(
                is_numeric($resourceTypeId) ? (int) $resourceTypeId : null,
            ))
            ->unique(static fn (PortalScope $scope): string => $scope->value)
            ->values()
            ->all();

        return $scopes;
    }
}
