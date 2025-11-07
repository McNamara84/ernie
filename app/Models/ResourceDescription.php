<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResourceDescription extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'resource_id',
        'description_type',
        'description',
    ];

    /** @return BelongsTo<resource, static> */
    public function resource(): BelongsTo
    {
        /** @var BelongsTo<resource, static> $relation */
        $relation = $this->belongsTo(Resource::class);

        return $relation;
    }
}
