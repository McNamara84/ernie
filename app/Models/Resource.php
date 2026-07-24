<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

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
 * @property int|null $datacenter_id
 * @property int|null $created_by_user_id
 * @property int|null $updated_by_user_id
 * @property string|null $legacy_source
 * @property int|null $legacy_source_id
 * @property string|null $legacy_source_status
 * @property bool $force_review_status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ResourceType|null $resourceType
 * @property-read Language|null $language
 * @property-read Publisher|null $publisher
 * @property-read User|null $createdBy
 * @property-read User|null $updatedBy
 * @property-read LandingPage|null $landingPage
 * @property-read ResourceAssessment|null $resourceAssessment
 * @property-read IgsnMetadata|null $igsnMetadata
 * @property-read Collection<int, Title> $titles
 * @property-read Collection<int, ResourceCreator> $creators
 * @property-read Collection<int, ResourceContributor> $contributors
 * @property-read Collection<int, Description> $descriptions
 * @property-read Collection<int, Subject> $subjects
 * @property-read Collection<int, ResourceDate> $dates
 * @property-read Collection<int, GeoLocation> $geoLocations
 * @property-read Collection<int, RelatedIdentifier> $relatedIdentifiers
 * @property-read Collection<int, RelatedItem> $relatedItems
 * @property-read Collection<int, FundingReference> $fundingReferences
 * @property-read Collection<int, Right> $rights
 * @property-read Collection<int, Size> $sizes
 * @property-read Collection<int, Format> $formats
 * @property-read Collection<int, IgsnClassification> $igsnClassifications
 * @property-read Collection<int, IgsnGeologicalAge> $igsnGeologicalAges
 * @property-read Collection<int, IgsnGeologicalUnit> $igsnGeologicalUnits
 * @property-read Collection<int, AlternateIdentifier> $alternateIdentifiers
 * @property-read Collection<int, ResourceInstrument> $instruments
 * @property-read Datacenter|null $datacenter
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.7/
 */
#[Fillable(['doi', 'publication_year', 'resource_type_id', 'version', 'language_id', 'publisher_id', 'datacenter_id', 'created_by_user_id', 'updated_by_user_id', 'legacy_source', 'legacy_source_id', 'legacy_source_status', 'force_review_status'])]
class Resource extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    protected $casts = [
        'publication_year' => 'integer',
        'legacy_source_id' => 'integer',
        'force_review_status' => 'boolean',
    ];

    /**
     * Relations that must be eager-loaded for DataCite export/registration.
     *
     * Shared by BatchResourceExportController and BatchResourceRegistrationController
     * so both endpoints preload the same graph and cannot drift apart when new
     * relations are added to the exporters.
     *
     * @var list<string>
     */
    public const DATACITE_EXPORT_RELATIONS = [
        'igsnMetadata',
        'landingPage',
        'resourceType',
        'language',
        'publisher',
        'titles.titleType',
        'creators.creatorable',
        'creators.affiliations',
        'contributors.contributorable',
        'contributors.contributorTypes',
        'contributors.affiliations',
        'descriptions.descriptionType',
        'dates.dateType',
        'subjects',
        'geoLocations',
        'rights',
        'relatedIdentifiers.identifierType',
        'relatedIdentifiers.relationType',
        'relatedItems.relationType',
        'relatedItems.titles',
        'relatedItems.creators.affiliations',
        'relatedItems.contributors.affiliations',
        'fundingReferences.funderIdentifierType',
        'alternateIdentifiers',
        'sizes',
        'formats',
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

    /** @return HasMany<RelatedItem, static> */
    public function relatedItems(): HasMany
    {
        /** @var HasMany<RelatedItem, static> $relation */
        $relation = $this->hasMany(RelatedItem::class)->orderBy('position');

        return $relation;
    }

    /** @return HasMany<FundingReference, static> */
    public function fundingReferences(): HasMany
    {
        /** @var HasMany<FundingReference, static> $relation */
        $relation = $this->hasMany(FundingReference::class);

        return $relation;
    }

    /** @return HasMany<ResourceInstrument, static> */
    public function instruments(): HasMany
    {
        /** @var HasMany<ResourceInstrument, static> $relation */
        $relation = $this->hasMany(ResourceInstrument::class)->orderBy('position');

        return $relation;
    }

    /** @return HasMany<ResourceRight, static> */
    public function resourceRights(): HasMany
    {
        /** @var HasMany<ResourceRight, static> $relation */
        $relation = $this->hasMany(ResourceRight::class);

        return $relation;
    }

    /** @return BelongsToMany<Right, static> */
    public function rights(): BelongsToMany
    {
        /** @var BelongsToMany<Right, static> $relation */
        $relation = $this->belongsToMany(Right::class, 'resource_rights', 'resource_id', 'rights_id')
            ->withPivot([
                'rights_text',
                'rights_uri',
                'rights_identifier',
                'rights_identifier_scheme',
                'scheme_uri',
                'language',
                'source',
            ])
            ->withTimestamps();

        return $relation;
    }

    /** @return BelongsTo<Datacenter, static> */
    public function datacenter(): BelongsTo
    {
        /** @var BelongsTo<Datacenter, static> $relation */
        $relation = $this->belongsTo(Datacenter::class);

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

    /**
     * Get alternate identifiers for this resource.
     *
     * Used for IGSN resources to store:
     * - 'name' field with type "Local accession number"
     * - 'sample_other_names' field with type "Local sample name"
     *
     * @return HasMany<AlternateIdentifier, static>
     *
     * @see https://github.com/McNamara84/ernie/issues/465
     */
    public function alternateIdentifiers(): HasMany
    {
        /** @var HasMany<AlternateIdentifier, static> $relation */
        $relation = $this->hasMany(AlternateIdentifier::class)->orderBy('position');

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

    /** @return HasOne<ResourceAssessment, static> */
    public function resourceAssessment(): HasOne
    {
        /** @var HasOne<ResourceAssessment, static> $relation */
        $relation = $this->hasOne(ResourceAssessment::class);

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
     * @return Collection<int, Subject>
     */
    public function getFreeTextSubjectsAttribute(): Collection
    {
        return $this->subjects->filter(fn (Subject $s) => $s->isFreeText());
    }

    /**
     * Get all controlled vocabulary subjects.
     *
     * @return Collection<int, Subject>
     */
    public function getControlledSubjectsAttribute(): Collection
    {
        return $this->subjects->filter(fn (Subject $s) => $s->isControlled());
    }

    /**
     * Get temporal coverage dates (dateType = 'Collected').
     *
     * @return Collection<int, ResourceDate>
     */
    public function getTemporalCoverageAttribute(): Collection
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

    /**
     * Check whether this resource has all mandatory DataCite fields filled (Issue #548).
     *
     * Mandatory fields: Main Title, Publication Year, Resource Type,
     * at least one Creator, at least one License, and an Abstract description.
     *
     * Expects that titles, creators, rights, and descriptions relations are already
     * eager loaded.
     */
    public function isComplete(): bool
    {
        $hasMainTitle = $this->titles->contains(
            fn (Title $title): bool => $title->isMainTitle() && trim($title->value) !== ''
        );

        if (! $hasMainTitle) {
            return false;
        }

        if ($this->publication_year === null) {
            return false;
        }

        if ($this->resource_type_id === null) {
            return false;
        }

        if ($this->creators->isEmpty()) {
            return false;
        }

        if ($this->rights->isEmpty()) {
            return false;
        }

        return $this->descriptions->contains(
            fn (Description $description): bool => $description->isAbstract() && trim($description->value) !== ''
        );
    }

    /**
     * Determine the publication status of this resource (Issue #548).
     *
     * Status hierarchy:
     * - 'review'/'published': legacy SUMARIO pending imports marked with force_review_status override completeness checks
     * - 'draft': non-legacy resources missing any mandatory field
     * - 'curation': all mandatory fields present, no DOI or no landing page
     * - 'review': has DOI + landing page with is_published = false
     * - 'published': has DOI + landing page with is_published = true
     */
    public function publicStatus(): string
    {
        if ($this->force_review_status) {
            if ($this->doi && $this->landingPage) {
                return $this->landingPage->is_published ? 'published' : 'review';
            }

            return 'review';
        }

        if (! $this->isComplete()) {
            return 'draft';
        }

        if ($this->doi && $this->landingPage) {
            return $this->landingPage->is_published ? 'published' : 'review';
        }

        return 'curation';
    }
}
