<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Institution extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'name',
        'ror_id',          // Legacy field for backwards compatibility
        'identifier',      // Generic identifier (ROR, labid, ISNI, etc.)
        'identifier_type', // Type of identifier (ROR, labid, ISNI, etc.)
    ];

    /** @return MorphMany<ResourceAuthor, static> */
    public function resourceAuthors(): MorphMany
    {
        /** @var MorphMany<ResourceAuthor, static> $relation */
        $relation = $this->morphMany(ResourceAuthor::class, 'authorable');

        return $relation;
    }

    /**
     * Check if this institution is an MSL Laboratory
     */
    public function isLaboratory(): bool
    {
        return $this->identifier_type === 'labid';
    }

    /**
     * Check if this institution has a ROR identifier
     */
    public function isRorInstitution(): bool
    {
        return $this->identifier_type === 'ROR';
    }
}
