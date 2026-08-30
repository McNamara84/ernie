<?php

declare(strict_types=1);

use App\Http\Controllers\PortalKeywordSuggestionController;
use App\Http\Requests\PortalKeywordSuggestionRequest;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Subject;
use App\Services\KeywordSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

covers(
    PortalKeywordSuggestionController::class,
    PortalKeywordSuggestionRequest::class,
    KeywordSuggestionService::class,
);

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();

    $this->resourceType = ResourceType::factory()->create([
        'name' => 'Dataset',
        'slug' => 'dataset',
    ]);

    $this->createKeyword = function (
        string $keyword,
        bool $published = true,
        ?string $scheme = null,
    ): void {
        $resource = Resource::factory()->create([
            'resource_type_id' => $this->resourceType->id,
        ]);

        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'is_published' => $published,
            'published_at' => $published ? now() : null,
        ]);

        Subject::factory()->create([
            'resource_id' => $resource->id,
            'value' => $keyword,
            'subject_scheme' => $scheme,
        ]);
    };
});

it('validates the bounded suggestion query', function (array $query, string $errorKey) {
    $this->getJson(route('portal.free-keyword-suggestions', $query))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errorKey);
})->with([
    'missing query' => [[], 'q'],
    'one character' => [['q' => 'a'], 'q'],
    'more than 100 characters' => [['q' => str_repeat('a', 101)], 'q'],
]);

it('matches case-insensitively and ranks prefixes before substrings', function () {
    ($this->createKeyword)('Seismicity');
    ($this->createKeyword)('Seismology');
    ($this->createKeyword)('Seismology');
    ($this->createKeyword)('Paleoseismology');
    ($this->createKeyword)('Paleoseismology');
    ($this->createKeyword)('Paleoseismology');

    $this->getJson(route('portal.free-keyword-suggestions', ['q' => 'SEIS']))
        ->assertOk()
        ->assertExactJson([
            'data' => [
                ['value' => 'Seismology', 'scheme' => null, 'count' => 2],
                ['value' => 'Seismicity', 'scheme' => null, 'count' => 1],
                ['value' => 'Paleoseismology', 'scheme' => null, 'count' => 3],
            ],
        ]);
});

it('returns at most twenty published free keywords', function () {
    foreach (range(1, 25) as $index) {
        ($this->createKeyword)(sprintf('Ocean keyword %02d', $index));
    }

    ($this->createKeyword)('Ocean unpublished', false);
    ($this->createKeyword)('Ocean controlled', true, 'Science Keywords');

    $this->getJson(route('portal.free-keyword-suggestions', ['q' => 'ocean']))
        ->assertOk()
        ->assertJsonCount(20, 'data')
        ->assertJsonMissing(['value' => 'Ocean unpublished'])
        ->assertJsonMissing(['value' => 'Ocean controlled']);
});

it('uses an independent public suggestion rate limit', function () {
    config([
        'bot_protection.enabled' => true,
        'bot_protection.limits.public_portal_suggestions_per_minute' => 1,
    ]);

    RateLimiter::clear('portal-suggestions:public:203.0.113.12');

    $visitor = $this->withServerVariables([
        'REMOTE_ADDR' => '203.0.113.12',
        'HTTP_USER_AGENT' => 'Mozilla/5.0',
    ]);

    $visitor->getJson(route('portal.free-keyword-suggestions', ['q' => 'ocean']))->assertOk();
    $visitor->getJson(route('portal.free-keyword-suggestions', ['q' => 'ocean']))->assertTooManyRequests();
});
