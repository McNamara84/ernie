<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $resource_id
 * @property string $type
 * @property int|null $user_id
 * @property string $actor_name
 * @property array<string, mixed> $payload
 * @property string|null $body
 * @property int $revision
 * @property Carbon $created_at
 * @property Carbon|null $edited_at
 * @property Carbon|null $deleted_at
 */
final class ResourceCurationEvent extends Model
{
    protected $table = 'resource_curation_events';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['payload' => 'array', 'created_at' => 'datetime', 'edited_at' => 'datetime', 'deleted_at' => 'datetime', 'revision' => 'integer'];
}
