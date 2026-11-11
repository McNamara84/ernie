<?php

use App\Enums\UserRole;
use App\Models\DescriptionType;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\User;
use App\Services\DataCiteRegistrationService;
use App\Services\DataCiteRequestLimiter;
use App\Support\DataCiteSchemaVersion;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

require_once __DIR__.'/../../Support/DataCitePublicationFixtures.php';

beforeEach(function () {
    config([
        'datacite.test_mode' => true,
        'datacite.test.username' => 'TEST.USER', 'datacite.test.password' => 'test-password',
        'datacite.test.client_id' => 'test.repo',
        'datacite.test.endpoint' => 'https://api.test.datacite.org',
        'datacite.test.prefixes' => ['10.83279'],
        'datacite.production.username' => 'PROD.USER', 'datacite.production.password' => 'prod-password',
        'datacite.production.client_id' => 'prod.repo',
        'datacite.production.endpoint' => 'https://api.datacite.org',
        'datacite.production.prefixes' => ['10.5880'],
        'datacite.production.igsn_prefix' => '10.60510',
        'datacite.production.igsn_username' => 'GFZ.IGSN', 'datacite.production.igsn_password' => 'igsn-password',
        'datacite.production.igsn_client_id' => 'igsn.repo',
        'datacite.landing_page_url_update.minimum_interval_ms' => 0,
    ]);
    app(DataCiteRequestLimiter::class)->clear();
    config(['app.url' => 'https://ernie.example']);
    URL::forceRootUrl('https://ernie.example');
    URL::forceScheme('https');
    Http::preventStrayRequests();
    $this->resource = Resource::factory()->create(['doi' => null]);
    completeDataCiteFixture($this->resource);
    LandingPage::factory()->create(['resource_id' => $this->resource->id, 'is_published' => false]);
    $this->target = url('/datasets/'.$this->resource->id);
});

test('registerDoi publishes a complete resource using basic auth and no schema override', function () {
    $this->resource->descriptions()->delete();
    $type = DescriptionType::firstOrCreate(['slug' => 'Abstract'], ['name' => 'Abstract', 'is_active' => true]);
    $this->resource->descriptions()->create([
        'value' => 'First line.'.PHP_EOL.PHP_EOL.'Second line.',
        'landing_page_html' => '<p>First <strong>line</strong>.</p><p>Second line.</p>',
        'description_type_id' => $type->id,
    ]);
    Http::fake(fn (Request $r) => $r->method() === 'HEAD'
        ? Http::response('', 200)
        : Http::response(dataCiteRecord('10.83279/new-record', attributes: $r['data']['attributes']), 201));
    $result = app(DataCiteRegistrationService::class)->registerDoi($this->resource, '10.83279');
    expect($result['complete'])->toBeTrue()->and($result['data']['id'])->toBe('10.83279/new-record');
    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && $r->url() === 'https://api.test.datacite.org/dois'
        && $r->header('Authorization')[0] === 'Basic '.base64_encode('TEST.USER:test-password')
        && $r->hasHeader('Content-Type', 'application/vnd.api+json')
        && $r['data']['attributes']['event'] === 'publish'
        && $r['data']['attributes']['prefix'] === '10.83279'
        && $r['data']['attributes']['descriptions'][0]['description'] === 'First line.<br><br>Second line.'
        && ! isset($r['data']['attributes']['schemaVersion']));
});

test('updateMetadata preserves state and kernel 4 while sending to the encoded DOI', function () {
    $this->resource->update(['doi' => '10.83279/existing']);
    $this->resource->landingPage->publish();
    Http::fake(fn () => Http::response(dataCiteRecord('10.83279/existing', 'registered')));
    $result = app(DataCiteRegistrationService::class)->updateMetadata($this->resource);
    expect($result['data']['attributes']['state'])->toBe('registered');
    Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
        && str_ends_with($r->url(), rawurlencode('10.83279/existing'))
        && $r['data']['attributes']['schemaVersion'] === DataCiteSchemaVersion::KERNEL_4
        && ! array_key_exists('event', $r['data']['attributes'])
        && $r['data']['attributes']['url'] === $this->resource->landingPage->public_url);
});

test('updateMetadata requires a DOI', function () {
    expect(fn () => app(DataCiteRegistrationService::class)->updateMetadata($this->resource))
        ->toThrow(RuntimeException::class, 'must have a DOI');
});

test('registerDoi does not repeat an ambiguous POST', function () {
    Http::fake(fn (Request $r) => $r->method() === 'HEAD' ? Http::response('', 200) : Http::response('Network error', 500));
    $result = app(DataCiteRegistrationService::class)->registerDoi($this->resource, '10.83279');
    expect($result['complete'])->toBeFalse()->and($result['publication']['status'])->toBe('pending_reconciliation');
    expect(Http::recorded(fn (Request $r) => $r->method() === 'POST'))->toHaveCount(1);
});

test('registration uses the effective environment and forces beginners to test', function (bool $beginner) {
    config(['datacite.test_mode' => false]);
    if ($beginner) {
        $this->actingAs(User::factory()->create(['role' => UserRole::BEGINNER]));
    }
    $prefix = $beginner ? '10.83279' : '10.5880';
    $repository = $beginner ? 'test.repo' : 'prod.repo';
    Http::fake(fn (Request $r) => $r->method() === 'HEAD' ? Http::response('', 200)
        : Http::response(dataCiteRecord($prefix.'/new', repository: $repository, attributes: $r['data']['attributes']), 201));
    $result = app(DataCiteRegistrationService::class)->registerDoi($this->resource, $prefix);
    expect($result['complete'])->toBeTrue();
    Http::assertSent(fn (Request $r) => $r->method() === 'POST'
        && $r->url() === ($beginner ? 'https://api.test.datacite.org/dois' : 'https://api.datacite.org/dois')
        && $r->header('Authorization')[0] === 'Basic '.base64_encode($beginner ? 'TEST.USER:test-password' : 'PROD.USER:prod-password'));
})->with([true, false]);

test('updateMetadata verifies the dedicated production IGSN repository', function () {
    config(['datacite.test_mode' => false]);
    $this->resource->update(['doi' => '10.60510/GFTEST001']);
    $this->resource->landingPage->publish();
    Http::fake(fn () => Http::response(dataCiteRecord('10.60510/GFTEST001', repository: 'igsn.repo')));
    app(DataCiteRegistrationService::class)->updateMetadata($this->resource);
    Http::assertSent(fn (Request $r) => $r->method() === 'GET'
        && $r->header('Authorization')[0] === 'Basic '.base64_encode('GFZ.IGSN:igsn-password'));
    Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
        && $r->header('Authorization')[0] === 'Basic '.base64_encode('GFZ.IGSN:igsn-password'));
});

test('registerDoi persists a definite rejection without declaring success', function () {
    Http::fake(fn (Request $r) => $r->method() === 'HEAD' ? Http::response('', 200)
        : Http::response(['errors' => [['title' => 'Invalid metadata']]], 422));
    expect(fn () => app(DataCiteRegistrationService::class)->registerDoi($this->resource, '10.83279'))
        ->toThrow(RequestException::class);
    expect($this->resource->fresh()->latestDoiPublication->status)->toBe('failed')
        ->and($this->resource->fresh()->landingPage->is_published)->toBeTrue();
});
