<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $resource_id
 * @property string $type
 * @property string $location
 * @property int|null $created_by
 * @property int $revision
 */
final class ResourceDataLocation extends Model
{
    protected $table = 'resource_data_locations';

    protected $guarded = [];

    public $timestamps = true;

    protected $casts = ['revision' => 'integer'];
}
