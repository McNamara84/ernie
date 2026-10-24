<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceDoiRegistrationEvidence;
use App\Services\Curation\CurationRegistrationService;
use App\Services\Curation\CurationService;
use App\Services\DataCiteServiceInterface;
use App\Services\DataCiteSyncService;
use App\Services\FakeDataCiteRegistrationService;

beforeEach(function (): void {
    $this->resource = Resource::factory()->create(['doi' => '10.1234/automatic-sync']);
    app(CurationService::class)->start($this->resource, null, 'import');
    LandingPage::factory()->published()->create(['resource_id' => $this->resource->id]);
});

it('records automatic sync evidence using the actual registration environment', function (string $environment, bool $testMode, bool $acceptTestEvidence): void {
    config(['datacite.test_mode' => $acceptTestEvidence]);
    $evidence = app(CurationRegistrationService::class);
    $evidence->record($this->resource, ['data' => ['id' => $this->resource->doi, 'attributes' => ['state' => 'draft']]], $environment, 'verification');
    $curation = app(CurationService::class);
    expect($curation->automatic($this->resource->fresh())['doi_registered_and_published'])->toBeFalse();
    $registration = Mockery::mock($environment === 'fake' ? FakeDataCiteRegistrationService::class : DataCiteServiceInterface::class);
    $registration->shouldReceive('isTestMode')->andReturn($testMode);
    $registration->shouldReceive('updateMetadata')->once()->with($this->resource)
        ->andReturn(['data' => ['id' => $this->resource->doi, 'attributes' => ['state' => 'findable']]]);

    expect((new DataCiteSyncService($registration))->syncIfRegistered($this->resource)->success)->toBeTrue();

    expect(ResourceDoiRegistrationEvidence::sole())->environment->toBe($environment)->state->toBe('findable')->source->toBe('automatic_sync');
    expect($curation->automatic($this->resource->fresh())['doi_registered_and_published'])->toBe($environment === 'production' || $acceptTestEvidence);
    expect($this->resource->checklistItems()->where('item_key', 'doi_registered_and_published')->value('state'))
        ->toBe($environment === 'production' || $acceptTestEvidence ? 'done' : 'open');
})->with([['production', false, false], ['test', true, true], ['fake', true, true], ['fake', true, false]]);

it('does not create publication evidence for failed incomplete or stale sync responses', function (string $scenario): void {
    $registration = Mockery::mock(DataCiteServiceInterface::class);
    $registration->shouldReceive('isTestMode')->andReturn(false);
    $registration->shouldReceive('updateMetadata')->once()->andReturnUsing(function () use ($scenario): array {
        if ($scenario === 'failure') {
            throw new RuntimeException('DataCite temporarily unavailable');
        }
        if ($scenario === 'incomplete') {
            return ['data' => ['id' => $this->resource->doi]];
        }
        $doi = $this->resource->doi;
        Resource::whereKey($this->resource->id)->update(['doi' => '10.1234/replaced-during-sync']);

        return ['data' => ['id' => $doi, 'attributes' => ['state' => 'findable']]];
    });

    $result = (new DataCiteSyncService($registration))->syncIfRegistered($this->resource);

    expect($result->success)->toBe($scenario !== 'failure')->and(ResourceDoiRegistrationEvidence::count())->toBe(0)
        ->and(app(CurationService::class)->automatic($this->resource->fresh())['doi_registered_and_published'])->toBeFalse();
})->with(['failure', 'incomplete', 'stale']);
