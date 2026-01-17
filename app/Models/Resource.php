<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Resource Model (DataCite Metadata)
 *
 * Central entity representing a research dataset with DataCite metadata.
 *
 * @property int $id
 * @property string|null $doi
 * @property int|null $publication_year
 * @property int|null $resource_type_id
 * @property string|null $version
 * @property int|null $language_id
 * @property int|null $publisher_id
 * @property int|null $created_by_user_id
 * @property int|null $updated_by_user_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read ResourceType|null $resourceType
 * @property-read Language|null $language
 * @property-read Publisher|null $publisher
 * @property-read User|null $createdBy
 * @property-read User|null $updatedBy
 * @property-read LandingPage|null $landingPage
 * @property-read IgsnMetadata|null $igsnMetadata
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Title> $titles
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ResourceCreator> $creators
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ResourceContributor> $contributors
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Description> $descriptions
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Subject> $subjects
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ResourceDate> $dates
 * @property-read \Illuminate\Database\Eloquent\Collection<int, GeoLocation> $geoLocations
 * @property-read \Illuminate\Database\Eloquent\Collection<int, RelatedIdentifier> $relatedIdentifiers
 * @property-read \Illuminate\Database\Eloquent\Collection<int, FundingReference> $fundingReferences
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Right> $rights
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Size> $sizes
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Format> $formats
 * @property-read \Illuminate\Database\Eloquent\Collection<int, IgsnClassification> $igsnClassifications
 * @property-read \Illuminate\Database\Eloquent\Collection<int, IgsnGeologicalAge> $igsnGeologicalAges
 * @property-read \Illuminate\Database\Eloquent\Collection<int, IgsnGeologicalUnit> $igsnGeologicalUnits
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.6/
 */
class Resource extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'doi',
        'publication_year',
        'resource_type_id',
        'version',
        'language_id',
        'publisher_id',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'publication_year' => 'integer',
    ];

    // =========================================================================
    // Lookup Table Relations
    // =========================================================================

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

    /** @return BelongsTo<Publisher, static> */
    public function publisher(): BelongsTo
    {
        /** @var BelongsTo<Publisher, static> $relation */
        $relation = $this->belongsTo(Publisher::class);

        return $relation;
    }

    // =========================================================================
    // DataCite Property Relations (HasMany)
    // =========================================================================

    /** @return HasMany<Title, static> */
    public function titles(): HasMany
    {
        /** @var HasMany<Title, static> $relation */
        $relation = $this->hasMany(Title::class);

        return $relation;
    }

    /** @return HasMany<ResourceCreator, static> */
    public function creators(): HasMany
    {
        /** @var HasMany<ResourceCreator, static> $relation */
        $relation = $this->hasMany(ResourceCreator::class)->orderBy('position');

        return $relation;
    }

    /** @return HasMany<ResourceContributor, static> */
    public function contributors(): HasMany
    {
        /** @var HasMany<ResourceContributor, static> $relation */
        $relation = $this->hasMany(ResourceContributor::class)->orderBy('position');

        return $relation;
    }

    /** @return HasMany<Description, static> */
    public function descriptions(): HasMany
    {
        /** @var HasMany<Description, static> $relation */
        $relation = $this->hasMany(Description::class);

        return $relation;
    }

    /** @return HasMany<Subject, static> */
    public function subjects(): HasMany
    {
        /** @var HasMany<Subject, static> $relation */
        $relation = $this->hasMany(Subject::class);

        return $relation;
    }

    /** @return HasMany<ResourceDate, static> */
    public function dates(): HasMany
    {
        /** @var HasMany<ResourceDate, static> $relation */
        $relation = $this->hasMany(ResourceDate::class);

        return $relation;
    }

    /** @return HasMany<GeoLocation, static> */
    public function geoLocations(): HasMany
    {
        /** @var HasMany<GeoLocation, static> $relation */
        $relation = $this->hasMany(GeoLocation::class);

        return $relation;
    }

    /** @return HasMany<RelatedIdentifier, static> */
    public function relatedIdentifiers(): HasMany
    {
        /** @var HasMany<RelatedIdentifier, static> $relation */
        $relation = $this->hasMany(RelatedIdentifier::class)->orderBy('position');

        return $relation;
    }

    /** @return HasMany<FundingReference, static> */
    public function fundingReferences(): HasMany
    {
        /** @var HasMany<FundingReference, static> $relation */
        $relation = $this->hasMany(FundingReference::class);

        return $relation;
    }

    /** @return BelongsToMany<Right, static> */
    public function rights(): BelongsToMany
    {
        /** @var BelongsToMany<Right, static> $relation */
        $relation = $this->belongsToMany(Right::class, 'resource_rights', 'resource_id', 'rights_id')
            ->withTimestamps();

        return $relation;
    }

    /** @return HasMany<Size, static> */
    public function sizes(): HasMany
    {
        /** @var HasMany<Size, static> $relation */
        $relation = $this->hasMany(Size::class);

        return $relation;
    }

    /** @return HasMany<Format, static> */
    public function formats(): HasMany
    {
        /** @var HasMany<Format, static> $relation */
        $relation = $this->hasMany(Format::class);

        return $relation;
    }

    // =========================================================================
    // IGSN Relations (Physical Samples)
    // =========================================================================

    /** @return HasOne<IgsnMetadata, static> */
    public function igsnMetadata(): HasOne
    {
        /** @var HasOne<IgsnMetadata, static> $relation */
        $relation = $this->hasOne(IgsnMetadata::class);

        return $relation;
    }

    /** @return HasMany<IgsnClassification, static> */
    public function igsnClassifications(): HasMany
    {
        /** @var HasMany<IgsnClassification, static> $relation */
        $relation = $this->hasMany(IgsnClassification::class)->orderBy('position');

        return $relation;
    }

    /** @return HasMany<IgsnGeologicalAge, static> */
    public function igsnGeologicalAges(): HasMany
    {
        /** @var HasMany<IgsnGeologicalAge, static> $relation */
        $relation = $this->hasMany(IgsnGeologicalAge::class)->orderBy('position');

        return $relation;
    }

    /** @return HasMany<IgsnGeologicalUnit, static> */
    public function igsnGeologicalUnits(): HasMany
    {
        /** @var HasMany<IgsnGeologicalUnit, static> $relation */
        $relation = $this->hasMany(IgsnGeologicalUnit::class)->orderBy('position');

        return $relation;
    }

    // =========================================================================
    // User Relations
    // =========================================================================

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

    /** @return HasOne<LandingPage, static> */
    public function landingPage(): HasOne
    {
        /** @var HasOne<LandingPage, static> $relation */
        $relation = $this->hasOne(LandingPage::class);

        return $relation;
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Get the main title.
     *
     * Main titles are identified by having a TitleType with slug 'MainTitle'.
     * In DataCite XML, MainTitle has no titleType attribute, but in the database
     * it's always stored with a reference to the MainTitle TitleType record.
     */
    public function getMainTitleAttribute(): ?string
    {
        $this->loadMissing('titles.titleType');
        $mainTitle = $this->titles->first(fn (Title $t) => $t->isMainTitle());

        return $mainTitle?->value;
    }

    /**
     * Get all free-text subjects.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Subject>
     */
    public function getFreeTextSubjectsAttribute(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->subjects->filter(fn (Subject $s) => $s->isFreeText());
    }

    /**
     * Get all controlled vocabulary subjects.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Subject>
     */
    public function getControlledSubjectsAttribute(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->subjects->filter(fn (Subject $s) => $s->isControlled());
    }

    /**
     * Get temporal coverage dates (dateType = 'Collected').
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, ResourceDate>
     */
    public function getTemporalCoverageAttribute(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->dates->filter(fn (ResourceDate $d) => $d->isCollected());
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope query to only include IGSN resources (Physical Objects).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeIgsns(Builder $query): Builder
    {
        return $query->whereHas('resourceType', fn (Builder $q) => $q->where('slug', 'physical-object'));
    }

    /**
     * Check if this resource is an IGSN (Physical Object).
     */
    public function isIgsn(): bool
    {
        $this->loadMissing('resourceType');

        return $this->resourceType?->slug === 'physical-object';
    }
}
