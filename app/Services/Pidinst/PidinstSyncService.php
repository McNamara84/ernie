<?php

declare(strict_types=1);

namespace App\Services\Pidinst;

use App\Enums\CacheKey;
use App\Exceptions\UnregisteredInstrumentHandle;
use App\Models\PidSetting;
use App\Models\ResourceInstrument;
use App\Services\B2inst\B2instClient;
use App\Services\B2inst\B2instInstrumentNormalizer;
use App\Services\B2inst\B2instPidResolver;
use App\Services\ElmoCacheRefreshDispatcherService;
use App\Services\VocabularyCacheService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class PidinstSyncService
{
    public const LOCK = 'b2inst:sync';

    public function __construct(
        private readonly B2instClient $client,
        private readonly B2instInstrumentNormalizer $normalizer,
        private readonly PidinstSnapshotStore $store,
        private readonly VocabularyCacheService $vocabularies,
        private readonly ElmoCacheRefreshDispatcherService $dispatcher,
        private readonly DataCiteInstrumentClient $dataCite,
        private readonly DataCiteInstrumentNormalizer $dataCiteNormalizer,
    ) {}

    /** @return array<string, mixed> */
    public function sync(bool $dryRun = false): array
    {
        $lock = Cache::lock(self::LOCK, 1200);
        if (! $lock->get()) {
            throw new RuntimeException('A PIDINST synchronization is already running.');
        }
        try {
            $started = microtime(true);
            $deadline = $started + min(1100, max(1, (int) (config('pidinst.max_duration') ?? config('b2inst.max_duration', 900))));
            $this->client->startBudget($deadline);
            $this->dataCite->startBudget($deadline);
            $old = $this->store->read();
            /** @var list<array<string, mixed>> $oldData */
            $oldData = $old['data'] ?? [];
            $oldById = array_column($oldData, null, 'id');
            $dataCiteSnapshot = $this->dataCite->records();
            $data = $provenance = $excluded = $pids = $sources = [];
            $deduplicatedCount = 0;
            foreach (['datacite', 'b2inst'] as $source) {
                $snapshot = $source === 'b2inst' ? $this->client->records() : $dataCiteSnapshot;
                $oldIds = $this->previousSourceIds($old ?? [], $source);
                if ($snapshot['total'] === 0 && ($oldIds !== [] || data_get($old, '_sync.sources.'.$source.'.acceptedCount', 0) > 0)) {
                    throw new RuntimeException('An empty '.strtoupper($source).' source cannot replace its existing instrument selection.');
                }
                $sourcePids = [];
                $excludedBefore = count($excluded);
                foreach ($snapshot['records'] as $record) {
                    $this->client->remainingSeconds();
                    $id = $source === 'datacite' ? 'datacite:'.DataCiteInstrumentNormalizer::doi($record['id']) : (string) $record['id'];
                    if ($source === 'b2inst' && str_starts_with($id, 'datacite:')) {
                        throw new RuntimeException('B2INST record ID uses the reserved DataCite prefix.');
                    }
                    try {
                        $instrument = $source === 'datacite' ? $this->dataCiteNormalizer->normalize($record) : $this->normalizer->normalize($record);
                    } catch (InvalidArgumentException $exception) {
                        if (isset($oldIds[$id]) && ! $exception instanceof UnregisteredInstrumentHandle) {
                            throw new RuntimeException('Invalid '.strtoupper($source).' record would remove an existing selection: '.$id.' ('.$exception->getMessage().')', previous: $exception);
                        }
                        $excluded[] = [
                            'id' => $id, 'source' => $source, 'reason' => $exception->getMessage(),
                            'reasonCode' => $exception instanceof UnregisteredInstrumentHandle ? UnregisteredInstrumentHandle::REASON : 'invalid_record',
                        ];

                        continue;
                    }
                    $pidKey = $this->pidKey($instrument['pidType'], $instrument['pid']);
                    if (isset($sourcePids[$pidKey])) {
                        throw new RuntimeException('Duplicate instrument PID for '.strtoupper($source).' records '.$sourcePids[$pidKey].' and '.$id);
                    }
                    $sourcePids[$pidKey] = $id;
                    $origins = [['source' => $source, 'id' => $id]];
                    if (isset($pids[$pidKey])) {
                        // DataCite is read first; B2INST is authoritative for an identical DOI.
                        $otherId = $pids[$pidKey];
                        $origins = array_merge($origins, $provenance[$otherId]['origins']);
                        unset($data[$otherId], $provenance[$otherId]);
                        $deduplicatedCount++;
                    }
                    $pids[$pidKey] = $id;
                    $data[$id] = $instrument;
                    $provenance[$id] = ($source === 'b2inst' ? $this->normalizer->provenance($record) : ['id' => $id])
                        + ['source' => $source, 'origins' => $origins];
                }
                $sources[$source] = [
                    'endpoint' => $source === 'b2inst' ? $this->client->host() : $this->dataCite->host(),
                    'serverCount' => $snapshot['total'], 'readCount' => count($snapshot['records']),
                    'acceptedCount' => count($sourcePids), 'excludedCount' => count($excluded) - $excludedBefore,
                ];
            }
            if ($data === [] && $oldData !== []) {
                throw new RuntimeException('An empty PIDINST candidate cannot replace an existing instrument list.');
            }
            ksort($data);
            ksort($provenance);
            $changes = ['added' => 0, 'changed' => 0, 'removed' => count(array_diff_key($oldById, $data)), 'pidChanges' => []];
            foreach ($data as $id => $instrument) {
                if (! isset($oldById[$id])) {
                    $changes['added']++;
                } elseif ($oldById[$id] != $instrument) {
                    $changes['changed']++;
                    if (($oldById[$id]['pid'] ?? null) !== $instrument['pid']) {
                        $changes['pidChanges'][] = ['id' => $id, 'from' => $oldById[$id]['pid'] ?? null, 'to' => $instrument['pid']];
                    }
                }
            }
            $signature = hash('sha256', json_encode([$data, $provenance], JSON_THROW_ON_ERROR));
            $changed = data_get($old, '_sync.signature') !== $signature;
            $checkedAt = now()->toIso8601String();
            foreach ($sources as &$sourceStatus) {
                $sourceStatus['checkedAt'] = $checkedAt;
            }
            unset($sourceStatus);
            $result = [
                'published' => ! $dryRun, 'changed' => $changed, 'itemCount' => count($data),
                'lastUpdated' => $changed ? $checkedAt : ($old['lastUpdated'] ?? $checkedAt),
                'checkedAt' => $checkedAt, 'serverCount' => array_sum(array_column($sources, 'serverCount')),
                'readCount' => array_sum(array_column($sources, 'readCount')), 'sources' => $sources,
                'deduplicatedCount' => $deduplicatedCount,
                'excluded' => $excluded, 'changes' => $changes,
                // Read-only audit: a refresh never rewrites dataset links.
                'storedLinksOutsideSelection' => $this->countUnlistedLinks($pids),
                'durationSeconds' => round(microtime(true) - $started, 3), 'refreshPending' => ! $dryRun,
            ];
            $this->client->remainingSeconds();
            if ($dryRun) {
                return $result;
            }
            $payload = [
                'lastUpdated' => $result['lastUpdated'], 'total' => count($data), 'data' => array_values($data),
                '_sync' => $result + ['version' => 2, 'signature' => $signature, 'records' => $provenance],
            ];
            $this->store->publish($payload, $changed);
            try {
                $this->refreshCaches();
                $payload['_sync']['refreshPending'] = false;
                $this->store->publish($payload);
                $result['refreshPending'] = false;
            } catch (Throwable $exception) {
                $result['warning'] = 'Snapshot published; cache refresh remains pending. Run get-pid4inst-instruments --refresh-caches.';
                Log::warning($result['warning'], ['error' => $exception->getMessage()]);
            }
            Log::info('PIDINST synchronization completed.', $result);

            return $result;
        } finally {
            $this->releaseLock($lock);
        }
    }

    public function retryCaches(): void
    {
        $lock = Cache::lock(self::LOCK, 1200);
        if (! $lock->get()) {
            throw new RuntimeException('A PIDINST synchronization is already running.');
        }
        try {
            $snapshot = $this->store->read();
            if ($snapshot === null) {
                throw new RuntimeException('No PIDINST snapshot has been published.');
            }
            $this->refreshCaches();
            $snapshot['_sync']['refreshPending'] = false;
            $this->store->publish($snapshot);
        } finally {
            $this->releaseLock($lock);
        }
    }

    private function refreshCaches(): void
    {
        $this->vocabularies->invalidateVocabularyCache(CacheKey::PID4INST_INSTRUMENTS);
        $setting = PidSetting::where('type', PidSetting::TYPE_PID4INST)->first();
        if ($setting !== null) {
            $this->dispatcher->dispatchForDataUpdate($setting, throwOnFailure: true);
        }
    }

    private function releaseLock(Lock $lock): void
    {
        try {
            $lock->release();
        } catch (Throwable $exception) {
            // Cache outages after publication must not turn a committed snapshot
            // into an apparent import failure. The lease still expires normally.
            Log::warning('PIDINST lock release failed; waiting for lease expiry.', ['error' => $exception->getMessage()]);
        }
    }

    /** @param array<string, mixed> $snapshot
     * @return array<string, true>
     */
    private function previousSourceIds(array $snapshot, string $source): array
    {
        $ids = [];
        foreach ($snapshot['data'] ?? [] as $record) {
            $id = $record['id'] ?? '';
            if (($source === 'datacite') === str_starts_with($id, 'datacite:')) {
                $ids[$id] = true;
            }
            foreach ($snapshot['_sync']['records'][$id]['origins'] ?? [] as $origin) {
                if (($origin['source'] ?? null) === $source) {
                    $ids[$origin['id']] = true;
                }
            }
        }

        return $ids;
    }

    private function pidKey(string $type, string $pid): string
    {
        $value = match ($type) {
            'Handle' => B2instPidResolver::handle($pid),
            'DOI' => preg_replace('~^https?://(?:dx\.)?doi\.org/~i', '', trim($pid)) ?? $pid,
            default => $pid,
        };

        return $type.':'.($type === 'URL' ? $value : strtolower($value));
    }

    /** @param array<string, string> $pids */
    private function countUnlistedLinks(array $pids): int
    {
        // Compare identity, including legacy resolver URLs, without rewriting links
        // or constructing a WHERE NOT IN clause beyond the database parameter limit.
        return ResourceInstrument::query()->select(['instrument_pid', 'instrument_pid_type'])->cursor()
            ->filter(function (ResourceInstrument $link) use ($pids): bool {
                try {
                    return ! isset($pids[$this->pidKey($link->instrument_pid_type, $link->instrument_pid)]);
                } catch (InvalidArgumentException) {
                    return true;
                }
            })->count();
    }
}
