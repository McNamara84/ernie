<?php

declare(strict_types=1);

namespace App\Services\OaiPmh;

use App\Models\OaiPmhDeletedRecord;
use App\Models\Resource;
use Illuminate\Database\Eloquent\Builder;

/** Select ICGEM models by their own DOI and immutable resource-type slug. */
final class IcgemSetService
{
    public const string SPEC = 'icgem';

    /** @return array{spec: string, name: string, description: string} */
    public function definition(): array
    {
        return [
            'spec' => self::SPEC,
            'name' => 'ICGEM Models',
            'description' => 'Published resources of type Model whose own DOI contains 10.5880/icgem, '
                .'ignoring ASCII letter case. Datacenter affiliation does not affect membership.',
        ];
    }

    public function matches(?string $doi, ?string $typeSlug): bool
    {
        return $typeSlug === 'model' && str_contains(strtolower($doi ?? ''), '10.5880/icgem');
    }

    public function matchesDeletedRecord(OaiPmhDeletedRecord $record): bool
    {
        return $this->matches($record->doi, in_array('resourcetype:model', $record->sets ?? [], true) ? 'model' : null);
    }

    /**
     * @param  Builder<Resource>  $query
     * @return Builder<Resource>
     */
    public function applyToResourceQuery(Builder $query): Builder
    {
        $this->applyDoiFilter($query);

        return $query->whereHas('resourceType', fn (Builder $type) => $type->where('slug', 'model'));
    }

    /**
     * @param  Builder<OaiPmhDeletedRecord>  $query
     * @return Builder<OaiPmhDeletedRecord>
     */
    public function applyToDeletedRecordQuery(Builder $query): Builder
    {
        $this->applyDoiFilter($query);

        return $query->whereJsonContains('sets', 'resourcetype:model');
    }

    /** @param Builder<Resource>|Builder<OaiPmhDeletedRecord> $query */
    private function applyDoiFilter(Builder $query): void
    {
        /** @var literal-string $column */
        $column = $query->getQuery()->getGrammar()->wrap($query->getModel()->qualifyColumn('doi'));

        // Explicit ASCII alternatives avoid accent-insensitive collations and
        // Unicode case folding, matching PHP's strtolower/str_contains exactly.
        if ($query->getModel()->getConnection()->getDriverName() === 'mysql') {
            $query->whereRaw("REGEXP_LIKE({$column}, ?, 'c')", ['10[.]5880/[iI][cC][gG][eE][mM]']);
        } else {
            $query->whereRaw("{$column} GLOB ?", ['*10.5880/[iI][cC][gG][eE][mM]*']);
        }
    }
}
