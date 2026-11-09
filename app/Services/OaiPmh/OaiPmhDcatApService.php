<?php

declare(strict_types=1);

namespace App\Services\OaiPmh;

use App\Models\OaiPmhDeletedRecord;
use App\Models\OaiPmhFormatRecord;
use App\Models\OaiPmhHarvest;
use App\Models\Resource;
use App\Services\DcatAp\DcatApRdfXmlExporter;
use App\Services\DcatAp\DcatApReadinessService;
use App\Services\DcatAp\DcatApRecordService;
use App\Services\DcatAp\DcatApResourceProfileService;
use DOMElement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Format-specific history; argument and token validation stays in OaiPmhService. */
final class OaiPmhDcatApService
{
    public function __construct(
        private readonly OaiPmhXmlResponseBuilder $xml,
        private readonly OaiPmhResumptionTokenService $tokens,
        private readonly OaiPmhHarvestService $harvests,
        private readonly DcatApRdfXmlExporter $exporter,
        private readonly DcatApResourceProfileService $profile,
        private readonly DcatApReadinessService $readiness,
        private readonly DcatApRecordService $records,
    ) {}

    /** @param array<string, string> $attributes */
    public function getRecord(string $doi, string $identifier, array $attributes): string
    {
        $resource = Resource::query()->where('doi', $doi)->whereHas('landingPage', fn ($q) => $q->where('is_published', true))->first();
        if ($resource !== null) {
            $this->records->reconcile($resource->id);
        }
        $record = OaiPmhFormatRecord::where('metadata_prefix', 'dcat_ap')->where('oai_identifier', $identifier)->first();
        if ($resource === null && $record?->resource_id !== null && $record->status === 'active') {
            $this->records->reconcile($record->resource_id);
            $record->refresh();
        }
        if ($record === null) {
            $known = $resource !== null || OaiPmhDeletedRecord::where('doi', $doi)->exists();

            return $this->error($known ? 'cannotDisseminateFormat' : 'idDoesNotExist', 'This identifier has no DCAT-AP representation', 'GetRecord', $attributes);
        }
        $this->xml->createEnvelope('GetRecord', $attributes);
        $this->append($this->xml->beginGetRecord(), $record, false);

        return $this->xml->toXml();
    }

    /** @param array<string, string> $attributes */
    public function listItems(string $verb, bool $headersOnly, ?string $set, ?Carbon $from, ?Carbon $until, int $cursor, int $pageSize, array $attributes, ?OaiPmhHarvest $harvest, bool $resuming, int $position): string
    {
        if ($resuming && $harvest === null) {
            return $this->error('badResumptionToken', 'The format inventory is missing', $verb, $attributes);
        }
        if ($harvest === null) {
            $query = OaiPmhFormatRecord::where('metadata_prefix', 'dcat_ap')
                ->when($set !== null, fn ($q) => $q->whereJsonContains('sets', $set))
                ->when($from !== null, fn ($q) => $q->where('datestamp', '>=', $from))
                ->when($until !== null, fn ($q) => $q->where('datestamp', '<=', $until));
            $harvest = DB::transaction(function () use ($query): OaiPmhHarvest {
                $harvest = OaiPmhHarvest::create(['item_count' => 0, 'expires_at' => now()->addSeconds((int) config('oaipmh.resumption_token_ttl'))]);
                $count = DB::table('oai_pmh_harvest_items')->insertUsing(['harvest_id', 'position', 'kind', 'identity_id'],
                    $query->selectRaw('? AS harvest_id, ROW_NUMBER() OVER (ORDER BY datestamp, id) - 1 AS position, ? AS kind, id AS identity_id', [$harvest->id, 'format']));
                $harvest->update(['item_count' => $count]);

                return $harvest;
            });
        }
        if ($harvest->item_count === 0) {
            $harvest->delete();

            return $this->error('noRecordsMatch', 'No records match the given criteria', $verb, $attributes);
        }
        $page = $this->harvests->page($harvest, $position, $pageSize);
        $records = OaiPmhFormatRecord::whereIn('id', array_column($page, 'id'))
            ->with(['resource' => fn ($q) => $q->with($this->profile->requiredRelations())])->get()->keyBy('id');
        // Recheck after reading the page: concurrent status/identity/set changes
        // retire the whole inventory rather than silently shrinking its count.
        foreach ($records as $record) {
            if (($from !== null && $record->datestamp->lessThan($from)) || ($until !== null && $record->datestamp->greaterThan($until))) {
                return $this->error($resuming ? 'badResumptionToken' : 'noRecordsMatch', 'A record moved outside the requested date interval; restart harvesting', $verb, $attributes);
            }
            if ($record->status === 'active' && ($record->resource === null || ! $this->readiness->available($record->resource))) {
                if ($record->resource_id !== null) {
                    $this->records->reconcile($record->resource_id);
                }
                // Reconciliation reads a fresh aggregate and may find it restored,
                // leaving the inventory active while this page still holds stale
                // metadata. Never append that copy, even if no history changed.
                $harvest->update(['expires_at' => now()]);

                return $this->error($resuming ? 'badResumptionToken' : 'noRecordsMatch', 'A record changed; restart harvesting', $verb, $attributes);
            }
        }
        if (! OaiPmhHarvest::whereKey($harvest->id)->where('expires_at', '>', now())->exists() || count($page) !== $records->count()) {
            return $this->error($resuming ? 'badResumptionToken' : 'noRecordsMatch', 'The inventory changed; restart harvesting', $verb, $attributes);
        }
        $this->xml->createEnvelope($verb, $attributes);
        $container = $headersOnly ? $this->xml->beginListIdentifiers() : $this->xml->beginListRecords();
        foreach ($page as $item) {
            $record = $records->get($item['id']);
            assert($record !== null);
            $this->append($container, $record, $headersOnly);
        }
        $next = $position + count($page);
        if ($next < $harvest->item_count) {
            $token = $this->tokens->create($verb, 'dcat_ap', $set, $from, $until, $cursor + count($page), $harvest->item_count, $harvest, $next);
            $this->xml->addResumptionToken($container, $token->token, $harvest->item_count, $cursor, $token->expires_at->utc()->format('Y-m-d\TH:i:s\Z'));
        } elseif ($resuming) {
            $this->xml->addResumptionToken($container, null, $harvest->item_count, $cursor);
        } else {
            $harvest->delete();
        }

        return $this->xml->toXml();
    }

    private function append(DOMElement $container, OaiPmhFormatRecord $record, bool $headersOnly): void
    {
        $timestamp = $record->datestamp->utc()->format('Y-m-d\TH:i:s\Z');
        if ($headersOnly) {
            $this->xml->addHeader($container, $record->oai_identifier, $timestamp, $record->sets, $record->status === 'deleted');
        } elseif ($record->status === 'deleted') {
            $this->xml->addDeletedRecord($container, $record->oai_identifier, $timestamp, $record->sets);
        } else {
            assert($record->resource !== null);
            $this->xml->addRecord($container, $record->oai_identifier, $timestamp, $record->sets, $this->exporter->export($record->resource));
        }
    }

    /** @param array<string, string> $attributes */
    private function error(string $code, string $message, string $verb, array $attributes): string
    {
        return $this->xml->createEnvelope($verb, $attributes)->addError($code, $message)->toXml();
    }
}
