<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Resource;
use App\Support\DataCiteSchemaVersion;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class DataCiteSchemaUpgradeService
{
    public function __construct(
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
        $remoteRecords = $this->fetchAllRemoteRecords();
        $resourcesByDoi = $this->resourcesByDoi();
        $expectedClient = $this->client->repositoryClientId();
        $allowedPrefixes = $this->client->prefixes();
        $igsnPrefix = $this->client->igsnPrefix();
        $seenFilteredDois = [];
        $candidates = [];
        $result = $this->emptyResult($apply);
        $result['remote_scanned'] = count($remoteRecords);

        foreach ($remoteRecords as $remoteRecord) {
            $doi = $this->remoteDoi($remoteRecord);
            if ($doi === '') {
                if ($doiFilter === []) {
                    $this->appendRecord($result, $this->record(
                        remoteRecord: $remoteRecord,
                        status: 'error',
                        message: 'DataCite returned a record without a DOI.',
                    ));
                }

                continue;
            }

            if ($doiFilter !== [] && ! isset($doiFilter[$doi])) {
                continue;
            }

            $seenFilteredDois[$doi] = true;
            $result['selected']++;
            $localMatches = $resourcesByDoi[$doi] ?? [];
            $resource = count($localMatches) === 1 ? $localMatches[0] : null;
            $schemaVersion = $this->remoteString($remoteRecord, 'attributes.schemaVersion');

            if (DataCiteSchemaVersion::isKernel4($schemaVersion)) {
                $this->appendRecord($result, $this->record(
                    remoteRecord: $remoteRecord,
                    resource: $resource,
                    status: 'already_current',
                    message: 'The DataCite record already uses Kernel 4.',
                ));

                continue;
            }

            $remoteClient = $this->remoteClient($remoteRecord);
            if ($remoteClient !== null && strcasecmp($remoteClient, $expectedClient) !== 0) {
                $this->appendRecord($result, $this->record(
                    remoteRecord: $remoteRecord,
                    resource: $resource,
                    status: 'manual_review',
                    message: "The record belongs to unexpected DataCite client {$remoteClient}.",
                ));

                continue;
            }

            if (! DataCiteSchemaVersion::isKnownLegacy($schemaVersion)) {
                $label = $schemaVersion === null || trim($schemaVersion) === '' ? '(missing)' : $schemaVersion;
                $this->appendRecord($result, $this->record(
                    remoteRecord: $remoteRecord,
                    resource: $resource,
                    status: 'manual_review',
                    message: "Unknown DataCite schema version {$label}.",
                ));

                continue;
            }

            $prefix = $this->doiPrefix($doi);
            if ($igsnPrefix !== '' && $prefix === $igsnPrefix) {
                $this->appendRecord($result, $this->record(
                    remoteRecord: $remoteRecord,
                    resource: $resource,
                    status: 'excluded_igsn',
                    message: 'IGSN records are outside the scope of this resource upgrade.',
                ));

                continue;
            }

            if (! in_array($prefix, $allowedPrefixes, true)) {
                $this->appendRecord($result, $this->record(
                    remoteRecord: $remoteRecord,
                    resource: $resource,
                    status: 'excluded_prefix',
                    message: "DOI prefix {$prefix} is not configured for this repository.",
                ));

                continue;
            }

            if ($localMatches === []) {
                $this->appendRecord($result, $this->record(
                    remoteRecord: $remoteRecord,
                    status: 'not_imported',
                    message: 'No local ERNIE resource has this DOI.',
                ));

                continue;
            }

            if (count($localMatches) !== 1) {
                $this->appendRecord($result, $this->record(
                    remoteRecord: $remoteRecord,
                    status: 'manual_review',
                    message: 'Multiple local ERNIE resources normalize to this DOI.',
                ));

                continue;
            }

            if ($resource === null) {
                throw new \LogicException('A unique local resource match unexpectedly resolved to null.');
            }

            if ($resource->id <= $afterId) {
                $this->appendRecord($result, $this->record(
                    remoteRecord: $remoteRecord,
                    resource: $resource,
                    status: 'skipped_before_id',
                    message: "Resource ID is not greater than --after-id={$afterId}.",
                ));

                continue;
            }

            if ($resource->isIgsn()) {
                $this->appendRecord($result, $this->record(
                    remoteRecord: $remoteRecord,
                    resource: $resource,
                    status: 'excluded_igsn',
                    message: 'Physical Object resources are handled by the separate IGSN workflow.',
                ));

                continue;
            }

            $preflightError = $this->preflightError($remoteRecord);
            if ($preflightError !== null) {
                $this->appendRecord($result, $this->record(
                    remoteRecord: $remoteRecord,
                    resource: $resource,
                    status: 'manual_review',
                    message: $preflightError,
                ));

                continue;
            }

            $candidates[] = [
                'remote' => $remoteRecord,
                'resource' => $resource,
            ];
        }

        foreach (array_diff_key($doiFilter, $seenFilteredDois) as $missingDoi => $_unused) {
            $this->appendRecord($result, $this->record(
                doi: $missingDoi,
                status: 'error',
                message: 'The requested DOI was not returned by the configured DataCite repository.',
            ));
        }

        usort(
            $candidates,
            static fn (array $left, array $right): int => $left['resource']->id <=> $right['resource']->id,
        );
        $result['candidates'] = count($candidates);
        $snapshotDirectory = $apply && $candidates !== [] ? $this->snapshotDirectory() : null;
        $result['snapshot_directory'] = $snapshotDirectory;
        $processed = 0;
        $aborted = false;

        foreach ($candidates as $candidate) {
            /** @var Resource $resource */
            $resource = $candidate['resource'];
            /** @var array<string, mixed> $remoteRecord */
            $remoteRecord = $candidate['remote'];

            if ($limit > 0 && $processed >= $limit) {
                $this->appendRecord($result, $this->record(
                    remoteRecord: $remoteRecord,
                    resource: $resource,
                    status: 'skipped_limit',
                    message: "The --limit={$limit} candidate limit was reached.",
                ));

                continue;
            }

            if ($aborted) {
                $this->appendRecord($result, $this->record(
                    remoteRecord: $remoteRecord,
                    resource: $resource,
                    status: 'not_processed',
                    message: 'The apply run was aborted after an authentication or authorization failure.',
                ));

                continue;
            }

            $processed++;
            $result['last_resource_id'] = $resource->id;

            if (! $apply) {
                $this->appendRecord($result, $this->record(
                    remoteRecord: $remoteRecord,
                    resource: $resource,
                    status: 'would_update',
                    message: 'Dry run: the resource is eligible for a Kernel 4 upgrade.',
                ));

                continue;
            }

            $record = $this->applyUpgrade($remoteRecord, $resource, (string) $snapshotDirectory);
            $this->appendRecord($result, $record);
            if ($record['status'] === 'authentication_failed') {
                $aborted = true;
            }
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function fetchAllRemoteRecords(): array
    {
        $pageSize = max(1, min(1000, (int) config('datacite.schema_upgrade.page_size', 1000)));
        $pageNumber = 1;
        $records = [];

        do {
            if ($pageNumber > 10_000) {
                throw new \RuntimeException('DataCite pagination exceeded the safety limit of 10,000 pages.');
            }

            $response = $this->client->listDois($pageNumber, $pageSize);
            $response->throw();
            $payload = $response->json();
            if (! is_array($payload) || ! isset($payload['data']) || ! is_array($payload['data']) || ! array_is_list($payload['data'])) {
                throw new \RuntimeException("DataCite DOI list page {$pageNumber} is not a valid JSON:API document.");
            }

            $pageRecords = array_values(array_filter(
                $payload['data'],
                static fn (mixed $record): bool => is_array($record),
            ));
            array_push($records, ...$pageRecords);

            $totalPages = data_get($payload, 'meta.totalPages');
            $hasKnownLastPage = is_int($totalPages) || (is_string($totalPages) && ctype_digit($totalPages));
            $isLastPage = $hasKnownLastPage
                ? $pageNumber >= (int) $totalPages
                : count($pageRecords) < $pageSize;
            $pageNumber++;
        } while (! $isLastPage);

        return $records;
    }

    /** @return array<string, list<Resource>> */
    private function resourcesByDoi(): array
    {
        $resources = Resource::query()
            ->with('resourceType')
            ->whereNotNull('doi')
            ->where('doi', '!=', '')
            ->get(['id', 'doi', 'resource_type_id']);
        $indexed = [];

        foreach ($resources as $resource) {
            $doi = $this->doiNormalizer->normalizeDoi((string) $resource->doi);
            if ($doi !== '') {
                $indexed[$doi][] = $resource;
            }
        }

        return $indexed;
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

    /**
     * @param  array<string, mixed>  $remoteRecord
     * @return array<string, mixed>
     */
    private function applyUpgrade(array $remoteRecord, Resource $resource, string $snapshotDirectory): array
    {
        $doi = $this->remoteDoi($remoteRecord);
        $attempts = 0;
        $snapshotPath = "{$snapshotDirectory}/".hash('sha256', $doi).'.json';

        try {
            $snapshot = json_encode([
                'storedAt' => now()->utc()->toIso8601String(),
                'dataciteClient' => $this->client->repositoryClientId(),
                'data' => $remoteRecord,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (! Storage::disk('local')->put($snapshotPath, $snapshot)) {
                throw new \RuntimeException('Unable to store the private pre-upgrade snapshot.');
            }

            $types = [
                'resourceTypeGeneral' => $this->remoteString($remoteRecord, 'attributes.types.resourceTypeGeneral'),
            ];
            $specificType = $this->remoteString($remoteRecord, 'attributes.types.resourceType');
            if ($specificType !== null && trim($specificType) !== '') {
                $types['resourceType'] = $specificType;
            }

            $payload = [
                'data' => [
                    'id' => $doi,
                    'type' => 'dois',
                    'attributes' => [
                        'doi' => $doi,
                        'schemaVersion' => DataCiteSchemaVersion::KERNEL_4,
                        'types' => $types,
                    ],
                ],
            ];

            $attempts++;
            $response = $this->client->updateDoi($doi, $payload);
            if (in_array($response->status(), [401, 403], true)) {
                return $this->record(
                    remoteRecord: $remoteRecord,
                    resource: $resource,
                    status: 'authentication_failed',
                    message: $this->responseError($response, 'DataCite rejected the configured repository credentials.'),
                    httpStatus: $response->status(),
                    attempts: $attempts,
                    snapshotPath: $snapshotPath,
                );
            }
            if (! $response->successful()) {
                return $this->record(
                    remoteRecord: $remoteRecord,
                    resource: $resource,
                    status: 'error',
                    message: $this->responseError($response, 'DataCite rejected the schema upgrade.'),
                    httpStatus: $response->status(),
                    attempts: $attempts,
                    snapshotPath: $snapshotPath,
                );
            }

            if (! $this->responseUsesKernel4($response)) {
                $attempts++;
                $verification = $this->client->getDoi($doi);
                if (in_array($verification->status(), [401, 403], true)) {
                    return $this->record(
                        remoteRecord: $remoteRecord,
                        resource: $resource,
                        status: 'authentication_failed',
                        message: $this->responseError($verification, 'DataCite rejected the verification request.'),
                        httpStatus: $verification->status(),
                        attempts: $attempts,
                        snapshotPath: $snapshotPath,
                    );
                }
                if (! $verification->successful() || ! $this->responseUsesKernel4($verification)) {
                    return $this->record(
                        remoteRecord: $remoteRecord,
                        resource: $resource,
                        status: 'verification_failed',
                        message: $this->responseError($verification, 'DataCite did not confirm Kernel 4 after the update.'),
                        httpStatus: $verification->status(),
                        attempts: $attempts,
                        snapshotPath: $snapshotPath,
                    );
                }
            }

            return $this->record(
                remoteRecord: $remoteRecord,
                resource: $resource,
                status: 'updated',
                message: 'DataCite confirmed the Kernel 4 schema upgrade.',
                httpStatus: $response->status(),
                attempts: $attempts,
                verifiedAt: now()->utc()->toIso8601String(),
                snapshotPath: $snapshotPath,
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->record(
                remoteRecord: $remoteRecord,
                resource: $resource,
                status: 'error',
                message: Str::limit($exception->getMessage(), 500, ''),
                attempts: $attempts,
                snapshotPath: Storage::disk('local')->exists($snapshotPath) ? $snapshotPath : null,
            );
        }
    }

    /** @param array<string, mixed> $remoteRecord */
    private function preflightError(array $remoteRecord): ?string
    {
        $resourceTypeGeneral = $this->remoteString($remoteRecord, 'attributes.types.resourceTypeGeneral');
        if ($resourceTypeGeneral === null || trim($resourceTypeGeneral) === '') {
            return 'The DataCite record has no types.resourceTypeGeneral value.';
        }
        if (! DataCiteSchemaVersion::isKernel4ResourceType($resourceTypeGeneral)) {
            return "The resourceTypeGeneral value {$resourceTypeGeneral} is not valid in Kernel 4.";
        }

        $contributors = data_get($remoteRecord, 'attributes.contributors', []);
        if (is_array($contributors)) {
            foreach ($contributors as $contributor) {
                if (is_array($contributor)
                    && strcasecmp((string) ($contributor['contributorType'] ?? ''), 'Funder') === 0) {
                    return 'The DataCite record contains the legacy contributorType Funder.';
                }
            }
        }

        return null;
    }

    private function responseUsesKernel4(Response $response): bool
    {
        $payload = $response->json();

        return is_array($payload)
            && DataCiteSchemaVersion::isKernel4(
                is_string(data_get($payload, 'data.attributes.schemaVersion'))
                    ? data_get($payload, 'data.attributes.schemaVersion')
                    : null,
            );
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

        return is_string($value) ? $value : null;
    }

    private function doiPrefix(string $doi): string
    {
        return strtolower(explode('/', $doi, 2)[0]);
    }

    private function snapshotDirectory(): string
    {
        $root = trim((string) config('datacite.schema_upgrade.snapshot_directory', 'datacite-schema-upgrades'), '/');
        if ($root === '') {
            $root = 'datacite-schema-upgrades';
        }

        return $root.'/'.now()->utc()->format('Ymd_His').'-'.Str::lower(Str::random(8));
    }

    /**
     * @param  array<string, mixed>  $remoteRecord
     * @return array<string, mixed>
     */
    private function record(
        array $remoteRecord = [],
        ?Resource $resource = null,
        ?string $doi = null,
        string $status = 'error',
        string $message = '',
        ?int $httpStatus = null,
        int $attempts = 0,
        ?string $verifiedAt = null,
        ?string $snapshotPath = null,
    ): array {
        return [
            'resource_id' => $resource?->id,
            'doi' => $doi ?? $this->remoteDoi($remoteRecord),
            'datacite_client' => $this->remoteClient($remoteRecord) ?? $this->client->repositoryClientId(),
            'state' => $this->remoteString($remoteRecord, 'attributes.state'),
            'source_schema' => $this->remoteString($remoteRecord, 'attributes.schemaVersion'),
            'target_schema' => DataCiteSchemaVersion::KERNEL_4,
            'status' => $status,
            'resource_type_general' => $this->remoteString($remoteRecord, 'attributes.types.resourceTypeGeneral'),
            'http_status' => $httpStatus,
            'attempts' => $attempts,
            'verified_at' => $verifiedAt,
            'message' => $message,
            'snapshot_path' => $snapshotPath,
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $record
     */
    private function appendRecord(array &$result, array $record): void
    {
        $result['records'][] = $record;

        match ($record['status']) {
            'already_current' => $result['already_current']++,
            'would_update' => $result['would_update']++,
            'updated' => $result['updated']++,
            'manual_review' => $result['manual_review']++,
            'not_imported' => $result['not_imported']++,
            'excluded_igsn', 'excluded_prefix' => $result['excluded']++,
            'error', 'verification_failed', 'authentication_failed' => $result['errors']++,
            default => $result['skipped']++,
        };
    }

    /** @return array<string, mixed> */
    private function emptyResult(bool $apply): array
    {
        return [
            'apply' => $apply,
            'remote_scanned' => 0,
            'selected' => 0,
            'candidates' => 0,
            'already_current' => 0,
            'would_update' => 0,
            'updated' => 0,
            'manual_review' => 0,
            'not_imported' => 0,
            'excluded' => 0,
            'skipped' => 0,
            'errors' => 0,
            'last_resource_id' => null,
            'snapshot_directory' => null,
            'records' => [],
        ];
    }
}
