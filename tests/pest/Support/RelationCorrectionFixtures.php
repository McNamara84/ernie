<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\AssistantSuggestion;
use App\Models\IdentifierType;
use App\Models\RelatedIdentifier;
use App\Models\RelationType;
use App\Models\Resource;
use App\Models\User;
use App\Services\Assistance\AssistantRegistrar;
use Database\Seeders\IdentifierTypeSeeder;
use Database\Seeders\RelationTypeSeeder;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Modules\Assistants\RelationTypeCorrection\Assistant;

final class RelationCorrectionFixtures
{
    public const OWN = '10.5880/correction.a';

    public const OTHER = '10.5880/correction.b';

    public static function target(string $current = 'HasPart'): RelatedIdentifier
    {
        (new IdentifierTypeSeeder)->run();
        (new RelationTypeSeeder)->run();
        $resource = Resource::factory()->create(['doi' => self::OWN]);

        return RelatedIdentifier::create(['resource_id' => $resource->id, 'identifier' => self::OTHER,
            'identifier_type_id' => IdentifierType::where('slug', 'DOI')->value('id'),
            'relation_type_id' => RelationType::where('slug', $current)->value('id'),
            'citation_label' => 'A manually curated citation.', 'source' => 'legacy_igsn_dif', 'position' => 3]);
    }

    public static function fake(string $relation = 'HasPart'): void
    {
        self::resetHttp();
        Http::preventStrayRequests();
        Http::fake([
            '*api.datacite.org/dois/'.rawurlencode(self::OTHER) => Http::response(['data' => ['attributes' => ['doi' => self::OTHER,
                'relatedIdentifiers' => [['relatedIdentifier' => self::OWN, 'relatedIdentifierType' => 'DOI', 'relationType' => $relation]]]]]),
            '*api.datacite.org/events*' => Http::response(['data' => [], 'links' => ['next' => null]]),
            '*scholexplorer*' => Http::response(['result' => [], 'totalPages' => 1]),
            '*' => Http::response([], 404),
        ]);
    }

    public static function resetHttp(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    public static function discover(): AssistantSuggestion
    {
        app(Assistant::class)->runDiscovery(static function (string $message): void {});

        return AssistantSuggestion::where('assistant_id', 'relation-type-correction')->firstOrFail();
    }

    /** @return array{relation_type_correction_fingerprint: string} */
    public static function input(AssistantSuggestion $suggestion): array
    {
        return ['relation_type_correction_fingerprint' => $suggestion->metadata['review_fingerprint']];
    }

    public static function actor(): User
    {
        return User::factory()->admin()->create();
    }

    public static function registerAssistant(): void
    {
        app(AssistantRegistrar::class)->register(app(Assistant::class));
    }
}
