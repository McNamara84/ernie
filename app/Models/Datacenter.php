<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DatacenterFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Datacenter Model
 *
 * Represents a datacenter for internal resource categorization.
 * Datacenters are not part of the DataCite metadata schema.
 *
 * @property int $id
 * @property string $name
 * @property int|null $landing_page_template_id
 * @property int|null $igsn_landing_page_template_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Resource> $resources
 * @property-read LandingPageTemplate|null $landingPageTemplate
 * @property-read LandingPageTemplate|null $igsnLandingPageTemplate
 */
class Datacenter extends Model
{
    public const GFZ_NAME = 'GFZ German Research Centre for Geosciences';

    /** @use HasFactory<DatacenterFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['name', 'landing_page_template_id', 'igsn_landing_page_template_id'];

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

    /** @return BelongsTo<LandingPageTemplate, static> */
    public function igsnLandingPageTemplate(): BelongsTo
    {
        /** @var BelongsTo<LandingPageTemplate, static> $relation */
        $relation = $this->belongsTo(
            LandingPageTemplate::class,
            'igsn_landing_page_template_id',
        );

        return $relation;
    }
}
