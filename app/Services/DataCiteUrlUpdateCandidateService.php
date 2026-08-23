<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DataCiteUrlUpdateScope;
use App\Models\IgsnMetadata;
use App\Models\Resource;
use Illuminate\Database\Eloquent\Builder;

class DataCiteUrlUpdateCandidateService
{
    /**
     * Iterate the authoritative local allow-list. DataCite is never used to
     * discover identifiers or to widen this candidate set.
     *
     * @param  callable(Resource): void  $callback
     */
    public function each(DataCiteUrlUpdateScope $scope, callable $callback): void
    {
        $this->query($scope)->chunkById(250, function ($resources) use ($scope, $callback): void {
            foreach ($resources as $resource) {
                if ($this->isEligible($resource, $scope)) {
                    $callback($resource);
                }
            }
        });
    }

    /** @return array{total: int, sample: list<Resource>} */
    public function summarize(DataCiteUrlUpdateScope $scope, int $sampleSize = 10): array
    {
        $total = 0;
        $sample = [];

        $this->each($scope, function (Resource $resource) use (&$total, &$sample, $sampleSize): void {
            $total++;
            if (count($sample) < $sampleSize) {
                $sample[] = $resource;
            }
        });

        return ['total' => $total, 'sample' => $sample];
    }

    public function isEligible(Resource $resource, DataCiteUrlUpdateScope $scope): bool
    {
        $resource->loadMissing([
            'resourceType',
            'igsnMetadata',
            'landingPage',
            'titles.titleType',
            'creators',
            'rights',
            'descriptions.descriptionType',
            'dates.dateType',
        ]);

        $identifier = trim((string) $resource->doi);
        $landingPage = $resource->landingPage;

        if ($identifier === '' || $landingPage === null || ! $landingPage->is_published || $landingPage->isExternal()) {
            return false;
        }

        if ($scope === DataCiteUrlUpdateScope::IGSNS) {
            return $resource->igsnMetadata?->upload_status === IgsnMetadata::STATUS_REGISTERED;
        }

        return $resource->igsnMetadata === null
            && ! $resource->isIgsn()
            && $resource->publicStatus() === 'published';
    }

    /** @return Builder<Resource> */
    private function query(DataCiteUrlUpdateScope $scope): Builder
    {
        $query = Resource::query()
            ->whereNotNull('doi')
            ->whereRaw("TRIM(doi) != ''")
            ->whereHas('landingPage', fn (Builder $landingPage): Builder => $landingPage
                ->where('is_published', true)
                ->where('template', '!=', 'external'))
            ->with([
                'resourceType',
                'igsnMetadata',
                'landingPage',
                'titles.titleType',
                'creators',
                'rights',
                'descriptions.descriptionType',
                'dates.dateType',
            ])
            ->orderBy('id');

        if ($scope === DataCiteUrlUpdateScope::IGSNS) {
            return $query->whereHas('igsnMetadata', fn (Builder $metadata): Builder => $metadata
                ->where('upload_status', IgsnMetadata::STATUS_REGISTERED));
        }

        return $query
            ->whereDoesntHave('igsnMetadata')
            ->whereDoesntHave('resourceType', fn (Builder $type): Builder => $type->where('slug', 'physical-object'));
    }
}
