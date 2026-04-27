<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $related_item_id
 * @property string $title
 * @property string $title_type
 * @property string|null $language
 * @property int $position
 * @property-read RelatedItem $relatedItem
 */
#[Fillable(['related_item_id', 'title', 'title_type', 'language', 'position'])]
class RelatedItemTitle extends Model
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
}
