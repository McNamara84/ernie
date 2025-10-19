<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Resource extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'doi',
        'year',
        'resource_type_id',
        'version',
        'language_id',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'year' => 'integer',
    ];

    /** @return BelongsTo<ResourceType, static> */
    public function resourceType(): BelongsTo
    {
        /** @var BelongsTo<ResourceType, static> $relation */
        $relation = $this->belongsTo(ResourceType::class);

        return $relation;
    }

    /** @return BelongsTo<Language, static> */
    public function language(): BelongsTo
    {
        /** @var BelongsTo<Language, static> $relation */
        $relation = $this->belongsTo(Language::class);

        return $relation;
    }

    /** @return HasMany<ResourceTitle, static> */
    public function titles(): HasMany
    {
        /** @var HasMany<ResourceTitle, static> $relation */
        $relation = $this->hasMany(ResourceTitle::class);

        return $relation;
    }

    /** @return BelongsToMany<License, static, \Illuminate\Database\Eloquent\Relations\Pivot, 'pivot'> */
    public function licenses(): BelongsToMany
    {
        /** @var BelongsToMany<License, static, \Illuminate\Database\Eloquent\Relations\Pivot, 'pivot'> $relation */
        $relation = $this->belongsToMany(License::class)->withTimestamps();

        return $relation;
    }

    /** @return HasMany<ResourceAuthor, static> */
    public function authors(): HasMany
    {
        /** @var HasMany<ResourceAuthor, static> $relation */
        $relation = $this->hasMany(ResourceAuthor::class)
            ->whereHas('roles', function ($query) {
                $query->where('name', 'Author');
            })
            ->orderBy('position');

        return $relation;
    }

    /** @return HasMany<ResourceAuthor, static> */
    public function contributors(): HasMany
    {
        /** @var HasMany<ResourceAuthor, static> $relation */
        $relation = $this->hasMany(ResourceAuthor::class)
            ->whereDoesntHave('roles', function ($query) {
                $query->where('name', 'Author');
            })
            ->orderBy('position');

        return $relation;
    }

    /** @return HasMany<ResourceDescription, static> */
    public function descriptions(): HasMany
    {
        /** @var HasMany<ResourceDescription, static> $relation */
        $relation = $this->hasMany(ResourceDescription::class);

        return $relation;
    }

    /** @return HasMany<ResourceDate, static> */
    public function dates(): HasMany
    {
        /** @var HasMany<ResourceDate, static> $relation */
        $relation = $this->hasMany(ResourceDate::class);

        return $relation;
    }

    /** @return HasMany<ResourceKeyword, static> */
    public function keywords(): HasMany
    {
        /** @var HasMany<ResourceKeyword, static> $relation */
        $relation = $this->hasMany(ResourceKeyword::class);

        return $relation;
    }

    /** @return HasMany<ResourceControlledKeyword, static> */
    public function controlledKeywords(): HasMany
    {
        /** @var HasMany<ResourceControlledKeyword, static> $relation */
        $relation = $this->hasMany(ResourceControlledKeyword::class);

        return $relation;
    }

    /** @return HasMany<ResourceCoverage, static> */
    public function coverages(): HasMany
    {
        /** @var HasMany<ResourceCoverage, static> $relation */
        $relation = $this->hasMany(ResourceCoverage::class);

        return $relation;
    }

    /** @return HasMany<RelatedIdentifier, static> */
    public function relatedIdentifiers(): HasMany
    {
        /** @var HasMany<RelatedIdentifier, static> $relation */
        $relation = $this->hasMany(RelatedIdentifier::class)->orderBy('position');

        return $relation;
    }

    /** @return HasMany<ResourceFundingReference, static> */
    public function fundingReferences(): HasMany
    {
        /** @var HasMany<ResourceFundingReference, static> $relation */
        $relation = $this->hasMany(ResourceFundingReference::class)->orderBy('position');

        return $relation;
    }

    /** @return BelongsTo<User, static> */
    public function createdBy(): BelongsTo
    {
        /** @var BelongsTo<User, static> $relation */
        $relation = $this->belongsTo(User::class, 'created_by_user_id');

        return $relation;
    }

    /** @return BelongsTo<User, static> */
    public function updatedBy(): BelongsTo
    {
        /** @var BelongsTo<User, static> $relation */
        $relation = $this->belongsTo(User::class, 'updated_by_user_id');

        return $relation;
    }
}
