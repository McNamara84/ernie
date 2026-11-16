<?php

use App\Exceptions\DataCiteVerificationException;
use App\Http\Controllers\EditorController;
use App\Models\DateType;
use App\Models\LandingPage;
use App\Models\LandingPageDomain;
use App\Models\Resource;
use App\Models\ResourceDoiPublication;
use App\Models\User;
use App\Services\DataCiteRequestLimiter;
use App\Services\Editor\EditorLoadProgressService;
use App\Services\ResourceDoiPublicationService;
use App\Services\SizeFormat\SizeFormatDownloadTargetResolverService;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;

require_once __DIR__.'/../../Support/DataCitePublicationFixtures.php';

beforeEach(function (): void {
    config([
        'datacite.test_mode' => true, 'datacite.test.username' => 'TEST.REPO',
        'datacite.test.password' => 'secret', 'datacite.test.client_id' => 'test.repo',
        'datacite.test.endpoint' => 'https://api.test.datacite.org',
        'datacite.test.prefixes' => ['10.83279'],
        'datacite.landing_page_url_update.minimum_interval_ms' => 0,
        'app.url' => 'https://ernie.example',
    ]);
    URL::forceRootUrl('https://ernie.example');
    URL::forceScheme('https');
    app(DataCiteRequestLimiter::class)->clear();
    Http::preventStrayRequests();
    $this->resource = Resource::factory()->create(['doi' => null]);
    completeDataCiteFixture($this->resource);
    $this->page = LandingPage::factory()->create(['resource_id' => $this->resource->id, 'is_published' => false]);
    $this->target = url('/datasets/'.$this->resource->id);
});

it('publishes the public target first and preserves preassigned identifiers', function (string $state): void {
    $doi = '10.83279/approved';
    if ($state !== 'unassigned') {
        $this->resource->update(['doi' => $doi]);
    }
    Http::fake(function (Request $request) use ($state, $doi) {
        if ($request->method() === 'HEAD') {
            expect($this->page->fresh()->is_published)->toBeTrue();

            return Http::response('', 200);
        }
        if ($request->method() === 'GET') {
            return $state === 'missing'
                ? Http::response([], 404)
                : Http::response(dataCiteRecord($doi, $state, attributes: ['url' => $this->target]));
        }
        expect($this->page->fresh()->is_published)->toBeTrue();
        expect($request['data']['attributes']['url'])->toBe($this->target);
        expect($request->method())->toBe(in_array($state, ['unassigned', 'missing']) ? 'POST' : 'PUT');
        if ($state !== 'unassigned') {
            expect($request['data']['attributes']['doi'])->toBe($doi);
        }
        expect($request['data']['attributes']['event'] ?? null)->toBe($state === 'findable' ? null : 'publish');

        return Http::response(dataCiteRecord($doi, attributes: $request['data']['attributes']), 201);
    });
    $result = app(ResourceDoiPublicationService::class)->publish($this->resource, '10.83279');
    expect($result['complete'])->toBeTrue()
        ->and($this->resource->fresh()->doi)->toBe($doi)
        ->and(ResourceDoiPublication::sole()->status)->toBe('succeeded')
        ->and($this->resource->dates()->whereHas('dateType', fn ($q) => $q->where('slug', 'Issued'))->count())->toBe(1);
})->with(['unassigned', 'missing', 'draft', 'registered', 'findable']);

it('blocks incomplete metadata before releasing the page', function (): void {
    $this->resource->creators()->delete();
    Http::fake();
    expect(fn () => app(ResourceDoiPublicationService::class)->publish($this->resource, '10.83279'))
        ->toThrow(DataCiteVerificationException::class);
    expect($this->page->fresh()->is_published)->toBeFalse();
    Http::assertNothingSent();
});

it('keeps a released landing page visible when its public target is unavailable', function (): void {
    Http::fake(fn () => Http::response('', 503));
    expect(fn () => app(ResourceDoiPublicationService::class)->publish($this->resource, '10.83279'))
        ->toThrow(DataCiteVerificationException::class);
    expect($this->page->fresh()->is_published)->toBeTrue()
        ->and(ResourceDoiPublication::sole()->status)->toBe('failed');
    Http::assertNotSent(fn (Request $request) => in_array($request->method(), ['POST', 'PUT']));
});

it('reconciles an accepted but interrupted create without a second POST', function (): void {
    $accepted = null;
    Http::fake(function (Request $request) use (&$accepted) {
        if ($request->method() === 'HEAD') {
            return Http::response('', 200);
        }
        if ($request->method() === 'POST') {
            $accepted = dataCiteRecord('10.83279/recovered', attributes: $request['data']['attributes']);

            return Http::failedConnection('Response lost after acceptance');
        }

        return Http::response(str_contains($request->url(), '/dois?') ? ['data' => [$accepted['data']]] : $accepted);
    });
    $service = app(ResourceDoiPublicationService::class);
    expect($service->publish($this->resource, '10.83279')['complete'])->toBeFalse()
        ->and(ResourceDoiPublication::sole()->status)->toBe('pending_reconciliation')
        ->and($this->page->fresh()->is_published)->toBeTrue();
    expect($service->publish($this->resource, '10.83279')['complete'])->toBeTrue()
        ->and($this->resource->fresh()->doi)->toBe('10.83279/recovered');
    expect(Http::recorded(fn (Request $request) => $request->method() === 'POST'))->toHaveCount(1);
});

it('leaves an ambiguous create pending across retries', function (): void {
    Http::fake(fn (Request $request) => match ($request->method()) {
        'HEAD' => Http::response('', 200),
        'POST' => Http::failedConnection(),
        default => Http::response(['data' => []]),
    });
    $service = app(ResourceDoiPublicationService::class);
    expect($service->publish($this->resource, '10.83279')['complete'])->toBeFalse();
    expect($service->publish($this->resource, '10.83279')['complete'])->toBeFalse();
    expect(Http::recorded(fn (Request $request) => $request->method() === 'POST'))->toHaveCount(1);
});

it('refuses changed context when resuming a pending publication', function (): void {
    Http::fake(fn (Request $request) => $request->method() === 'HEAD' ? Http::response('', 200) : Http::failedConnection());
    expect(app(ResourceDoiPublicationService::class)->publish($this->resource, '10.83279')['complete'])->toBeFalse();
    config(['datacite.test.client_id' => 'different.repository']);
    expect(fn () => app(ResourceDoiPublicationService::class)->publish($this->resource, '10.83279'))
        ->toThrow(DataCiteVerificationException::class, 'different DOI');
    expect(Http::recorded(fn (Request $request) => $request->method() === 'POST'))->toHaveCount(1);
});

it('detects metadata changed while checking the target and sends no write', function (): void {
    Http::fake(function () {
        $this->resource->update(['version' => 'changed']);

        return Http::response('', 200);
    });
    expect(fn () => app(ResourceDoiPublicationService::class)->publish($this->resource, '10.83279'))
        ->toThrow(DataCiteVerificationException::class, 'changed');
    Http::assertNotSent(fn (Request $request) => in_array($request->method(), ['POST', 'PUT']));
});

it('requires valid schema values before releasing the landing page', function (): void {
    $this->resource->update(['publication_year' => 99]);
    Http::fake();
    expect(fn () => app(ResourceDoiPublicationService::class)->publish($this->resource, '10.83279'))->toThrow(DataCiteVerificationException::class);
    expect($this->page->fresh()->is_published)->toBeFalse();
    Http::assertNothingSent();
});

it('blocks foreign repositories and unavailable verification before publication', function (int $status, string $owner): void {
    $this->resource->update(['doi' => '10.83279/preassigned']);
    Http::fake(fn () => Http::response(dataCiteRecord($this->resource->doi, repository: $owner), $status));
    expect(fn () => app(ResourceDoiPublicationService::class)->publish($this->resource))->toThrow($status === 200 ? DataCiteVerificationException::class : RequestException::class);
    expect($this->page->fresh()->is_published)->toBeFalse()->and(ResourceDoiPublication::count())->toBe(0);
    Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
})->with([[200, 'foreign.repo'], [401, 'test.repo'], [503, 'test.repo']]);

it('rejects unsafe target redirects without a DataCite write', function (string $location): void {
    Http::fake(fn () => Http::response('', 302, ['Location' => $location]));
    expect(fn () => app(ResourceDoiPublicationService::class)->publish($this->resource, '10.83279'))->toThrow(DataCiteVerificationException::class);
    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request) => in_array($request->method(), ['POST', 'PUT']));
})->with(['https://foreign.example/page', 'https://ernie.example/login', 'https://ernie.example/datasets/1?preview=secret', 'http://ernie.example/page']);

it('accepts the public canonical redirect and bounded GET fallback without credentials', function (): void {
    Http::fake(function (Request $request) {
        if ($request->method() === 'POST') {
            return Http::response(dataCiteRecord('10.83279/fallback', attributes: $request['data']['attributes']), 201);
        }
        expect($request->hasHeader('Authorization'))->toBeFalse();
        if ($request->url() === $this->target) {
            return Http::response('', 302, ['Location' => $this->page->fresh()->public_url]);
        }

        return Http::response('', $request->method() === 'HEAD' ? 405 : 200);
    });
    expect(app(ResourceDoiPublicationService::class)->publish($this->resource, '10.83279')['complete'])->toBeTrue();
    expect(Http::recorded()->map(fn ($pair) => $pair[0]->method())->all())->toBe(['HEAD', 'HEAD', 'GET', 'POST']);
});

it('retains definitive remote rejection and can retry after correction', function (): void {
    $rejected = true;
    Http::fake(function (Request $request) use (&$rejected) {
        if ($request->method() === 'HEAD') {
            return Http::response('', 200);
        }

        return $rejected ? Http::response([], 422) : Http::response(dataCiteRecord('10.83279/corrected', attributes: $request['data']['attributes']), 201);
    });
    expect(fn () => app(ResourceDoiPublicationService::class)->publish($this->resource, '10.83279'))->toThrow(RequestException::class);
    expect(ResourceDoiPublication::sole()->status)->toBe('failed')->and($this->page->fresh()->is_published)->toBeTrue();
    $rejected = false;
    expect(app(ResourceDoiPublicationService::class)->publish($this->resource, '10.83279')['complete'])->toBeTrue();
    expect(ResourceDoiPublication::count())->toBe(2);
});

it('keeps accepted publication pending when local completion no longer matches approval', function (): void {
    $accepted = null;
    Http::fake(function (Request $request) use (&$accepted) {
        if ($request->method() === 'HEAD') {
            return Http::response('', 200);
        }
        if ($request->method() === 'POST') {
            $accepted = dataCiteRecord('10.83279/accepted', attributes: $request['data']['attributes']);
            $this->resource->update(['version' => 'edited-after-send']);
        }

        return Http::response($accepted);
    });
    $service = app(ResourceDoiPublicationService::class);
    expect($service->publish($this->resource, '10.83279')['complete'])->toBeFalse();
    expect($service->publish($this->resource, '10.83279')['complete'])->toBeFalse();
    expect(ResourceDoiPublication::sole()->doi)->toBe('10.83279/accepted')->and($this->resource->fresh()->doi)->toBeNull();
    expect(Http::recorded(fn (Request $request) => $request->method() === 'POST'))->toHaveCount(1);
});

it('exposes pending publication after reload without performing a remote request', function (): void {
    Http::fake(fn (Request $request) => $request->method() === 'HEAD' ? Http::response('', 200) : Http::failedConnection());
    app(ResourceDoiPublicationService::class)->publish($this->resource, '10.83279');
    Http::fake();
    $user = User::factory()->curator()->create();
    $load = app(EditorLoadProgressService::class)->begin($user->id, $this->resource->id);
    $this->actingAs($user)->withHeader(EditorController::RESOURCE_LOAD_TOKEN_HEADER, $load['token'])
        ->get('/editor?resourceId='.$this->resource->id)->assertOk()
        ->assertInertia(fn ($page) => $page->where('doiPublication.status', 'pending_reconciliation'));
    Http::assertNothingSent();
});

it('publishes an approved external URL and keeps an existing Issued date', function (): void {
    app()->instance(SizeFormatDownloadTargetResolverService::class,
        new SizeFormatDownloadTargetResolverService(fn (): array => ['93.184.216.34']));
    $domain = LandingPageDomain::factory()->create(['domain' => 'https://external.example/']);
    $this->page->update(['template' => 'external', 'external_domain_id' => $domain->id, 'external_path' => 'approved']);
    $issued = DateType::firstOrCreate(['slug' => 'Issued'], ['name' => 'Issued', 'is_active' => true]);
    $this->resource->dates()->create(['date_type_id' => $issued->id, 'date_value' => '2020-01-01']);
    Http::fake(function (Request $request) {
        if ($request->method() === 'HEAD') {
            expect($request->url())->toBe('https://external.example/approved');

            return Http::response('', 200);
        }
        expect($request['data']['attributes']['url'])->toBe('https://external.example/approved');

        return Http::response(dataCiteRecord('10.83279/external', attributes: $request['data']['attributes']), 201);
    });
    expect(app(ResourceDoiPublicationService::class)->publish($this->resource, '10.83279')['complete'])->toBeTrue();
    expect($this->resource->dates()->where('date_type_id', $issued->id)->sole()->date_value)->toBe('2020-01-01');
});

it('keeps multiple matching remote creations pending for manual reconciliation', function (): void {
    $accepted = [];
    Http::fake(function (Request $request) use (&$accepted) {
        if ($request->method() === 'HEAD') {
            return Http::response('', 200);
        }
        if ($request->method() === 'POST') {
            foreach (['first', 'second'] as $suffix) {
                $accepted['10.83279/'.$suffix] = dataCiteRecord('10.83279/'.$suffix, attributes: $request['data']['attributes']);
            }

            return Http::failedConnection();
        }
        if (str_contains($request->url(), '?')) {
            return Http::response(['data' => array_column($accepted, 'data')]);
        }

        return Http::response($accepted[rawurldecode(basename(parse_url($request->url(), PHP_URL_PATH)))]);
    });
    $service = app(ResourceDoiPublicationService::class);
    expect($service->publish($this->resource, '10.83279')['complete'])->toBeFalse();
    expect($service->publish($this->resource, '10.83279')['complete'])->toBeFalse();
    expect($this->resource->fresh()->doi)->toBeNull()->and(ResourceDoiPublication::sole()->status)->toBe('pending_reconciliation');
    expect(Http::recorded(fn (Request $request) => $request->method() === 'POST'))->toHaveCount(1);
});
