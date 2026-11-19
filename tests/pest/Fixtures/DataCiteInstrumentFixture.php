<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Illuminate\Support\Facades\Http;

/** Synthetic records matching the public Instrument search contract. */
final class DataCiteInstrumentFixture
{
    public static function record(string $doi = '10.1234/sensor', array $attributes = []): array
    {
        return ['id' => $doi, 'type' => 'dois', 'attributes' => array_replace([
            'doi' => $doi, 'state' => 'findable', 'types' => ['resourceTypeGeneral' => 'Instrument'],
            'titles' => [['title' => 'DataCite sensor']], 'url' => 'https://instruments.example.test/datacite',
            'descriptions' => [['descriptionType' => 'Abstract', 'description' => 'Sensor description']],
            'creators' => [['name' => 'Sensor manufacturer']],
            'contributors' => [['name' => 'Sensor owner', 'contributorType' => 'HostingInstitution']],
            'updated' => '2026-11-17T08:00:00Z', 'metadataVersion' => 1,
        ], $attributes)];
    }

    public static function page(array $records = [], ?int $total = null, ?string $next = null): array
    {
        return ['data' => $records, 'meta' => ['total' => $total ?? count($records)], 'links' => ['next' => $next]];
    }

    public static function fakeEmpty(): void
    {
        config(['pidinst.datacite.host' => 'https://datacite.example.test']);
        Http::fake(['https://datacite.example.test/*' => Http::response(self::page())]);
    }
}
