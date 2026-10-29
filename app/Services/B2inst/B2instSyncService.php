<?php

declare(strict_types=1);

namespace App\Services\B2inst;

use App\Enums\CacheKey;
use App\Models\PidSetting;
use App\Models\ResourceInstrument;
use App\Services\ElmoCacheRefreshDispatcherService;
use App\Services\VocabularyCacheService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class B2instSyncService
{
    public const LOCK = 'b2inst:sync';

    public function __construct(
        private readonly B2instClient $client,
        private readonly B2instInstrumentNormalizer $normalizer,
        private readonly B2instSnapshotStore $store,
        private readonly VocabularyCacheService $vocabularies,
        private readonly ElmoCacheRefreshDispatcherService $dispatcher,
    ) {}

    /** @return array<string, mixed> */
    public function sync(bool $dryRun = false): array
    {
        $lock = Cache::lock(self::LOCK, 1200);
        if (! $lock->get()) {
            throw new RuntimeException('A B2INST synchronization is already running.');
        }
        try {
            $started = microtime(true);
            $this->client->startBudget();
            $old = $this->store->read();
            $snapshot = $this->client->records();
            $data = $provenance = $excluded = $pids = [];
            foreach ($snapshot['records'] as $record) {
                $this->client->remainingSeconds();
                $id = (string) $record['id'];
                try {
                    $instrument = $this->normalizer->normalize($record);
                } catch (InvalidArgumentException $exception) {
                    $excluded[] = ['id' => $id, 'reason' => $exception->getMessage()];

                    continue;
                }
                $pidKey = $this->pidKey($instrument['pidType'], $instrument['pid']);
                if (isset($pids[$pidKey])) {
                    throw new RuntimeException('Duplicate instrument PID for B2INST records '.$pids[$pidKey].' and '.$id);
                }
                $pids[$pidKey] = $id;
                $data[$id] = $instrument;
                $provenance[$id] = $this->normalizer->provenance($record);
            }
            /** @var list<array<string, mixed>> $oldData */
            $oldData = $old['data'] ?? [];
            if ($data === [] && $oldData !== []) {
                throw new RuntimeException('An empty B2INST candidate cannot replace an existing instrument list.');
            }
            $oldById = array_column($oldData, null, 'id');
            foreach ($excluded as $exclusion) {
                if (isset($oldById[$exclusion['id']])) {
                    throw new RuntimeException('Invalid B2INST record would remove an existing selection: '.$exclusion['id'].' ('.$exclusion['reason'].')');
                }
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
            $result = [
                'published' => ! $dryRun, 'changed' => $changed, 'itemCount' => count($data),
                'lastUpdated' => $changed ? $checkedAt : ($old['lastUpdated'] ?? $checkedAt),
                'checkedAt' => $checkedAt, 'serverCount' => $snapshot['total'], 'readCount' => count($snapshot['records']),
                'excluded' => $excluded, 'changes' => $changes,
                // Read-only audit: a refresh never rewrites dataset links.
                'storedLinksOutsideSelection' => $this->countUnlistedLinks($pids),
                'durationSeconds' => round(microtime(true) - $started, 3), 'refreshPending' => ! $dryRun,
            ];
            if ($dryRun) {
                return $result;
            }
            $payload = [
                'lastUpdated' => $result['lastUpdated'], 'total' => count($data), 'data' => array_values($data),
                '_sync' => $result + ['source' => $this->client->host(), 'signature' => $signature, 'records' => $provenance],
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
            Log::info('B2INST synchronization completed.', $result);

            return $result;
        } finally {
            $this->releaseLock($lock);
        }
    }

    public function retryCaches(): void
    {
        $lock = Cache::lock(self::LOCK, 1200);
        if (! $lock->get()) {
            throw new RuntimeException('A B2INST synchronization is already running.');
        }
        try {
            $snapshot = $this->store->read();
            if ($snapshot === null) {
                throw new RuntimeException('No B2INST snapshot has been published.');
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
            Log::warning('B2INST lock release failed; waiting for lease expiry.', ['error' => $exception->getMessage()]);
        }
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
