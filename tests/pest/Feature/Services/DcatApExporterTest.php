<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Enums\DcatApFormat;
use App\Models\ContributorType;
use App\Models\DateType;
use App\Models\Description;
use App\Models\Format;
use App\Models\IdentifierType;
use App\Models\IgsnMetadata;
use App\Models\Institution;
use App\Models\LandingPage;
use App\Models\LandingPageDomain;
use App\Models\OaiPmhFormatRecord;
use App\Models\Person;
use App\Models\RelationType;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\ResourceType;
use App\Models\Title;
use App\Models\TitleType;
use App\Services\DcatAp\DcatApDataThemeResolver;
use App\Services\DcatAp\DcatApRdfXmlExporter;
use App\Services\DcatAp\DcatApReadinessService;
use App\Services\DcatAp\DcatApRecordService;
use App\Services\DcatAp\RdfDocument;
use App\Services\LandingPageMetadataLinkService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;
use Tests\Fixtures\DcatApExampleMetadata;

function dcatResource(array $attributes = []): Resource
{
    $resource = Resource::factory()->create($attributes);
    Title::factory()->create(['resource_id' => $resource->id, 'value' => 'Magnetic & seismic observations']);
    Description::factory()->abstract()->create(['resource_id' => $resource->id, 'value' => 'Measurements: 0 < x < 2; α & β.', 'language' => null]);
    LandingPage::factory()->published()->create(['resource_id' => $resource->id, 'doi_prefix' => $resource->doi, 'slug' => 'observations']);

    return $resource->refresh();
}

function dcatXPath(string $xml): DOMXPath
{
    $document = new DOMDocument;
    expect($document->loadXML($xml, LIBXML_NONET))->toBeTrue();
    $xpath = new DOMXPath($document);
    foreach (RdfDocument::NS as $prefix => $namespace) {
        $xpath->registerNamespace($prefix, $namespace);
    }

    return $xpath;
}

beforeEach(fn () => config(['dcatap.enabled' => true]));

it('exports a deterministic catalog with a single dataset, record and fallback distribution', function () {
    $resource = dcatResource();
    $exporter = app(DcatApRdfXmlExporter::class);
    $xml = $exporter->export($resource);
    $xpath = dcatXPath($xml);
    expect($xpath->evaluate('count(//dcat:dataset)'))->toBe(1.0)
        ->and($xpath->evaluate('string(//dcat:dataset/rdf:Description/@rdf:about)'))->toBe('https://doi.org/'.$resource->doi)
        ->and($xpath->evaluate('string(//dcat:dataset/rdf:Description/dct:description)'))->toBe('Measurements: 0 < x < 2; α & β.')
        ->and($xpath->evaluate('count(//dcat:dataset/rdf:Description/dct:description/@xml:lang)'))->toBe(0.0)
        ->and($xpath->evaluate('count(//dcat:downloadURL)'))->toBe(0.0)
        ->and($xpath->evaluate('string(//vcard:hasEmail/@rdf:resource)'))->toBe('mailto:datapub@gfz.de')
        ->and($xml)->not->toContain('rdf:nodeID');
    $this->travel(2)->days();
    expect($exporter->export($resource))->toBe($xml);
    expect($xpath->document->schemaValidate(public_path('schemas/oai-pmh/dcat-ap/3.0.1/rdf-envelope.xsd')))->toBeTrue();
});

it('offers identical public RDF and metadata discovery', function () {
    $resource = dcatResource();
    $url = $resource->landingPage->getPublicPath().'/metadata/dcat-ap.rdf';
    $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/rdf+xml; charset=UTF-8');
    expect($response->getContent())->toBe(app(DcatApRdfXmlExporter::class)->export($resource));
    $links = app(LandingPageMetadataLinkService::class)->for($resource, $resource->landingPage);
    expect(collect($links)->firstWhere('format', 'dcat-ap')['url'])->toBe(url($url));
    config(['dcatap.enabled' => false]);
    $this->get($url)->assertNotFound();
    expect(collect(app(LandingPageMetadataLinkService::class)->for($resource, $resource->landingPage))->contains('format', 'dcat-ap'))->toBeFalse();
});

it('excludes unsupported types including physical objects', function (string $slug, bool $available) {
    $type = ResourceType::firstOrCreate(['slug' => $slug], ['name' => $slug, 'is_active' => true]);
    $resource = dcatResource(['resource_type_id' => $type->id]);
    config(['dcatap.epos.enabled' => true]);
    foreach (DcatApFormat::cases() as $profile) {
        expect(app(DcatApReadinessService::class)->available($resource, $profile))->toBe($available);
        $this->get($resource->landingPage->getPublicPath().'/metadata/'.$profile->slug().'.rdf')->assertStatus($available ? 200 : 404);
    }
})->with(['dataset' => ['dataset', true], 'collection' => ['collection', true], 'model' => ['model', true], 'image' => ['image', true], 'sound' => ['sound', true], 'software' => ['software', false], 'sample' => ['physical-object', false], 'instrument' => ['instrument', false], 'service' => ['service', false]]);

it('does not substitute methods for an absent abstract', function () {
    $resource = dcatResource();
    $resource->descriptions()->delete();
    Description::factory()->methods()->create(['resource_id' => $resource->id]);
    expect(app(DcatApReadinessService::class)->errors($resource->refresh()))->toContain('missing_abstract');
    $this->get($resource->landingPage->getPublicPath().'/metadata/dcat-ap.rdf')->assertNotFound();
});

it('retains unregistered MIME downloads without asserting IANA registration', function () {
    $resource = dcatResource();
    $format = Format::create(['resource_id' => $resource->id, 'value' => 'application/x-netcdf']);
    $resource->landingPage->update(['ftp_url' => 'https://example.org/data.nc', 'ftp_format_id' => $format->id]);
    $xpath = dcatXPath(app(DcatApRdfXmlExporter::class)->export($resource->refresh()));
    expect($xpath->evaluate('string(//dcat:downloadURL/@rdf:resource)'))->toBe('https://example.org/data.nc')
        ->and($xpath->evaluate('count(//dcat:mediaType)'))->toBe(0.0)
        ->and($xpath->evaluate('string(//dct:format/rdf:Description/@rdf:about)'))->toBe('http://publications.europa.eu/resource/authority/file-type/NETCDF');
});

it('retains tombstone metadata without downloads', function () {
    $resource = dcatResource(['access_level' => AccessLevel::OPEN]);
    $resource->landingPage->forceFill(['is_tombstone' => true, 'tombstone_statement' => 'Superseded.', 'ftp_url' => 'https://example.org/data.csv'])->save();
    $xpath = dcatXPath(app(DcatApRdfXmlExporter::class)->export($resource->refresh()));
    expect($xpath->evaluate('count(//dcat:downloadURL)'))->toBe(0.0)
        ->and($xpath->evaluate('string(//dct:accessRights/rdf:Description/@rdf:about)'))->toEndWith('/NON_PUBLIC')
        ->and($xpath->evaluate('string(//dcat:dataset/rdf:Description/dct:description[last()])'))->toContain('withdrawn', 'Superseded.');
});

it('maps access rights and suppresses embargoed downloads', function (string $level, string $code) {
    $resource = dcatResource(['access_level' => $level]);
    $format = Format::create(['resource_id' => $resource->id, 'value' => 'text/csv']);
    $resource->landingPage->update(['ftp_url' => 'https://example.org/data.csv', 'ftp_format_id' => $format->id]);
    $xpath = dcatXPath(app(DcatApRdfXmlExporter::class)->export($resource->refresh()));
    expect($xpath->evaluate('string(//dct:accessRights/rdf:Description/@rdf:about)'))->toEndWith('/'.$code);
    if ($level === 'embargoed') {
        expect($xpath->evaluate('count(//dcat:downloadURL)'))->toBe(0.0);
    }
})->with([['open', 'PUBLIC'], ['restricted', 'RESTRICTED'], ['embargoed', 'RESTRICTED'], ['metadata-only', 'NON_PUBLIC']]);

it('preserves distinct licenses as rights and collapses duplicate license URLs', function () {
    $resource = dcatResource();
    foreach ([['License A', 'https://example.org/a'], ['License B', 'https://example.org/b']] as [$label, $uri]) {
        $resource->resourceRights()->create(['rights_text' => $label, 'rights_uri' => $uri]);
    }
    $xpath = dcatXPath(app(DcatApRdfXmlExporter::class)->export($resource->refresh()));
    expect($xpath->evaluate('count(//dcat:distribution//dct:license)'))->toBe(0.0)
        ->and($xpath->evaluate('string(//dcat:distribution//dct:rights//rdfs:label)'))->toContain('License A', 'License B');
    $resource->resourceRights->last()->update(['rights_uri' => 'https://example.org/a', 'rights_text' => 'License A']);
    $resource->resourceRights()->create(['rights_uri' => 'http://purl.org/coar/access_right/c_abf2', 'rights_text' => 'Open access']);
    $xpath = dcatXPath(app(DcatApRdfXmlExporter::class)->export($resource->refresh()));
    expect($xpath->evaluate('count(//dcat:distribution//dct:license)'))->toBe(1.0)
        ->and($xpath->evaluate('count(//dcat:distribution//dct:rights)'))->toBe(0.0);
});

it('does not infer license equivalence from URL spelling differences', function (string $otherUri) {
    $resource = dcatResource();
    $resource->resourceRights()->create(['rights_text' => 'License A', 'rights_uri' => 'https://example.org/a']);
    $resource->resourceRights()->create(['rights_text' => 'License B', 'rights_uri' => $otherUri]);
    $xpath = dcatXPath(app(DcatApRdfXmlExporter::class)->export($resource->refresh()));
    expect($xpath->evaluate('count(//dcat:distribution//dct:license)'))->toBe(0.0)
        ->and($xpath->evaluate('string(//dcat:distribution//dct:rights//rdfs:label)'))->toContain('License A', 'License B', 'https://example.org/a', $otherUri);
})->with(['http://example.org/a', 'https://example.org/a/']);

it('exports unresolved rights without a catalog relation through both transports', function (?string $identifier): void {
    $resource = dcatResource();
    $resource->resourceRights()->create([
        'rights_id' => null, 'rights_text' => null, 'rights_uri' => null, 'rights_identifier' => $identifier,
    ]);
    $urls = [
        $resource->landingPage->getPublicPath().'/metadata/dcat-ap.rdf',
        '/oai-pmh?'.http_build_query(['verb' => 'GetRecord', 'metadataPrefix' => 'dcat_ap', 'identifier' => config('oaipmh.identifier_prefix').':'.$resource->doi]),
    ];
    foreach ($urls as $url) {
        $xpath = dcatXPath($this->get($url)->assertOk()->getContent());
        expect($xpath->evaluate('count(//dcat:distribution//dct:license)'))->toBe($identifier === null ? 0.0 : 1.0)
            ->and($xpath->evaluate('string(//dcat:distribution//dct:license//rdfs:label)'))->toBe($identifier ?? '')
            ->and($xpath->evaluate('count(//dcat:distribution//dct:rights)'))->toBe(0.0);
    }
})->with([null, 'LicenseRef-Unresolved']);

it('exports selected contact emails and creator snapshots without unrelated contributor contacts', function () {
    $resource = dcatResource();
    $person = Person::factory()->create(['given_name' => 'New', 'family_name' => 'Name', 'name_identifier' => '0000-0002-1825-0097', 'name_identifier_scheme' => 'ORCID']);
    ResourceCreator::factory()->forPerson($person)->create(['resource_id' => $resource->id, 'name_snapshot' => 'Original, Name', 'is_contact' => true, 'email' => 'author@example.org']);
    $contributor = ResourceContributor::factory()->forPerson()->create(['resource_id' => $resource->id, 'email' => 'contact@example.org']);
    $type = ContributorType::firstOrCreate(['slug' => 'ContactPerson'], ['name' => 'Contact person']);
    $contributor->contributorTypes()->attach($type);
    ResourceContributor::factory()->forPerson()->create(['resource_id' => $resource->id, 'email' => 'unrelated@example.org']);
    $xml = app(DcatApRdfXmlExporter::class)->export($resource->refresh());
    $xpath = dcatXPath($xml);
    expect($xpath->evaluate('string(//dct:creator//foaf:name)'))->toBe('Original, Name')
        ->and($xpath->evaluate('string(//dct:creator/rdf:Description/@rdf:about)'))->toBe('https://orcid.org/0000-0002-1825-0097')
        ->and($xml)->toContain('mailto:author@example.org', 'mailto:contact@example.org')->not->toContain('mailto:datapub@gfz.de', 'unrelated@example.org');
});

it('selects energy only from the controlled subject hierarchies', function (?string $scheme, ?string $breadcrumb, string $value, bool $energy) {
    $resource = dcatResource();
    $resource->subjects()->create(['subject_scheme' => $scheme, 'breadcrumb_path' => $breadcrumb, 'value' => $value]);
    $themes = app(DcatApDataThemeResolver::class)->resolve($resource->refresh());
    expect($themes)->toContain('TECH', 'ENVI')->and(in_array('ENER', $themes, true))->toBe($energy);
})->with([
    ['Science Keywords', null, 'EARTH SCIENCE > SOLID EARTH > GEOTHERMAL DYNAMICS > GEOTHERMAL ENERGY', true],
    ['NASA/GCMD Earth Science Keywords', 'Science Keywords > earth science > human dimensions > economic resources > energy production/use > oil', 'Oil', true],
    ['GCMD', 'Earth Science > Solid Earth > Geothermal Dynamics > Geothermal Energy > Heat', 'Heat', true],
    ['GEMET', 'energy > geothermal energy', 'geothermal energy', true],
    ['GEMET', null, 'energy', false],
    [null, null, 'energy > geothermal energy', false],
    ['Science Keywords', null, 'EARTH SCIENCE > SOLID EARTH > GEOTHERMAL DYNAMICS', false],
    ['Instruments', null, 'EARTH SCIENCE > SOLID EARTH > GEOTHERMAL DYNAMICS > GEOTHERMAL ENERGY', false],
]);

it('groups abstract languages, preserves methods and date precision and omits invalid optional dates', function () {
    $resource = dcatResource(['publication_year' => 1999]);
    Description::factory()->abstract()->create(['resource_id' => $resource->id, 'value' => '<p>Deutscher Text.</p>', 'language' => 'de']);
    Description::factory()->abstract()->create(['resource_id' => $resource->id, 'value' => 'Zweiter Absatz.', 'language' => 'de']);
    Description::factory()->methods()->create(['resource_id' => $resource->id, 'value' => 'A < B & C.', 'language' => 'en']);
    Description::factory()->technicalInfo()->create(['resource_id' => $resource->id, 'value' => 'Do not export technical information']);
    foreach ([['Issued', '2025-02-30'], ['Updated', '2024-03'], ['Collected', '1990/1995'], ['Collected', '/2000']] as [$slug, $value]) {
        $type = DateType::firstOrCreate(['slug' => $slug], ['name' => $slug]);
        $resource->dates()->create(['date_type_id' => $type->id, 'date_value' => $value]);
    }
    $xml = app(DcatApRdfXmlExporter::class)->export($resource->refresh());
    $xpath = dcatXPath($xml);
    expect($xpath->evaluate('count(//dcat:dataset/rdf:Description/dct:description[@xml:lang="de"])'))->toBe(1.0)
        ->and($xpath->evaluate('string(//dcat:dataset/rdf:Description/dct:description[@xml:lang="de"])'))->toBe("Deutscher Text.\n\nZweiter Absatz.")
        ->and($xpath->evaluate('string(//dct:provenance//rdfs:label)'))->toBe('A < B & C.')
        ->and($xpath->evaluate('string(//dcat:dataset/rdf:Description/dct:issued)'))->toBe('1999')
        ->and($xpath->evaluate('string(//dcat:dataset/rdf:Description/dct:modified/@rdf:datatype)'))->toEndWith('#gYearMonth')
        ->and($xpath->evaluate('count(//dcat:startDate)'))->toBe(1.0)->and($xpath->evaluate('count(//dcat:endDate)'))->toBe(2.0)
        ->and($xml)->not->toContain('Do not export technical information', '2025-02-30');
});

it('maps all direct relations and preserves remaining roles', function (string $relation, string $property) {
    $resource = dcatResource();
    $type = IdentifierType::firstOrCreate(['slug' => 'DOI'], ['name' => 'DOI']);
    $role = RelationType::firstOrCreate(['slug' => $relation], ['name' => 'Editable label']);
    $resource->relatedIdentifiers()->create(['identifier' => '10.5880/related', 'identifier_type_id' => $type->id, 'relation_type_id' => $role->id]);
    $xpath = dcatXPath(app(DcatApRdfXmlExporter::class)->export($resource->refresh()));
    expect($xpath->evaluate('count(//dcat:dataset/rdf:Description/'.$property.')'))->toBe(1.0);
    if ($property === 'dcat:qualifiedRelation') {
        expect($xpath->evaluate('string(//dcat:hadRole/rdf:Description/@rdf:about)'))->toEndWith('#'.strtolower($relation));
    }
})->with([
    ['IsCitedBy', 'dct:isReferencedBy'], ['IsReferencedBy', 'dct:isReferencedBy'], ['IsSupplementTo', 'dct:isReferencedBy'],
    ['IsDocumentedBy', 'foaf:page'], ['IsDerivedFrom', 'dct:source'], ['HasVersion', 'dcat:hasVersion'],
    ['IsPreviousVersionOf', 'dcat:hasVersion'], ['IsPartOf', 'dcat:qualifiedRelation'], ['Other', 'dcat:qualifiedRelation'],
]);

it('keeps known byte sizes and IANA media types on the corresponding distribution', function () {
    $resource = dcatResource();
    $format = Format::create(['resource_id' => $resource->id, 'value' => 'text/csv']);
    $size = $resource->sizes()->create(['numeric_value' => 1.5, 'unit' => 'KiB']);
    $resource->landingPage->files()->create(['url' => 'https://example.org/data.csv', 'format_id' => $format->id, 'size_id' => $size->id, 'position' => 0]);
    $xpath = dcatXPath(app(DcatApRdfXmlExporter::class)->export($resource->refresh()));
    expect($xpath->evaluate('string(//dcat:byteSize)'))->toBe('1536')
        ->and($xpath->evaluate('string(//dcat:byteSize/@rdf:datatype)'))->toEndWith('#nonNegativeInteger')
        ->and($xpath->evaluate('string(//dcat:mediaType/rdf:Description/@rdf:about)'))->toBe('https://www.iana.org/assignments/media-types/text/csv');
});

it('excludes misclassified IGSNs and whitespace-only required text', function () {
    $resource = dcatResource();
    IgsnMetadata::create(['resource_id' => $resource->id, 'sample_type' => 'Rock']);
    expect(app(DcatApReadinessService::class)->errors($resource->refresh()))->toContain('unsupported_resource_type');
    $resource->igsnMetadata->delete();
    $resource->titles->first()->update(['value' => '   ']);
    $resource->descriptions->first()->update(['value' => '<p> </p>']);
    expect(app(DcatApReadinessService::class)->errors($resource->refresh()))->toContain('missing_main_title', 'missing_abstract');
});

it('audits readiness and ambiguity without changing metadata or history', function () {
    $resource = dcatResource();
    $resource->resourceRights()->create(['rights_text' => 'License A']);
    $resource->resourceRights()->create(['rights_text' => 'License B']);
    $resource->descriptions->first()->delete();
    $timestamp = $resource->refresh()->updated_at->toISOString();
    $history = OaiPmhFormatRecord::first()->getAttributes();
    config(['dcatap.enabled' => false]);
    Artisan::call('dcat-ap:audit', ['--format' => 'json']);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $rows = $report['records'];
    expect($report['summary'])->toBe(['total' => 1, 'available' => 0, 'blocked' => 1, 'with_warnings' => 1]);
    expect($rows[0]['errors'])->toContain('missing_abstract')->and($rows[0]['warnings'])->toContain('ambiguous_licenses')
        ->and($resource->refresh()->updated_at->toISOString())->toBe($timestamp)
        ->and(OaiPmhFormatRecord::first()->getAttributes())->toBe($history);
    Artisan::call('dcat-ap:audit', ['--format' => 'csv']);
    expect(Artisan::output())->toContain('missing_abstract', 'ambiguous_licenses');
    $this->artisan('dcat-ap:audit', ['--format' => 'invalid'])->assertFailed();
});

it('refreshes cached HTML and HTTP discovery when the flag or required metadata changes', function () {
    $resource = dcatResource();
    $path = $resource->landingPage->getPublicPath();
    $response = $this->get($path)->assertOk();
    expect($response->headers->get('Link'))->toContain('/metadata/dcat-ap.rdf', 'application/rdf+xml');
    $response->assertSee('rel="describedby"', false)->assertSee('/metadata/dcat-ap.rdf', false);
    config(['dcatap.enabled' => false]);
    $disabled = $this->get($path)->assertOk();
    expect($disabled->headers->get('Link'))->not->toContain('dcat-ap.rdf');
    config(['dcatap.enabled' => true]);
    $resource->descriptions->first()->delete();
    $missing = $this->get($path)->assertOk();
    expect($missing->headers->get('Link'))->not->toContain('dcat-ap.rdf');
});

it('uses external landing URLs only while the resource is not withdrawn', function () {
    $resource = dcatResource();
    $domain = LandingPageDomain::factory()->create(['domain' => 'https://example.org/']);
    $resource->landingPage->update(['template' => 'external', 'external_domain_id' => $domain->id, 'external_path' => 'dataset']);
    $xpath = dcatXPath(app(DcatApRdfXmlExporter::class)->export($resource->refresh()));
    expect($xpath->evaluate('string(//dcat:accessURL/@rdf:resource)'))->toBe('https://example.org/dataset');
    $resource->landingPage->forceFill(['is_tombstone' => true])->save();
    $xpath = dcatXPath(app(DcatApRdfXmlExporter::class)->export($resource->refresh()));
    expect($xpath->evaluate('string(//dcat:accessURL/@rdf:resource)'))->toBe(url($resource->landingPage->getPublicPath()));
});

it('keeps edited download URLs fresh when switching back to either cached flag state', function (bool $enabledDuringEdit): void {
    config(['bot_protection.enabled' => true, 'bot_protection.landing_cache_ttl' => 600]);
    $resource = dcatResource();
    $landing = $resource->landingPage;
    $landing->update(['ftp_url' => 'https://example.org/old.csv']);
    $path = $landing->getPublicPath();
    foreach ([false, true] as $enabled) {
        config(['dcatap.enabled' => $enabled]);
        $this->get($path)->assertOk()->assertInertia(fn (AssertableInertia $view) => $view->where('landingPage.ftp_url', 'https://example.org/old.csv'));
    }

    config(['dcatap.enabled' => $enabledDuringEdit]);
    $landing->update(['ftp_url' => 'https://example.org/new.csv']);
    foreach ([false, true] as $enabled) {
        config(['dcatap.enabled' => $enabled]);
        $this->get($path)->assertOk()->assertInertia(fn (AssertableInertia $view) => $view->where('landingPage.ftp_url', 'https://example.org/new.csv'));
    }
})->with([false, true]);

it('keeps each main or translated title and each abstract as an independent EPOS string', function () {
    config(['dcatap.epos.enabled' => true]);
    $resource = dcatResource();
    foreach (['TranslatedTitle' => 'Magnetische Messungen', 'Subtitle' => 'A subtitle'] as $slug => $value) {
        $type = TitleType::firstOrCreate(['slug' => $slug], ['name' => $slug]);
        Title::factory()->create(['resource_id' => $resource->id, 'title_type_id' => $type->id, 'value' => $value, 'language' => 'de']);
    }
    foreach (["Erster Absatz.\n\nZweiter Absatz.", 'Eine weitere Beschreibung.'] as $text) {
        Description::factory()->abstract()->create(['resource_id' => $resource->id, 'value' => $text, 'language' => 'de']);
    }
    $resource->load('titles.titleType');
    // Hydrate a pre-migration main title whose type was stored as NULL.
    $legacy = $resource->titles->first();
    $legacy->title_type_id = null;
    $legacy->setRelation('titleType', null);
    $xml = DcatApFormat::Epos->exporter()->export($resource);
    $xpath = dcatXPath($xml);
    $dataset = '//dcat:dataset/rdf:Description';
    expect($xpath->evaluate("count($dataset/dct:title)"))->toBe(2.0)
        ->and($xpath->evaluate("string($dataset/dct:title[1])"))->toBe('Magnetic & seismic observations')
        ->and($xpath->evaluate("string($dataset/dct:title[2])"))->toBe('Magnetische Messungen')
        ->and($xpath->evaluate("count($dataset/dct:description)"))->toBe(3.0)
        ->and($xpath->evaluate("string($dataset/dct:description[2])"))->toBe("Erster Absatz.\n\nZweiter Absatz.")
        ->and($xpath->evaluate("string($dataset/dct:description[3])"))->toBe('Eine weitere Beschreibung.')
        ->and($xpath->evaluate("count($dataset/dct:title/@xml:lang | $dataset/dct:description/@xml:lang)"))->toBe(0.0)
        ->and($xml)->not->toContain('A subtitle');
});

it('retains EPOS distribution identifiers across reordering and switches to the permitted fallback on embargo or withdrawal', function () {
    config(['dcatap.epos.enabled' => true]);
    $resource = dcatResource(['access_level' => AccessLevel::OPEN]);
    $landing = $resource->landingPage;
    $format = Format::create(['resource_id' => $resource->id, 'value' => 'application/x-observations']);
    $urls = ['https://example.org/a.obs', 'https://example.org/b.obs'];
    $files = [];
    foreach ($urls as $index => $url) {
        $files[] = $landing->files()->create(['url' => $url, 'format_id' => $format->id, 'position' => $index]);
    }
    $distribution = '//dcat:distribution/rdf:Description';
    for ($pass = 0; $pass < 2; $pass++) {
        $xml = DcatApFormat::Epos->exporter()->export($resource->refresh());
        $xpath = dcatXPath($xml);
        expect($xpath->evaluate("count($distribution)"))->toBe(2.0);
        foreach ($urls as $url) {
            $node = "$distribution".'[dcat:downloadURL="'.$url.'"]';
            $id = 'https://doi.org/'.$resource->doi.'#epos-distribution-'.hash('sha256', $url);
            expect($xpath->evaluate("string($node/@rdf:about)"))->toBe($id)
                ->and($xpath->evaluate("string($node/dct:identifier)"))->toBe($id)
                ->and($xpath->evaluate("string($node/dcat:accessURL/@rdf:datatype)"))->toBe(RdfDocument::NS['xsd'].'anyURI')
                ->and($xpath->evaluate("string($node/dct:format)"))->toBe('application/x-observations');
        }
        if ($pass === 0) {
            $files[0]->update(['position' => 2]);
        }
    }
    $resource->update(['access_level' => AccessLevel::EMBARGOED]);
    $fallback = 'https://doi.org/'.$resource->doi.'#epos-distribution-landing-page';
    $xpath = dcatXPath(DcatApFormat::Epos->exporter()->export($resource->refresh()));
    expect($xpath->evaluate("string($distribution/dct:identifier)"))->toBe($fallback)
        ->and($xpath->evaluate('count(//dcat:downloadURL)'))->toBe(0.0)
        ->and($xpath->evaluate('string(//dcat:accessURL)'))->toBe($landing->public_url);
    $domain = LandingPageDomain::factory()->create(['domain' => 'https://example.org/']);
    $landing->forceFill(['template' => 'external', 'external_domain_id' => $domain->id, 'external_path' => 'external',
        'is_tombstone' => true, 'tombstone_statement' => 'Withdrawn for recalibration.'])->save();
    $xml = DcatApFormat::Epos->exporter()->export($resource->refresh());
    $xpath = dcatXPath($xml);
    expect($xml)->toContain('Withdrawn for recalibration.')->not->toContain($urls[0], $urls[1], 'https://example.org/external')
        ->and($xpath->evaluate('string(//dct:accessRights/rdf:Description/@rdf:about)'))->toEndWith('/NON_PUBLIC')
        ->and($xpath->evaluate('string(//dcat:accessURL)'))->toBe(url($landing->getPublicPath()));
});

it('advertises only eligible enabled profiles in cached HTML and HTTP and serves the advertised downloads', function () {
    config(['bot_protection.enabled' => true, 'bot_protection.landing_cache_ttl' => 600]);
    $resource = dcatResource();
    $path = $resource->landingPage->getPublicPath();
    $states = [[true, false], [true, true], [false, true], [false, false], [true, false]];
    foreach ($states as [$eu, $epos]) {
        config(['dcatap.enabled' => $eu, 'dcatap.epos.enabled' => $epos]);
        $page = $this->get($path)->assertOk();
        foreach (DcatApFormat::cases() as $profile) {
            $download = $path.'/metadata/'.$profile->slug().'.rdf';
            $header = $page->headers->get('Link');
            if (! $profile->enabled()) {
                expect($header)->not->toContain($download);
                $page->assertDontSee($download, false);
                $this->get($download)->assertNotFound();

                continue;
            }
            expect($header)->toContain($download, config($profile->configKey().'.profile'));
            $page->assertSee($download, false);
            $this->get($download)->assertOk()->assertHeader('Content-Type', 'application/rdf+xml; charset=UTF-8')
                ->assertHeader('Content-Disposition', 'attachment; filename="observations-'.$profile->slug().'.rdf"')
                ->assertSee($profile->schemaUrl(), false);
        }
    }
    $resource->descriptions->first()->delete();
    foreach ($states as [$eu, $epos]) {
        config(['dcatap.enabled' => $eu, 'dcatap.epos.enabled' => $epos]);
        expect($this->get($path)->assertOk()->headers->get('Link'))->not->toContain('/metadata/dcat-ap.rdf', '/metadata/epos-dcat-ap.rdf');
        $this->get($path.'/metadata/epos-dcat-ap.rdf')->assertNotFound();
    }
});

it('reports EPOS mapping fallbacks while the profile is disabled and retains eligible keyword values', function () {
    $resource = dcatResource();
    $resource->subjects()->create(['subject_scheme' => 'Unindexed scheme', 'value' => 'Geomagnetism', 'value_uri' => 'https://example.org/concepts/9']);
    $resource->geoLocations()->create(['geo_type' => 'line', 'polygon_points' => [['latitude' => 2, 'longitude' => 0], ['latitude' => 1, 'longitude' => 4]]]);
    $resource->alternateIdentifiers()->create(['type' => ' ', 'value' => 'Missing scheme']);
    $resource->alternateIdentifiers()->create(['type' => 'Local registry', 'value' => 'OBS-7']);
    $resource->instruments()->create(['instrument_pid_type' => 'DOI', 'instrument_pid' => '10.5880/sensor', 'instrument_name' => 'Fluxgate']);
    $resource->instruments()->create(['instrument_pid_type' => 'DOI', 'instrument_pid' => 'invalid', 'instrument_name' => 'Unidentified sensor']);
    $this->artisan('dcat-ap:sync', ['--profile' => 'epos_dcat_ap'])->assertFailed();
    $this->artisan('dcat-ap:sync', ['--profile' => 'unknown'])->assertFailed();
    $this->artisan('dcat-ap:audit', ['--profile' => 'unknown'])->assertFailed();
    expect(Artisan::call('dcat-ap:audit', ['--profile' => 'epos_dcat_ap']))->toBe(0);
    $row = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['records'][0];
    expect($row['available'])->toBeTrue()
        ->and($row['warnings'])->toContain('eu_theme_labels_as_keywords', 'controlled_subject_as_keyword', 'geometry_as_bounding_box', 'missing_identifier_scheme')
        ->and(OaiPmhFormatRecord::where('metadata_prefix', 'epos_dcat_ap')->exists())->toBeFalse();
    config(['dcatap.epos.enabled' => true]);
    $xml = DcatApFormat::Epos->exporter()->export($resource->refresh());
    $xpath = dcatXPath($xml);
    expect($xml)->toContain('Geomagnetism', 'Fluxgate')->not->toContain('https://example.org/concepts/9', 'Missing scheme', 'Unidentified sensor')
        ->and($xpath->evaluate('count(//dcat:keyword[text()="Geomagnetism"])'))->toBe(1.0)
        ->and($xpath->evaluate('string(//skos:notation)'))->toBe('OBS-7')
        ->and($xpath->evaluate('string(//adms:schemeAgency)'))->toBe('Local registry')
        ->and($xpath->evaluate('string(//dct:relation/rdf:Description/@rdf:about)'))->toBe('https://doi.org/10.5880/sensor');
});

it('preserves the EU RDF/XML fingerprint used by existing harvest history', function () {
    $this->travelTo(now()->setDateTime(2026, 1, 10, 12, 0));
    URL::forceRootUrl('https://metadata.example.org');
    URL::forceScheme('https');
    config([
        'dcatap.enabled' => false,
        'dcatap.catalog.uri' => 'https://dataservices.gfz.de/',
        'dcatap.catalog.title' => 'GFZ Data Services',
        'dcatap.catalog.description' => 'Research data published by GFZ Data Services.',
        'dcatap.catalog.publisher' => 'GFZ Data Services',
        'iso19115.metadata_contact.name' => 'GFZ Data Services',
        'iso19115.metadata_contact.email' => 'datapub@gfz.de',
    ]);
    $resource = DcatApExampleMetadata::load('energy');
    $resource->landingPage->update(['ftp_url' => null, 'published_at' => now()]);
    $resource->update(['access_level' => AccessLevel::OPEN]);
    config(['dcatap.enabled' => true]);
    $xml = DcatApFormat::Eu->exporter()->export($resource->refresh(), forOaiPmh: true);
    // Captured from the preceding EU exporter for this fixed metadata and clock.
    expect(hash('sha256', $xml))->toBe('42051ce9fbd6835d52f6143d24c4fed1b6a39d4e3e1e0b8ff4aa7c78ae299f8b');
    app(DcatApRecordService::class)->reconcile($resource->id);
    $record = OaiPmhFormatRecord::where('resource_id', $resource->id)->where('metadata_prefix', DcatApFormat::Eu->value)->sole();
    expect($record->fingerprint)->toBe(hash('sha256', $xml.json_encode($record->sets, JSON_THROW_ON_ERROR)));
});

it('withholds both downloads for incomplete and legacy records and resumes after correction', function () {
    config(['dcatap.epos.enabled' => true]);
    $resource = dcatResource();
    $landing = $resource->landingPage;
    $path = $landing->getPublicPath();
    $readiness = app(DcatApReadinessService::class);
    $verify = function (string $error) use ($resource, $path, $readiness) {
        expect($readiness->errors($resource->refresh()))->toContain($error);
        foreach (DcatApFormat::cases() as $format) {
            $this->get($path.'/metadata/'.$format->slug().'.rdf')->assertNotFound();
        }
    };
    $landing->update(['is_published' => false]);
    $verify('not_published');
    $landing->update(['is_published' => true]);
    $doi = $resource->doi;
    $resource->update(['doi' => 'bad-doi']);
    $verify('invalid_doi');
    $resource->update(['doi' => $doi]);
    $igsn = IgsnMetadata::create(['resource_id' => $resource->id, 'sample_type' => 'Rock']);
    $verify('unsupported_resource_type');
    $igsn->delete();
    $title = $resource->titles->first();
    $title->update(['value' => ' ']);
    $verify('missing_main_title');
    $title->update(['value' => 'Restored title']);
    // Legacy records can lack both a resource timestamp and a landing-page publication date.
    $landing->update(['published_at' => null]);
    Resource::withoutTimestamps(fn () => Resource::whereKey($resource->id)->update(['updated_at' => null]));
    $verify('missing_metadata_timestamp');
    $landing->update(['published_at' => now()]);
    foreach (DcatApFormat::cases() as $format) {
        $this->get($path.'/metadata/'.$format->slug().'.rdf')->assertOk();
    }
});

it('keeps a verified ROR creator and omits incomplete optional metadata in both RDF profiles', function () {
    config(['dcatap.epos.enabled' => true, 'iso19115.metadata_contact.name' => '']);
    $resource = dcatResource();
    $institution = Institution::factory()->withRor('https://ror.org/04z8jg394')->create(['name' => 'Observatory']);
    ResourceCreator::factory()->forInstitution($institution)->create(['resource_id' => $resource->id]);
    ResourceCreator::factory()->create(['resource_id' => $resource->id, 'creatorable_type' => Person::class, 'creatorable_id' => 999999]);
    $doi = IdentifierType::firstOrCreate(['slug' => 'DOI'], ['name' => 'DOI']);
    $role = RelationType::firstOrCreate(['slug' => 'IsDerivedFrom'], ['name' => 'IsDerivedFrom']);
    $resource->relatedIdentifiers()->create(['identifier' => 'broken DOI', 'identifier_type_id' => $doi->id, 'relation_type_id' => $role->id]);
    $resource->geoLocations()->create(['point_longitude' => 0, 'point_latitude' => null]);
    foreach (DcatApFormat::cases() as $profile) {
        $xml = $profile->exporter()->export($resource->refresh());
        $xpath = dcatXPath($xml);
        expect($xpath->evaluate('count(//dct:creator)'))->toBe(1.0)
            ->and($xpath->evaluate('string(//dct:creator/rdf:Description/@rdf:about)'))->toBe('https://ror.org/04z8jg394')
            ->and($xpath->evaluate('count(//dct:spatial | //dcat:contactPoint)'))->toBe(0.0)
            ->and($xml)->not->toContain('broken DOI');
    }
    $resource->descriptions->first()->delete();
    expect(fn () => DcatApFormat::Epos->exporter()->export($resource->refresh()))->toThrow(DomainException::class, 'missing_abstract');
});
