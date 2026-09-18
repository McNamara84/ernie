<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/** Event-emitting pivot for public contributor-role cache invalidation. */
final class ResourceContributorTypePivot extends Pivot
{
    protected $table = 'resource_contributor_contributor_type';

    public $incrementing = true;

    protected $guarded = [];
}
