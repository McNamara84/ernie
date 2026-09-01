<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Igsn\IgsnMetadataValueType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['resource_id', 'type', 'value', 'normalized_value_hash', 'position'])]
class IgsnMetadataValue extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    protected $casts = [
        'type' => IgsnMetadataValueType::class,
    ];

    /** @return BelongsTo<Resource, static> */
    public function resource(): BelongsTo
    {
        /** @var BelongsTo<Resource, static> $relation */
        $relation = $this->belongsTo(Resource::class);

        return $relation;
    }
}
