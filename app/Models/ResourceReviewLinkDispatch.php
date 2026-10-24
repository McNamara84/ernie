<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $resource_id
 * @property string $operation_id
 * @property string $kind
 * @property int|null $sent_by
 * @property Carbon $queued_at
 * @property int $queued_recipients
 * @property list<string>|null $queued_recipient_keys
 */
final class ResourceReviewLinkDispatch extends Model
{
    protected $table = 'resource_review_link_dispatches';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = ['queued_at' => 'datetime', 'queued_recipient_keys' => 'array'];
}
