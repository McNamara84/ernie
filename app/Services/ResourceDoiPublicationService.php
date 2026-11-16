<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DataCiteVerificationException;
use App\Exceptions\JsonValidationException;
use App\Models\Resource;
use App\Models\ResourceDoiPublication;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Coordinates an explicitly approved publication; never called by metadata sync. */
class ResourceDoiPublicationService
{
    public function __construct(
        private readonly DataCiteMemberApiClient $client,
        private readonly DataCiteRegistrationVerifierService $verifier,
        private readonly ResourceDoiPublicationTargetService $targets,
    ) {}

    /** @return array<string, mixed> */
    public function publish(Resource $resource, ?string $prefix = null, bool $igsn = false, ?int $actorId = null): array
    {
        return Cache::lock('datacite:publication:'.$resource->id, 300)->block(5, function () use ($resource, $prefix, $igsn, $actorId): array {
            $current = Resource::query()->with(Resource::DATACITE_EXPORT_RELATIONS)->findOrFail($resource->id);
            $pending = ResourceDoiPublication::where('resource_id', $current->id)
                ->whereNotIn('status', ['succeeded', 'failed', 'superseded'])->latest('id')->first();
            if ($pending !== null) {
                $pending->assertContext($this->client);
                if (in_array($pending->status, ['remote_write_started', 'pending_reconciliation'], true)) {
                    return $this->reconcile($current, $pending);
                }
                try {
                    $this->assertCurrent($current, $pending);
                } catch (DataCiteVerificationException $exception) {
                    $pending->update(['status' => 'superseded', 'last_error' => $exception->getMessage()]);
                    throw $exception;
                }

                return $this->execute($current, $pending);
            }
            app(EmbargoService::class)->normalizeResource($current);
            app(EmbargoService::class)->assertCanRegister($current);
            if (! $current->isComplete()) {
                throw new DataCiteVerificationException('metadata_incomplete', 'Complete the required resource metadata before publication.');
            }
            $doi = trim((string) $current->doi);
            if ($doi !== '' && Resource::whereRaw('LOWER(doi) = ?', [strtolower($doi)])->count() !== 1) {
                throw new DataCiteVerificationException('ambiguous_local_doi', 'This DOI is assigned to more than one local resource. Resolve the duplicate before publication.');
            }
            $prefix = $doi !== '' ? explode('/', $doi, 2)[0] : trim((string) $prefix);
            $allowed = $igsn && ! $this->client->isTestMode() ? [$this->client->igsnPrefix()] : $this->client->prefixes();
            if (! in_array(strtolower($prefix), $allowed, true)
                || ($doi !== '' && ! preg_match('/^10\.\d{4,}(?:\.\d+)*\/\S+$/', $doi))) {
                throw new DataCiteVerificationException('invalid_doi_prefix', 'The DOI or prefix is not permitted in this DataCite environment.');
            }
            $repository = $this->client->repositoryClientId($doi !== '' ? $doi : $prefix);
            $target = $this->targets->target($current);
            $attributes = $this->attributes($current);
            if ($igsn) {
                $attributes['publicationYear'] = (string) now(config('app.timezone'))->year;
            }
            try {
                app(JsonSchemaValidator::class)->validate($attributes, strictMode: $doi !== '');
            } catch (JsonValidationException $exception) {
                throw new DataCiteVerificationException('metadata_invalid', implode(' ', $exception->getErrorMessages()));
            }
            $evidence = $doi !== '' ? $this->verifier->verify($this->client, $doi) : null;
            $payload = ['data' => ['type' => 'dois', 'attributes' => [
                ...$attributes, 'url' => $target, 'event' => 'publish',
            ]]];
            if ($doi === '') {
                unset($payload['data']['attributes']['doi']);
                $payload['data']['attributes']['prefix'] = $prefix;
            }
            $pending = DB::transaction(function () use ($current, $prefix, $doi, $repository, $target, $payload, $evidence, $actorId, $igsn): ResourceDoiPublication {
                Resource::whereKey($current->id)->lockForUpdate()->firstOrFail();

                return ResourceDoiPublication::create([
                    'operation' => $igsn ? 'igsn_publication' : 'doi_publication',
                    'uuid' => (string) Str::uuid(), 'resource_id' => $current->id, 'user_id' => $actorId ?? auth()->id(),
                    'test_mode' => $this->client->isTestMode(), 'endpoint' => $this->client->endpoint(),
                    'repository' => $repository, 'prefix' => $prefix, 'doi' => $doi !== '' ? $doi : null,
                    'target_url' => $target, 'metadata_hash' => $this->metadataHash($current),
                    'payload' => $payload, 'previous_state' => $evidence?->state, 'status' => 'prepared',
                ]);
            });
            // An earlier embargo POST may already have succeeded. Preserve that
            // ambiguity rather than creating another DOI through the new workflow.
            if ($current->embargo_registration_started_at !== null) {
                $pending->update(['status' => 'pending_reconciliation']);

                return $this->reconcile($current, $pending);
            }

            return $this->execute($current, $pending);
        });
    }

    /** @return array<string, mixed> */
    private function execute(Resource $resource, ResourceDoiPublication $publication): array
    {
        try {
            $this->assertCurrent($resource, $publication);
            DB::transaction(function () use ($resource, $publication): void {
                $current = Resource::whereKey($resource->id)->lockForUpdate()->firstOrFail();
                $this->assertCurrent($current, $publication);
                $page = $current->landingPage()->lockForUpdate()->firstOrFail();
                if (! $page->is_published) {
                    $page->publish();
                }
                $publication->update(['status' => 'landing_page_published', 'last_error' => null]);
            });
            $resource = $resource->fresh(Resource::DATACITE_EXPORT_RELATIONS) ?? throw new \RuntimeException('Resource disappeared.');
            $this->targets->assertReachable($resource, $publication->target_url);
            $this->assertCurrent($resource, $publication);
            $payload = $publication->payload;
            $beforeWrite = function () use ($resource, $publication): void {
                $this->assertCurrent($resource, $publication);
                $publication->update(['status' => 'remote_write_started', 'attempts' => $publication->attempts + 1]);
            };
            if ($publication->doi === null || $publication->previous_state === 'missing') {
                $response = $this->client->createDoi($payload, allowRetry: false, beforeWrite: $beforeWrite);
            } else {
                $payload['data']['id'] = $publication->doi;
                if ($publication->previous_state === 'findable') {
                    unset($payload['data']['attributes']['event']);
                }
                $response = $this->client->updateDoi($publication->doi, $payload, beforeWrite: $beforeWrite, publicationId: $publication->id);
            }
            $response->throw();
            $doi = $response->json('data.id');
            if (! is_string($doi) || ($publication->doi !== null && strcasecmp($doi, $publication->doi) !== 0)
                || ! str_starts_with(strtolower($doi), strtolower($publication->prefix).'/')) {
                throw new DataCiteVerificationException('doi_publication_unconfirmed', 'DataCite did not confirm the expected DOI.', true);
            }
            $evidence = $this->verifier->fromResponse($this->client, $doi, $response);
            if ($evidence->state !== 'findable' || $evidence->url !== $publication->target_url) {
                throw new DataCiteVerificationException('doi_publication_unconfirmed', 'DataCite did not confirm publication and the approved landing-page URL.', true);
            }
            // Persist the remotely accepted identifier before finalizing local state.
            $publication->update(['doi' => $doi]);

            return $this->complete($resource, $publication);
        } catch (Throwable $exception) {
            $uncertain = $publication->fresh()?->status === 'remote_write_started';
            if ($exception instanceof RequestException && in_array($exception->response->status(), [400, 401, 403, 422, 429], true)) {
                $uncertain = false;
            }
            $publication->update([
                'status' => $uncertain ? 'pending_reconciliation' : 'failed',
                'last_error' => $exception->getMessage(),
                'last_error_code' => $exception instanceof DataCiteVerificationException ? $exception->reason : 'datacite_publication_failed',
            ]);
            if (! $uncertain) {
                throw $exception;
            }

            return $this->pendingResponse($publication);
        }
    }

    /** @return array<string, mixed> */
    private function reconcile(Resource $resource, ResourceDoiPublication $publication): array
    {
        try {
            $publication->assertContext($this->client);
            if ($publication->doi !== null) {
                $response = $this->client->getDoi($publication->doi);
                $response->throw();
                $records = [$response->json('data')];
            } else {
                $response = $this->client->findDoisByUrl($publication->target_url, $publication->prefix);
                $response->throw();
                $records = $response->json('data');
            }
            $matches = [];
            foreach (is_array($records) ? $records : [] as $record) {
                if (! is_array($record) || ! is_string($record['id'] ?? null)
                    || ! str_starts_with(strtolower($record['id']), strtolower($publication->prefix).'/')) {
                    continue;
                }
                // Search results can be stale: verify each candidate individually.
                $candidate = $this->client->getDoi($record['id']);
                $candidate->throw();
                $evidence = $this->verifier->fromResponse($this->client, $record['id'], $candidate);
                if ($evidence->state === 'findable' && $evidence->url === $publication->target_url
                    && $this->matchesMetadata($publication->payload['data']['attributes'], $candidate->json('data.attributes'))) {
                    $matches[] = $record['id'];
                }
            }
            if (count($matches) !== 1) {
                throw new \RuntimeException('The earlier publication is not uniquely confirmed. No second create request was sent.');
            }
            $publication->update(['doi' => $matches[0]]);
            $this->targets->assertReachable($resource, $publication->target_url);

            return $this->complete($resource, $publication);
        } catch (Throwable $exception) {
            $publication->update(['status' => 'pending_reconciliation', 'last_error' => $exception->getMessage()]);

            return $this->pendingResponse($publication);
        }
    }

    /** @return array<string, mixed> */
    private function complete(Resource $resource, ResourceDoiPublication $publication): array
    {
        DB::transaction(function () use ($resource, $publication): void {
            $current = Resource::whereKey($resource->id)->lockForUpdate()->firstOrFail();
            if ($current->doi && strcasecmp($current->doi, (string) $publication->doi) !== 0) {
                throw new \RuntimeException('The resource DOI changed. Publication needs manual reconciliation.');
            }
            if ($current->landingPage?->is_published !== true || $current->landingPage->is_tombstone) {
                throw new \RuntimeException('The landing page changed. Publication needs manual reconciliation.');
            }
            $this->assertCurrent($current, $publication);
            $current->doi = $publication->doi;
            $current->publication_year = (int) $publication->payload['data']['attributes']['publicationYear'];
            $current->embargo_registration_started_at = null;
            $current->embargo_registration_prefix = null;
            $current->embargo_registration_access_level = null;
            $current->save();
            app(ResourceStorageService::class)->ensureSystemDate($current, 'Issued');
            $publication->update(['status' => 'succeeded', 'completed_at' => now(), 'last_error' => null, 'last_error_code' => null]);
        });

        return ['data' => ['id' => $publication->doi], 'publication' => $publication->summary(), 'complete' => true];
    }

    private function assertCurrent(Resource $resource, ResourceDoiPublication $publication): void
    {
        $publication->assertContext($this->client);
        $current = $resource->fresh(Resource::DATACITE_EXPORT_RELATIONS);
        if ($current === null || $current->landingPage?->is_tombstone
            || $this->metadataHash($current) !== $publication->metadata_hash
            || $this->targets->target($current) !== $publication->target_url) {
            throw new DataCiteVerificationException('doi_publication_superseded', 'The approved metadata or landing page changed. Review the record and confirm publication again.');
        }
    }

    /** @return array<string, mixed> */
    private function attributes(Resource $resource): array
    {
        return (new DataCiteJsonExporter)->export($resource)['data']['attributes'];
    }

    private function metadataHash(Resource $resource): string
    {
        return hash('sha256', json_encode($this->attributes($resource), JSON_THROW_ON_ERROR));
    }

    public static function approvalFingerprint(Resource $resource): string
    {
        $resource->loadMissing(Resource::DATACITE_EXPORT_RELATIONS);
        $page = $resource->landingPage;

        return hash('sha256', json_encode([
            (new DataCiteJsonExporter)->export($resource),
            $page?->template, $page?->external_url,
            $page?->is_tombstone, url('/datasets/'.$resource->id),
        ], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $expected */
    private function matchesMetadata(array $expected, mixed $actual): bool
    {
        if (! is_array($actual)) {
            return false;
        }
        unset($expected['event'], $expected['prefix']);
        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $actual) || $value != $actual[$key]) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function pendingResponse(ResourceDoiPublication $publication): array
    {
        return ['data' => ['id' => $publication->doi], 'publication' => $publication->summary(), 'complete' => false];
    }
}
