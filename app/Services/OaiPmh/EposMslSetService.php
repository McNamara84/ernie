<?php

declare(strict_types=1);

namespace App\Services\OaiPmh;

use App\Models\Subject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** The project set uses complete, free keywords rather than MSL vocabulary terms. */
final class EposMslSetService
{
    public const string SPEC = 'epos-msl';

    private const string WHITESPACE = " \t\n\v\f\r";

    private const array KEYWORDS = ['epos', 'msl'];

    private const array CONTROLLED_FIELDS = [
        'subject_scheme', 'scheme_uri', 'value_uri', 'classification_code', 'breadcrumb_path',
    ];

    /** @return array{spec: string, name: string, description: string} */
    public function definition(): array
    {
        return [
            'spec' => self::SPEC,
            'name' => 'EPOS-MSL Project',
            'description' => 'Published resources with the complete free keyword EPOS or MSL (or both). '
                .'Matching ignores ASCII letter case and surrounding spaces, tabs and line breaks. '
                .'Subjects with a subject scheme, scheme URI, value URI, classification code or hierarchy path are excluded.',
        ];
    }

    public function matches(Subject $subject): bool
    {
        foreach (self::CONTROLLED_FIELDS as $field) {
            if (trim((string) $subject->getAttribute($field), self::WHITESPACE) !== '') {
                return false;
            }
        }

        return in_array(strtolower(trim($subject->value, self::WHITESPACE)), self::KEYWORDS, true);
    }

    /** @param Builder<Subject> $query */
    public function applyToSubjects(Builder $query): void
    {
        foreach (self::CONTROLLED_FIELDS as $field) {
            [$expression, $bindings] = $this->normalizedColumn($query, $field);
            $query->whereRaw($expression.' = ?', [...$bindings, '']);
        }

        [$expression, $bindings] = $this->normalizedColumn($query, 'value');
        $query->whereRaw($expression.' IN (?, ?)', [...$bindings, ...self::KEYWORDS]);
    }

    /**
     * MySQL TRIM only removes spaces; SQLite accepts an explicit character set.
     * Binary comparison prevents the default MySQL collation matching accents.
     *
     * @param  Builder<Subject>  $query
     * @param  'subject_scheme'|'scheme_uri'|'value_uri'|'classification_code'|'breadcrumb_path'|'value'  $field
     * @return array{literal-string, list<string>}
     */
    private function normalizedColumn(Builder $query, string $field): array
    {
        // Only fixed subject field names enter this expression; values are bound.
        /** @var literal-string $column */
        $column = $query->getQuery()->getGrammar()->wrap('subjects.'.$field);

        if (DB::connection($query->getModel()->getConnectionName())->getDriverName() === 'mysql') {
            return [
                "CAST(LOWER(REGEXP_REPLACE(COALESCE({$column}, ''), ?, '')) AS BINARY)",
                ['^['.self::WHITESPACE.']+|['.self::WHITESPACE.']+$'],
            ];
        }

        return ["LOWER(TRIM(COALESCE({$column}, ''), ?)) COLLATE BINARY", [self::WHITESPACE]];
    }
}
