<?php

declare(strict_types=1);

use App\Http\Controllers\Api\RelatedIdentifierCitationLabelController;
use App\Models\User;
use App\Services\Citations\RelatedIdentifierCitationLabelService;

covers(RelatedIdentifierCitationLabelController::class);

function actingAsCitationLabelEditor(): void
{
    test()->actingAs(User::factory()->create());
}

it('resolves a normalized DOI citation label for an authenticated editor', function (): void {
    actingAsCitationLabelEditor();

    $citationLabels = Mockery::mock(RelatedIdentifierCitationLabelService::class);
    $citationLabels->shouldReceive('resolve')
        ->once()
        ->with('10.5880/gfz.test.2026', 'DOI')
        ->andReturn('Doe, J. (2026): Resolved DOI citation.');
    $this->app->instance(RelatedIdentifierCitationLabelService::class, $citationLabels);

    $this->getJson('/api/v1/related-identifiers/citation-label?identifier='.urlencode('https://doi.org/10.5880/GFZ.TEST.2026').'&identifierType=DOI')
        ->assertOk()
        ->assertExactJson([
            'citation' => 'Doe, J. (2026): Resolved DOI citation.',
            'identifier' => '10.5880/gfz.test.2026',
            'identifier_type' => 'DOI',
        ]);
});

it('resolves an exact cached URL citation label for an authenticated editor', function (): void {
    actingAsCitationLabelEditor();

    $url = 'https://example.org/legacy/resource?version=1';
    $citationLabels = Mockery::mock(RelatedIdentifierCitationLabelService::class);
    $citationLabels->shouldReceive('resolve')
        ->once()
        ->with($url, 'URL')
        ->andReturn('Legacy URL citation');
    $this->app->instance(RelatedIdentifierCitationLabelService::class, $citationLabels);

    $this->getJson('/api/v1/related-identifiers/citation-label?identifier='.urlencode("  {$url}  ").'&identifierType=URL')
        ->assertOk()
        ->assertExactJson([
            'citation' => 'Legacy URL citation',
            'identifier' => $url,
            'identifier_type' => 'URL',
        ]);
});

it('returns not found when no citation label can be resolved', function (): void {
    actingAsCitationLabelEditor();

    $citationLabels = Mockery::mock(RelatedIdentifierCitationLabelService::class);
    $citationLabels->shouldReceive('resolve')
        ->once()
        ->with('https://example.org/not-cached', 'URL')
        ->andReturnNull();
    $this->app->instance(RelatedIdentifierCitationLabelService::class, $citationLabels);

    $this->getJson('/api/v1/related-identifiers/citation-label?identifier='.urlencode('https://example.org/not-cached').'&identifierType=URL')
        ->assertNotFound()
        ->assertExactJson([
            'error' => 'No citation label could be resolved for this identifier.',
        ]);
});

it('requires authentication', function (): void {
    $this->getJson('/api/v1/related-identifiers/citation-label?identifier='.urlencode('10.5880/test').'&identifierType=DOI')
        ->assertUnauthorized();
});

it('rejects invalid citation lookup input', function (array $query, string $errorKey): void {
    actingAsCitationLabelEditor();

    $this->getJson('/api/v1/related-identifiers/citation-label?'.http_build_query($query))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errorKey);
})->with([
    'missing identifier' => [['identifierType' => 'DOI'], 'identifier'],
    'empty identifier' => [['identifier' => '   ', 'identifierType' => 'DOI'], 'identifier'],
    'invalid DOI' => [['identifier' => 'not-a-doi', 'identifierType' => 'DOI'], 'identifier'],
    'non-http URL' => [['identifier' => 'javascript:alert(1)', 'identifierType' => 'URL'], 'identifier'],
    'ftp URL' => [['identifier' => 'ftp://example.org/file', 'identifierType' => 'URL'], 'identifier'],
    'unsupported type' => [['identifier' => '2142/example', 'identifierType' => 'Handle'], 'identifierType'],
    'missing type' => [['identifier' => '10.5880/test'], 'identifierType'],
    'overlong identifier' => [['identifier' => 'https://example.org/'.str_repeat('a', 2184), 'identifierType' => 'URL'], 'identifier'],
]);
