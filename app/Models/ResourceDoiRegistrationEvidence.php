<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $resource_id
 * @property string $doi
 * @property string $environment
 * @property string $state
 * @property string $source
 * @property Carbon $confirmed_at
 */
final class ResourceDoiRegistrationEvidence extends Model
{
    protected $table = 'resource_doi_registration_evidence';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['confirmed_at' => 'datetime'];
}
