<?php

declare(strict_types=1);

uses(Tests\TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class);

use App\Models\AssistantSuggestion;
use App\Models\Resource;
use App\Services\SizeFormat\SizeFormatSizeParserService;
use App\Services\SizeFormat\SizeFormatSuggestionAcceptanceService;
use App\Services\SizeFormat\SizeFormatSuggestionDiscoveryService;
use App\Services\SizeFormatFileProbeService;
use Illuminate\Support\Facades\Http;
use Modules\Assistants\SizeFormatSuggestion\Assistant;

beforeEach(function (): void {
    $probeService = app(SizeFormatFileProbeService::class);
    $sizeParser = app(SizeFormatSizeParserService::class);
    $this->discoveryService = new SizeFormatSuggestionDiscoveryService($probeService, $sizeParser);
    $this->acceptanceService = new SizeFormatSuggestionAcceptanceService($sizeParser);
    $this->assistant = new Assistant($this->discoveryService, $this->acceptanceService);
});

it('stores size and format suggestions for eligible resources', function (): void {
    Http::fake(function (\Illuminate\Http\Client\Request $request) {
        $url = $request->url();

        if ($url === 'https://doi.org/10.1234/size-format-test') {
            return Http::response('', 302, [
                'Location' => 'https://dataservices.gfz-potsdam.de/landing-size-format-test',
            ]);
        }

        if ($url === 'https://dataservices.gfz-potsdam.de/landing-size-format-test') {
            return Http::response(<<<'HTML'
                <html>
                    <body>
                        <a class="piwik_download" href="/download/test/">Download data</a>
                    </body>
                </html>
                HTML, 200, [
                    'Content-Type' => 'text/html',
                ]);
        }

        if ($url === 'https://dataservices.gfz-potsdam.de/download/test/') {
            return Http::response(<<<'HTML'
                <a href="test.zip">test.zip</a> 2026-06-14 10:00 12.5M
                HTML, 200, [
                    'Content-Type' => 'text/html',
                ]);
        }

        return Http::response('', 404);
    });

    Resource::factory()->create(['doi' => '10.1234/size-format-test']);

    $count = $this->assistant->runDiscovery(fn (): null => null);

    expect($count)->toBeGreaterThan(0)
        ->and(AssistantSuggestion::where('assistant_id', 'size-format-suggestion')->count())->toBeGreaterThan(0);
});

it('accepts a format suggestion and returns the acceptance message', function (): void {
    $resource = Resource::factory()->create();
    $suggestion = AssistantSuggestion::create([
        'assistant_id' => 'size-format-suggestion',
        'resource_id' => $resource->id,
        'target_type' => 'format',
        'target_id' => $resource->id,
        'suggested_value' => 'zip',
        'suggested_label' => 'FORMAT: zip',
        'similarity_score' => null,
        'metadata' => [],
        'discovered_at' => now(),
    ]);

    $acceptMethod = new ReflectionMethod($this->assistant, 'applyAccepted');
    $result = $acceptMethod->invoke($this->assistant, $suggestion);

    expect($result['success'])->toBeTrue()
        ->and($result['message'])->toContain("Format 'application/zip' applied.");
});

it('accepts a size suggestion and returns the acceptance message', function (): void {
    $resource = Resource::factory()->create();
    $suggestion = AssistantSuggestion::create([
        'assistant_id' => 'size-format-suggestion',
        'resource_id' => $resource->id,
        'target_type' => 'size',
        'target_id' => $resource->id,
        'suggested_value' => '12.5MB',
        'suggested_label' => 'SIZE: 12.5MB',
        'similarity_score' => null,
        'metadata' => [
            'parsed_size' => [
                'numeric_value' => '12.5',
                'unit' => 'MB',
                'type' => null,
            ],
        ],
        'discovered_at' => now(),
    ]);

    $acceptMethod = new ReflectionMethod($this->assistant, 'applyAccepted');
    $result = $acceptMethod->invoke($this->assistant, $suggestion);

    expect($result['success'])->toBeTrue()
        ->and($result['message'])->toContain("Size '");
});

it('returns a failure message for unknown suggestion types', function (): void {
    $resource = Resource::factory()->create();
    $suggestion = AssistantSuggestion::create([
        'assistant_id' => 'size-format-suggestion',
        'resource_id' => $resource->id,
        'target_type' => 'unknown',
        'target_id' => $resource->id,
        'suggested_value' => 'something',
        'suggested_label' => 'UNKNOWN: something',
        'similarity_score' => null,
        'metadata' => [],
        'discovered_at' => now(),
    ]);

    $acceptMethod = new ReflectionMethod($this->assistant, 'applyAccepted');
    $result = $acceptMethod->invoke($this->assistant, $suggestion);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toBe('Unknown suggestion type.');
});