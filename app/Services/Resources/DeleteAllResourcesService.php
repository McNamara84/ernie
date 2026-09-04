<?php

declare(strict_types=1);

namespace App\Services\Resources;

use App\Enums\PortalCacheArea;
use App\Enums\PortalScope;
use App\Models\Affiliation;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Publisher;
use App\Models\Resource;
use App\Models\ResourceAssessment;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Services\Assessment\AssessmentAverageSummaryVersionService;
use App\Services\PortalCacheInvalidationService;
use App\Services\ResourceCacheService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class DeleteAllResourcesService
{
    public function __construct(
        private readonly ResourceCacheService $resourceCacheService,
        private readonly PortalCacheInvalidationService $portalCacheInvalidationService,
        private readonly AssessmentAverageSummaryVersionService $assessmentSummaryVersionService,
    ) {}

    public function deleteAll(): int
    {
        $deletedResources = 0;
        $hadAssessments = false;

        DB::transaction(function () use (&$deletedResources, &$hadAssessments): void {
            $managedIgsnImagePaths = $this->managedIgsnSampleImagePaths();

            $this->trackPublishedResourcesAsDeleted();

            $hadAssessments = ResourceAssessment::query()->exists();
            $deletedResources = Resource::withoutEvents(fn (): int => Resource::query()->delete());

            $this->deleteOrphanedAffiliations();
            $this->deleteOrphanedPeopleAndPublishers();
            $this->deleteManagedIgsnSampleImagesAfterCommit($managedIgsnImagePaths);
        });

        if ($deletedResources > 0) {
            $this->invalidateCaches();

            if ($hadAssessments) {
                $this->assessmentSummaryVersionService->bump();
            }
        }

        return $deletedResources;
    }

    /**
     * @return list<string>
     */
    private function managedIgsnSampleImagePaths(): array
    {
        $paths = DB::table('igsn_metadata')
            ->whereNotNull('sample_image_storage_path')
            ->where('sample_image_storage_path', '!=', '')
            ->distinct()
            ->pluck('sample_image_storage_path')
            ->filter(static fn (mixed $path): bool => is_string($path) && $path !== '')
            ->map(static fn (mixed $path): string => (string) $path)
            ->values()
            ->all();

        return array_values($paths);
    }

    /**
     * @param  list<string>  $paths
     */
    private function deleteManagedIgsnSampleImagesAfterCommit(array $paths): void
    {
        if ($paths === []) {
            return;
        }

        $disk = (string) config('igsn_images.disk', 'public');

        DB::afterCommit(static function () use ($disk, $paths): void {
            Storage::disk($disk)->delete($paths);
        });
    }

    private function trackPublishedResourcesAsDeleted(): void
    {
        $timestamp = now();
        $identifierPrefix = (string) config('oaipmh.identifier_prefix');

        DB::table('resources')
            ->join('landing_pages', 'landing_pages.resource_id', '=', 'resources.id')
            ->leftJoin('resource_types', 'resource_types.id', '=', 'resources.resource_type_id')
            ->where('landing_pages.is_published', true)
            ->whereNotNull('resources.doi')
            ->where('resources.doi', '!=', '')
            ->select([
                'resources.doi',
                'resources.publication_year',
                'resource_types.slug as resource_type_slug',
            ])
            ->orderBy('resources.id')
            ->chunk(1000, function ($resources) use ($identifierPrefix, $timestamp): void {
                $records = [];

                foreach ($resources as $resource) {
                    $sets = [];

                    if ($resource->resource_type_slug !== null && $resource->resource_type_slug !== '') {
                        $sets[] = 'resourcetype:'.$resource->resource_type_slug;
                    }

                    if ($resource->publication_year !== null) {
                        $sets[] = 'year:'.$resource->publication_year;
                    }

                    $records[] = [
                        'oai_identifier' => $identifierPrefix.':'.$resource->doi,
                        'doi' => $resource->doi,
                        'datestamp' => $timestamp,
                        'sets' => json_encode($sets, JSON_THROW_ON_ERROR),
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ];
                }

                if ($records === []) {
                    return;
                }

                DB::table('oai_pmh_deleted_records')->upsert(
                    $records,
                    ['oai_identifier'],
                    ['doi', 'datestamp', 'sets', 'updated_at'],
                );
            });
    }

    private function deleteOrphanedAffiliations(): void
    {
        Affiliation::query()
            ->where('affiliatable_type', ResourceCreator::class)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('resource_creators')
                    ->whereColumn('resource_creators.id', 'affiliations.affiliatable_id');
            })
            ->delete();

        Affiliation::query()
            ->where('affiliatable_type', ResourceContributor::class)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('resource_contributors')
                    ->whereColumn('resource_contributors.id', 'affiliations.affiliatable_id');
            })
            ->delete();
    }

    private function deleteOrphanedPeopleAndPublishers(): void
    {
        Person::whereDoesntHave('resourceCreators')
            ->whereDoesntHave('resourceContributors')
            ->delete();

        Institution::whereDoesntHave('resourceCreators')
            ->whereDoesntHave('resourceContributors')
            ->delete();

        Publisher::whereDoesntHave('resources')->delete();
    }

    private function invalidateCaches(): void
    {
        $this->resourceCacheService->invalidateAllResourceCaches();
        $this->portalCacheInvalidationService->schedule(
            PortalScope::cases(),
            PortalCacheArea::all(),
        );
    }
}
