<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * OAI-PMH resumption token for cursor-based pagination.
 *
 * Tokens are generated when list responses exceed the configured page size.
 * They store the query state to resume from where the previous response ended.
 *
 * @property int $id
 * @property string $token Random 64-character token string
 * @property string $verb OAI-PMH verb (ListRecords, ListIdentifiers, ListSets)
 * @property string|null $metadata_prefix Metadata format prefix
 * @property string|null $set_spec Set filter specification
 * @property \Illuminate\Support\Carbon|null $from_date Date range start
 * @property \Illuminate\Support\Carbon|null $until_date Date range end
 * @property int $cursor Current offset position
 * @property int $complete_list_size Total result count
 * @property \Illuminate\Support\Carbon $expires_at Token expiration timestamp
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
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
            'complete_list_size' => 'integer',
        ];
    }
}
