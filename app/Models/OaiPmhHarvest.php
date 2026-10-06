<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An ordered identity snapshot shared by all tokens for a paginated harvest.
 * Metadata remains live; changed items may disappear, but never shift other pages.
 * DOI changes invalidate inventories containing the resource and their tokens.
 *
 * @property int $id
 * @property int $item_count
 * @property Carbon $expires_at
 */
class OaiPmhHarvest extends Model
{
    /** @var list<string> */
    protected $fillable = ['item_count', 'expires_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['item_count' => 'integer', 'expires_at' => 'datetime'];
    }
}
