<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Contact Message Model
 *
 * Stores messages sent via the landing page contact form.
 * Used for logging, rate-limiting analysis, and audit purposes.
 *
 * @property int $id
 * @property int $resource_id
 * @property int|null $resource_creator_id
 * @property int|null $resource_contributor_id
 * @property bool $send_to_all
 * @property string $sender_name
 * @property string $sender_email
 * @property string $message
 * @property bool $copy_to_sender
 * @property string|null $ip_address
 * @property int $recipient_count
 * @property int $delivered_recipient_count
 * @property \Illuminate\Support\Carbon|null $queued_at
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property \Illuminate\Support\Carbon|null $failed_at
 * @property string|null $failure_reason
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Resource $resource
 * @property-read ResourceCreator|null $resourceCreator
 * @property-read ResourceContributor|null $resourceContributor
 */
#[Fillable(['resource_id', 'resource_creator_id', 'resource_contributor_id', 'send_to_all', 'sender_name', 'sender_email', 'message', 'copy_to_sender', 'ip_address', 'recipient_count', 'delivered_recipient_count', 'queued_at', 'sent_at', 'failed_at', 'failure_reason'])]
class ContactMessage extends Model
{
    /** @use HasFactory<\Database\Factories\ContactMessageFactory> */
    use HasFactory;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'send_to_all' => 'boolean',
        'copy_to_sender' => 'boolean',
        'recipient_count' => 'integer',
        'delivered_recipient_count' => 'integer',
        'queued_at' => 'datetime',
        'sent_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Resource, static>
     */
    public function resource(): BelongsTo
    {
        /** @var BelongsTo<Resource, static> $relation */
        $relation = $this->belongsTo(Resource::class);

        return $relation;
    }

    /**
     * @return BelongsTo<ResourceCreator, static>
     */
    public function resourceCreator(): BelongsTo
    {
        /** @var BelongsTo<ResourceCreator, static> $relation */
        $relation = $this->belongsTo(ResourceCreator::class);

        return $relation;
    }

    /**
     * @return BelongsTo<ResourceContributor, static>
     */
    public function resourceContributor(): BelongsTo
    {
        /** @var BelongsTo<ResourceContributor, static> $relation */
        $relation = $this->belongsTo(ResourceContributor::class);

        return $relation;
    }

    /**
     * Check if the message has been sent.
     */
    public function isSent(): bool
    {
        return $this->sent_at !== null;
    }

    /**
     * Mark the message as queued.
     */
    public function markAsQueued(): void
    {
        if ($this->queued_at !== null) {
            return;
        }

        $this->forceFill(['queued_at' => now()])->save();
    }

    /**
     * Mark the message as sent.
     */
    public function markAsSent(): void
    {
        if ($this->sent_at !== null || $this->failed_at !== null) {
            return;
        }

        $this->forceFill(['sent_at' => now()])->save();
    }

    /**
     * Mark one tracked recipient delivery as completed.
     */
    public function markRecipientDelivered(): void
    {
        if (! $this->exists) {
            return;
        }

        DB::transaction(function (): void {
            /** @var self|null $contactMessage */
            $contactMessage = static::query()->lockForUpdate()->find($this->getKey());

            if ($contactMessage === null || $contactMessage->failed_at !== null || $contactMessage->sent_at !== null) {
                return;
            }

            $requiredRecipients = max(1, $contactMessage->recipient_count);
            $deliveredRecipients = min($requiredRecipients, $contactMessage->delivered_recipient_count + 1);

            $attributes = [
                'delivered_recipient_count' => $deliveredRecipients,
            ];

            if ($deliveredRecipients >= $requiredRecipients) {
                $attributes['sent_at'] = now();
            }

            $contactMessage->forceFill($attributes)->save();
        });

        $this->refresh();
    }

    /**
     * Mark the message as failed.
     */
    public function markAsFailed(?string $reason = null): void
    {
        if (! $this->exists) {
            return;
        }

        DB::transaction(function () use ($reason): void {
            /** @var self|null $contactMessage */
            $contactMessage = static::query()->lockForUpdate()->find($this->getKey());

            if ($contactMessage === null || $contactMessage->failed_at !== null || $contactMessage->sent_at !== null) {
                return;
            }

            $contactMessage->forceFill([
                'failed_at' => now(),
                'failure_reason' => $reason,
            ])->save();
        });

        $this->refresh();
    }

    /**
     * Count messages from an IP address within the last hour.
     * Used for rate-limiting.
     */
    public static function countRecentFromIp(string $ipAddress, int $minutes = 60): int
    {
        return static::where('ip_address', $ipAddress)
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->count();
    }
}
