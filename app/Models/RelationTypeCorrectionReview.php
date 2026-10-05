<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $resource_id
 * @property int|null $related_identifier_id
 * @property int|null $actor_id
 * @property int $suggestion_id
 * @property string $decision
 * @property string|null $reason
 * @property array<string, mixed> $snapshot
 * @property string $context_fingerprint
 * @property string $review_fingerprint
 * @property Carbon $reviewed_at
 */
#[Fillable(['resource_id', 'related_identifier_id', 'actor_id', 'suggestion_id', 'decision', 'reason', 'snapshot', 'context_fingerprint', 'review_fingerprint', 'reviewed_at'])]
class RelationTypeCorrectionReview extends Model
{
    public $timestamps = false;

    /** @var array<string, string> */
    protected $casts = ['snapshot' => 'array', 'reviewed_at' => 'datetime'];
}
