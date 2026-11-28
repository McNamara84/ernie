<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\IgsnRegistrationItemStatus;
use App\Enums\IgsnRegistrationRunStatus;
use App\Models\IgsnRegistrationRun;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class IgsnRegistrationPreviewService
{
    public function __construct(
        private readonly DataCitePayloadService $payloads,
        private readonly DataCiteModeResolverService $modes,
    ) {}

    public function fingerprint(Resource $resource): string
    {
        return hash('sha256', json_encode([
            ResourceDoiPublicationService::approvalFingerprint($resource),
            $resource->access_level, $resource->landingPage?->is_published, $resource->landingPage?->tombstone_revision,
            $resource->igsnMetadata?->isRegistered(), $this->prefix(), now(config('app.timezone'))->year,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function payload(Resource $resource): array
    {
        $resource->loadMissing(Resource::DATACITE_EXPORT_RELATIONS);
        if ($resource->igsnMetadata?->isRegistered()) {
            $payload = $this->payloads->metadataUpdate($resource, $this->prefix());

            // Metadata-only updates have no publish event and need no remote lifecycle read.
            return DataCiteMemberApiClient::protectTombstonePayload($payload['data']['id'], $payload, false) ?? $payload;
        }

        return $this->payloads->publication($resource, explode('/', (string) $resource->doi, 2)[0], [$this->prefix()], true);
    }

    public function single(Resource $resource, User $user): IgsnRegistrationRun
    {
        $payload = $this->payload($resource);
        $fingerprint = $this->fingerprint($resource);

        return DB::transaction(function () use ($resource, $user, $payload, $fingerprint): IgsnRegistrationRun {
            $run = IgsnRegistrationRun::create([
                'initiated_by_user_id' => $user->id, 'activity_actor' => app(UserActivityService::class)->actor($user),
                'status' => IgsnRegistrationRunStatus::COMPLETED, 'is_simulation' => true, 'test_mode' => true,
                'datacite_endpoint' => '', 'total' => 1, 'processed' => 1, 'started_at' => now(), 'completed_at' => now(),
            ]);
            $run->items()->create([
                'resource_id' => $resource->id, 'identifier' => $resource->doi,
                'operation' => $resource->igsnMetadata?->isRegistered() ? 'update' : 'register',
                'status' => IgsnRegistrationItemStatus::PREVIEW_READY,
                'approval_fingerprint' => $fingerprint, 'preview_payload' => $payload, 'processed_at' => now(),
            ]);

            return $run->refresh();
        });
    }

    public function confirm(IgsnRegistrationRun $run, User $user): IgsnRegistrationRun
    {
        return DB::transaction(function () use ($run, $user): IgsnRegistrationRun {
            $run = IgsnRegistrationRun::query()->lockForUpdate()->findOrFail($run->id);
            abort_unless($run->is_simulation && $run->status->isTerminal(), 409, 'Only a finished simulation can be confirmed.');
            if ($run->preview_confirmed_at !== null) {
                return $run;
            }
            abort_unless($this->modes->shouldUseTestMode($user) && $this->modes->shouldUseTestMode($run->initiatedBy),
                409, 'The DataCite mode changed. Reopen the IGSN list to see its current status.');

            $run->items()->where('status', IgsnRegistrationItemStatus::PREVIEW_READY)
                ->whereNull('preview_confirmed_at')->chunkById(50, function (Collection $items) use ($run): void {
                    $resources = Resource::query()->with(Resource::DATACITE_EXPORT_RELATIONS)
                        ->whereKey($items->pluck('resource_id')->all())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                    foreach ($items as $item) {
                        $resource = $resources->get($item->resource_id);
                        $metadata = $resource?->igsnMetadata;
                        if ($resource === null || $metadata === null || $item->approval_fingerprint !== $this->fingerprint($resource)) {
                            $item->update(['status' => IgsnRegistrationItemStatus::FAILED, 'error_message' => 'The sample or its landing page changed. Start a new preview.']);
                            $run->increment('failed');

                            continue;
                        }
                        if ($metadata->simulated_registered_at === null) {
                            $metadata->update(['simulated_registered_at' => now()]);
                        }
                        $item->update(['preview_confirmed_at' => now()]);
                    }
                });
            $run->update(['preview_confirmed_at' => now()]);
            DB::afterCommit(function () use ($run, $user): void {
                $activities = app(UserActivityService::class);
                $activities->summary($activities->actor($user), 'IGSN registration simulation', $run->id,
                    ['simulated' => $run->processed - $run->failed - $run->cancelled, 'failed' => $run->failed], true);
            });

            return $run;
        }, 3);
    }

    private function prefix(): string
    {
        return strtolower(trim((string) config('datacite.production.igsn_prefix')));
    }
}
