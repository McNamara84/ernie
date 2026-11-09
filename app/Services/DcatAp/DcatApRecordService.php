<?php

declare(strict_types=1);

namespace App\Services\DcatAp;

use App\Models\LandingPage;
use App\Models\OaiPmhFormatRecord;
use App\Models\OaiPmhHarvest;
use App\Models\Resource;
use App\Services\BotProtection\LandingPageRenderDataCacheService;
use App\Services\OaiPmh\OaiPmhSetService;
use App\Support\IdentifierUrl;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\DB;
use WeakMap;

/** Maintains the public history of a format whose availability depends on metadata quality. */
final class DcatApRecordService
{
    /** @var WeakMap<DatabaseTransactionRecord, WeakMap<DatabaseTransactionRecord, array<int, bool>>> */
    private readonly WeakMap $pending;

    public function __construct(
        private readonly DcatApResourceProfileService $profile,
        private readonly DcatApReadinessService $readiness,
        private readonly DcatApRdfXmlExporter $exporter,
        private readonly OaiPmhSetService $sets,
    ) {
        $this->pending = new WeakMap;
    }

    public function schedule(int $resourceId, bool $touch = true): void
    {
        if (! $this->profile->isEnabled()) {
            return;
        }
        /** @var DatabaseTransactionsManager $manager */
        $manager = app('db.transactions');
        $connection = DB::connection()->getName();
        $transactions = $manager->callbackApplicableTransactions()
            ->filter(fn (DatabaseTransactionRecord $transaction): bool => $transaction->connection === $connection);
        $root = $transactions->first();
        $current = $transactions->last();
        if ($root === null || $current === null) {
            $this->reconcile($resourceId, $touch);

            return;
        }

        // One callback per outer transaction; retain each savepoint's requests
        // separately so rolling it back also discards its touch flags.
        if (! isset($this->pending[$root])) {
            /** @var WeakMap<DatabaseTransactionRecord, array<int, bool>> $batch */
            $batch = new WeakMap;
            $this->pending[$root] = $batch;
            $root->addCallback(function () use ($batch): void {
                $resources = [];
                foreach ($batch as $requests) {
                    foreach ($requests as $id => $requestedTouch) {
                        $resources[$id] = ($resources[$id] ?? false) || $requestedTouch;
                    }
                }
                foreach ($resources as $id => $requestedTouch) {
                    $this->reconcile($id, $requestedTouch);
                }
            });
        }
        $batch = $this->pending[$root];
        if (! isset($batch[$current])) {
            $batch[$current] = [];
            $current->addCallbackForRollback(static function () use ($batch, $current): void {
                unset($batch[$current]);
            });
        }
        $requests = $batch[$current];
        $requests[$resourceId] = ($requests[$resourceId] ?? false) || $touch;
        $batch[$current] = $requests;
    }

    public function reconcile(int $resourceId, bool $touch = false): void
    {
        if (! $this->profile->isEnabled()) {
            return;
        }
        $this->readiness->validateConfiguration();
        DB::transaction(function () use ($resourceId, $touch): void {
            $resource = Resource::query()->lockForUpdate()->find($resourceId);
            $resource?->loadMissing($this->profile->requiredRelations());
            $available = $resource !== null && $this->readiness->errors($resource) === [];
            $doi = $resource === null ? null : IdentifierUrl::doi($resource->doi);
            $identifier = $doi === null ? null : config('oaipmh.identifier_prefix').':'.$doi;
            $records = OaiPmhFormatRecord::query()->where('metadata_prefix', 'dcat_ap')
                ->where('resource_id', $resourceId)->lockForUpdate()->get();
            foreach ($records as $record) {
                if ((! $available || $record->oai_identifier !== $identifier) && $record->status !== 'deleted') {
                    $record->update(['status' => 'deleted', 'datestamp' => now()->startOfSecond()]);
                    $this->invalidate($record->id);
                }
            }
            if ($resource === null || ! $available || $doi === null || $identifier === null) {
                return;
            }
            if ($touch && ($resource->updated_at === null || $resource->updated_at->lessThan(now()->startOfSecond()))) {
                $resource->updated_at = now()->startOfSecond();
                Resource::query()->whereKey($resourceId)->update(['updated_at' => $resource->updated_at]);
            }
            $sets = $this->sets->getSetsForResource($resource);
            $fingerprint = hash('sha256', $this->exporter->export($resource).json_encode($sets, JSON_THROW_ON_ERROR));
            $record = OaiPmhFormatRecord::query()->where('metadata_prefix', 'dcat_ap')->where('oai_identifier', $identifier)->lockForUpdate()->first();
            if ($record !== null && $record->status === 'active' && $record->fingerprint === $fingerprint
                && $record->export_revision === (int) config('dcatap.revision')) {
                return;
            }
            if ($record !== null && ($record->status !== 'active' || $record->sets !== $sets)) {
                $this->invalidate($record->id);
            }
            $datestamp = now()->startOfSecond();
            foreach ([$resource->updated_at, $resource->landingPage?->published_at, $record?->datestamp] as $time) {
                if ($time !== null && $time->greaterThan($datestamp)) {
                    $datestamp = $time->copy();
                }
            }
            OaiPmhFormatRecord::updateOrCreate(['metadata_prefix' => 'dcat_ap', 'oai_identifier' => $identifier], [
                'doi' => $doi, 'resource_id' => $resourceId, 'status' => 'active',
                'first_available_at' => $record->first_available_at ?? $datestamp,
                'datestamp' => $datestamp, 'sets' => $sets, 'resource_type' => $resource->resourceType?->slug,
                'is_igsn' => false, 'export_revision' => (int) config('dcatap.revision'), 'fingerprint' => $fingerprint,
            ]);
        });
        $landing = LandingPage::where('resource_id', $resourceId)->first();
        if ($landing !== null) {
            app(LandingPageRenderDataCacheService::class)->forget($landing);
        }
    }

    private function invalidate(int $recordId): void
    {
        OaiPmhHarvest::query()->whereIn('id', DB::table('oai_pmh_harvest_items')->select('harvest_id')
            ->where('kind', 'format')->where('identity_id', $recordId))->update(['expires_at' => now()]);
    }
}
