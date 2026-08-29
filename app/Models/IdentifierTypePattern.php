<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Identifier Type Pattern Model
 *
 * Stores validation and detection regex patterns for identifier types.
 *
 * @property int $id
 * @property int $identifier_type_id
 * @property string $type
 * @property string $pattern
 * @property bool $is_active
 * @property int $priority
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['identifier_type_id', 'type', 'pattern', 'is_active', 'priority'])]
class IdentifierTypePattern extends Model
{
    protected $casts = [
        'is_active' => 'boolean',
        'priority' => 'integer',
    ];

    /** @return BelongsTo<IdentifierType, static> */
    public function identifierType(): BelongsTo
    {
        /** @var BelongsTo<IdentifierType, static> $relation */
        $relation = $this->belongsTo(IdentifierType::class);

        return $relation;
    }

    /**
     * @param  Builder<IdentifierTypePattern>  $query
     * @return Builder<IdentifierTypePattern>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<IdentifierTypePattern>  $query
     * @return Builder<IdentifierTypePattern>
     */
    public function scopeValidation(Builder $query): Builder
    {
        return $query->where('type', 'validation');
    }

    /**
     * @param  Builder<IdentifierTypePattern>  $query
     * @return Builder<IdentifierTypePattern>
     */
    public function scopeDetection(Builder $query): Builder
    {
        return $query->where('type', 'detection');
    }
}
