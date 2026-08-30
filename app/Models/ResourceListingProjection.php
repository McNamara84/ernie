<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Read-optimized, denormalized values used by the internal resource listing.
 *
 * @property int $resource_id
 * @property bool $is_igsn
 * @property string $workflow_status
 * @property int $workflow_status_rank
 * @property bool $is_dashboard_draft
 * @property int|null $resource_type_id
 * @property string|null $resource_type_slug
 * @property string $resource_type_sort
 * @property int|null $datacenter_id
 * @property int|null $curator_user_id
 * @property string $curator_name
 * @property int|null $publication_year
 * @property int $sort_year
 * @property string $sort_doi
 * @property string $main_title
 * @property string $first_creator_sort
 * @property string $created_sort
 * @property string $updated_sort
 * @property string $search_text
 */
final class ResourceListingProjection extends Model
{
    protected $primaryKey = 'resource_id';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'resource_id' => 'integer',
        'is_igsn' => 'boolean',
        'workflow_status_rank' => 'integer',
        'is_dashboard_draft' => 'boolean',
        'resource_type_id' => 'integer',
        'datacenter_id' => 'integer',
        'curator_user_id' => 'integer',
        'publication_year' => 'integer',
        'sort_year' => 'integer',
    ];

    /** @return BelongsTo<Resource, $this> */
    public function resource(): BelongsTo
    {
        /** @var BelongsTo<Resource, $this> $relation */
        $relation = $this->belongsTo(Resource::class);

        return $relation;
    }
}
