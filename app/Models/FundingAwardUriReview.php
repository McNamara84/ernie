<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Internal decision archive; snapshots outlive their live foreign keys.
 *
 * @property int $id
 * @property string $decision
 * @property array<string, mixed> $evidence
 * @property array<string, mixed> $reviewer_snapshot
 * @property array<string, mixed> $funding_snapshot
 * @property string|null $old_award_uri
 * @property string|null $new_award_uri
 * @property string $proposed_award_uri
 */
class FundingAwardUriReview extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reviewer_snapshot' => 'array', 'resource_snapshot' => 'array', 'funding_snapshot' => 'array',
            'evidence' => 'array', 'confidence' => 'array', 'ambiguity' => 'array', 'reviewed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static fn () => throw new LogicException('Award URI reviews are immutable.'));
        static::deleting(static fn () => throw new LogicException('Award URI reviews are retained.'));
    }
}
