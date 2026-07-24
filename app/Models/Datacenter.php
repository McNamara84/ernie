<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Datacenter Model
 *
 * Represents a datacenter for internal resource categorization.
 * Datacenters are not part of the DataCite metadata schema.
 *
 * @property int $id
 * @property string $name
 * @property int|null $landing_page_template_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Resource> $resources
 * @property-read LandingPageTemplate|null $landingPageTemplate
 */
class Datacenter extends Model
{
    public const GFZ_NAME = 'GFZ German Research Centre for Geosciences';

    /** @use HasFactory<\Database\Factories\DatacenterFactory> */
    use HasFactory;
    /** @var list<string> */
    protected $fillable = ['name', 'landing_page_template_id'];

    /** @return HasMany<Resource, static> */
    public function resources(): HasMany
    {
        /** @var HasMany<Resource, static> $relation */
        $relation = $this->hasMany(Resource::class);

        return $relation;
    }

    /** @return BelongsTo<LandingPageTemplate, static> */
    public function landingPageTemplate(): BelongsTo
    {
        /** @var BelongsTo<LandingPageTemplate, static> $relation */
        $relation = $this->belongsTo(LandingPageTemplate::class);

        return $relation;
    }
}
