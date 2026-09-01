<?php

declare(strict_types=1);

namespace App\Services\Igsn;

use App\Models\IgsnMetadata;
use App\Models\Resource;
use App\Services\BotProtection\LandingPageRenderDataCacheService;
use App\Services\IgsnDifXmlParser;
use App\Services\LegacyIgsnPortalService;
use App\Support\IgsnIdentifier;
use App\Support\LegacyIgsnDatacenterCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class IgsnLegacyDifBackfillService
{
    public function __construct(
        private readonly LegacyIgsnPortalService $legacyPortal,
        private readonly IgsnDifMetadataExtractor $extractor,
        private readonly IgsnDifXmlParser $parser,
        private readonly LandingPageRenderDataCacheService $landingPageCache,
    ) {}

    /**
     * @param  list<string>  $dois
     * @param  list<string>  $datacenters
     * @return array{
     *     scanned: int,
     *     changed: int,
     *     unchanged: int,
     *     manual_review: int,
     *     missing_dif: int,
     *     unknown_paths: int,
     *     errors: int,
     *     cache_invalidation_failures: int,
     *     last_scanned_resource_id: int|null,
     *     sync_resource_ids: list<int>,
     *     records: list<array<string, int|string|null>>
     * }
     */
    public function run(
        bool $apply = false,
        int $afterId = 0,
        int $limit = 0,
        int $chunk = 100,
        array $dois = [],
        array $datacenters = [],
    ): array {
        $chunk = max(1, min(100, $chunk));
        $limit = max(0, $limit);
        $cursor = max(0, $afterId);
        $doiFilter = $this->normalizeDoiFilter($dois);
        $datacenterNames = $this->normalizeDatacenterFilter($datacenters);
        /** @var array{
         *     scanned: int, changed: int, unchanged: int, manual_review: int,
         *     missing_dif: int, unknown_paths: int, errors: int,
         *     cache_invalidation_failures: int, last_scanned_resource_id: int|null,
         *     sync_resource_ids: list<int>, records: list<array<string, int|string|null>>
         * } $stats
         */
        $stats = [
            'scanned' => 0,
            'changed' => 0,
            'unchanged' => 0,
            'manual_review' => 0,
            'missing_dif' => 0,
            'unknown_paths' => 0,
            'errors' => 0,
            'cache_invalidation_failures' => 0,
            'last_scanned_resource_id' => null,
            'sync_resource_ids' => [],
            'records' => [],
        ];

        while ($limit === 0 || $stats['scanned'] < $limit) {
            $batchSize = $limit === 0 ? $chunk : min($chunk, $limit - $stats['scanned']);
            $resources = Resource::query()
                ->with([
                    'igsnMetadata',
                    'datacenter',
                    'landingPage',
                    'relatedIdentifiers.identifierType',
                    'relatedIdentifiers.relationType',
                    'fundingReferences',
                    'dates.dateType',
                ])
                ->whereHas('igsnMetadata')
                ->where('id', '>', $cursor)
                ->when($doiFilter !== [], fn (Builder $query): Builder => $query->whereIn('doi', $doiFilter))
                ->when(
                    $datacenterNames !== [],
                    fn (Builder $query): Builder => $query->whereHas(
                        'datacenter',
                        fn (Builder $datacenter): Builder => $datacenter->whereIn('name', $datacenterNames),
                    ),
                )
                ->orderBy('id')
                ->limit($batchSize)
                ->get();

            if ($resources->isEmpty()) {
                break;
            }

            $cursor = (int) $resources->last()->id;
            $stats['last_scanned_resource_id'] = $cursor;
            $byHandle = [];

            foreach ($resources as $resource) {
                $stats['scanned']++;
                $handle = is_string($resource->doi) ? IgsnIdentifier::handleFromDoi($resource->doi) : null;
                if ($handle === null) {
                    $stats['errors']++;
                    $stats['records'][] = $this->emptyRecord($resource, '', 'error', 'Invalid IGSN DOI.');

                    continue;
                }
                $byHandle[$handle] = $resource;
            }

            if ($byHandle === []) {
                continue;
            }

            try {
                $documents = $this->legacyPortal->difForHandles(array_keys($byHandle));
            } catch (Throwable $exception) {
                Log::warning('Legacy IGSN DIF backfill batch failed', [
                    'handles' => array_keys($byHandle),
                    'error_class' => $exception::class,
                ]);
                foreach ($byHandle as $handle => $resource) {
                    $stats['errors']++;
                    $stats['records'][] = $this->emptyRecord($resource, $handle, 'error', 'Legacy DIF request failed.');
                }

                continue;
            }

            foreach ($byHandle as $handle => $resource) {
                $difXml = $documents[$handle] ?? null;
                if (! is_string($difXml)) {
                    $stats['missing_dif']++;
                    $stats['records'][] = $this->emptyRecord($resource, $handle, 'missing_dif', 'No DIF metadata returned.');

                    continue;
                }

                try {
                    $metadata = $this->extractor->extract($difXml);
                    if ($metadata === null) {
                        throw new RuntimeException('DIF metadata does not contain a readable sample.');
                    }

                    $diff = $this->diff($resource, $metadata);
                    $syncEligible = $this->isDataCiteSyncEligible($resource);
                    if ($apply && $diff['changed']) {
                        $this->apply((int) $resource->id, $difXml);
                        if ($syncEligible) {
                            $stats['sync_resource_ids'][] = (int) $resource->id;
                        }
                        if (! $this->invalidateLandingPage($resource)) {
                            $stats['cache_invalidation_failures']++;
                        }
                    }

                    if ($diff['changed']) {
                        $stats['changed']++;
                    } else {
                        $stats['unchanged']++;
                    }
                    if ($diff['conflicts'] !== []) {
                        $stats['manual_review']++;
                    }
                    $stats['unknown_paths'] += count($metadata['legacy_dif']['unknown_paths']);
                    $stats['records'][] = [
                        'resource_id' => (int) $resource->id,
                        'doi' => (string) $resource->doi,
                        'handle' => $handle,
                        'datacenter' => $this->datacenterName($resource),
                        'schema_namespace' => (string) ($metadata['legacy_dif']['schema_namespace'] ?? ''),
                        'status' => $diff['changed'] ? ($apply ? 'updated' : 'would_update') : 'unchanged',
                        'changed_fields' => implode(' | ', $diff['changed_fields']),
                        'conflicts' => implode(' | ', $diff['conflicts']),
                        'unknown_paths' => implode(' | ', $metadata['legacy_dif']['unknown_paths']),
                        'missing_dif' => 0,
                        'datacite_sync_status' => $apply && $diff['changed'] && $syncEligible ? 'pending' : 'not_queued',
                        'sync_run_id' => null,
                        'message' => '',
                    ];
                } catch (Throwable $exception) {
                    $stats['errors']++;
                    $stats['records'][] = $this->emptyRecord($resource, $handle, 'error', $exception->getMessage());
                    Log::warning('Legacy IGSN DIF backfill record failed', [
                        'resource_id' => $resource->id,
                        'handle' => $handle,
                        'error_class' => $exception::class,
                    ]);
                }
            }
        }

        $stats['sync_resource_ids'] = array_values(array_unique($stats['sync_resource_ids']));

        return [
            'scanned' => $stats['scanned'],
            'changed' => $stats['changed'],
            'unchanged' => $stats['unchanged'],
            'manual_review' => $stats['manual_review'],
            'missing_dif' => $stats['missing_dif'],
            'unknown_paths' => $stats['unknown_paths'],
            'errors' => $stats['errors'],
            'cache_invalidation_failures' => $stats['cache_invalidation_failures'],
            'last_scanned_resource_id' => $stats['last_scanned_resource_id'],
            'sync_resource_ids' => $stats['sync_resource_ids'],
            'records' => $stats['records'],
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{changed: bool, changed_fields: list<string>, conflicts: list<string>}
     */
    private function diff(Resource $resource, array $metadata): array
    {
        $changedFields = [];
        $conflicts = array_map(
            static fn (array $conflict): string => (string) ($conflict['field'] ?? 'unknown'),
            $metadata['conflicts'],
        );
        $igsn = $resource->igsnMetadata;
        if ($igsn === null) {
            throw new RuntimeException('IGSN metadata relation is missing.');
        }

        if ($this->legacyPayloadHasMissingValues($igsn->legacy_dif_json ?? [], $metadata['legacy_dif'])) {
            $changedFields[] = 'legacy_dif_json';
        }

        foreach ($metadata['scalars'] as $field => $value) {
            if ($value === null) {
                continue;
            }
            $existing = $igsn->{$field};
            if ($this->isEmpty($existing)) {
                $changedFields[] = 'igsn_metadata.'.$field;
            } elseif ($this->normalizeComparable($existing) !== $this->normalizeComparable($value)) {
                $prefix = $field === 'is_private' ? 'privacy:' : '';
                $conflicts[] = sprintf('%s%s (%s != %s)', $prefix, $field, $this->displayValue($existing), $this->displayValue($value));
            }
        }

        foreach ($metadata['root_related_identifiers'] as $identifier) {
            $doi = $this->normalizeDoi($identifier['identifier']);
            $exists = $resource->relatedIdentifiers->contains(
                fn ($related): bool => $related->identifierType->slug === 'DOI'
                    && $related->relationType->slug === 'Cites'
                    && $this->normalizeDoi((string) $related->identifier) === $doi,
            );
            if (! $exists) {
                $changedFields[] = 'related_identifiers';
                break;
            }
        }

        foreach ($metadata['funding_agencies'] as $funder) {
            if (! $resource->fundingReferences->contains(
                fn ($reference): bool => $this->normalizeComparable($reference->funder_name) === $this->normalizeComparable($funder),
            )) {
                $changedFields[] = 'funding_references';
                break;
            }
        }

        foreach (['publish_dates' => 'Available', 'sampling_dates' => 'Collected'] as $field => $type) {
            foreach ($metadata[$field] as $value) {
                if (! $resource->dates->contains(
                    fn ($date): bool => $date->dateType->slug === $type
                        && $this->normalizeDateComparable($date->date_value) === $this->normalizeDateComparable($value),
                )) {
                    $changedFields[] = 'dates.'.$type;
                    break 2;
                }
            }
        }

        $changedFields = array_values(array_unique($changedFields));
        $conflicts = array_values(array_unique($conflicts));

        return [
            'changed' => $changedFields !== [],
            'changed_fields' => $changedFields,
            'conflicts' => $conflicts,
        ];
    }

    private function apply(int $resourceId, string $difXml): void
    {
        DB::transaction(function () use ($resourceId, $difXml): void {
            $resource = Resource::query()->whereKey($resourceId)->lockForUpdate()->firstOrFail();
            $igsn = $resource->igsnMetadata()->lockForUpdate()->first();
            if ($igsn === null || ! $this->parser->enrichFromDifXml($difXml, $resource, $igsn, additive: true)) {
                throw new RuntimeException('Unable to apply the legacy DIF metadata.');
            }
        });
    }

    private function invalidateLandingPage(Resource $resource): bool
    {
        $landingPage = $resource->landingPage;
        if ($landingPage === null || ! $landingPage->isPublished()) {
            return true;
        }

        try {
            $this->landingPageCache->forgetById((int) $landingPage->id);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $existing
     * @param  array<string, mixed>  $incoming
     */
    private function legacyPayloadHasMissingValues(array $existing, array $incoming): bool
    {
        if ($existing === []) {
            return true;
        }

        $existingFields = [];
        foreach (is_array($existing['fields'] ?? null) ? $existing['fields'] : [] as $field) {
            $existingFields[$this->fieldKey($field)] = true;
        }
        foreach (is_array($incoming['fields'] ?? null) ? $incoming['fields'] : [] as $field) {
            if (! isset($existingFields[$this->fieldKey($field)])) {
                return true;
            }
        }

        return false;
    }

    private function fieldKey(mixed $field): string
    {
        if (! is_array($field)) {
            return md5((string) json_encode($field));
        }
        ksort($field);

        return hash('sha256', (string) json_encode($field, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @param list<string> $dois
     * @return list<string>
     */
    private function normalizeDoiFilter(array $dois): array
    {
        $normalized = [];
        foreach ($dois as $doi) {
            $value = IgsnIdentifier::normalizeInputToDoi($doi);
            if ($value === null) {
                throw new InvalidArgumentException(sprintf('Invalid IGSN DOI or handle filter: "%s".', $doi));
            }
            $normalized[$value] = true;
        }

        return array_keys($normalized);
    }

    /** @param list<string> $datacenters
     * @return list<string>
     */
    private function normalizeDatacenterFilter(array $datacenters): array
    {
        $names = [];
        foreach ($datacenters as $datacenter) {
            $key = strtoupper(trim($datacenter));
            if (! str_starts_with($key, 'IGSNDB.')) {
                $key = 'IGSNDB.'.$key;
            }
            $entry = LegacyIgsnDatacenterCatalog::find($key);
            if ($entry === null) {
                throw new InvalidArgumentException(sprintf('Unknown legacy IGSN datacenter: "%s".', $datacenter));
            }
            $names[$entry['name']] = true;
        }

        return array_keys($names);
    }

    private function normalizeDoi(string $value): string
    {
        return mb_strtolower(trim(preg_replace('#^(?:https?://(?:dx\.)?doi\.org/|doi:\s*)#i', '', $value) ?? $value));
    }

    private function normalizeComparable(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $value) ?? (string) $value));
    }

    private function normalizeDateComparable(mixed $value): string
    {
        $value = trim((string) $value);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $matches) === 1) {
            return sprintf('%04d-%02d-%02d', (int) $matches[1], (int) $matches[2], (int) $matches[3]);
        }

        return $value;
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private function displayValue(mixed $value): string
    {
        return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }

    private function isDataCiteSyncEligible(Resource $resource): bool
    {
        return is_string($resource->doi)
            && trim($resource->doi) !== ''
            && $resource->igsnMetadata?->upload_status === IgsnMetadata::STATUS_REGISTERED;
    }

    private function datacenterName(Resource $resource): string
    {
        if ($resource->datacenter_id === null) {
            return '';
        }

        $datacenter = $resource->datacenter;

        return $datacenter === null ? '' : (string) $datacenter->name;
    }

    /** @return array<string, int|string|null> */
    private function emptyRecord(Resource $resource, string $handle, string $status, string $message): array
    {
        return [
            'resource_id' => (int) $resource->id,
            'doi' => (string) $resource->doi,
            'handle' => $handle,
            'datacenter' => $this->datacenterName($resource),
            'schema_namespace' => '',
            'status' => $status,
            'changed_fields' => '',
            'conflicts' => '',
            'unknown_paths' => '',
            'missing_dif' => $status === 'missing_dif' ? 1 : 0,
            'datacite_sync_status' => 'not_queued',
            'sync_run_id' => null,
            'message' => $message,
        ];
    }
}
