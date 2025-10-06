<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Affiliation extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'resource_author_id',
        'value',
        'ror_id',
    ];

    /** @return BelongsTo<ResourceAuthor, static> */
    public function resourceAuthor(): BelongsTo
    {
        /** @var BelongsTo<ResourceAuthor, static> $relation */
        $relation = $this->belongsTo(ResourceAuthor::class);

        return $relation;
    }
}
