<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\AssistantSuggestion;
use App\Models\FunderIdentifierType;
use App\Models\FundingReference;
use App\Models\Resource;
use App\Services\Assistance\AssistantRegistrar;
use App\Services\FundingAwardUri\AwardMetadata;
use Database\Seeders\FunderIdentifierTypeSeeder;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\Assistants\FundingAwardUriEnrichment\Assistant;

final class FundingAwardUriFixtures
{
    public const URI = 'https://doi.org/10.35802/218300';

    /** @param array<string, mixed> $overrides */
    public static function target(array $overrides = []): FundingReference
    {
        (new FunderIdentifierTypeSeeder)->run();

        return FundingReference::create([...[
            'resource_id' => Resource::factory()->create(['doi' => null])->id,
            'funder_name' => 'Wellcome', 'funder_identifier' => 'https://doi.org/10.13039/100010269',
            'funder_identifier_type_id' => FunderIdentifierType::where('name', 'Crossref Funder ID')->value('id'),
            'scheme_uri' => 'https://doi.org/', 'award_number' => '218300', 'award_title' => 'Research award', 'award_uri' => null,
        ], ...$overrides]);
    }

    /** @return array<string, mixed> */
    public static function grant(string $number = '218300', string $doi = '10.35802/218300', string $funder = '10.13039/100010269'): array
    {
        return ['type' => 'grant', 'DOI' => $doi, 'award' => $number, 'project' => [[
            'project-title' => [['title' => 'Research award']],
            'funding' => [['funder' => ['id' => [['id' => $funder, 'id-type' => 'DOI']]]]],
        ]]];
    }

    /** @param list<array<string, mixed>>|null $items */
    public static function fake(?array $items = null): void
    {
        Cache::flush();
        config(['funding_award_uri.enabled' => true, 'funding_award_uri.request_interval_ms' => 0]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $items ??= [self::grant()];
        Http::fake(['https://api.crossref.org/works*' => Http::response(['message' => ['total-results' => count($items), 'items' => $items]]),
            'https://cordis.europa.eu/*' => Http::response(['results' => ['bindings' => []]])]);
    }

    public static function discover(): AssistantSuggestion
    {
        app(Assistant::class)->runDiscovery(static function (string $message): void {});

        return AssistantSuggestion::where('assistant_id', AwardMetadata::ASSISTANT_ID)->firstOrFail();
    }

    /** @return array{award_uri_review_fingerprint: string} */
    public static function input(AssistantSuggestion $suggestion): array
    {
        return ['award_uri_review_fingerprint' => $suggestion->metadata['review_fingerprint']];
    }

    public static function register(): void
    {
        app(AssistantRegistrar::class)->register(app(Assistant::class));
    }
}
