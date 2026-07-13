<?php

declare(strict_types=1);

namespace App\Services\Rights;

use App\Models\Resource;
use App\Models\ResourceRight;
use App\Models\Right;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Persists DataCite rights statements on resources.
 *
 * This service deliberately keeps two concepts separate:
 *
 * - "selected catalog rights" are the rights a curator chooses in ERNIE.
 * - "raw imported statements" are the exact rights metadata that arrived from
 *   DataCite JSON/XML or another import channel.
 *
 * A row may have both: raw imported text plus a trusted `rights_id` link. A row
 * may also remain unresolved with `rights_id = null`; the SPDX assistant can
 * later propose a safe link for that exact row.
 */
final class ResourceRightsStorageService
{
    /**
     * @param  list<string>  $licenseIdentifiers
     * @param  array<int, array<string, mixed>>  $rawRights
     * @param  array<int, int>  $sourceResourceRightLinks
     */
    public function syncEditorRights(
        Resource $resource,
        array $licenseIdentifiers,
        array $rawRights = [],
        ?string $fallbackLanguage = null,
        array $sourceResourceRightLinks = [],
        bool $replaceUnresolvedRawRights = false,
    ): void {
        $selectedRights = $this->rightsByIdentifier($licenseIdentifiers);
        $selectedRightIds = array_values($selectedRights);
        $sourceResourceRightIds = array_keys($sourceResourceRightLinks);

        // The editor owns linked catalog selections, so a normal save should
        // remove linked rows that are no longer selected. Unresolved imported
        // rows stay in place; otherwise a curator could lose raw DataCite input
        // before the SPDX assistant has reviewed it.
        ResourceRight::query()
            ->where('resource_id', $resource->id)
            ->whereNotNull('rights_id')
            ->when(
                $selectedRightIds !== [],
                fn ($query) => $query->whereNotIn('rights_id', $selectedRightIds),
            )
            ->when(
                $sourceResourceRightIds !== [],
                fn ($query) => $query->whereKeyNot($sourceResourceRightIds),
            )
            ->delete();

        $this->linkSourceResourceRightRows($resource, $sourceResourceRightLinks);

        foreach ($selectedRightIds as $rightId) {
            ResourceRight::query()->firstOrCreate([
                'resource_id' => $resource->id,
                'rights_id' => $rightId,
            ]);
        }

        if ($replaceUnresolvedRawRights) {
            ResourceRight::query()
                ->where('resource_id', $resource->id)
                ->whereNull('rights_id')
                ->delete();
        }

        $this->persistImportedStatements($resource, $rawRights, 'editor-import', $fallbackLanguage, $selectedRightIds);
    }

    /**
     * Persist raw rights statements without treating them as an editor sync.
     *
     * This is used by direct DataCite transformations where the import itself
     * creates a Resource model. It preserves every usable rights statement,
     * linking to the local catalog only when there is an exact known match.
     *
     * @param  array<int, array<string, mixed>>  $rawRights
     * @param  list<int>|null  $allowedCatalogRightIds
     */
    public function persistImportedStatements(
        Resource $resource,
        array $rawRights,
        string $source,
        ?string $fallbackLanguage = null,
        ?array $allowedCatalogRightIds = null,
    ): void {
        foreach ($rawRights as $statement) {
            $payload = $this->normalizeStatement($statement, $source, $fallbackLanguage);

            if ($payload === null) {
                continue;
            }

            $rightId = $this->resolveCatalogRightId($payload);
            // During editor saves, hidden raw import context must not recreate
            // a linked catalog row that the curator just removed.
            if (
                $allowedCatalogRightIds !== null
                && ($rightId === null || ! in_array($rightId, $allowedCatalogRightIds, true))
            ) {
                $rightId = null;
            }

            $row = $this->findOrCreateStatementRow($resource, $payload, $rightId);

            $this->fillEmptyRawColumns($row, $payload);
        }
    }

    /**
     * Convert DataCite-style keys into database column names.
     *
     * The UI and importers may provide camelCase (`rightsUri`), historical
     * variants (`rightsURI`), or database-shaped snake_case. Normalizing in one
     * place makes future assistants easier to copy without losing edge cases.
     *
     * @param  array<string, mixed>  $statement
     * @return array<string, string|null>|null
     */
    public function normalizeStatement(
        array $statement,
        string $source,
        ?string $fallbackLanguage = null,
    ): ?array {
        $payload = [
            'rights_text' => $this->stringValue($statement, ['rights', 'rights_text']),
            'rights_uri' => $this->stringValue($statement, ['rightsUri', 'rightsURI', 'rights_uri']),
            'rights_identifier' => $this->stringValue($statement, ['rightsIdentifier', 'rights_identifier']),
            'rights_identifier_scheme' => $this->stringValue($statement, ['rightsIdentifierScheme', 'rights_identifier_scheme']),
            'scheme_uri' => $this->stringValue($statement, ['schemeUri', 'schemeURI', 'scheme_uri']),
            'language' => $this->stringValue($statement, ['lang', 'language']) ?? $this->normalizeNullable($fallbackLanguage),
            'source' => $this->stringValue($statement, ['source']) ?? $this->normalizeNullable($source),
        ];

        // A rights row is only useful when it contains something that can be
        // exported or matched. Metadata-only rows would clutter the database.
        if (
            $payload['rights_text'] === null
            && $payload['rights_uri'] === null
            && $payload['rights_identifier'] === null
        ) {
            return null;
        }

        return $payload;
    }

    /**
     * @param  list<string>  $licenseIdentifiers
     * @return array<string, int>
     */
    private function rightsByIdentifier(array $licenseIdentifiers): array
    {
        $identifiers = array_values(array_unique(array_filter(
            array_map(fn (mixed $identifier): ?string => $this->normalizeNullable($identifier), $licenseIdentifiers),
        )));

        if ($identifiers === []) {
            return [];
        }

        /** @var array<string, int> $rightsByIdentifier */
        $rightsByIdentifier = Right::query()
            ->whereIn('identifier', $identifiers)
            ->pluck('id', 'identifier')
            ->all();

        $missingLicenses = array_values(array_diff($identifiers, array_keys($rightsByIdentifier)));

        if ($missingLicenses !== []) {
            throw ValidationException::withMessages([
                'licenses' => 'Some provided licenses are unknown: '.implode(', ', $missingLicenses),
            ]);
        }

        return $rightsByIdentifier;
    }

    /**
     * @param  array<string, string|null>  $payload
     */
    private function resolveCatalogRightId(array $payload): ?int
    {
        $identifier = $payload['rights_identifier'];

        if ($identifier !== null) {
            return Right::query()
                ->where('identifier', $identifier)
                ->value('id');
        }

        $name = $payload['rights_text'];

        if ($name !== null) {
            $rightId = Right::query()
                ->where('name', $name)
                ->value('id');

            if ($rightId !== null) {
                return (int) $rightId;
            }
        }

        $uri = $payload['rights_uri'];

        if ($uri !== null) {
            $rightId = Right::query()
                ->where('uri', $uri)
                ->value('id');

            if ($rightId !== null) {
                return (int) $rightId;
            }
        }

        return null;
    }

    /**
     * @param  array<string, string|null>  $payload
     */
    private function findOrCreateStatementRow(Resource $resource, array $payload, ?int $rightId): ResourceRight
    {
        if ($rightId !== null) {
            /** @var ResourceRight $row */
            $row = ResourceRight::query()->firstOrCreate([
                'resource_id' => $resource->id,
                'rights_id' => $rightId,
            ]);

            return $row;
        }

        $query = ResourceRight::query()
            ->where('resource_id', $resource->id)
            ->whereNull('rights_id');

        foreach ([
            'rights_text',
            'rights_uri',
            'rights_identifier',
            'rights_identifier_scheme',
            'scheme_uri',
            'language',
            'source',
        ] as $column) {
            $value = $payload[$column] ?? null;

            $query = $value === null
                ? $query->whereNull($column)
                : $query->where($column, $value);
        }

        /** @var ResourceRight|null $existing */
        $existing = $query->first();

        if ($existing !== null) {
            return $existing;
        }

        /** @var ResourceRight $created */
        $created = ResourceRight::query()->create(array_merge([
            'resource_id' => $resource->id,
            'rights_id' => null,
        ], $payload));

        return $created;
    }

    /**
     * @param  array<string, string|null>  $payload
     */
    private function fillEmptyRawColumns(ResourceRight $row, array $payload): void
    {
        $dirty = false;

        foreach ($payload as $column => $value) {
            if ($value === null) {
                continue;
            }

            // Existing curator/import values win. The assistant can later link
            // the row, but this storage path should not rewrite original text.
            if ($this->normalizeNullable($row->{$column}) === null) {
                $row->{$column} = $value;
                $dirty = true;
            }
        }

        if ($dirty) {
            $row->save();
        }
    }

    /**
     * @param  array<int, int>  $sourceResourceRightLinks
     */
    private function linkSourceResourceRightRows(Resource $resource, array $sourceResourceRightLinks): void
    {
        foreach ($sourceResourceRightLinks as $resourceRightId => $rightId) {
            /** @var ResourceRight|null $sourceRow */
            $sourceRow = ResourceRight::query()
                ->where('resource_id', $resource->id)
                ->whereKey($resourceRightId)
                ->first();

            if (! $sourceRow instanceof ResourceRight || $sourceRow->rights_id === $rightId) {
                continue;
            }

            /** @var ResourceRight|null $existingLinkedRow */
            $existingLinkedRow = ResourceRight::query()
                ->where('resource_id', $resource->id)
                ->where('rights_id', $rightId)
                ->whereKeyNot($sourceRow->id)
                ->first();

            if ($existingLinkedRow instanceof ResourceRight) {
                $payload = [
                    'rights_text' => $this->normalizeNullable($sourceRow->rights_text),
                    'rights_uri' => $this->normalizeNullable($sourceRow->rights_uri),
                    'rights_identifier' => $this->normalizeNullable($sourceRow->rights_identifier),
                    'rights_identifier_scheme' => $this->normalizeNullable($sourceRow->rights_identifier_scheme),
                    'scheme_uri' => $this->normalizeNullable($sourceRow->scheme_uri),
                    'language' => $this->normalizeNullable($sourceRow->language),
                    'source' => $this->normalizeNullable($sourceRow->source),
                ];

                $this->fillEmptyRawColumns($existingLinkedRow, $payload);
                $sourceRow->delete();

                continue;
            }

            $sourceRow->rights_id = $rightId;
            $sourceRow->save();
        }
    }

    /**
     * @param  array<string, mixed>  $statement
     * @param  list<string>  $keys
     */
    private function stringValue(array $statement, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (Arr::exists($statement, $key)) {
                return $this->normalizeNullable($statement[$key]);
            }
        }

        return null;
    }

    private function normalizeNullable(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
