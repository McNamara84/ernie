<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceDoiRegistrationEvidence;
use App\Models\User;
use App\Services\Curation\CurationService;
use App\Services\DataCiteServiceInterface;
use App\Services\FakeDataCiteRegistrationService;

beforeEach(function (): void {
    config(['datacite.test_mode' => true]);
    $this->actingAs(User::factory()->curator()->create());
    $this->resource = Resource::factory()->create(['doi' => null]);
    LandingPage::factory()->draft()->create(['resource_id' => $this->resource->id]);
    app(CurationService::class)->start($this->resource, null, 'import');
    app()->instance(DataCiteServiceInterface::class, new FakeDataCiteRegistrationService);
});

it('records curation evidence after explicit publication persists the new DOI', function (): void {
    $this->postJson(route('resources.publish-doi', $this->resource), [
        'prefix' => '10.83279', 'confirm_publication' => true,
    ])->assertOk()->assertJsonPath('success', true);

    $resource = $this->resource->fresh('landingPage');
    expect($resource->doi)->not->toBeNull()
        ->and($resource->landingPage->is_published)->toBeTrue();
    expect(ResourceDoiRegistrationEvidence::sole())
        ->doi->toBe(strtolower($resource->doi))
        ->state->toBe('findable')->environment->toBe('fake')->source->toBe('registration');
    expect(app(CurationService::class)->automatic($resource)['doi_registered_and_published'])->toBeTrue();
});

it('does not record completed curation evidence while publication needs reconciliation', function (): void {
    $registration = Mockery::mock(DataCiteServiceInterface::class);
    $registration->shouldReceive('isTestMode')->andReturn(true);
    $registration->shouldReceive('registerDoi')->once()->andReturn([
        'complete' => false, 'publication' => ['status' => 'pending_reconciliation'],
        'data' => ['id' => '10.83279/pending', 'attributes' => ['state' => 'findable']],
    ]);
    app()->instance(DataCiteServiceInterface::class, $registration);

    $this->postJson(route('resources.publish-doi', $this->resource), [
        'prefix' => '10.83279', 'confirm_publication' => true,
    ])->assertAccepted()->assertJsonPath('success', false);

    expect(ResourceDoiRegistrationEvidence::count())->toBe(0)
        ->and(app(CurationService::class)->automatic($this->resource->fresh())['doi_registered_and_published'])->toBeFalse();
});

it('keeps curation evidence when a published resource is updated in a batch', function (): void {
    $publication = app(DataCiteServiceInterface::class)->registerDoi($this->resource, '10.83279');
    expect($publication['data']['attributes']['state'])->toBe('findable');

    $this->postJson('/resources/batch-register', ['ids' => [$this->resource->id]])
        ->assertOk()->assertJsonPath('success.0.updated', true)->assertJsonCount(0, 'failed');

    expect(ResourceDoiRegistrationEvidence::sole())
        ->state->toBe('findable')->environment->toBe('fake')->source->toBe('update');
});
