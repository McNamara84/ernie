<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $related_item_contributor_id
 * @property string $name
 * @property string|null $affiliation_identifier
 * @property string|null $scheme
 * @property string|null $scheme_uri
 * @property int $position
 * @property-read RelatedItemContributor $contributor
 */
#[Fillable([
    'related_item_contributor_id',
    'name',
    'affiliation_identifier',
    'scheme',
    'scheme_uri',
    'position',
])]
class RelatedItemContributorAffiliation extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    /** @var array<string, string> */
    protected $casts = [
        'position' => 'integer',
    ];

    /** @return BelongsTo<RelatedItemContributor, $this> */
    public function contributor(): BelongsTo
    {
        return $this->belongsTo(RelatedItemContributor::class, 'related_item_contributor_id');
    }
}
