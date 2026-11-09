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
use Illuminate\Support\Facades\DB;

/** Maintains the public history of a format whose availability depends on metadata quality. */
final class DcatApRecordService
{
    public function __construct(
        private readonly DcatApResourceProfileService $profile,
        private readonly DcatApReadinessService $readiness,
        private readonly DcatApRdfXmlExporter $exporter,
        private readonly OaiPmhSetService $sets,
    ) {}

    public function schedule(int $resourceId, bool $touch = true): void
    {
        if (! $this->profile->isEnabled()) {
            return;
        }
        // Child collections are often replaced inside a transaction. Evaluate its
        // final aggregate only; a temporary empty collection is not a withdrawal.
        DB::afterCommit(fn () => $this->reconcile($resourceId, $touch));
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
