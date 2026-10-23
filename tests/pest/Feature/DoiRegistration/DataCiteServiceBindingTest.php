<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\User;
use App\Services\DataCiteServiceInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('uses the interface binding for DOI registration and metadata updates', function (bool $batch, bool $existingDoi, bool $testMode): void {
    Http::preventStrayRequests();
    Queue::fake();
    config([
        'datacite.test_mode' => $testMode,
        'datacite.test.prefixes' => ['10.83279'],
        'datacite.production.prefixes' => ['10.5880'],
    ]);

    $user = User::factory()->curator()->create();
    $prefix = $testMode ? '10.83279' : '10.5880';
    $doi = $prefix.'/interface-binding';
    $resource = Resource::factory()->create(['doi' => $existingDoi ? $doi : null]);
    LandingPage::factory()->for($resource)->create();

    // Bind only the contract so resolving the concrete service cannot satisfy the test.
    $service = Mockery::mock(DataCiteServiceInterface::class);
    $service->shouldReceive('isTestMode')->atLeast()->once()->andReturn($testMode);
    $service->shouldReceive($existingDoi ? 'updateMetadata' : 'registerDoi')
        ->once()
        ->withArgs(fn (Resource $actual, ?string $actualPrefix = null): bool => $actual->is($resource)
            && $actualPrefix === ($existingDoi ? null : $prefix))
        ->andReturn(['data' => ['id' => $doi, 'attributes' => ['state' => 'findable']]]);
    app()->instance(DataCiteServiceInterface::class, $service);

    $payload = $existingDoi ? [] : ['prefix' => $prefix];
    if ($batch) {
        $payload['ids'] = [$resource->id];
    }

    $response = $this->actingAs($user)->postJson(
        $batch ? '/resources/batch-register' : route('resources.register-doi', $resource),
        $payload,
    )->assertOk();

    if ($batch) {
        $response->assertJsonCount(1, 'success')
            ->assertJsonPath('success.0.id', $resource->id)
            ->assertJsonPath('success.0.doi', $doi)
            ->assertJsonPath('success.0.updated', $existingDoi)
            ->assertJsonPath('failed', []);
    } else {
        $response->assertJsonPath('success', true)
            ->assertJsonPath('doi', $doi)
            ->assertJsonPath('updated', $existingDoi)
            ->assertJsonPath('mode', $testMode ? 'test' : 'production');
    }

    expect($resource->fresh()->doi)->toBe($doi);
    expect($resource->registrationEvidence()->sole())
        ->doi->toBe($doi)
        ->environment->toBe($testMode ? 'test' : 'production')
        ->state->toBe('findable')
        ->source->toBe($existingDoi ? 'update' : 'registration');
    Http::assertNothingSent();
})->with(['single' => false, 'batch' => true])
    ->with(['new DOI' => false, 'existing DOI' => true])
    ->with(['test environment' => true, 'production environment' => false]);
