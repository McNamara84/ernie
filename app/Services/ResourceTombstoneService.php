<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TombstoneReason;
use App\Models\LandingPage;
use App\Models\LandingPageTemplate;
use App\Models\Resource;
use App\Models\ResourceTombstoneTransition;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ResourceTombstoneService
{
    /** @var list<string> */
    private const CONFIG_FIELDS = ['template', 'landing_page_template_id', 'ftp_url', 'primary_download_label', 'ftp_format_id', 'ftp_size_id', 'downloads_unavailable', 'external_domain_id', 'external_path', 'is_published', 'published_at'];

    public function __construct(private readonly ResourceTombstoneSyncService $sync) {}

    /** @return array<string, mixed> */
    public function state(Resource $resource, User $user): array
    {
        $page = $resource->landingPage;
        $latest = ResourceTombstoneTransition::where('resource_id', $resource->id)->latest('revision')->first();
        $activation = ResourceTombstoneTransition::where('resource_id', $resource->id)->where('action', 'activate')->latest('revision')->first();

        return [
            'can_manage' => $user->can('manageTombstone', LandingPage::class),
            'is_tombstone' => (bool) $page?->is_tombstone,
            'revision' => (int) $page?->tombstone_revision,
            'reason' => $page?->tombstone_reason?->value,
            'statement' => $page?->tombstone_statement,
            'reasons' => array_map(fn (TombstoneReason $reason): array => ['value' => $reason->value, 'label' => $reason->label()], TombstoneReason::cases()),
            'sync' => $latest?->only(['status', 'attempts', 'last_error', 'completed_at']),
            'restore' => $page?->is_tombstone ? [
                'has_configuration' => $activation?->snapshot !== null,
                'template' => $activation?->snapshot['configuration']['template'] ?? null,
                'is_published' => $activation?->snapshot['configuration']['is_published'] ?? null,
                'datacite_state' => $activation?->previous_state,
            ] : null,
        ];
    }

    /** @return array{status: string, reason: ?string} */
    public function activationEligibility(Resource $resource, User $user): array
    {
        if (! $user->can('manageTombstone', LandingPage::class)) {
            return ['status' => 'ineligible', 'reason' => 'forbidden'];
        }
        if ($resource->landingPage?->is_tombstone) {
            return ['status' => 'ineligible', 'reason' => 'already_active'];
        }

        $registration = $this->registration($resource, deferWhenLimited: true);

        return ['status' => $registration['status'], 'reason' => $registration['reason']];
    }

    /** @return array{status: string, reason: ?string, state: ?string, url: ?string} */
    private function registration(Resource $resource, bool $deferWhenLimited = false): array
    {
        $result = ['status' => 'ineligible', 'reason' => null, 'state' => null, 'url' => null];
        if (! is_string($resource->doi) || trim($resource->doi) === '') {
            return [...$result, 'reason' => 'missing_doi'];
        }
        if ($resource->isIgsn()) {
            return [...$result, 'reason' => 'igsn'];
        }

        try {
            $client = app(DataCiteMemberApiClient::class);
            $response = $client->getDoi($resource->doi, deferWhenLimited: $deferWhenLimited);
            if ($response->status() === 404) {
                return [...$result, 'reason' => 'not_registered'];
            }
            $response->throw();
            $state = $response->json('data.attributes.state');
            $url = $response->json('data.attributes.url');
            $owner = $response->json('data.relationships.client.data.id');
            if ($state === 'draft') {
                return [...$result, 'reason' => 'not_registered'];
            }
            if (! in_array($state, ['registered', 'findable'], true) || ! is_string($owner)) {
                return [...$result, 'status' => 'unavailable', 'reason' => 'verification_unavailable'];
            }
            if (! is_string($url) || trim($url) === '') {
                return [...$result, 'reason' => 'missing_target_url'];
            }
            if (strtolower($owner) !== $client->repositoryClientId()) {
                return [...$result, 'reason' => 'foreign_repository'];
            }

            return ['status' => 'eligible', 'reason' => null, 'state' => $state, 'url' => $url];
        } catch (\Throwable) {
            return [...$result, 'status' => 'unavailable', 'reason' => 'verification_unavailable'];
        }
    }

    /** @param array<string, mixed> $data */
    public function activate(Resource $resource, User $user, array $data): LandingPage
    {
        if (! $resource->doi || $resource->isIgsn()) {
            throw ValidationException::withMessages(['doi' => 'Tombstone pages require an existing resource DOI.']);
        }
        $client = app(DataCiteMemberApiClient::class);

        return DataCiteDoiWriteLockService::run($resource->doi, $client->isTestMode(), function () use ($resource, $user, $data, $client): LandingPage {
            $registration = $this->registration($resource);
            if ($registration['status'] !== 'eligible') {
                $message = match ($registration['reason']) {
                    'foreign_repository' => 'The DOI does not belong to the configured DataCite repository.',
                    'not_registered', 'missing_target_url' => 'The DOI must already be registered with DataCite and have a target URL.',
                    default => 'The registered DOI could not be verified with DataCite. Please retry.',
                };
                throw ValidationException::withMessages(['doi' => $message]);
            }
            $remoteState = $registration['state'];
            $remoteUrl = $registration['url'];

            return DB::transaction(function () use ($resource, $user, $data, $client, $remoteState, $remoteUrl): LandingPage {
                $locked = Resource::whereKey($resource->id)->lockForUpdate()->firstOrFail();
                abort_if($locked->doi !== $resource->doi, 409, 'The resource DOI changed. Reload the setup modal.');
                $locked->load(['titles.titleType', 'creators', 'publisher', 'resourceType']);
                if (! $locked->main_title || $locked->creators->isEmpty() || ! $locked->publication_year || $locked->publisher === null) {
                    throw ValidationException::withMessages(['resource' => 'A title, creators, publication year and publisher are required for the tombstone citation.']);
                }
                $activityBefore = app(UserActivityService::class)->snapshot($locked, true);
                $page = LandingPage::where('resource_id', $locked->id)->lockForUpdate()->first();
                $this->assertRevision($page, (int) $data['revision']);
                abort_if((bool) $page?->is_tombstone, 409, 'A tombstone page is already active.');
                $snapshot = $page === null ? null : [
                    'version' => 1,
                    'configuration' => $page->only(self::CONFIG_FIELDS),
                    'files' => $page->files->toArray(),
                    'links' => $page->links->toArray(),
                ];
                $page ??= new LandingPage(['resource_id' => $locked->id, 'template' => LandingPageTemplate::DEFAULT_TEMPLATE_SLUG]);
                $page->forceFill([
                    'doi_prefix' => $locked->doi,
                    'is_tombstone' => true,
                    'tombstone_reason' => $data['reason'],
                    'tombstone_statement' => trim((string) $data['statement']),
                    'tombstoned_at' => now(),
                    'tombstoned_by_user_id' => $user->id,
                    'tombstone_revision' => ($page->tombstone_revision ?? 0) + 1,
                    'template' => LandingPageTemplate::DEFAULT_TEMPLATE_SLUG,
                    'landing_page_template_id' => LandingPageTemplate::defaultForType(LandingPageTemplate::TEMPLATE_TYPE_RESOURCE)->id,
                    'external_domain_id' => null,
                    'external_path' => null,
                    'is_published' => true,
                    'published_at' => $page->published_at ?? now(),
                ])->save();
                $locked->touch();
                $transition = ResourceTombstoneTransition::create([
                    'resource_id' => $locked->id, 'user_id' => $user->id, 'activity_actor' => app(UserActivityService::class)->actor($user), 'revision' => $page->tombstone_revision,
                    'action' => 'activate', 'reason' => $data['reason'], 'statement' => $page->tombstone_statement,
                    'snapshot' => $snapshot, 'doi' => $locked->doi, 'test_mode' => $client->isTestMode(),
                    'previous_state' => $remoteState, 'previous_url' => $remoteUrl,
                    'target_state' => 'registered', 'target_url' => $page->public_url, 'available_at' => now(),
                ]);
                $this->sync->dispatch($transition);

                app(UserActivityService::class)->landingChange($user, $locked, $activityBefore, 'landing-page.tombstone.activate');

                return $page;
            });
        });
    }

    /** @param array<string, mixed> $data */
    public function change(Resource $resource, User $user, array $data, bool $restore = false): LandingPage
    {
        $previous = ResourceTombstoneTransition::where('resource_id', $resource->id)->latest('revision')->firstOrFail();

        return DataCiteDoiWriteLockService::run($previous->doi, $previous->test_mode, fn (): LandingPage => DB::transaction(function () use ($resource, $user, $data, $restore): LandingPage {
            $locked = Resource::whereKey($resource->id)->lockForUpdate()->firstOrFail();
            $activityBefore = app(UserActivityService::class)->snapshot($locked, true);
            $page = LandingPage::where('resource_id', $locked->id)->lockForUpdate()->firstOrFail();
            $this->assertRevision($page, (int) $data['revision']);
            abort_unless($page->is_tombstone, 409, 'The resource is no longer a tombstone.');
            $previous = ResourceTombstoneTransition::where('resource_id', $locked->id)->latest('revision')->firstOrFail();
            $targetState = $previous->target_state;
            $targetUrl = $previous->target_url;
            if ($restore) {
                $activation = ResourceTombstoneTransition::where('resource_id', $locked->id)->where('action', 'activate')->latest('revision')->firstOrFail();
                $configuration = $activation->snapshot['configuration'] ?? null;
                if (! is_array($configuration)) {
                    if (! array_key_exists('restore_published', $data)) {
                        throw ValidationException::withMessages(['restore_published' => 'Choose whether the restored default landing page should be published.']);
                    }
                    $configuration = ['template' => LandingPageTemplate::DEFAULT_TEMPLATE_SLUG, 'landing_page_template_id' => null, 'is_published' => $data['restore_published'], 'published_at' => $data['restore_published'] ? now() : null];
                }
                $page->forceFill($configuration);
                $page->forceFill(['is_tombstone' => false, 'tombstone_reason' => null, 'tombstone_statement' => null, 'tombstoned_at' => null, 'tombstoned_by_user_id' => null]);
                $targetState = (string) $activation->previous_state;
                $targetUrl = $activation->snapshot === null && $page->is_published ? $page->public_url : (string) $activation->previous_url;
            } else {
                $page->tombstone_reason = TombstoneReason::from((string) $data['reason']);
                $page->tombstone_statement = trim((string) $data['statement']);
                if (! $page->isDirty(['tombstone_reason', 'tombstone_statement'])) {
                    return $page;
                }
            }
            $page->tombstone_revision++;
            $page->save();
            $locked->touch();
            $transition = ResourceTombstoneTransition::create([
                'resource_id' => $locked->id, 'user_id' => $user->id, 'activity_actor' => app(UserActivityService::class)->actor($user), 'revision' => $page->tombstone_revision,
                'action' => $restore ? 'restore' : 'update_statement', 'reason' => $page->tombstone_reason?->value, 'statement' => $page->tombstone_statement,
                'doi' => $previous->doi, 'test_mode' => $previous->test_mode,
                'target_state' => $targetState, 'target_url' => $targetUrl, 'available_at' => now(),
            ]);
            $this->sync->dispatch($transition);

            app(UserActivityService::class)->landingChange($user, $locked, $activityBefore, $restore ? 'landing-page.tombstone.restore' : 'landing-page.tombstone.update');

            return $page;
        }));
    }

    public function retry(Resource $resource, int $revision, ?User $user = null): void
    {
        $transition = ResourceTombstoneTransition::where('resource_id', $resource->id)->latest('revision')->firstOrFail();
        DataCiteDoiWriteLockService::run($transition->doi, $transition->test_mode, function () use ($resource, $revision, $user): void {
            DB::transaction(function () use ($resource, $revision, $user): void {
                $locked = Resource::whereKey($resource->id)->lockForUpdate()->firstOrFail();
                $page = LandingPage::where('resource_id', $locked->id)->lockForUpdate()->firstOrFail();
                $this->assertRevision($page, $revision);
                $transition = ResourceTombstoneTransition::where('resource_id', $locked->id)->latest('revision')->firstOrFail();
                abort_if($transition->status === 'running', 409, 'DataCite synchronization is already running.');
                if ($transition->status !== 'succeeded') {
                    $transition->update(['status' => 'pending', 'attempts' => 0, 'available_at' => now(), 'last_error' => null]);
                    $this->sync->dispatch($transition);
                    $activities = app(UserActivityService::class);
                    $activities->record($activities->actor($user), 'landing-page.tombstone.retry', 'requested a tombstone synchronization retry for',
                        $activities->subject($locked), operationId: (string) $transition->id);
                }
            });
        });
    }

    private function assertRevision(?LandingPage $page, int $revision): void
    {
        abort_if((int) $page?->tombstone_revision !== $revision, 409, 'The landing page changed. Reload the setup modal before saving.');
    }
}
