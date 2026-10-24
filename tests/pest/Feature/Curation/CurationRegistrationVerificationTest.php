<?php

declare(strict_types=1);

use App\Jobs\VerifyCurationRegistrationJob;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceDoiRegistrationEvidence;
use App\Services\Curation\CurationService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    config([
        'datacite.test_mode' => true,
        'datacite.test.endpoint' => 'https://api.test.datacite.org',
        'datacite.test.username' => 'TEST.USER',
        'datacite.test.password' => 'test-only',
    ]);
    $this->resource = Resource::factory()->create(['doi' => '10.1234/verify-curation']);
    LandingPage::factory()->published()->create(['resource_id' => $this->resource->id]);
    app(CurationService::class)->start($this->resource, null, 'explicit', true);
});

it('verifies an existing DOI asynchronously and reconciles publication evidence', function (): void {
    Queue::assertPushed(VerifyCurationRegistrationJob::class, fn ($job): bool => $job->resourceId === $this->resource->id);
    Http::fake(['*datacite.org/*' => Http::response(['data' => ['id' => $this->resource->doi, 'attributes' => ['state' => 'findable']]])]);
    expect(app(CurationService::class)->automatic($this->resource->fresh())['doi_registered_and_published'])->toBeFalse();

    app()->call([new VerifyCurationRegistrationJob($this->resource->id, $this->resource->doi, true), 'handle']);

    expect(ResourceDoiRegistrationEvidence::sole())->environment->toBe('test')->source->toBe('verification');
    expect(app(CurationService::class)->automatic($this->resource->fresh())['doi_registered_and_published'])->toBeTrue();
    Http::assertSentCount(1);
});

it('does not request a deleted resource or a DOI replaced before verification', function (bool $deleted): void {
    $job = new VerifyCurationRegistrationJob($this->resource->id, $this->resource->doi, true);
    $deleted ? $this->resource->delete() : $this->resource->update(['doi' => '10.1234/replacement']);
    app()->call([$job, 'handle']);
    Http::assertNothingSent();
    expect(ResourceDoiRegistrationEvidence::count())->toBe(0);
})->with([true, false]);

it('ignores evidence when the DOI changes during the verification request', function (): void {
    $doi = $this->resource->doi;
    Http::fake(['*datacite.org/*' => function () use ($doi) {
        $this->resource->update(['doi' => '10.1234/replacement']);

        return Http::response(['data' => ['id' => $doi, 'attributes' => ['state' => 'findable']]]);
    }]);
    app()->call([new VerifyCurationRegistrationJob($this->resource->id, $doi, true), 'handle']);
    expect(ResourceDoiRegistrationEvidence::count())->toBe(0);
    expect(app(CurationService::class)->automatic($this->resource->fresh())['doi_registered_and_published'])->toBeFalse();
});

it('leaves missing registrations unconfirmed and lets temporary failures retry', function (int $status): void {
    Http::fake(['*datacite.org/*' => Http::response([], $status)]);
    $verify = fn () => app()->call([new VerifyCurationRegistrationJob($this->resource->id, $this->resource->doi, true), 'handle']);
    if ($status === 404) {
        $verify();
    } else {
        expect($verify)->toThrow(RequestException::class);
    }
    expect(ResourceDoiRegistrationEvidence::count())->toBe(0);
    expect(app(CurationService::class)->automatic($this->resource->fresh())['doi_registered_and_published'])->toBeFalse();
})->with([404, 503]);
