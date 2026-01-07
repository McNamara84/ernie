<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Publisher Model (DataCite #4)
 *
 * @property int $id
 * @property string $name
 * @property string|null $identifier
 * @property string|null $identifier_scheme
 * @property string|null $scheme_uri
 * @property string $language
 * @property bool $is_default
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.6/properties/publisher/
 */
class Publisher extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory<static>> */
    use HasFactory;

    protected $fillable = [
        'name',
        'identifier',
        'identifier_scheme',
        'scheme_uri',
        'language',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    /**
     * Get the default publisher.
     */
    public static function getDefault(): ?self
    {
        return static::where('is_default', true)->first();
    }

    /**
     * @param  Builder<Publisher>  $query
     * @return Builder<Publisher>
     */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    /** @return HasMany<Resource, static> */
    public function resources(): HasMany
    {
        /** @var HasMany<Resource, static> $relation */
        $relation = $this->hasMany(Resource::class);

        return $relation;
    }
}
