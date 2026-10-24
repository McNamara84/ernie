<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $resource_id
 * @property int|null $started_by
 * @property string $start_source
 * @property Carbon $started_at
 * @property int|null $lead_curator_id
 * @property string|null $waiting_for
 * @property string|null $waiting_text
 * @property Carbon|null $waiting_since
 * @property string|null $needs_help_text
 * @property string|null $deadline
 * @property string|null $deadline_reason
 * @property Carbon $last_activity_at
 * @property int $revision
 */
final class ResourceCurationState extends Model
{
    protected $table = 'resource_curation_states';

    protected $guarded = [];

    public $timestamps = false;

    protected $primaryKey = 'resource_id';

    public $incrementing = false;

    protected $casts = ['started_at' => 'datetime', 'waiting_since' => 'datetime', 'last_activity_at' => 'datetime', 'revision' => 'integer'];
}
