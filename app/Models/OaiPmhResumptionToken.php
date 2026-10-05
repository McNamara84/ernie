<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * OAI-PMH resumption token for cursor-based pagination.
 *
 * Tokens are generated when list responses exceed the configured page size.
 * They store the query state to resume from where the previous response ended.
 *
 * @property int $id
 * @property string $token Random 64-character token string
 * @property string $verb OAI-PMH verb (ListRecords, ListIdentifiers)
 * @property string|null $metadata_prefix Metadata format prefix
 * @property string|null $set_spec Set filter specification
 * @property Carbon|null $from_date Date range start
 * @property Carbon|null $until_date Date range end
 * @property int $cursor Number of records returned before the next page
 * @property int $complete_list_size Total result count
 * @property int|null $harvest_id Ordered identity snapshot, null for pre-migration tokens
 * @property int|null $harvest_position Next position in the identity snapshot
 * @property-read OaiPmhHarvest|null $harvest
 * @property Carbon $expires_at Token expiration timestamp
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OaiPmhResumptionToken extends Model
{
    protected $table = 'oai_pmh_resumption_tokens';

    /** @var list<string> */
    protected $fillable = [
        'token',
        'verb',
        'metadata_prefix',
        'set_spec',
        'from_date',
        'until_date',
        'cursor',
        'complete_list_size',
        'expires_at',
        'harvest_id',
        'harvest_position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_date' => 'datetime',
            'until_date' => 'datetime',
            'expires_at' => 'datetime',
            'cursor' => 'integer',
            'harvest_position' => 'integer',
            'complete_list_size' => 'integer',
        ];
    }

    /** @return BelongsTo<OaiPmhHarvest, $this> */
    public function harvest(): BelongsTo
    {
        return $this->belongsTo(OaiPmhHarvest::class, 'harvest_id');
    }
}
