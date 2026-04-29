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
     * Map this resource type's human-readable `name` to the
     * DataCite 4.7 `resourceTypeGeneral` enum value (PascalCase, no spaces),
     * which is also the canonical representation used for
     * `related_items.related_item_type`.
     *
     * Examples: `Journal Article` → `JournalArticle`, `Book` → `Book`.
     *
     * @see https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/relateditem/
     */
    public function dataciteResourceTypeGeneral(): string
    {
        return self::nameToDataciteResourceTypeGeneral($this->name);
    }

    /**
     * Static helper for boundary code that has the human-readable name
     * but not a hydrated model (e.g. validation rules or vocabulary endpoints).
     */
    public static function nameToDataciteResourceTypeGeneral(string $name): string
    {
        return Str::studly($name);
    }

    /**
     * List of all DataCite resourceTypeGeneral values currently allowed
     * as `related_items.related_item_type`. Derived from the active rows
     * in `resource_types` so curators can extend the vocabulary at runtime
     * without touching code.
     *
     * @return list<string>
     */
    public static function activeDataciteResourceTypesGeneral(): array
    {
        /** @var list<string> $names */
        $names = self::query()
            ->active()
            ->pluck('name')
            ->all();

        return array_values(array_unique(array_map(
            self::nameToDataciteResourceTypeGeneral(...),
            $names,
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
