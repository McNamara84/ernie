<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Event-emitting pivot used when rights are attached through belongsToMany().
 */
final class ResourceRightPivot extends Pivot
{
    protected $table = 'resource_rights';

    public $incrementing = true;

    protected $guarded = [];
}
