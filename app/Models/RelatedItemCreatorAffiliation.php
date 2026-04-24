<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $related_item_creator_id
 * @property string $name
 * @property string|null $affiliation_identifier
 * @property string|null $scheme
 * @property string|null $scheme_uri
 * @property int $position
 * @property-read RelatedItemCreator $creator
 */
#[Fillable([
    'related_item_creator_id',
    'name',
    'affiliation_identifier',
    'scheme',
    'scheme_uri',
    'position',
])]
class RelatedItemCreatorAffiliation extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    /** @var array<string, string> */
    protected $casts = [
        'position' => 'integer',
    ];

    /** @return BelongsTo<RelatedItemCreator, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(RelatedItemCreator::class, 'related_item_creator_id');
    }
}
