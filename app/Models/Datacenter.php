<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Datacenter Model
 *
 * Represents a datacenter for internal resource categorization.
 * Datacenters are not part of the DataCite metadata schema.
 *
 * @property int $id
 * @property string $name
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Resource> $resources
 */
class Datacenter extends Model
{
    /** @use HasFactory<\Database\Factories\DatacenterFactory> */
    use HasFactory;
    /** @var list<string> */
    protected $fillable = ['name'];

    /** @return BelongsToMany<Resource, static> */
    public function resources(): BelongsToMany
    {
        /** @var BelongsToMany<Resource, static> $relation */
        $relation = $this->belongsToMany(Resource::class, 'resource_datacenter')
            ->withTimestamps();

        return $relation;
    }
}
