<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CacheKey;
use App\Models\LandingPage;
use App\Models\LandingPageDomain;
use App\Models\Resource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class GeofonEventLandingPageUrlRepairService
{
    public function __construct(
        private readonly GeofonEventLandingPageUrlService $urls,
        private readonly DataCiteMemberApiClient $client,
        private readonly DoiSuggestionService $doiNormalizer,
    ) {}

    /**
     * @param  list<string>  $dois
     * @return array<string, mixed>
     */
    public function run(
        bool $apply = false,
        int $afterId = 0,
        int $limit = 0,
        array $dois = [],
    ): array {
        if ($afterId < 0 || $limit < 0) {
            throw new \InvalidArgumentException('The resource ID and limit must not be negative.');
        }

        $doiFilter = $this->normalizeDoiFilter($dois);
        $expectedClient = $this->client->repositoryClientId();
        $result = $this->emptyResult($apply);
        $seenFilteredDois = [];
        $processed = 0;
        $abortWrites = false;
        $snapshotDirectory = null;

        Resource::query()
            ->where('id', '>', $afterId)
            ->whereNotNull('doi')
            ->whereRaw("TRIM(doi) != ''")
            ->whereHas('landingPage', fn ($query) => $query->where('template', 'external'))
            ->with(['datacenter', 'landingPage.externalDomain'])
            ->orderBy('id')
            ->chunkById(100, function (Collection $resources) use (
                $apply,
                $limit,
                $doiFilter,
                $expectedClient,
                &$seenFilteredDois,
                &$processed,
                &$abortWrites,
                &$snapshotDirectory,
                &$result,
            ): void {
                foreach ($resources as $resource) {
                    $doi = $this->doiNormalizer->normalizeDoi((string) $resource->doi);
                    if ($doiFilter !== [] && ! isset($doiFilter[$doi])) {
                        continue;
                    }

                    $seenFilteredDois[$doi] = true;
                    $result['resources_scanned']++;
                    $localUrl = $this->localUrl($resource->landingPage);
                    $localInspection = $this->urls->inspect($localUrl);
                    $datacenter = trim((string) $resource->datacenter?->name);
                    $isExpectedDatacenter = strcasecmp(
                        $datacenter,
                        LegacyMetaworksDatacenterLookupService::GEOFON_EVENTS_DATACENTER,
                    ) === 0;

                    if (! $isExpectedDatacenter) {
                        if ($localInspection['status'] === 'legacy') {
                            $this->appendRecord($result, $this->record(
                                resource: $resource,
                                localUrl: $localUrl,
                                localStatus: 'manual_review',
                                overallStatus: 'manual_review_wrong_datacenter',
                                eventId: $localInspection['event_id'],
                                targetUrl: $localInspection['target_url'],
                                message: 'A legacy GEOFON event URL is assigned to an unexpected or missing datacenter.',
                            ));
                        }

                        continue;
                    }

                    if (! $localInspection['recognized_host']) {
                        $this->appendRecord($result, $this->record(
                            resource: $resource,
                            localUrl: $localUrl,
                            localStatus: 'excluded_non_geofon',
                            overallStatus: 'skipped_non_geofon_host',
                            message: $localInspection['message'] ?? 'The external landing page does not use an allowed GEOFON host.',
                        ));

                        continue;
                    }

                    $result['candidates']++;
                    if ($limit > 0 && $processed >= $limit) {
                        $this->appendRecord($result, $this->record(
                            resource: $resource,
                            localUrl: $localUrl,
                            localStatus: $localInspection['status'],
                            overallStatus: 'skipped_limit',
                            eventId: $localInspection['event_id'],
                            targetUrl: $localInspection['target_url'],
                            message: "The --limit={$limit} candidate limit was reached.",
                        ));

                        continue;
                    }

                    if ($abortWrites) {
                        $this->appendRecord($result, $this->record(
                            resource: $resource,
                            localUrl: $localUrl,
                            localStatus: $localInspection['status'],
                            overallStatus: 'not_processed_authentication',
                            eventId: $localInspection['event_id'],
                            targetUrl: $localInspection['target_url'],
                            message: 'The run stopped after a DataCite authentication or authorization failure.',
                        ));

                        continue;
                    }

                    $processed++;
                    $result['last_resource_id'] = $resource->id;
                    [$record, $authenticationFailed] = $this->processResource(
                        resource: $resource,
                        localUrl: $localUrl,
                        localInspection: $localInspection,
                        expectedClient: $expectedClient,
                        apply: $apply,
                        snapshotDirectory: $snapshotDirectory,
                    );
                    $snapshotDirectory = is_string($record['snapshot_directory'] ?? null)
                        ? $record['snapshot_directory']
                        : $snapshotDirectory;
                    unset($record['snapshot_directory']);
                    $this->appendRecord($result, $record);
                    $abortWrites = $authenticationFailed;
                }
            });

        foreach (array_diff_key($doiFilter, $seenFilteredDois) as $missingDoi => $_unused) {
            $this->appendRecord($result, $this->record(
                doi: $missingDoi,
                overallStatus: 'requested_doi_not_found',
                message: 'No matching external ERNIE resource was found after the selected resource ID.',
            ));
        }

        $result['snapshot_directory'] = $snapshotDirectory;

        return $result;
    }

    /**
     * @param array{
     *     status: 'legacy'|'current'|'unknown'|'invalid',
     *     recognized_host: bool,
     *     event_id: string|null,
     *     target_url: string|null,
     *     needs_update: bool,
     *     message: string|null
     * } $localInspection
     * @return array{array<string, mixed>, bool}
     */
    private function processResource(
        Resource $resource,
        string $localUrl,
        array $localInspection,
        string $expectedClient,
        bool $apply,
        ?string $snapshotDirectory,
    ): array {
        $doi = $this->doiNormalizer->normalizeDoi((string) $resource->doi);
        $eventId = $this->urls->eventIdFromDoi($doi);
        if ($eventId === null) {
            return [$this->record(
                resource: $resource,
                localUrl: $localUrl,
                localStatus: $localInspection['status'],
                overallStatus: 'manual_review_doi',
                message: 'The DOI does not match a supported GEOFON seismic-event namespace and event ID.',
            ), false];
        }

        $targetUrl = $this->urls->targetUrl($eventId);
        if (! in_array($localInspection['status'], ['legacy', 'current'], true)) {
            return [$this->record(
                resource: $resource,
                localUrl: $localUrl,
                localStatus: 'manual_review',
                overallStatus: 'manual_review_local_url',
                eventId: $eventId,
                targetUrl: $targetUrl,
                message: $localInspection['message'] ?? 'The local landing-page URL is not supported.',
            ), false];
        }

        if ($localInspection['event_id'] !== $eventId) {
            return [$this->record(
                resource: $resource,
                localUrl: $localUrl,
                localStatus: 'manual_review',
                overallStatus: 'manual_review_event_id_mismatch',
                eventId: $eventId,
                targetUrl: $targetUrl,
                message: 'The local landing-page event ID does not match the DOI suffix.',
            ), false];
        }

        try {
            $response = $this->client->getDoi($doi);
        } catch (ConnectionException $exception) {
            return [$this->record(
                resource: $resource,
                localUrl: $localUrl,
                localStatus: $localInspection['status'],
                dataciteStatus: 'connection_failed',
                overallStatus: 'datacite_preflight_failed',
                eventId: $eventId,
                targetUrl: $targetUrl,
                message: Str::limit($exception->getMessage(), 500, ''),
            ), false];
        } catch (Throwable $exception) {
            report($exception);

            return [$this->record(
                resource: $resource,
                localUrl: $localUrl,
                localStatus: $localInspection['status'],
                dataciteStatus: 'error',
                overallStatus: 'datacite_preflight_failed',
                eventId: $eventId,
                targetUrl: $targetUrl,
                message: Str::limit($exception->getMessage(), 500, ''),
            ), false];
        }

        if (in_array($response->status(), [401, 403], true)) {
            return [$this->record(
                resource: $resource,
                localUrl: $localUrl,
                localStatus: $localInspection['status'],
                dataciteStatus: 'authentication_failed',
                overallStatus: 'authentication_failed',
                eventId: $eventId,
                targetUrl: $targetUrl,
                updateHttpStatus: $response->status(),
                message: $this->responseError($response, 'DataCite rejected the configured repository credentials.'),
            ), true];
        }

        if (! $response->successful()) {
            $status = $response->status() === 404 ? 'remote_missing' : 'datacite_preflight_failed';

            return [$this->record(
                resource: $resource,
                localUrl: $localUrl,
                localStatus: $localInspection['status'],
                dataciteStatus: $status,
                overallStatus: $status,
                eventId: $eventId,
                targetUrl: $targetUrl,
                updateHttpStatus: $response->status(),
                message: $this->responseError($response, 'DataCite rejected the DOI preflight request.'),
            ), false];
        }

        $remoteRecord = $this->remoteRecord($response);
        if ($remoteRecord === null) {
            return [$this->record(
                resource: $resource,
                localUrl: $localUrl,
                localStatus: $localInspection['status'],
                dataciteStatus: 'invalid_response',
                overallStatus: 'datacite_preflight_failed',
                eventId: $eventId,
                targetUrl: $targetUrl,
                updateHttpStatus: $response->status(),
                message: 'DataCite returned an invalid DOI document.',
            ), false];
        }

        $remoteDoi = $this->remoteDoi($remoteRecord);
        $remoteClient = $this->remoteClient($remoteRecord);
        $remoteUrl = $this->remoteString($remoteRecord, 'attributes.url') ?? '';
        $remoteState = $this->remoteString($remoteRecord, 'attributes.state');
        $remoteInspection = $this->urls->inspect($remoteUrl);

        if ($remoteDoi !== $doi) {
            return [$this->record(
                resource: $resource,
                localUrl: $localUrl,
                dataciteUrl: $remoteUrl,
                localStatus: $localInspection['status'],
                dataciteStatus: 'manual_review',
                dataciteState: $remoteState,
                overallStatus: 'manual_review_remote_doi',
                eventId: $eventId,
                targetUrl: $targetUrl,
                message: 'The DOI returned by DataCite does not match the local DOI.',
            ), false];
        }

        if ($remoteClient === null || strcasecmp($remoteClient, $expectedClient) !== 0) {
            return [$this->record(
                resource: $resource,
                localUrl: $localUrl,
                dataciteUrl: $remoteUrl,
                localStatus: $localInspection['status'],
                dataciteStatus: 'manual_review',
                dataciteState: $remoteState,
                overallStatus: 'manual_review_datacite_client',
                eventId: $eventId,
                targetUrl: $targetUrl,
                message: $remoteClient === null
                    ? 'The DataCite DOI document does not identify its repository client.'
                    : "The DOI belongs to unexpected DataCite client {$remoteClient}.",
            ), false];
        }

        if (! in_array($remoteState, ['draft', 'registered', 'findable'], true)) {
            return [$this->record(
                resource: $resource,
                localUrl: $localUrl,
                dataciteUrl: $remoteUrl,
                localStatus: $localInspection['status'],
                dataciteStatus: 'manual_review',
                dataciteState: $remoteState,
                overallStatus: 'manual_review_datacite_state',
                eventId: $eventId,
                targetUrl: $targetUrl,
                message: 'The DataCite DOI document has a missing or unsupported state.',
            ), false];
        }

        if (! in_array($remoteInspection['status'], ['legacy', 'current'], true)) {
            return [$this->record(
                resource: $resource,
                localUrl: $localUrl,
                dataciteUrl: $remoteUrl,
                localStatus: $localInspection['status'],
                dataciteStatus: 'manual_review',
                dataciteState: $remoteState,
                overallStatus: 'manual_review_datacite_url',
                eventId: $eventId,
                targetUrl: $targetUrl,
                message: $remoteInspection['message'] ?? 'The DataCite landing-page URL is not supported.',
            ), false];
        }

        if ($remoteInspection['event_id'] !== $eventId) {
            return [$this->record(
                resource: $resource,
                localUrl: $localUrl,
                dataciteUrl: $remoteUrl,
                localStatus: $localInspection['status'],
                dataciteStatus: 'manual_review',
                dataciteState: $remoteState,
                overallStatus: 'manual_review_event_id_mismatch',
                eventId: $eventId,
                targetUrl: $targetUrl,
                message: 'The DataCite landing-page event ID does not match the DOI suffix.',
            ), false];
        }

        $localNeedsUpdate = $localInspection['needs_update'];
        $dataCiteNeedsUpdate = $remoteInspection['needs_update'];
        if (! $localNeedsUpdate && ! $dataCiteNeedsUpdate) {
            return [$this->record(
                resource: $resource,
                localUrl: $localUrl,
                dataciteUrl: $remoteUrl,
                localStatus: 'already_current',
                dataciteStatus: 'already_current',
                dataciteState: $remoteState,
                overallStatus: 'already_current',
                eventId: $eventId,
                targetUrl: $targetUrl,
                message: 'ERNIE and DataCite already use the canonical GEOFON event URL.',
            ), false];
        }

        $probe = $this->urls->probe($targetUrl);
        if (! $probe['reachable']) {
            return [$this->record(
                resource: $resource,
                localUrl: $localUrl,
                dataciteUrl: $remoteUrl,
                localStatus: $localNeedsUpdate ? 'blocked' : 'already_current',
                dataciteStatus: $dataCiteNeedsUpdate ? 'blocked' : 'already_current',
                dataciteState: $remoteState,
                overallStatus: 'target_unreachable',
                eventId: $eventId,
                targetUrl: $targetUrl,
                targetHttpStatus: $probe['http_status'],
                targetEffectiveUrl: $probe['effective_url'],
                message: $probe['message'] ?? 'The canonical GEOFON event URL is not reachable.',
            ), false];
        }

        if (! $apply) {
            return [$this->record(
                resource: $resource,
                localUrl: $localUrl,
                dataciteUrl: $remoteUrl,
                localStatus: $localNeedsUpdate ? 'would_update' : 'already_current',
                dataciteStatus: $dataCiteNeedsUpdate ? 'would_update' : 'already_current',
                dataciteState: $remoteState,
                overallStatus: match (true) {
                    $localNeedsUpdate && $dataCiteNeedsUpdate => 'would_update_both',
                    $localNeedsUpdate => 'would_update_local',
                    default => 'would_update_datacite',
                },
                eventId: $eventId,
                targetUrl: $targetUrl,
                targetHttpStatus: $probe['http_status'],
                targetEffectiveUrl: $probe['effective_url'],
                message: 'Dry run: the stale GEOFON event URL is eligible for repair.',
            ), false];
        }

        $snapshotPath = null;
        $updateHttpStatus = null;
        if ($dataCiteNeedsUpdate) {
            $snapshotDirectory ??= $this->snapshotDirectory();
            $snapshotPath = $this->storeSnapshot($snapshotDirectory, $doi, $remoteRecord);
            if ($snapshotPath === null) {
                return [$this->record(
                    resource: $resource,
                    localUrl: $localUrl,
                    dataciteUrl: $remoteUrl,
                    localStatus: $localNeedsUpdate ? 'blocked' : 'already_current',
                    dataciteStatus: 'snapshot_failed',
                    dataciteState: $remoteState,
                    overallStatus: 'snapshot_failed',
                    eventId: $eventId,
                    targetUrl: $targetUrl,
                    targetHttpStatus: $probe['http_status'],
                    targetEffectiveUrl: $probe['effective_url'],
                    snapshotDirectory: $snapshotDirectory,
                    message: 'The private DataCite pre-update snapshot could not be stored.',
                ), false];
            }

            try {
                $update = $this->client->updateLandingPageUrl($doi, $targetUrl);
            } catch (Throwable $exception) {
                report($exception);

                return [$this->record(
                    resource: $resource,
                    localUrl: $localUrl,
                    dataciteUrl: $remoteUrl,
                    localStatus: $localNeedsUpdate ? 'blocked' : 'already_current',
                    dataciteStatus: 'update_failed',
                    dataciteState: $remoteState,
                    overallStatus: 'datacite_update_failed',
                    eventId: $eventId,
                    targetUrl: $targetUrl,
                    targetHttpStatus: $probe['http_status'],
                    targetEffectiveUrl: $probe['effective_url'],
                    snapshotPath: $snapshotPath,
                    snapshotDirectory: $snapshotDirectory,
                    message: Str::limit($exception->getMessage(), 500, ''),
                ), false];
            }

            $updateHttpStatus = $update->status();
            if (in_array($updateHttpStatus, [401, 403], true)) {
                return [$this->record(
                    resource: $resource,
                    localUrl: $localUrl,
                    dataciteUrl: $remoteUrl,
                    localStatus: $localNeedsUpdate ? 'blocked' : 'already_current',
                    dataciteStatus: 'authentication_failed',
                    dataciteState: $remoteState,
                    overallStatus: 'authentication_failed',
                    eventId: $eventId,
                    targetUrl: $targetUrl,
                    targetHttpStatus: $probe['http_status'],
                    targetEffectiveUrl: $probe['effective_url'],
                    updateHttpStatus: $updateHttpStatus,
                    snapshotPath: $snapshotPath,
                    snapshotDirectory: $snapshotDirectory,
                    message: $this->responseError($update, 'DataCite rejected the configured repository credentials.'),
                ), true];
            }

            $confirmedUrl = $update->json('data.attributes.url');
            if (! $update->successful() || ! is_string($confirmedUrl) || ! $this->urls->urlsEqual($confirmedUrl, $targetUrl)) {
                return [$this->record(
                    resource: $resource,
                    localUrl: $localUrl,
                    dataciteUrl: $remoteUrl,
                    localStatus: $localNeedsUpdate ? 'blocked' : 'already_current',
                    dataciteStatus: 'update_failed',
                    dataciteState: $remoteState,
                    overallStatus: 'datacite_update_failed',
                    eventId: $eventId,
                    targetUrl: $targetUrl,
                    targetHttpStatus: $probe['http_status'],
                    targetEffectiveUrl: $probe['effective_url'],
                    updateHttpStatus: $updateHttpStatus,
                    snapshotPath: $snapshotPath,
                    snapshotDirectory: $snapshotDirectory,
                    message: $this->responseError($update, 'DataCite did not confirm the requested landing-page URL.'),
                ), false];
            }

            $verification = $this->verifyDataCiteUrl($doi, $targetUrl);
            if (! $verification['verified']) {
                return [$this->record(
                    resource: $resource,
                    localUrl: $localUrl,
                    dataciteUrl: $remoteUrl,
                    localStatus: $localNeedsUpdate ? 'blocked' : 'already_current',
                    dataciteStatus: $verification['authentication_failed'] ? 'authentication_failed' : 'verification_failed',
                    dataciteState: $remoteState,
                    overallStatus: $verification['authentication_failed'] ? 'authentication_failed' : 'datacite_verification_failed',
                    eventId: $eventId,
                    targetUrl: $targetUrl,
                    targetHttpStatus: $probe['http_status'],
                    targetEffectiveUrl: $probe['effective_url'],
                    updateHttpStatus: $verification['http_status'] ?? $updateHttpStatus,
                    snapshotPath: $snapshotPath,
                    snapshotDirectory: $snapshotDirectory,
                    message: $verification['message'],
                ), $verification['authentication_failed']];
            }
        }

        $localStatus = 'already_current';
        if ($localNeedsUpdate) {
            try {
                $localStatus = $this->updateLocalLandingPage($resource, $eventId);
            } catch (Throwable $exception) {
                report($exception);

                return [$this->record(
                    resource: $resource,
                    localUrl: $localUrl,
                    dataciteUrl: $remoteUrl,
                    localStatus: 'update_failed',
                    dataciteStatus: $dataCiteNeedsUpdate ? 'updated' : 'already_current',
                    dataciteState: $remoteState,
                    overallStatus: $dataCiteNeedsUpdate ? 'partial_local_error' : 'local_update_failed',
                    eventId: $eventId,
                    targetUrl: $targetUrl,
                    targetHttpStatus: $probe['http_status'],
                    targetEffectiveUrl: $probe['effective_url'],
                    updateHttpStatus: $updateHttpStatus,
                    snapshotPath: $snapshotPath,
                    snapshotDirectory: $snapshotDirectory,
                    message: Str::limit($exception->getMessage(), 500, ''),
                ), false];
            }

            if ($localStatus === 'concurrent_change') {
                return [$this->record(
                    resource: $resource,
                    localUrl: $localUrl,
                    dataciteUrl: $remoteUrl,
                    localStatus: 'concurrent_change',
                    dataciteStatus: $dataCiteNeedsUpdate ? 'updated' : 'already_current',
                    dataciteState: $remoteState,
                    overallStatus: 'concurrent_change',
                    eventId: $eventId,
                    targetUrl: $targetUrl,
                    targetHttpStatus: $probe['http_status'],
                    targetEffectiveUrl: $probe['effective_url'],
                    updateHttpStatus: $updateHttpStatus,
                    snapshotPath: $snapshotPath,
                    snapshotDirectory: $snapshotDirectory,
                    message: 'The local landing page changed after preflight and was not overwritten.',
                ), false];
            }
        }

        $localUpdated = $localStatus === 'updated';

        return [$this->record(
            resource: $resource,
            localUrl: $localUrl,
            dataciteUrl: $remoteUrl,
            localStatus: $localStatus,
            dataciteStatus: $dataCiteNeedsUpdate ? 'updated' : 'already_current',
            dataciteState: $remoteState,
            overallStatus: match (true) {
                $localUpdated && $dataCiteNeedsUpdate => 'updated_both',
                $localUpdated => 'updated_local',
                default => 'updated_datacite',
            },
            eventId: $eventId,
            targetUrl: $targetUrl,
            targetHttpStatus: $probe['http_status'],
            targetEffectiveUrl: $probe['effective_url'],
            updateHttpStatus: $updateHttpStatus,
            snapshotPath: $snapshotPath,
            snapshotDirectory: $snapshotDirectory,
            message: 'The canonical GEOFON event URL was confirmed in ERNIE and DataCite.',
        ), false];
    }

    private function updateLocalLandingPage(Resource $resource, string $eventId): string
    {
        $landingPage = $resource->landingPage;
        if ($landingPage === null) {
            return 'concurrent_change';
        }

        $expectedDomainId = $landingPage->external_domain_id;
        $expectedPath = $landingPage->external_path;
        $updated = DB::transaction(function () use ($resource, $eventId, $expectedDomainId, $expectedPath): bool {
            $lockedResource = Resource::query()->lockForUpdate()->find($resource->id);
            $lockedLandingPage = LandingPage::query()
                ->where('resource_id', $resource->id)
                ->lockForUpdate()
                ->first();

            if ($lockedResource === null
                || $lockedLandingPage === null
                || $lockedLandingPage->template !== 'external'
                || $lockedLandingPage->external_domain_id !== $expectedDomainId
                || $lockedLandingPage->external_path !== $expectedPath
                || strcasecmp(
                    trim((string) $lockedResource->datacenter()->value('name')),
                    LegacyMetaworksDatacenterLookupService::GEOFON_EVENTS_DATACENTER,
                ) !== 0) {
                return false;
            }

            $domain = LandingPageDomain::query()->firstOrCreate([
                'domain' => GeofonEventLandingPageUrlService::CANONICAL_DOMAIN,
            ]);
            $lockedLandingPage->update([
                'external_domain_id' => $domain->id,
                'external_path' => 'eqinfo/event.php?id='.$eventId,
            ]);

            return true;
        });

        if (! $updated) {
            return 'concurrent_change';
        }

        CacheKey::LANDING_PAGE_DOWNLOAD_URL_SUGGESTIONS->forget();

        return 'updated';
    }

    /**
     * @return array{verified: bool, authentication_failed: bool, http_status: int|null, message: string}
     */
    private function verifyDataCiteUrl(string $doi, string $targetUrl): array
    {
        try {
            $response = $this->client->getDoi($doi);
        } catch (Throwable $exception) {
            report($exception);

            return [
                'verified' => false,
                'authentication_failed' => false,
                'http_status' => null,
                'message' => Str::limit($exception->getMessage(), 500, ''),
            ];
        }

        $authenticationFailed = in_array($response->status(), [401, 403], true);
        $url = $response->json('data.attributes.url');
        $verified = $response->successful()
            && is_string($url)
            && $this->urls->urlsEqual($url, $targetUrl);

        return [
            'verified' => $verified,
            'authentication_failed' => $authenticationFailed,
            'http_status' => $response->status(),
            'message' => $verified
                ? 'DataCite confirmed the canonical landing-page URL.'
                : $this->responseError($response, 'DataCite did not confirm the canonical landing-page URL.'),
        ];
    }

    /** @param array<string, mixed> $remoteRecord */
    private function storeSnapshot(string $directory, string $doi, array $remoteRecord): ?string
    {
        $path = $directory.'/'.hash('sha256', $doi).'.json';

        try {
            $snapshot = json_encode([
                'storedAt' => now()->utc()->toIso8601String(),
                'dataciteClient' => $this->client->repositoryClientId(),
                'data' => $remoteRecord,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            return Storage::disk('local')->put($path, $snapshot) ? $path : null;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function snapshotDirectory(): string
    {
        $root = trim((string) config(
            'datacite.geofon_event_url_repair.snapshot_directory',
            'geofon-event-url-updates',
        ), '/');
        if ($root === '') {
            $root = 'geofon-event-url-updates';
        }

        return $root.'/'.now()->utc()->format('Ymd_His').'-'.Str::lower(Str::random(8));
    }

    private function localUrl(?LandingPage $landingPage): string
    {
        if ($landingPage === null
            || $landingPage->externalDomain === null
            || $landingPage->external_path === null) {
            return '';
        }

        return rtrim($landingPage->externalDomain->domain, '/').'/'.ltrim($landingPage->external_path, '/');
    }

    /** @return array<string, mixed>|null */
    private function remoteRecord(Response $response): ?array
    {
        $record = $response->json('data');

        return is_array($record) ? $record : null;
    }

    /** @param array<string, mixed> $remoteRecord */
    private function remoteDoi(array $remoteRecord): string
    {
        $doi = $this->remoteString($remoteRecord, 'attributes.doi')
            ?? (is_string($remoteRecord['id'] ?? null) ? $remoteRecord['id'] : '');

        return $this->doiNormalizer->normalizeDoi($doi);
    }

    /** @param array<string, mixed> $remoteRecord */
    private function remoteClient(array $remoteRecord): ?string
    {
        return $this->remoteString($remoteRecord, 'attributes.clientId')
            ?? $this->remoteString($remoteRecord, 'relationships.client.data.id');
    }

    /** @param array<string, mixed> $remoteRecord */
    private function remoteString(array $remoteRecord, string $path): ?string
    {
        $value = data_get($remoteRecord, $path);

        return is_string($value) ? trim($value) : null;
    }

    private function responseError(Response $response, string $fallback): string
    {
        $payload = $response->json();
        if (is_array($payload)) {
            $message = data_get($payload, 'errors.0.detail') ?? data_get($payload, 'errors.0.title');
            if (is_string($message) && trim($message) !== '') {
                return Str::limit(trim($message), 500, '');
            }
        }

        return $fallback;
    }

    /**
     * @param  list<string>  $dois
     * @return array<string, true>
     */
    private function normalizeDoiFilter(array $dois): array
    {
        $normalized = [];
        foreach ($dois as $doi) {
            $value = $this->doiNormalizer->normalizeDoi($doi);
            if ($value === '' || ! $this->doiNormalizer->isValidDoiFormat($value)) {
                throw new \InvalidArgumentException("Invalid DOI filter: {$doi}");
            }

            $normalized[$value] = true;
        }

        return $normalized;
    }

    /** @return array<string, mixed> */
    private function record(
        ?Resource $resource = null,
        ?string $doi = null,
        string $localUrl = '',
        string $dataciteUrl = '',
        string $localStatus = 'not_checked',
        string $dataciteStatus = 'not_checked',
        ?string $dataciteState = null,
        string $overallStatus = 'error',
        ?string $eventId = null,
        ?string $targetUrl = null,
        ?int $targetHttpStatus = null,
        ?string $targetEffectiveUrl = null,
        ?int $updateHttpStatus = null,
        ?string $snapshotPath = null,
        ?string $snapshotDirectory = null,
        string $message = '',
    ): array {
        return [
            'resource_id' => $resource?->id,
            'doi' => $doi ?? $this->doiNormalizer->normalizeDoi((string) $resource?->doi),
            'datacenter' => $resource?->datacenter?->name,
            'event_id' => $eventId,
            'local_before_url' => $localUrl,
            'datacite_before_url' => $dataciteUrl,
            'target_url' => $targetUrl,
            'local_status' => $localStatus,
            'datacite_status' => $dataciteStatus,
            'datacite_state' => $dataciteState,
            'target_http_status' => $targetHttpStatus,
            'target_effective_url' => $targetEffectiveUrl,
            'update_http_status' => $updateHttpStatus,
            'overall_status' => $overallStatus,
            'snapshot_path' => $snapshotPath,
            'snapshot_directory' => $snapshotDirectory,
            'message' => Str::limit($message, 500, ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $record
     */
    private function appendRecord(array &$result, array $record): void
    {
        $result['records'][] = $record;
        $status = (string) $record['overall_status'];

        if ($status === 'already_current') {
            $result['already_current']++;
        } elseif (str_starts_with($status, 'would_update_')) {
            $result['would_update']++;
        } elseif (str_starts_with($status, 'updated_')) {
            $result['updated']++;
        } elseif (str_starts_with($status, 'manual_review_')) {
            $result['manual_review']++;
            if ($status === 'manual_review_wrong_datacenter') {
                $result['wrong_datacenter']++;
            }
        } elseif ($status === 'target_unreachable') {
            $result['target_unreachable']++;
            $result['errors']++;
        } elseif ($status === 'concurrent_change') {
            $result['concurrent_changes']++;
            $result['errors']++;
        } elseif (in_array($status, ['skipped_non_geofon_host', 'skipped_limit', 'not_processed_authentication'], true)) {
            $result['skipped']++;
        } else {
            $result['errors']++;
        }

        if (($record['local_status'] ?? null) === 'updated') {
            $result['local_updated']++;
        }
        if (($record['datacite_status'] ?? null) === 'updated') {
            $result['datacite_updated']++;
        }
    }

    /** @return array<string, mixed> */
    private function emptyResult(bool $apply): array
    {
        return [
            'apply' => $apply,
            'resources_scanned' => 0,
            'candidates' => 0,
            'already_current' => 0,
            'would_update' => 0,
            'updated' => 0,
            'local_updated' => 0,
            'datacite_updated' => 0,
            'wrong_datacenter' => 0,
            'manual_review' => 0,
            'target_unreachable' => 0,
            'concurrent_changes' => 0,
            'skipped' => 0,
            'errors' => 0,
            'last_resource_id' => null,
            'snapshot_directory' => null,
            'records' => [],
        ];
    }
}
