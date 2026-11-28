<?php

declare(strict_types=1);

namespace App\Services\OaiPmh;

use App\Models\OaiPmhDeletedRecord;
use App\Models\OaiPmhFormatRecord;
use App\Models\Resource;
use Illuminate\Database\Eloquent\Builder;

/** Select resources whose own GFZ DOI suffix starts with "icgem". */
final class IcgemSetService
{
    public const string SPEC = 'icgem';

    /** @return array{spec: string, name: string, description: string} */
    public function definition(): array
    {
        return [
            'spec' => self::SPEC,
            'name' => 'ICGEM Models',
            'description' => 'Published resources of any type whose own DOI starts with 10.5880/icgem, ignoring ASCII letter case.',
        ];
    }

    public function matches(?string $doi): bool
    {
        return str_starts_with(strtolower($doi ?? ''), '10.5880/icgem');
    }

    /**
     * @template T of Resource|OaiPmhDeletedRecord|OaiPmhFormatRecord
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    public function applyDoiFilter(Builder $query): Builder
    {
        /** @var literal-string $column */
        $column = $query->getQuery()->getGrammar()->wrap($query->getModel()->qualifyColumn('doi'));

        // Explicit ASCII alternatives avoid accent-insensitive collations and
        // Unicode case folding, matching PHP's prefix check.
        if ($query->getModel()->getConnection()->getDriverName() === 'mysql') {
            return $query->whereRaw("REGEXP_LIKE({$column}, ?, 'c')", ['^10[.]5880/[iI][cC][gG][eE][mM]']);
        }

        return $query->whereRaw("{$column} GLOB ?", ['10.5880/[iI][cC][gG][eE][mM]*']);
    }
}
