<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SyncResourceTombstoneWithDataCiteJob;
use App\Models\ResourceTombstoneTransition;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ResourceTombstoneSyncService
{
    public function dispatch(ResourceTombstoneTransition $transition): void
    {
        DB::afterCommit(fn () => $this->enqueue($transition->id));
    }

    private function enqueue(int $id): void
    {
        $job = new SyncResourceTombstoneWithDataCiteJob($id);
        try {
            dispatch($job)->onQueue(app(DataCiteQueueService::class)->queue());
        } catch (Throwable) {
            if ($job->uniqueLockOwner !== '') {
                try {
                    (new UniqueLock(app(Repository::class)))->release($job);
                } catch (Throwable) {
                    // Cache failures must not invalidate the already committed lifecycle change.
                }
            }
            // The durable outbox is recovered by the scheduler, even if dispatch fails.
            Log::warning('Tombstone sync dispatch deferred', ['transition_id' => $id]);
        }
    }

    public function recover(): void
    {
        ResourceTombstoneTransition::query()
            ->where(function ($query): void {
                $query->where(function ($pending): void {
                    $pending->where('status', 'pending')->where('available_at', '<=', now());
                })->orWhere(function ($running): void {
                    $running->where('status', 'running')->where('updated_at', '<=', now()->subMinutes(6));
                });
            })->eachById(function (ResourceTombstoneTransition $transition): void {
                if ($transition->status === 'running') {
                    // A timed-out worker may leave a unique lock behind. Claim this recovery once.
                    $claimed = ResourceTombstoneTransition::whereKey($transition->id)
                        ->where('status', 'running')->where('updated_at', '<=', now()->subMinutes(6))
                        ->update(['status' => 'pending', 'available_at' => now(), 'updated_at' => now()]);
                    if ($claimed === 0) {
                        return;
                    }
                    (new UniqueLock(app(Repository::class)))->release(new SyncResourceTombstoneWithDataCiteJob($transition->id));
                }
                $this->enqueue($transition->id);
            }, 100);
    }

    public function sync(int $id): void
    {
        $transition = ResourceTombstoneTransition::find($id);
        if ($transition === null || in_array($transition->status, ['succeeded', 'superseded', 'failed'], true)) {
            return;
        }

        try {
            DataCiteDoiWriteLockService::run($transition->doi, $transition->test_mode, function () use ($transition): void {
                $transition->refresh();
                $latest = ResourceTombstoneTransition::where('resource_id', $transition->resource_id)->latest('revision')->first();
                if ($latest?->id !== $transition->id) {
                    $transition->update(['status' => 'superseded']);

                    return;
                }
                if (in_array($transition->status, ['succeeded', 'superseded', 'failed'], true)
                    || ($transition->status === 'pending' && $transition->available_at?->isFuture())) {
                    return;
                }
                $transition->update(['status' => 'running', 'attempts' => $transition->attempts + 1]);
                $client = new DataCiteMemberApiClient(app(DataCiteRequestLimiter::class), $transition->test_mode);
                $response = $client->getDoi($transition->doi);
                $response->throw();
                $state = $response->json('data.attributes.state');
                $owner = $response->json('data.relationships.client.data.id');
                if (! is_string($owner) || strtolower($owner) !== $client->repositoryClientId()) {
                    throw new \RuntimeException('The DOI repository ownership could not be verified.');
                }
                if (! in_array($state, ['registered', 'findable'], true)) {
                    throw new \RuntimeException('The DOI is no longer registered.');
                }
                if ($state !== $transition->target_state || $response->json('data.attributes.url') !== $transition->target_url) {
                    $attributes = ['url' => $transition->target_url];
                    if ($state !== $transition->target_state) {
                        $attributes['event'] = $transition->target_state === 'registered' ? 'hide' : 'publish';
                    }
                    $response = $client->updateDoi($transition->doi, ['data' => ['type' => 'dois', 'id' => $transition->doi, 'attributes' => $attributes]]);
                    $response->throw();
                    if ($response->json('data.attributes.state') !== $transition->target_state || $response->json('data.attributes.url') !== $transition->target_url) {
                        throw new \RuntimeException('DataCite has not confirmed the requested state and URL.');
                    }
                }
                $transition->update(['status' => 'succeeded', 'completed_at' => now(), 'available_at' => null, 'last_error' => null]);
                $resource = \App\Models\Resource::find($transition->resource_id);
                if ($resource !== null) {
                    $activities = app(UserActivityService::class);
                    $activities->record($transition->activity_actor ?? $activities->actor($transition->user_id), 'tombstone.datacite_synced',
                        'completed tombstone DataCite synchronization for', $activities->subject($resource), operationId: (string) $transition->id, testMode: $transition->test_mode);
                }
            });
        } catch (Throwable $exception) {
            $status = $exception instanceof RequestException ? $exception->response->status() : null;
            $retryable = $status === null || $status === 429 || $status >= 500;
            $retry = $retryable && $transition->attempts < 5;
            $transition->update([
                'status' => $retry ? 'pending' : 'failed',
                'available_at' => $retry ? now()->addSeconds(min(3600, 30 * 2 ** $transition->attempts)) : null,
                'last_error' => $status !== null ? "DataCite request failed (HTTP {$status})." : 'DataCite synchronization could not be confirmed. Please retry.',
            ]);
            Log::warning('Tombstone sync incomplete', ['transition_id' => $id, 'http_status' => $status]);
        }
    }
}
