<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contact Message Model
 *
 * Stores messages sent via the landing page contact form.
 * Used for logging, rate-limiting analysis, and audit purposes.
 *
 * @property int $id
 * @property int $resource_id
 * @property int|null $resource_creator_id
 * @property bool $send_to_all
 * @property string $sender_name
 * @property string $sender_email
 * @property string $message
 * @property bool $copy_to_sender
 * @property string|null $ip_address
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Resource $resource
 * @property-read ResourceCreator|null $resourceCreator
 */
class ContactMessage extends Model
{
    /** @use HasFactory<\Database\Factories\ContactMessageFactory> */
    use HasFactory;

    protected $fillable = [
        'resource_id',
        'resource_creator_id',
        'send_to_all',
        'sender_name',
        'sender_email',
        'message',
        'copy_to_sender',
        'ip_address',
        'sent_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'send_to_all' => 'boolean',
        'copy_to_sender' => 'boolean',
        'sent_at' => 'datetime',
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
     * Check if the message has been sent.
     */
    public function isSent(): bool
    {
        return $this->sent_at !== null;
    }

    /**
     * Mark the message as sent.
     */
    public function markAsSent(): void
    {
        $this->update(['sent_at' => now()]);
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
