<?php

declare(strict_types=1);

namespace App\Services\Spdx;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reads unresolved rights statements from `resource_rights`.
 *
 * The SPDX enrichment migration adds raw import columns beside the optional
 * catalog `rights_id` link. Discovery only reads unresolved rows and remains
 * schema-aware so partially migrated installations fail closed instead of
 * crashing while the database is being updated.
 */
class SpdxRightsMatchInputProvider
{
    private const string TARGET_TYPE = 'resource_right';

    /**
     * Raw columns that may exist after import persistence is implemented.
     *
     * @var list<string>
     */
    private const RAW_COLUMNS = [
        'rights_text',
        'rights_uri',
        'rights_identifier',
        'rights_identifier_scheme',
        'scheme_uri',
        'language',
        'source',
    ];

    /**
     * Columns that contain enough evidence to attempt SPDX matching.
     *
     * @var list<string>
     */
    private const EVIDENCE_COLUMNS = [
        'rights_text',
        'rights_uri',
        'rights_identifier',
    ];

    /**
     * Return unresolved resource-right rows as matcher input objects.
     *
     * A row is unresolved when `rights_id` is NULL. Linked rows are already
     * trusted and should not be re-suggested by the assistant.
     *
     * @return Collection<int, SpdxRightsMatchInput>
     */
    public function pendingInputs(): Collection
    {
        $availableColumns = $this->availableRawColumns();
        $evidenceColumns = array_values(array_intersect(self::EVIDENCE_COLUMNS, $availableColumns));

        if ($evidenceColumns === []) {
            return collect();
        }

        $rows = DB::table('resource_rights')
            ->select(array_merge(['id', 'resource_id'], $availableColumns))
            ->whereNull('rights_id')
            ->where(function ($query) use ($evidenceColumns): void {
                foreach ($evidenceColumns as $column) {
                    $query->orWhere(function ($query) use ($column): void {
                        $query->whereNotNull($column)
                            ->where($column, '!=', '');
                    });
                }
            })
            ->orderBy('id')
            ->get();

        return $rows->map(fn (object $row): SpdxRightsMatchInput => new SpdxRightsMatchInput(
            resourceId: (int) $row->resource_id,
            targetType: self::TARGET_TYPE,
            targetId: (int) $row->id,
            rightsText: $this->value($row, 'rights_text'),
            rightsUri: $this->value($row, 'rights_uri'),
            rightsIdentifier: $this->value($row, 'rights_identifier'),
            rightsIdentifierScheme: $this->value($row, 'rights_identifier_scheme'),
            schemeUri: $this->value($row, 'scheme_uri'),
            language: $this->value($row, 'language'),
            source: $this->value($row, 'source'),
        ));
    }

    /**
     * @return list<string>
     */
    private function availableRawColumns(): array
    {
        $columns = [];

        foreach (self::RAW_COLUMNS as $column) {
            if (Schema::hasColumn('resource_rights', $column)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    private function value(object $row, string $column): ?string
    {
        if (! property_exists($row, $column)) {
            return null;
        }

        $value = $row->{$column};

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
