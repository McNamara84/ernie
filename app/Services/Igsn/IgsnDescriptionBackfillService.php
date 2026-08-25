<?php

declare(strict_types=1);

namespace App\Services\Igsn;

use App\Models\GeoLocation;
use App\Models\Resource;
use App\Services\BotProtection\LandingPageRenderDataCacheService;
use App\Services\LegacyIgsnPortalService;
use App\Support\IgsnIdentifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class IgsnDescriptionBackfillService
{
    public function __construct(
        private readonly LegacyIgsnPortalService $legacyPortal,
        private readonly IgsnDifMetadataExtractor $extractor,
        private readonly LandingPageRenderDataCacheService $landingPageCache,
    ) {}

    /**
     * @param  list<string>  $dois
     * @return array{scanned: int, changed: int, unchanged: int, missing_dif: int, errors: int, records: list<array{resource_id: int, doi: string, handle: string, status: string, descriptions_changed: bool, locality_changed: bool, message: string}>}
     */
    public function run(
        bool $apply = false,
        int $afterId = 0,
        int $limit = 0,
        int $chunk = 100,
        array $dois = [],
    ): array {
        $chunk = max(1, min(100, $chunk));
        $limit = max(0, $limit);
        $cursor = max(0, $afterId);
        $doiFilter = $this->normalizeDoiFilter($dois);
        $stats = [
            'scanned' => 0,
            'changed' => 0,
            'unchanged' => 0,
            'missing_dif' => 0,
            'errors' => 0,
            'records' => [],
        ];

        if ($dois !== [] && $doiFilter === []) {
            return $stats;
        }

        while ($limit === 0 || $stats['scanned'] < $limit) {
            $batchSize = $limit === 0 ? $chunk : min($chunk, $limit - $stats['scanned']);
            $resources = Resource::query()
                ->with(['igsnMetadata', 'geoLocations', 'landingPage'])
                ->whereHas('igsnMetadata')
                ->where('id', '>', $cursor)
                ->when($doiFilter !== [], fn (Builder $query): Builder => $query->whereIn('doi', $doiFilter))
                ->orderBy('id')
                ->limit($batchSize)
                ->get();

            if ($resources->isEmpty()) {
                break;
            }

            $cursor = (int) $resources->last()->id;
            $byHandle = [];
            foreach ($resources as $resource) {
                $stats['scanned']++;
                $handle = is_string($resource->doi) ? IgsnIdentifier::handleFromDoi($resource->doi) : null;
                if ($handle === null) {
                    $stats['errors']++;
                    $stats['records'][] = $this->record($resource, '', 'error', false, false, 'Invalid IGSN DOI.');

                    continue;
                }
                $byHandle[$handle] = $resource;
            }

            if ($byHandle === []) {
                continue;
            }

            try {
                $documents = $this->legacyPortal->difForHandles(array_keys($byHandle));
            } catch (\Throwable $exception) {
                Log::warning('IGSN description backfill batch failed', [
                    'handles' => array_keys($byHandle),
                    'error' => $exception->getMessage(),
                ]);
                foreach ($byHandle as $handle => $resource) {
                    $stats['errors']++;
                    $stats['records'][] = $this->record($resource, $handle, 'error', false, false, $exception->getMessage());
                }

                continue;
            }

            foreach ($byHandle as $handle => $resource) {
                $difXml = $documents[$handle] ?? null;
                if (! is_string($difXml)) {
                    $stats['missing_dif']++;
                    $stats['records'][] = $this->record($resource, $handle, 'missing_dif', false, false, 'No DIF metadata returned.');

                    continue;
                }

                try {
                    $fields = $this->extractor->extractDescriptionFields($difXml);
                    if ($fields === null) {
                        throw new \RuntimeException('DIF metadata does not contain a readable sample.');
                    }

                    $result = $this->updateResource($resource, $fields, $apply);
                    $changed = $result['descriptions_changed'] || $result['locality_changed'];
                    $stats[$changed ? 'changed' : 'unchanged']++;
                    $stats['records'][] = $this->record(
                        $resource,
                        $handle,
                        $changed ? ($apply ? 'updated' : 'would_update') : 'unchanged',
                        $result['descriptions_changed'],
                        $result['locality_changed'],
                        $result['message'],
                    );
                } catch (\Throwable $exception) {
                    $stats['errors']++;
                    $stats['records'][] = $this->record($resource, $handle, 'error', false, false, $exception->getMessage());
                    Log::warning('IGSN description backfill record failed', [
                        'resource_id' => $resource->id,
                        'handle' => $handle,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }

        return $stats;
    }

    /**
     * @param  array{description_groups: list<array{entries: list<array{value: string, scheme: string|null}>}>, material_descriptions: list<string>, locality_description: string|null}  $fields
     * @return array{descriptions_changed: bool, locality_changed: bool, message: string}
     */
    private function updateResource(Resource $resource, array $fields, bool $apply): array
    {
        $metadata = $resource->igsnMetadata;
        if ($metadata === null) {
            throw new \RuntimeException('Resource has no IGSN metadata.');
        }

        $descriptionJson = $metadata->description_json ?? [];
        if ($fields['description_groups'] === []) {
            unset($descriptionJson['description_groups'], $descriptionJson['material_descriptions']);
        } else {
            $descriptionJson['description_groups'] = $fields['description_groups'];
            $descriptionJson['material_descriptions'] = $fields['material_descriptions'];
        }
        $normalizedDescriptionJson = $descriptionJson !== [] ? $descriptionJson : null;
        $descriptionsChanged = $normalizedDescriptionJson !== $metadata->description_json;

        $location = $resource->geoLocations->first();
        $incomingLocality = $fields['locality_description'];
        $localityChanged = $incomingLocality !== null
            && ($location === null || $location->locality_description === null || trim($location->locality_description) === '');
        $message = $incomingLocality !== null
            && $location !== null
            && is_string($location->locality_description)
            && trim($location->locality_description) !== ''
            && $location->locality_description !== $incomingLocality
                ? 'Existing locality description was preserved.'
                : '';

        if (! $apply || (! $descriptionsChanged && ! $localityChanged)) {
            return [
                'descriptions_changed' => $descriptionsChanged,
                'locality_changed' => $localityChanged,
                'message' => $message,
            ];
        }

        DB::transaction(function () use ($metadata, $normalizedDescriptionJson, $descriptionsChanged, $resource, $location, $incomingLocality, $localityChanged): void {
            if ($descriptionsChanged) {
                $metadata->description_json = $normalizedDescriptionJson;
                $metadata->save();
            }

            if ($localityChanged) {
                if ($location === null) {
                    GeoLocation::create([
                        'resource_id' => $resource->id,
                        'locality_description' => $incomingLocality,
                    ]);
                } else {
                    $location->locality_description = $incomingLocality;
                    $location->save();
                }
            }
        });

        $landingPage = $resource->landingPage;
        if ($landingPage !== null && $landingPage->isPublished()) {
            $this->landingPageCache->forgetById((int) $landingPage->id);
        }

        return [
            'descriptions_changed' => $descriptionsChanged,
            'locality_changed' => $localityChanged,
            'message' => $message,
        ];
    }

    /** @param list<string> $dois @return list<string> */
    private function normalizeDoiFilter(array $dois): array
    {
        $normalized = [];
        foreach ($dois as $doi) {
            $value = IgsnIdentifier::normalizeInputToDoi($doi);
            if ($value !== null) {
                $normalized[$value] = true;
            }
        }

        return array_keys($normalized);
    }

    /**
     * @return array{resource_id: int, doi: string, handle: string, status: string, descriptions_changed: bool, locality_changed: bool, message: string}
     */
    private function record(Resource $resource, string $handle, string $status, bool $descriptionsChanged, bool $localityChanged, string $message): array
    {
        return [
            'resource_id' => (int) $resource->id,
            'doi' => (string) $resource->doi,
            'handle' => $handle,
            'status' => $status,
            'descriptions_changed' => $descriptionsChanged,
            'locality_changed' => $localityChanged,
            'message' => $message,
        ];
    }
}
