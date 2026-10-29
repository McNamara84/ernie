<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/** Synthetic v3 examples, checked against public responses on 2026-10-29. */
final class B2instFixture
{
    public static function record(string $id = 'abc12-def34', array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => $id, 'parent' => ['id' => 'parent-'.$id, 'communities' => ['ids' => ['community-1']]],
            'revision_id' => 2, 'updated' => '2026-10-29T10:00:00Z',
            'versions' => ['is_latest' => true, 'index' => 1], 'is_published' => true,
            'access' => ['record' => 'public', 'files' => 'restricted'],
            'deletion_status' => ['is_deleted' => false, 'status' => 'P'],
            'links' => ['self_html' => 'https://b2inst.example.test/records/'.$id],
            'pids' => ['epic' => ['identifier' => 'http://hdl.handle.net/21.T11975/'.$id]],
            'metadata' => [
                'SchemaVersion' => '1.0', 'Name' => 'Test instrument '.$id,
                'Identifier' => ['identifierType' => 'Handle', 'identifierValue' => '21.T11975/'.$id],
                'Description' => 'Instrument description', 'LandingPage' => 'https://instruments.example.test/'.$id,
                'Owner' => [['ownerName' => 'GFZ']], 'Manufacturer' => [['manufacturerName' => 'Acme']],
                'Model' => ['modelName' => 'M-1'], 'InstrumentType' => [['instrumentTypeName' => 'Sensor']],
                'MeasuredVariable' => ['Temperature'],
                'AlternateIdentifier' => [['alternateIdentifierType' => 'SerialNumber', 'alternateIdentifierValue' => 'SN-42']],
            ],
        ], $overrides);
    }

    /** Public migrated GFZ example: only fields required for the regression are retained. */
    public static function migrated(): array
    {
        return self::record('7z780-7kj63', [
            'parent' => ['id' => 'gqxtc-19e60'], 'revision_id' => 20,
            'metadata' => ['Identifier' => ['identifierValue' => '21.11157/7z780-7kj63'], 'LandingPage' => 'https://sensors.gfz.de/devices/842'],
            'pids' => ['epic' => ['identifier' => 'http://hdl.handle.net/21.11157/d96a9d31-fd99-4a0c-9b98-514a04b18423']],
        ]);
    }

    public static function page(array $records, ?int $total = null, ?string $next = null): array
    {
        return ['hits' => ['hits' => $records, 'total' => $total ?? count($records)], 'links' => ['next' => $next]];
    }

    public static function handle(string $target): array
    {
        return ['responseCode' => 1, 'values' => [['type' => 'URL', 'data' => ['format' => 'string', 'value' => $target]]]];
    }
}
