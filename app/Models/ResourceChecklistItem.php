<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $resource_id
 * @property string $item_key
 * @property string $state
 * @property string|null $reason
 * @property int|null $changed_by
 * @property Carbon $changed_at
 * @property int $revision
 */
final class ResourceChecklistItem extends Model
{
    protected $table = 'resource_checklist_items';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['changed_at' => 'datetime', 'revision' => 'integer'];
}
