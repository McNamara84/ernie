<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An ordered identity snapshot shared by all tokens for a paginated harvest.
 * Metadata remains live; changed items may disappear, but never shift other pages.
 *
 * @property int $id
 * @property list<array{kind: 'resource'|'deleted', id: int}> $items
 * @property Carbon $expires_at
 */
class OaiPmhHarvest extends Model
{
    /** @var list<string> */
    protected $fillable = ['items', 'expires_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['items' => 'array', 'expires_at' => 'datetime'];
    }
}
