<?php

declare(strict_types=1);

use App\Models\DateType;
use App\Models\Description;
use App\Models\Format;
use App\Models\LandingPage;
use App\Models\OaiPmhFormatRecord;
use App\Models\OaiPmhHarvest;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Title;
use App\Services\DcatAp\DcatApRecordService;
use App\Services\DcatAp\RdfDocument;
use Illuminate\Support\Facades\DB;

function oaiDcatResource(array $attributes = []): Resource
{
    $resource = Resource::factory()->create($attributes);
    Title::factory()->create(['resource_id' => $resource->id]);
    Description::factory()->abstract()->create(['resource_id' => $resource->id]);
    LandingPage::factory()->published()->create(['resource_id' => $resource->id, 'doi_prefix' => $resource->doi]);

    return $resource->refresh();
}

function oaiDcatQuery(string $verb, array $arguments = []): string
{
    return '/oai-pmh?'.http_build_query(['verb' => $verb, ...$arguments]);
}

function oaiDcatXPath(string $xml): DOMXPath
{
    $document = new DOMDocument;
    expect($document->loadXML($xml, LIBXML_NONET))->toBeTrue();
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('oai', 'http://www.openarchives.org/OAI/2.0/');
    foreach (RdfDocument::NS as $prefix => $namespace) {
        $xpath->registerNamespace($prefix, $namespace);
    }

    return $xpath;
}

beforeEach(fn () => config(['dcatap.enabled' => true, 'oaipmh.page_size' => 2]));

/** Count actual aggregate reconciliations via their format-history read. */
function oaiDcatReconciledResources(): array
{
    return collect(DB::getQueryLog())->filter(function (array $query): bool {
        $sql = str_replace(['"', '`'], '', $query['query']);

        return str_starts_with($sql, 'select * from oai_pmh_format_records where metadata_prefix = ? and resource_id = ?');
    })->map(fn (array $query): int => (int) $query['bindings'][1])->values()->all();
}

it('reconciles each edited aggregate once after replacing child metadata', function (int $rows): void {
    $resources = [oaiDcatResource(), oaiDcatResource()];
    DB::enableQueryLog();
    try {
        DB::transaction(function () use ($resources, $rows): void {
            foreach ($resources as $resource) {
                foreach ($resource->descriptions as $description) {
                    $description->delete();
                }
                Description::factory()->abstract()->count($rows)->create(['resource_id' => $resource->id]);
            }
            expect(oaiDcatReconciledResources())->toBe([]);
        });
        expect(oaiDcatReconciledResources())->toEqualCanonicalizing(array_map(fn (Resource $resource): int => $resource->id, $resources));
        expect(OaiPmhFormatRecord::where('status', 'active')->count())->toBe(2);
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
})->with([1, 25]);

it('merges touch requests across committed nested transactions', function (bool $outerTouch): void {
    $resource = oaiDcatResource();
    $this->travel(1)->hours();
    $this->freezeSecond();
    DB::enableQueryLog();
    try {
        DB::transaction(function () use ($resource, $outerTouch): void {
            app(DcatApRecordService::class)->schedule($resource->id, $outerTouch);
            DB::transaction(fn () => app(DcatApRecordService::class)->schedule($resource->id, ! $outerTouch));
            app(DcatApRecordService::class)->schedule($resource->id, false);
            expect(oaiDcatReconciledResources())->toBe([]);
        });
        expect(oaiDcatReconciledResources())->toBe([$resource->id])
            ->and($resource->refresh()->updated_at->equalTo(now()))->toBeTrue();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
})->with([false, true]);

it('discards rolled-back nested touch requests and committed descendants', function (): void {
    $kept = oaiDcatResource();
    $discarded = oaiDcatResource();
    $before = $kept->updated_at->toISOString();
    $this->travel(1)->hours();
    DB::enableQueryLog();
    try {
        DB::transaction(function () use ($kept, $discarded): void {
            app(DcatApRecordService::class)->schedule($kept->id, false);
            try {
                DB::transaction(function () use ($kept, $discarded): void {
                    app(DcatApRecordService::class)->schedule($kept->id, true);
                    DB::transaction(fn () => app(DcatApRecordService::class)->schedule($discarded->id));
                    throw new RuntimeException('rollback nested');
                });
            } catch (RuntimeException $exception) {
                expect($exception->getMessage())->toBe('rollback nested');
            }
        });
        expect(oaiDcatReconciledResources())->toBe([$kept->id])
            ->and($kept->refresh()->updated_at->toISOString())->toBe($before);
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
});

it('reschedules after rollback when the first request was inside a nested transaction', function (bool $retryInsideOuter): void {
    $resource = oaiDcatResource();
    DB::enableQueryLog();
    $schedule = fn () => app(DcatApRecordService::class)->schedule($resource->id);
    $rollback = function () use ($schedule): void {
        try {
            DB::transaction(function () use ($schedule): void {
                DB::transaction($schedule);
                throw new RuntimeException('rollback parent');
            });
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())->toBe('rollback parent');
        }
    };
    try {
        if ($retryInsideOuter) {
            DB::transaction(function () use ($rollback, $schedule): void {
                $rollback();
                expect(oaiDcatReconciledResources())->toBe([]);
                DB::transaction($schedule);
            });
        } else {
            $rollback();
            expect(oaiDcatReconciledResources())->toBe([]);
            DB::transaction($schedule);
        }
        expect(oaiDcatReconciledResources())->toBe([$resource->id]);
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
})->with([false, true]);

it('does not retain a pending batch after a reconciliation failure', function (): void {
    $resource = oaiDcatResource();
    $title = config('dcatap.catalog.title');
    config(['dcatap.catalog.title' => '']);
    expect(fn () => DB::transaction(fn () => app(DcatApRecordService::class)->schedule($resource->id)))->toThrow(LogicException::class);
    config(['dcatap.catalog.title' => $title]);
    DB::enableQueryLog();
    try {
        DB::transaction(fn () => app(DcatApRecordService::class)->schedule($resource->id));
        expect(oaiDcatReconciledResources())->toBe([$resource->id]);
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
});

it('registers the format independently of ISO and excludes ineligible identifiers', function () {
    $resource = oaiDcatResource();
    config(['iso19115.enabled' => false]);
    $xml = $this->get(oaiDcatQuery('ListMetadataFormats'))->assertOk()->getContent();
    expect($xml)->toContain('<metadataPrefix>dcat_ap</metadataPrefix>', '<metadataNamespace>http://data.europa.eu/r5r/</metadataNamespace>')->not->toContain('<metadataPrefix>iso19115_3</metadataPrefix>');
    $resource->descriptions->first()->delete();
    $this->get(oaiDcatQuery('ListMetadataFormats', ['identifier' => config('oaipmh.identifier_prefix').':'.$resource->doi]))->assertSee('<metadataPrefix>dcat_ap</metadataPrefix>', false);
    $never = Resource::factory()->create();
    LandingPage::factory()->published()->create(['resource_id' => $never->id]);
    $this->get(oaiDcatQuery('GetRecord', ['identifier' => config('oaipmh.identifier_prefix').':'.$never->doi, 'metadataPrefix' => 'dcat_ap']))->assertSee('code="cannotDisseminateFormat"', false);
    config(['dcatap.enabled' => false]);
    $this->get(oaiDcatQuery('ListMetadataFormats'))->assertDontSee('<metadataPrefix>dcat_ap</metadataPrefix>', false);
});

it('embeds the same RDF as the public download', function () {
    $resource = oaiDcatResource();
    $arguments = ['identifier' => config('oaipmh.identifier_prefix').':'.$resource->doi, 'metadataPrefix' => 'dcat_ap'];
    $oai = oaiDcatXPath($this->get(oaiDcatQuery('GetRecord', $arguments))->assertOk()->getContent());
    $public = oaiDcatXPath($this->get($resource->landingPage->getPublicPath().'/metadata/dcat-ap.rdf')->assertOk()->getContent());
    // Namespace-aware DOM comparison; semantic graph isomorphism is also checked by test:dcat-ap.
    $embedded = $oai->query('//oai:metadata/rdf:RDF')->item(0);
    expect($embedded)->not->toBeNull();
    $document = new DOMDocument;
    $document->appendChild($document->importNode($embedded, true));
    expect($oai->evaluate('string(//dct:title)'))->toBe($public->evaluate('string(//dct:title)'))
        ->and($oai->evaluate('count(//rdf:Description)'))->toBe($public->evaluate('count(//rdf:Description)'))
        ->and($document->schemaValidate(public_path('schemas/oai-pmh/dcat-ap/3.0.1/rdf-envelope.xsd')))->toBeTrue();
    $this->post('/oai-pmh', ['verb' => 'GetRecord', ...$arguments])->assertOk()->assertSee('http://www.w3.org/ns/dcat#Dataset', false);
});

it('persists format-only deletion and restores eligibility with an incremental datestamp', function () {
    $resource = oaiDcatResource();
    $record = OaiPmhFormatRecord::where('resource_id', $resource->id)->firstOrFail();
    $initial = $record->datestamp->copy();
    $this->travel(1)->hours();
    $resource->descriptions->first()->delete();
    expect($record->refresh()->status)->toBe('deleted')->and($record->datestamp->greaterThan($initial))->toBeTrue();
    $arguments = ['identifier' => config('oaipmh.identifier_prefix').':'.$resource->doi];
    $this->get(oaiDcatQuery('GetRecord', [...$arguments, 'metadataPrefix' => 'dcat_ap']))->assertSee('status="deleted"', false)->assertDontSee('<metadata>', false);
    $this->get(oaiDcatQuery('GetRecord', [...$arguments, 'metadataPrefix' => 'oai_dc']))->assertDontSee('status="deleted"', false)->assertSee('<metadata>', false);
    $this->travel(1)->hours();
    Description::factory()->abstract()->create(['resource_id' => $resource->id]);
    expect($record->refresh()->status)->toBe('active')->and(OaiPmhFormatRecord::count())->toBe(1);
    $this->get(oaiDcatQuery('ListIdentifiers', ['metadataPrefix' => 'dcat_ap', 'from' => $initial->addHour()->format('Y-m-d\TH:i:s\Z')]))->assertSee($resource->doi);
});

it('serializes supported date precision as valid XSD literals through both export paths', function (string $value, string $normalized, string $datatype): void {
    $resource = oaiDcatResource();
    DB::transaction(function () use ($resource, $value): void {
        foreach (['Issued', 'Updated', 'Collected'] as $slug) {
            $type = DateType::firstOrCreate(['slug' => $slug], ['name' => $slug]);
            $resource->dates()->create([
                'date_type_id' => $type->id,
                ...($slug === 'Collected' ? ['start_date' => $value, 'end_date' => $value] : ['date_value' => $value]),
            ]);
        }
    });
    $urls = [
        $resource->landingPage->getPublicPath().'/metadata/dcat-ap.rdf',
        oaiDcatQuery('GetRecord', ['metadataPrefix' => 'dcat_ap', 'identifier' => config('oaipmh.identifier_prefix').':'.$resource->doi]),
        oaiDcatQuery('ListRecords', ['metadataPrefix' => 'dcat_ap']),
    ];
    foreach ($urls as $url) {
        $xpath = oaiDcatXPath($this->get($url)->assertOk()->getContent());
        foreach (['//dcat:dataset/rdf:Description/dct:issued', '//dcat:dataset/rdf:Description/dct:modified', '//dcat:startDate', '//dcat:endDate'] as $path) {
            expect($xpath->evaluate('string('.$path.')'))->toBe($normalized)
                ->and($xpath->evaluate('string('.$path.'/@rdf:datatype)'))->toBe('http://www.w3.org/2001/XMLSchema#'.$datatype);
            // libxml independently validates the lexical form, not just our expected text.
            $literal = new DOMDocument;
            $literal->appendChild($literal->createElement('value', $xpath->evaluate('string('.$path.')')));
            expect($literal->schemaValidateSource('<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"><xs:element name="value" type="xs:'.$datatype.'"/></xs:schema>'))->toBeTrue();
        }
    }
})->with([
    ['2026-07-16T12:30Z', '2026-07-16T12:30:00Z', 'dateTime'],
    ['2026-07-16T12:30+02:00', '2026-07-16T12:30:00+02:00', 'dateTime'],
    ['2026-07-16T12:30-03:30', '2026-07-16T12:30:00-03:30', 'dateTime'],
    ['2026-07-16T12:30:45.125Z', '2026-07-16T12:30:45.125Z', 'dateTime'],
    ['-0054', '-0054', 'gYear'],
    ['-0054-03', '-0054-03', 'gYearMonth'],
    ['-0054-03-15', '-0054-03-15', 'date'],
    ['-0054-03-15T12:30Z', '-0054-03-15T12:30:00Z', 'dateTime'],
    ['2026', '2026', 'gYear'],
    ['2026-07', '2026-07', 'gYearMonth'],
    ['2026-07-16', '2026-07-16', 'date'],
]);

it('reconciles the final transaction state without transient withdrawals or rollback changes', function () {
    $resource = oaiDcatResource();
    $record = OaiPmhFormatRecord::where('resource_id', $resource->id)->firstOrFail();
    $this->travel(1)->hours();
    DB::transaction(function () use ($resource, $record): void {
        $resource->descriptions->first()->delete();
        expect($record->fresh()->status)->toBe('active');
        Description::factory()->abstract()->create(['resource_id' => $resource->id]);
    });
    expect($record->refresh()->status)->toBe('active');
    $before = $record->getAttributes();
    try {
        DB::transaction(function () use ($resource): void {
            $resource->refresh()->descriptions->first()->delete();
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }
    expect($record->refresh()->getAttributes())->toBe($before);
});

it('invalidates snapshots when eligibility changes and preserves other page identities on normal edits', function () {
    $resources = collect([oaiDcatResource(), oaiDcatResource(), oaiDcatResource(), oaiDcatResource()]);
    $first = oaiDcatXPath($this->get(oaiDcatQuery('ListRecords', ['metadataPrefix' => 'dcat_ap']))->getContent());
    $token = $first->evaluate('string(//oai:resumptionToken)');
    expect($token)->not->toBe('')->and($first->evaluate('string(//oai:resumptionToken/@completeListSize)'))->toBe('4');
    $resources[0]->titles->first()->update(['value' => 'Corrected title']);
    $page = $this->get(oaiDcatQuery('ListRecords', ['resumptionToken' => $token]))->assertOk();
    $retry = $this->get(oaiDcatQuery('ListRecords', ['resumptionToken' => $token]))->assertOk();
    expect(oaiDcatXPath($page->getContent())->evaluate('string(//oai:header/oai:identifier)'))->toBe(oaiDcatXPath($retry->getContent())->evaluate('string(//oai:header/oai:identifier)'));
    $resources[0]->refresh()->descriptions->first()->delete();
    $this->get(oaiDcatQuery('ListRecords', ['resumptionToken' => $token]))->assertSee('code="badResumptionToken"', false);
});

it('retains old DOI and last eligible sets after identity changes, unpublishing and deletion', function () {
    $resource = oaiDcatResource(['doi' => '10.5880/dcat.old', 'publication_year' => 2020]);
    $old = config('oaipmh.identifier_prefix').':'.$resource->doi;
    $resource->update(['doi' => '10.5880/dcat.new']);
    $this->get(oaiDcatQuery('GetRecord', ['metadataPrefix' => 'dcat_ap', 'identifier' => $old]))->assertSee('status="deleted"', false);
    $resource->refresh()->landingPage->update(['is_published' => false]);
    $this->get(oaiDcatQuery('ListIdentifiers', ['metadataPrefix' => 'dcat_ap', 'set' => 'year:2020']))->assertSee('dcat.old')->assertSee('dcat.new');
    $resource->delete();
    expect(OaiPmhFormatRecord::where('status', 'deleted')->count())->toBe(2);
});

it('rejects a DCAT snapshot containing an unlinked active record', function (?int $resourceId, string $verb): void {
    $resources = [oaiDcatResource(), oaiDcatResource(), oaiDcatResource()];
    $first = oaiDcatXPath($this->get(oaiDcatQuery($verb, ['metadataPrefix' => 'dcat_ap']))->assertOk()->getContent());
    $token = $first->evaluate('string(//oai:resumptionToken)');
    expect($token)->not->toBe('');
    // Simulate a broken historical link without observer-driven invalidation.
    DB::table('oai_pmh_format_records')->where('resource_id', $resources[2]->id)->update(['resource_id' => $resourceId]);

    $response = $this->get(oaiDcatQuery($verb, ['resumptionToken' => $token]))->assertOk();
    $response->assertSee('code="badResumptionToken"', false)->assertDontSee('<'.$verb.'>', false);
    expect(OaiPmhHarvest::where('expires_at', '>', now())->exists())->toBeFalse();
    $this->get(oaiDcatQuery($verb, ['resumptionToken' => $token]))->assertOk()->assertSee('code="badResumptionToken"', false);
    config(['oaipmh.page_size' => 10]);
    $fresh = oaiDcatXPath($this->get(oaiDcatQuery($verb, ['metadataPrefix' => 'dcat_ap']))->assertOk()->getContent());
    expect($fresh->evaluate('count(//oai:header)'))->toBe(3.0)
        ->and($fresh->evaluate('count(//oai:header[@status="deleted"])'))->toBe(1.0);
})->with([null, 999999])->with(['ListRecords', 'ListIdentifiers']);

it('rejects an initial DCAT page containing an active record without a resource link', function (string $verb): void {
    $resource = oaiDcatResource();
    DB::table('oai_pmh_format_records')->where('resource_id', $resource->id)->update(['resource_id' => null]);

    $this->get(oaiDcatQuery($verb, ['metadataPrefix' => 'dcat_ap']))->assertOk()
        ->assertSee('code="noRecordsMatch"', false)->assertDontSee('<'.$verb.'>', false);
    expect(OaiPmhHarvest::where('expires_at', '>', now())->exists())->toBeFalse();
    $fresh = oaiDcatXPath($this->get(oaiDcatQuery($verb, ['metadataPrefix' => 'dcat_ap']))->assertOk()->getContent());
    expect($fresh->evaluate('count(//oai:header)'))->toBe(1.0)
        ->and($fresh->evaluate('count(//oai:header[@status="deleted"])'))->toBe(1.0)
        ->and($fresh->evaluate('count(//oai:metadata)'))->toBe(0.0);
})->with(['ListRecords', 'ListIdentifiers']);

it('restarts a DCAT snapshot if eligibility recovers during reconciliation', function (string $verb): void {
    $resources = [oaiDcatResource(), oaiDcatResource(), oaiDcatResource()];
    $target = $resources[2];
    $first = oaiDcatXPath($this->get(oaiDcatQuery($verb, ['metadataPrefix' => 'dcat_ap']))->assertOk()->getContent());
    $token = $first->evaluate('string(//oai:resumptionToken)');
    expect($token)->not->toBe('');
    $abstract = $target->descriptions->first()->getAttributes();
    DB::table('descriptions')->where('id', $abstract['id'])->delete();
    $reads = 0;
    Resource::retrieved(function (Resource $resource) use ($target, $abstract, &$reads): void {
        if ($resource->id === $target->id && ++$reads === 2) {
            // The page loaded an ineligible aggregate. Another writer restores it
            // before reconciliation reloads it, leaving the page's copy stale.
            DB::table('descriptions')->insert($abstract);
        }
    });

    $response = $this->get(oaiDcatQuery($verb, ['resumptionToken' => $token]))->assertOk();
    expect($reads)->toBe(2);
    $response->assertSee('code="badResumptionToken"', false)->assertDontSee('<'.$verb.'>', false);
    expect(OaiPmhFormatRecord::where('resource_id', $target->id)->value('status'))->toBe('active')
        ->and(OaiPmhHarvest::where('expires_at', '>', now())->exists())->toBeFalse();
    $this->get(oaiDcatQuery($verb, ['resumptionToken' => $token]))->assertOk()->assertSee('code="badResumptionToken"', false);
    config(['oaipmh.page_size' => 10]);
    $fresh = oaiDcatXPath($this->get(oaiDcatQuery($verb, ['metadataPrefix' => 'dcat_ap']))->assertOk()->getContent());
    expect($fresh->evaluate('count(//oai:header)'))->toBe(3.0);
})->with(['ListRecords', 'ListIdentifiers']);

it('retires unavailable records detected while rechecking a DCAT snapshot', function (string $verb): void {
    $resources = [oaiDcatResource(), oaiDcatResource(), oaiDcatResource()];
    $first = oaiDcatXPath($this->get(oaiDcatQuery($verb, ['metadataPrefix' => 'dcat_ap']))->assertOk()->getContent());
    $token = $first->evaluate('string(//oai:resumptionToken)');
    DB::table('descriptions')->where('resource_id', $resources[2]->id)->delete();

    $this->get(oaiDcatQuery($verb, ['resumptionToken' => $token]))->assertOk()->assertSee('code="badResumptionToken"', false);
    expect(OaiPmhFormatRecord::where('resource_id', $resources[2]->id)->value('status'))->toBe('deleted');
    config(['oaipmh.page_size' => 10]);
    $fresh = oaiDcatXPath($this->get(oaiDcatQuery($verb, ['metadataPrefix' => 'dcat_ap']))->assertOk()->getContent());
    expect($fresh->evaluate('count(//oai:header)'))->toBe(3.0)
        ->and($fresh->evaluate('count(//oai:header[@status="deleted"])'))->toBe(1.0);
})->with(['ListRecords', 'ListIdentifiers']);

it('uses a new availability datestamp on backfill and preserves history while disabled', function () {
    config(['dcatap.enabled' => false]);
    $resource = oaiDcatResource(['updated_at' => '2001-01-01']);
    expect(OaiPmhFormatRecord::count())->toBe(0);
    config(['dcatap.enabled' => true]);
    $this->artisan('dcat-ap:sync')->assertSuccessful();
    $record = OaiPmhFormatRecord::firstOrFail();
    expect($record->datestamp->isSameDay(now()))->toBeTrue();
    config(['dcatap.enabled' => false]);
    $resource->descriptions->first()->delete();
    expect($record->fresh()->status)->toBe('active');
    config(['dcatap.enabled' => true]);
    app(DcatApRecordService::class)->reconcile($resource->id);
    expect($record->fresh()->status)->toBe('deleted');
});

it('retires orphaned history with stable deleted headers and invalidates only affected inventories', function (bool $nullLink, string $action): void {
    $this->freezeSecond();
    config(['oaipmh.page_size' => 1]);
    oaiDcatResource(['publication_year' => 2025]);
    oaiDcatResource(['publication_year' => 2025]);
    $resource = oaiDcatResource(['publication_year' => 2000]);
    $record = OaiPmhFormatRecord::where('resource_id', $resource->id)->firstOrFail();
    $tokens = [];
    foreach (['ListRecords', 'ListIdentifiers'] as $verb) {
        $first = oaiDcatXPath($this->get(oaiDcatQuery($verb, ['metadataPrefix' => 'dcat_ap']))->assertOk()->getContent());
        $tokens[$verb] = $first->evaluate('string(//oai:resumptionToken)');
        expect($tokens[$verb])->not->toBe('');
    }
    $unrelated = oaiDcatXPath($this->get(oaiDcatQuery('ListRecords', ['metadataPrefix' => 'dcat_ap', 'set' => 'year:2025']))->assertOk()->getContent());
    $unrelatedToken = $unrelated->evaluate('string(//oai:resumptionToken)');
    expect($unrelatedToken)->not->toBe('');
    // Simulate a deletion missed by observers; format history deliberately has no FK.
    DB::table('resources')->where('id', $resource->id)->delete();
    if ($nullLink) {
        $record->update(['resource_id' => null]);
    }
    $retained = array_diff_key($record->refresh()->getAttributes(), array_flip(['status', 'datestamp']));
    $this->travel(1)->hours();
    $act = function () use ($action, $record): void {
        if ($action === 'sync') {
            $this->artisan('dcat-ap:sync')->assertSuccessful();
        } else {
            $xpath = oaiDcatXPath($this->get(oaiDcatQuery('GetRecord', ['metadataPrefix' => 'dcat_ap', 'identifier' => $record->oai_identifier]))->assertOk()->getContent());
            expect($xpath->evaluate('string(//oai:header/@status)'))->toBe('deleted')
                ->and($xpath->evaluate('string(//oai:header/oai:identifier)'))->toBe($record->oai_identifier)
                ->and($xpath->evaluate('count(//oai:metadata)'))->toBe(0.0);
        }
    };
    $act();
    expect($record->refresh()->status)->toBe('deleted')
        ->and($record->datestamp->equalTo(now()))->toBeTrue()
        ->and(array_diff_key($record->getAttributes(), array_flip(['status', 'datestamp'])))->toBe($retained);
    foreach ($tokens as $verb => $token) {
        $this->get(oaiDcatQuery($verb, ['resumptionToken' => $token]))->assertOk()->assertSee('code="badResumptionToken"', false);
    }
    $this->get(oaiDcatQuery('ListRecords', ['resumptionToken' => $unrelatedToken]))->assertOk()->assertDontSee('<error', false);

    $deletedAt = $record->datestamp->toISOString();
    $fresh = oaiDcatXPath($this->get(oaiDcatQuery('ListRecords', ['metadataPrefix' => 'dcat_ap']))->assertOk()->getContent());
    $freshToken = $fresh->evaluate('string(//oai:resumptionToken)');
    $this->travel(10)->minutes();
    $act();
    expect($record->refresh()->datestamp->toISOString())->toBe($deletedAt);
    $this->get(oaiDcatQuery('ListRecords', ['resumptionToken' => $freshToken]))->assertOk()->assertDontSee('<error', false);
    config(['oaipmh.page_size' => 10]);
    foreach (['ListRecords', 'ListIdentifiers'] as $verb) {
        $page = oaiDcatXPath($this->get(oaiDcatQuery($verb, ['metadataPrefix' => 'dcat_ap']))->assertOk()->getContent());
        expect($page->evaluate('count(//oai:header)'))->toBe(3.0)
            ->and($page->evaluate('count(//oai:header[@status="deleted"])'))->toBe(1.0)
            ->and($page->evaluate('string(//oai:header[@status="deleted"]/oai:setSpec[last()])'))->toBe('year:2000');
    }
})->with([false, true])->with(['sync', 'GetRecord']);

it('cleans orphaned history across chunk boundaries without retiring other formats', function (): void {
    $resource = oaiDcatResource();
    $source = OaiPmhFormatRecord::where('resource_id', $resource->id)->firstOrFail();
    $rows = [];
    for ($index = 0; $index < 205; $index++) {
        $rows[] = array_replace($source->getAttributes(), [
            'id' => null, 'resource_id' => null, 'doi' => '10.5880/orphan.'.$index,
            'oai_identifier' => config('oaipmh.identifier_prefix').':10.5880/orphan.'.$index,
        ]);
    }
    DB::table('oai_pmh_format_records')->insert($rows);
    $other = $source->replicate();
    $other->fill(['metadata_prefix' => 'other_format', 'resource_id' => null])->save();
    $otherBefore = $other->refresh()->getAttributes();

    $this->artisan('dcat-ap:sync')->assertSuccessful();
    expect(OaiPmhFormatRecord::where('metadata_prefix', 'dcat_ap')->where('status', 'deleted')->count())->toBe(205)
        ->and($source->refresh()->status)->toBe('active')
        ->and($other->refresh()->getAttributes())->toBe($otherBefore);
});

it('repairs detached history when a current eligible resource still owns the DOI', function (string $action): void {
    $this->freezeSecond();
    config(['oaipmh.page_size' => 1]);
    $resource = oaiDcatResource();
    oaiDcatResource();
    $record = OaiPmhFormatRecord::where('resource_id', $resource->id)->firstOrFail();
    $original = $record->getAttributes();
    $first = oaiDcatXPath($this->get(oaiDcatQuery('ListRecords', ['metadataPrefix' => 'dcat_ap']))->assertOk()->getContent());
    $token = $first->evaluate('string(//oai:resumptionToken)');
    $record->update(['resource_id' => null]);
    $this->travel(1)->hours();
    if ($action === 'sync') {
        $this->artisan('dcat-ap:sync')->assertSuccessful();
    } else {
        $this->get(oaiDcatQuery('GetRecord', ['metadataPrefix' => 'dcat_ap', 'identifier' => $record->oai_identifier]))
            ->assertOk()->assertSee('<metadata>', false)->assertDontSee('status="deleted"', false);
    }
    expect($record->refresh()->status)->toBe('active')
        ->and($record->resource_id)->toBe($resource->id)
        ->and($record->fingerprint)->toBe($original['fingerprint'])
        ->and($record->first_available_at->toDateTimeString())->toBe($original['first_available_at'])
        ->and($record->datestamp->equalTo(now()))->toBeTrue();
    $this->get(oaiDcatQuery('ListRecords', ['resumptionToken' => $token]))->assertOk()->assertSee('code="badResumptionToken"', false);
})->with(['sync', 'GetRecord']);

it('rechecks orphan retirement against current history and feature availability', function (string $state): void {
    $this->freezeSecond();
    $resource = oaiDcatResource();
    $record = OaiPmhFormatRecord::where('resource_id', $resource->id)->firstOrFail();
    $record->update(['resource_id' => null]);
    match ($state) {
        'relinked' => $record->update(['resource_id' => $resource->id]),
        'deleted' => $record->update(['status' => 'deleted']),
        'other format' => $record->update(['metadata_prefix' => 'other_format']),
        'disabled' => config(['dcatap.enabled' => false]),
        'missing' => $record->delete(),
    };
    $before = $record->fresh()?->getAttributes();
    $harvest = OaiPmhHarvest::create(['item_count' => 1, 'expires_at' => now()->addDay()]);
    DB::table('oai_pmh_harvest_items')->insert([
        'harvest_id' => $harvest->id, 'position' => 0, 'kind' => 'format', 'identity_id' => $record->id,
    ]);
    $expiresAt = $harvest->expires_at->toISOString();
    $this->travel(1)->hours();

    app(DcatApRecordService::class)->retireOrphan($record->id);

    expect($record->fresh()?->getAttributes())->toBe($before)
        ->and($harvest->refresh()->expires_at->toISOString())->toBe($expiresAt);
})->with(['relinked', 'deleted', 'other format', 'disabled', 'missing']);

it('rolls back orphan retirement and inventory invalidation together', function (): void {
    $this->freezeSecond();
    $resource = oaiDcatResource();
    $record = OaiPmhFormatRecord::where('resource_id', $resource->id)->firstOrFail();
    $record->update(['resource_id' => null]);
    $before = $record->refresh()->getAttributes();
    $inventories = [];
    foreach (['format', 'resource'] as $kind) {
        $harvest = OaiPmhHarvest::create(['item_count' => 1, 'expires_at' => now()->addDay()]);
        DB::table('oai_pmh_harvest_items')->insert([
            'harvest_id' => $harvest->id, 'position' => 0, 'kind' => $kind, 'identity_id' => $record->id,
        ]);
        $inventories[$kind] = $harvest;
    }
    $expiresAt = $inventories['format']->expires_at->toISOString();
    $this->travel(1)->hours();
    $service = app(DcatApRecordService::class);

    expect(fn () => DB::transaction(function () use ($service, $record, $inventories): void {
        $service->retireOrphan($record->id);
        expect($record->fresh()->status)->toBe('deleted')
            ->and($inventories['format']->fresh()->expires_at->equalTo(now()))->toBeTrue();
        throw new RuntimeException('Roll back orphan retirement');
    }))->toThrow(RuntimeException::class, 'Roll back orphan retirement');

    expect($record->refresh()->getAttributes())->toBe($before)
        ->and($inventories['format']->refresh()->expires_at->toISOString())->toBe($expiresAt)
        ->and($inventories['resource']->refresh()->expires_at->toISOString())->toBe($expiresAt);
    $service->retireOrphan($record->id);
    expect($record->refresh()->status)->toBe('deleted')
        ->and($inventories['format']->refresh()->expires_at->equalTo(now()))->toBeTrue()
        ->and($inventories['resource']->refresh()->expires_at->toISOString())->toBe($expiresAt);
});

it('updates incremental timestamps for download metadata and shared publisher changes', function () {
    $resource = oaiDcatResource();
    $format = Format::create(['resource_id' => $resource->id, 'value' => 'text/csv']);
    $size = $resource->sizes()->create(['numeric_value' => 1, 'unit' => 'KiB']);
    $resource->landingPage->update(['ftp_url' => 'https://example.org/data.csv', 'ftp_format_id' => $format->id, 'ftp_size_id' => $size->id]);
    $record = OaiPmhFormatRecord::where('resource_id', $resource->id)->firstOrFail();
    foreach ([fn () => $size->update(['numeric_value' => 2]), fn () => $format->update(['value' => 'application/zip']), fn () => $resource->publisher->update(['name' => 'Updated publisher'])] as $mutation) {
        $before = $record->refresh()->datestamp->copy();
        $this->travel(1)->hours();
        $mutation();
        expect($record->refresh()->datestamp->greaterThan($before))->toBeTrue();
    }
    $before = $record->getAttributes();
    $this->travel(1)->hours();
    $resource->landingPage->update(['view_count' => 42]);
    expect($record->refresh()->getAttributes())->toBe($before);
});

it('retains only the last eligible sets when a type becomes unsupported and exposes historical sets', function () {
    $resource = oaiDcatResource(['publication_year' => 1980]);
    $software = ResourceType::firstOrCreate(['slug' => 'software'], ['name' => 'Software']);
    $resource->update(['resource_type_id' => $software->id, 'publication_year' => 2000]);
    $record = OaiPmhFormatRecord::where('resource_id', $resource->id)->firstOrFail();
    expect($record->status)->toBe('deleted')->and($record->sets)->toContain('resourcetype:dataset', 'year:1980')->not->toContain('resourcetype:software');
    $this->get(oaiDcatQuery('ListSets'))->assertSee('<setSpec>year:1980</setSpec>', false);
    $this->get(oaiDcatQuery('ListRecords', ['metadataPrefix' => 'dcat_ap', 'set' => 'resourcetype:software']))->assertSee('code="noRecordsMatch"', false);
});

it('rejects expired, wrong-verb and disabled-format tokens and validates normal date arguments', function () {
    oaiDcatResource();
    oaiDcatResource();
    oaiDcatResource();
    $xml = oaiDcatXPath($this->get(oaiDcatQuery('ListRecords', ['metadataPrefix' => 'dcat_ap']))->getContent());
    $token = $xml->evaluate('string(//oai:resumptionToken)');
    $this->get(oaiDcatQuery('ListIdentifiers', ['resumptionToken' => $token]))->assertSee('code="badResumptionToken"', false);
    $this->get(oaiDcatQuery('ListRecords', ['metadataPrefix' => 'dcat_ap', 'from' => '2026-01-01', 'until' => '2026-01-02T00:00:00Z']))->assertSee('code="badArgument"', false);
    config(['dcatap.enabled' => false]);
    $this->get(oaiDcatQuery('ListRecords', ['resumptionToken' => $token]))->assertSee('code="badResumptionToken"', false);
    config(['dcatap.enabled' => true]);
    $this->travel(2)->days();
    $this->get(oaiDcatQuery('ListRecords', ['resumptionToken' => $token]))->assertSee('code="badResumptionToken"', false);
});

it('does not retire records because of a global configuration failure', function () {
    $resource = oaiDcatResource();
    config(['dcatap.catalog.title' => '']);
    expect(fn () => app(DcatApRecordService::class)->reconcile($resource->id))->toThrow(LogicException::class);
    expect(OaiPmhFormatRecord::where('resource_id', $resource->id)->first()->status)->toBe('active');
});

it('rejects continued date-bounded harvests after a record moves beyond until', function () {
    $this->freezeSecond();
    $resources = collect([oaiDcatResource(), oaiDcatResource(), oaiDcatResource()]);
    $arguments = ['metadataPrefix' => 'dcat_ap', 'from' => now()->format('Y-m-d\TH:i:s\Z'), 'until' => now()->format('Y-m-d\TH:i:s\Z')];
    $first = oaiDcatXPath($this->post('/oai-pmh', ['verb' => 'ListRecords', ...$arguments])->assertOk()->getContent());
    expect($first->evaluate('string(//oai:resumptionToken/@completeListSize)'))->toBe('3');
    $token = $first->evaluate('string(//oai:resumptionToken)');
    $this->travel(1)->hours();
    $resources[2]->titles->first()->update(['value' => 'Updated after the requested interval']);
    $this->get(oaiDcatQuery('ListRecords', ['resumptionToken' => $token]))->assertSee('code="badResumptionToken"', false);
});

it('eager loads metadata without adding a query for every harvested resource', function () {
    for ($index = 0; $index < 10; $index++) {
        oaiDcatResource();
    }
    $counts = [];
    DB::enableQueryLog();
    try {
        foreach ([2, 10] as $size) {
            config(['oaipmh.page_size' => $size]);
            DB::flushQueryLog();
            $response = $this->get(oaiDcatQuery('ListRecords', ['metadataPrefix' => 'dcat_ap']))->assertOk();
            $counts[] = count(DB::getQueryLog());
            expect(oaiDcatXPath($response->getContent())->evaluate('count(//oai:record)'))->toBe((float) $size);
        }
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    expect($counts[1])->toBeLessThanOrEqual($counts[0] + 3);
});
