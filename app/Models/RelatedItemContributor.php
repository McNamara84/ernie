<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $related_item_id
 * @property string $contributor_type
 * @property string $name_type
 * @property string $name
 * @property string|null $given_name
 * @property string|null $family_name
 * @property string|null $name_identifier
 * @property string|null $name_identifier_scheme
 * @property string|null $scheme_uri
 * @property int $position
 * @property-read RelatedItem $relatedItem
 * @property-read \Illuminate\Database\Eloquent\Collection<int, RelatedItemContributorAffiliation> $affiliations
 */
#[Fillable([
    'related_item_id',
    'contributor_type',
    'name_type',
    'name',
    'given_name',
    'family_name',
    'name_identifier',
    'name_identifier_scheme',
    'scheme_uri',
    'position',
])]
class RelatedItemContributor extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    /** @var array<string, string> */
    protected $casts = [
        'position' => 'integer',
    ];

    /** @return BelongsTo<RelatedItem, $this> */
    public function relatedItem(): BelongsTo
    {
        return $this->belongsTo(RelatedItem::class);
    }

    /** @return HasMany<RelatedItemContributorAffiliation, $this> */
    public function affiliations(): HasMany
    {
        return $this->hasMany(RelatedItemContributorAffiliation::class)->orderBy('position');
    }
}
