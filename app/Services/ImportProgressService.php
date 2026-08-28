<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ImportProgressService
{
    private const LOCK_TTL_SECONDS = 15;

    private const LOCK_WAIT_SECONDS = 5;

    /** @var list<int> */
    private const LOCK_RETRY_BACKOFF_MILLISECONDS = [100, 250];

    public const TYPE_RESOURCE = 'resource';

    public const TYPE_IGSN = 'igsn';

    /** @return array<string, mixed>|null */
    public function get(string $type, string $importId): ?array
    {
        $progress = Cache::get($this->progressKey($type, $importId));

        return is_array($progress) ? $progress : null;
    }

    /** @param array<string, mixed> $values */
    public function update(string $type, string $importId, array $values): void
    {
        $key = $this->progressKey($type, $importId);

        $this->withProgressLock($type, $importId, 'update', function () use ($key, $values): void {
            $progress = Cache::get($key, []);

            foreach ($values as $name => $value) {
                $progress[$name] = $value;
            }

            if (array_key_exists('sync_full_metadata_total', $values)) {
                unset($progress['sync_full_metadata_resource_ids']);
            }

            Cache::put($key, $progress, now()->addHours(24));
        });
    }

    /**
     * @param  list<int>  $resourceIds
     * @param  list<int>  $fullMetadataResourceIds
     */
    public function beginSync(
        string $type,
        string $importId,
        array $resourceIds,
        bool $retry = false,
        array $fullMetadataResourceIds = [],
    ): void {
        $resourceIds = array_values(array_unique(array_map('intval', $resourceIds)));
        $fullMetadataResourceIds = array_values(array_intersect(
            $resourceIds,
            array_unique(array_map('intval', $fullMetadataResourceIds)),
        ));

        if ($retry) {
            Cache::put($this->failureIdsKey($type, $importId), [], now()->addHours(24));
        } else {
            Cache::forget($this->failureIdsKey($type, $importId));
        }
        Cache::put($this->pendingIdsKey($type, $importId), $resourceIds, now()->addHours(24));
        Cache::put(
            $this->fullMetadataIdsKey($type, $importId),
            $fullMetadataResourceIds,
            now()->addHours(24),
        );

        $this->update($type, $importId, [
            'status' => 'running',
            'phase' => 'syncing',
            'sync_total' => count($resourceIds),
            'sync_processed' => 0,
            'sync_succeeded' => 0,
            'sync_failed' => 0,
            'sync_errors' => [],
            'sync_full_metadata_total' => count($fullMetadataResourceIds),
            'sync_skipped_test_mode' => false,
            'sync_retry_available' => false,
            'sync_retry' => $retry,
            'completed_at' => null,
        ]);
    }

    public function markSyncSkipped(
        string $type,
        string $importId,
        int $eligibleCount,
        int $fullMetadataCount = 0,
    ): void {
        Cache::forget($this->fullMetadataIdsKey($type, $importId));

        $this->update($type, $importId, [
            'status' => 'completed',
            'phase' => 'completed',
            'sync_total' => $eligibleCount,
            'sync_processed' => 0,
            'sync_succeeded' => 0,
            'sync_failed' => 0,
            'sync_errors' => [],
            'sync_full_metadata_total' => $fullMetadataCount,
            'sync_skipped_test_mode' => $eligibleCount > 0,
            'sync_retry_available' => false,
            'completed_at' => now()->toIso8601String(),
        ]);
    }

    public function markCompletedWithoutSync(string $type, string $importId): void
    {
        Cache::forget($this->fullMetadataIdsKey($type, $importId));

        $this->update($type, $importId, [
            'status' => 'completed',
            'phase' => 'completed',
            'sync_total' => 0,
            'sync_processed' => 0,
            'sync_succeeded' => 0,
            'sync_failed' => 0,
            'sync_errors' => [],
            'sync_full_metadata_total' => 0,
            'sync_skipped_test_mode' => false,
            'sync_retry_available' => false,
            'completed_at' => now()->toIso8601String(),
        ]);
    }

    public function recordSyncSuccess(string $type, string $importId, int $resourceId): void
    {
        $this->recordSyncResult($type, $importId, $resourceId, null, null);
    }

    public function recordSyncFailure(
        string $type,
        string $importId,
        int $resourceId,
        ?string $doi,
        string $error,
    ): void {
        $this->recordSyncResult($type, $importId, $resourceId, $doi, $error);
    }

    public function finalizeSync(string $type, string $importId): void
    {
        $key = $this->progressKey($type, $importId);

        $this->withProgressLock($type, $importId, 'finalize_sync', function () use ($key, $type, $importId): void {
            $progress = Cache::get($key);

            if (! is_array($progress) || ($progress['status'] ?? null) === 'cancelled') {
                return;
            }

            $pendingKey = $this->pendingIdsKey($type, $importId);
            $pendingIds = Cache::get($pendingKey, []);
            $pendingIds = is_array($pendingIds)
                ? array_values(array_unique(array_map('intval', $pendingIds)))
                : [];

            if ($pendingIds !== []) {
                $progress['sync_processed'] = (int) ($progress['sync_processed'] ?? 0) + count($pendingIds);
                $progress['sync_failed'] = (int) ($progress['sync_failed'] ?? 0) + count($pendingIds);
                $errors = is_array($progress['sync_errors'] ?? null) ? $progress['sync_errors'] : [];

                foreach ($pendingIds as $resourceId) {
                    if (count($errors) >= 100) {
                        break;
                    }

                    $errors[] = [
                        'resource_id' => $resourceId,
                        'doi' => null,
                        'error' => 'The DataCite synchronization job did not complete.',
                    ];
                }

                $progress['sync_errors'] = $errors;
                $failureKey = $this->failureIdsKey($type, $importId);
                $failureIds = Cache::get($failureKey, []);
                $failureIds = is_array($failureIds) ? $failureIds : [];
                Cache::put(
                    $failureKey,
                    array_values(array_unique(array_merge(array_map('intval', $failureIds), $pendingIds))),
                    now()->addHours(24),
                );
            }

            Cache::forget($pendingKey);
            $progress['status'] = 'completed';
            $progress['phase'] = 'completed';
            $progress['sync_retry_available'] = (int) ($progress['sync_failed'] ?? 0) > 0
                && config('datacite.test_mode') === false;
            $progress['completed_at'] = now()->toIso8601String();
            Cache::put($key, $progress, now()->addHours(24));
        });
    }

    /** @return list<int> */
    public function failedResourceIds(string $type, string $importId): array
    {
        $ids = Cache::get($this->failureIdsKey($type, $importId), []);

        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** @return list<int> */
    public function fullMetadataResourceIds(string $type, string $importId): array
    {
        $ids = Cache::get($this->fullMetadataIdsKey($type, $importId));

        if ($ids === null) {
            $progress = $this->get($type, $importId);
            $ids = $progress['sync_full_metadata_resource_ids'] ?? [];
        }

        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    public function progressKey(string $type, string $importId): string
    {
        return match ($type) {
            self::TYPE_RESOURCE => "datacite_import:{$importId}",
            self::TYPE_IGSN => "igsn_import:{$importId}",
            default => throw new \InvalidArgumentException("Unsupported import type: {$type}"),
        };
    }

    private function failureIdsKey(string $type, string $importId): string
    {
        return "import_datacite_sync_failures:{$type}:{$importId}";
    }

    private function pendingIdsKey(string $type, string $importId): string
    {
        return "import_datacite_sync_pending:{$type}:{$importId}";
    }

    private function fullMetadataIdsKey(string $type, string $importId): string
    {
        return "import_datacite_sync_full_metadata:{$type}:{$importId}";
    }

    private function recordSyncResult(
        string $type,
        string $importId,
        int $resourceId,
        ?string $doi,
        ?string $error,
    ): void {
        $key = $this->progressKey($type, $importId);

        $this->withProgressLock($type, $importId, 'record_sync_result', function () use ($key, $type, $importId, $resourceId, $doi, $error): void {
            $progress = Cache::get($key, []);

            if (($progress['status'] ?? null) === 'cancelled') {
                return;
            }

            $progress['sync_processed'] = (int) ($progress['sync_processed'] ?? 0) + 1;
            $pendingKey = $this->pendingIdsKey($type, $importId);
            $pendingIds = Cache::get($pendingKey, []);

            if (is_array($pendingIds)) {
                $pendingIds = array_values(array_filter(
                    array_map('intval', $pendingIds),
                    static fn (int $id): bool => $id !== $resourceId,
                ));
                Cache::put($pendingKey, $pendingIds, now()->addHours(24));
            }

            if ($error === null) {
                $progress['sync_succeeded'] = (int) ($progress['sync_succeeded'] ?? 0) + 1;
            } else {
                $progress['sync_failed'] = (int) ($progress['sync_failed'] ?? 0) + 1;
                $errors = is_array($progress['sync_errors'] ?? null) ? $progress['sync_errors'] : [];

                if (count($errors) < 100) {
                    $errors[] = [
                        'resource_id' => $resourceId,
                        'doi' => $doi,
                        'error' => $error,
                    ];
                }

                $progress['sync_errors'] = $errors;
                $failureKey = $this->failureIdsKey($type, $importId);
                $failureIds = Cache::get($failureKey, []);
                $failureIds = is_array($failureIds) ? $failureIds : [];
                $failureIds[] = $resourceId;
                Cache::put($failureKey, array_values(array_unique($failureIds)), now()->addHours(24));
            }

            if ((int) $progress['sync_processed'] >= (int) ($progress['sync_total'] ?? 0)) {
                $progress['status'] = 'completed';
                $progress['phase'] = 'completed';
                $progress['sync_retry_available'] = (int) ($progress['sync_failed'] ?? 0) > 0
                    && config('datacite.test_mode') === false;
                $progress['completed_at'] = now()->toIso8601String();
            }

            Cache::put($key, $progress, now()->addHours(24));
        });
    }

    private function withProgressLock(
        string $type,
        string $importId,
        string $operation,
        Closure $callback,
    ): void {
        $lockKey = $this->progressKey($type, $importId).':lock';

        try {
            retry(
                self::LOCK_RETRY_BACKOFF_MILLISECONDS,
                static fn (): mixed => Cache::lock($lockKey, self::LOCK_TTL_SECONDS)
                    ->block(self::LOCK_WAIT_SECONDS, $callback),
                when: static fn (Throwable $exception): bool => $exception instanceof LockTimeoutException,
            );
        } catch (LockTimeoutException $exception) {
            Log::warning('Import progress update skipped after repeated lock timeouts', [
                'import_type' => $type,
                'import_id' => $importId,
                'operation' => $operation,
                'lock_key' => $lockKey,
                'attempts' => count(self::LOCK_RETRY_BACKOFF_MILLISECONDS) + 1,
            ]);
        }
    }
}
