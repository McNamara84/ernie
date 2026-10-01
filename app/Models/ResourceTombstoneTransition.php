<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An immutable lifecycle record with mutable outbox execution fields.
 *
 * @property int $id
 * @property int $resource_id
 * @property int $revision
 * @property string $action
 * @property string|null $reason
 * @property string|null $statement
 * @property array<string, mixed>|null $snapshot
 * @property string $doi
 * @property bool $test_mode
 * @property string|null $previous_state
 * @property string|null $previous_url
 * @property string $target_state
 * @property string $target_url
 * @property string $status
 * @property int $attempts
 * @property Carbon|null $available_at
 * @property Carbon|null $completed_at
 * @property string|null $last_error
 * @property Carbon $updated_at
 */
class ResourceTombstoneTransition extends Model
{
    /** @var list<string> */
    protected $fillable = ['resource_id', 'user_id', 'revision', 'action', 'reason', 'statement', 'snapshot', 'doi', 'test_mode', 'previous_state', 'previous_url', 'target_state', 'target_url', 'status', 'attempts', 'available_at', 'completed_at', 'last_error'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['snapshot' => 'array', 'test_mode' => 'boolean', 'revision' => 'integer', 'attempts' => 'integer', 'available_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
