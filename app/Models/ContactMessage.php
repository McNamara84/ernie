<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contact Message Model
 *
 * Stores contact form submissions from landing page visitors.
 * Used for logging, rate limiting, and spam detection.
 *
 * @property int $id
 * @property int $resource_id
 * @property string $sender_name
 * @property string $sender_email
 * @property array<int> $recipient_contributor_ids
 * @property string $message
 * @property string|null $ip_address
 * @property bool $honeypot_triggered
 * @property bool $send_copy_to_sender
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 *
 * @property-read Resource $resource
 */
class ContactMessage extends Model
{

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'resource_id',
        'sender_name',
        'sender_email',
        'recipient_contributor_ids',
        'message',
        'ip_address',
        'honeypot_triggered',
        'send_copy_to_sender',
        'sent_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'recipient_contributor_ids' => 'array',
        'honeypot_triggered' => 'boolean',
        'send_copy_to_sender' => 'boolean',
        'sent_at' => 'datetime',
    ];

    /**
     * Get the resource this message is about.
     *
     * @return BelongsTo<Resource, static>
     */
    public function resource(): BelongsTo
    {
        /** @var BelongsTo<Resource, static> $relation */
        $relation = $this->belongsTo(Resource::class);

        return $relation;
    }

    /**
     * Get the recipient contributors.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, ResourceContributor>
     */
    public function getRecipients(): \Illuminate\Database\Eloquent\Collection
    {
        return ResourceContributor::whereIn('id', $this->recipient_contributor_ids)->get();
    }

    /**
     * Check if this message was likely sent by a bot (honeypot triggered).
     */
    public function isSpam(): bool
    {
        return $this->honeypot_triggered;
    }

    /**
     * Mark the message as sent.
     */
    public function markAsSent(): void
    {
        $this->update(['sent_at' => now()]);
    }

    /**
     * Check if the message has been sent.
     */
    public function wasSent(): bool
    {
        return $this->sent_at !== null;
    }

    /**
     * Count recent messages from an IP address for rate limiting.
     */
    public static function countRecentFromIp(string $ipAddress, int $hours = 1): int
    {
        return static::where('ip_address', $ipAddress)
            ->where('created_at', '>=', now()->subHours($hours))
            ->where('honeypot_triggered', false)
            ->count();
    }
}
