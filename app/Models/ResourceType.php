<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Str;

#[Fillable(['name', 'slug', 'description', 'is_active', 'is_elmo_active'])]
class ResourceType extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    protected $casts = [
        'is_active' => 'boolean',
        'is_elmo_active' => 'boolean',
    ];

    /**
     * @param  Builder<ResourceType>  $query
     * @return Builder<ResourceType>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<ResourceType>  $query
     * @return Builder<ResourceType>
     */
    public function scopeElmoActive(Builder $query): Builder
    {
        return $query->where('is_elmo_active', true);
    }

    /**
     * @param  Builder<ResourceType>  $query
     * @return Builder<ResourceType>
     */
    public function scopeOrderByName(Builder $query): Builder
    {
        return $query->orderBy('name');
    }

    /**
     * Map this resource type to the DataCite 4.7 `resourceTypeGeneral` enum
     * value (PascalCase, no spaces), which is also the canonical representation
     * used for `related_items.related_item_type`.
     *
     * Derived from the immutable `slug` column rather than the human-readable
     * `name` because curators can rename the latter via editor settings: a
     * rename of "Journal Article" to "Articles in Journals" must not change
     * the canonical DataCite enum (still `JournalArticle`) nor invalidate
     * already-stored `related_item_type` values.
     *
     * Examples: slug `journal-article` → `JournalArticle`, slug `book` → `Book`.
     *
     * @see https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/relateditem/
     */
    public function dataciteResourceTypeGeneral(): string
    {
        return self::slugToDataciteResourceTypeGeneral($this->slug);
    }

    /**
     * Static helper for boundary code that has the immutable slug but not a
     * hydrated model (e.g. validation rules or vocabulary endpoints).
     */
    public static function slugToDataciteResourceTypeGeneral(string $slug): string
    {
        return Str::studly($slug);
    }

    /**
     * List of all DataCite resourceTypeGeneral values currently allowed
     * as `related_items.related_item_type`. Derived from the immutable
     * `slug` column of the active rows in `resource_types` so curators
     * can extend the vocabulary at runtime without touching code, while
     * renames of the user-facing `name` cannot drift the allow-list away
     * from already-stored values.
     *
     * @return list<string>
     */
    public static function activeDataciteResourceTypesGeneral(): array
    {
        /** @var list<string> $slugs */
        $slugs = self::query()
            ->active()
            ->pluck('slug')
            ->all();

        return array_values(array_unique(array_map(
            self::slugToDataciteResourceTypeGeneral(...),
            $slugs,
        )));
    }

    /**
     * Get the resources with this resource type.
     *
     * @return HasMany<\App\Models\Resource, static>
     */
    public function resources(): HasMany
    {
        /** @var HasMany<\App\Models\Resource, static> $relation */
        $relation = $this->hasMany(Resource::class);

        return $relation;
    }

    /**
     * Licenses (Rights) that EXCLUDE this resource type.
     *
     * If a license is in this relationship, that license is NOT available for this resource type.
     *
     * @return BelongsToMany<Right, static, Pivot, 'pivot'>
     */
    public function excludedFromRights(): BelongsToMany
    {
        /** @var BelongsToMany<Right, static, Pivot, 'pivot'> $relation */
        $relation = $this->belongsToMany(
            Right::class,
            'right_resource_type_exclusions',
            'resource_type_id',
            'right_id'
        )->withTimestamps();

        return $relation;
    }
}
