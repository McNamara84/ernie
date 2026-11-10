<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\ContributorType;
use App\Models\DateType;
use App\Models\DescriptionType;
use App\Models\IdentifierType;
use App\Models\Institution;
use App\Models\LandingPageDomain;
use App\Models\Language;
use App\Models\Person;
use App\Models\Publisher;
use App\Models\RelationType;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\TitleType;
use App\Services\DcatAp\DcatApRecordService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class DcatApSharedMetadataObserver
{
    /** @var \WeakMap<Model, list<int>>|null */
    private static ?\WeakMap $deletingResources = null;

    public function saved(Model $model): void
    {
        if (! $model->wasRecentlyCreated) {
            $this->schedule($model);
        }
    }

    public function deleting(Model $model): void
    {
        self::$deletingResources ??= new \WeakMap;
        self::$deletingResources[$model] = $this->resourceIds($model);
    }

    public function deleted(Model $model): void
    {
        foreach (self::$deletingResources[$model] ?? [] as $resourceId) {
            app(DcatApRecordService::class)->schedule($resourceId);
        }
        unset(self::$deletingResources[$model]);
    }

    private function schedule(Model $model): void
    {
        foreach ($this->resourceIds($model) as $resourceId) {
            app(DcatApRecordService::class)->schedule($resourceId);
        }
    }

    /** @return list<int> */
    private function resourceIds(Model $model): array
    {
        if (! config('dcatap.enabled', false)) {
            return [];
        }
        $query = Resource::query();
        $relation = match ($model::class) {
            Publisher::class => 'publisher',
            ResourceType::class => 'resourceType',
            Language::class => 'language',
            Right::class => 'resourceRights.right',
            TitleType::class => 'titles.titleType',
            DescriptionType::class => 'descriptions.descriptionType',
            DateType::class => 'dates.dateType',
            ContributorType::class => 'contributors.contributorTypes',
            RelationType::class => 'relatedIdentifiers.relationType',
            IdentifierType::class => 'relatedIdentifiers.identifierType',
            LandingPageDomain::class => 'landingPage.externalDomain',
            default => null,
        };
        if ($relation !== null) {
            $query->whereHas($relation, fn (Builder $related) => $related->whereKey($model->getKey()));
        } elseif ($model instanceof Person || $model instanceof Institution) {
            $query->where(fn (Builder $parties) => $parties
                ->whereHas('creators', fn (Builder $q) => $q->where('creatorable_type', $model->getMorphClass())->where('creatorable_id', $model->getKey()))
                ->orWhereHas('contributors', fn (Builder $q) => $q->where('contributorable_type', $model->getMorphClass())->where('contributorable_id', $model->getKey())));
        } else {
            return [];
        }

        return array_values($query->pluck('resources.id')->map(static fn ($id): int => (int) $id)->all());
    }
}
