<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\ContactMessageCopy;
use App\Mail\ContactPersonMessage;
use App\Models\ContactMessage;
use App\Models\Resource;
use App\Models\ResourceContributor;
use Illuminate\Support\Facades\Mail;

/**
 * Service for handling contact form submissions on landing pages.
 */
class ContactMessageService
{
    /**
     * Maximum number of messages allowed per IP per hour.
     */
    private const int RATE_LIMIT_MAX_PER_HOUR = 5;

    /**
     * Process a contact form submission.
     *
     * @param  Resource  $resource  The dataset resource
     * @param  string  $senderName  Name of the sender
     * @param  string  $senderEmail  Email of the sender
     * @param  array<int>  $recipientContributorIds  IDs of recipient contributors
     * @param  string  $message  The message content
     * @param  bool  $sendCopyToSender  Whether to send a copy to the sender
     * @param  string|null  $ipAddress  IP address of the sender
     * @param  bool  $honeypotTriggered  Whether the honeypot field was filled
     * @return ContactMessage The created contact message
     */
    public function processContactForm(
        Resource $resource,
        string $senderName,
        string $senderEmail,
        array $recipientContributorIds,
        string $message,
        bool $sendCopyToSender = false,
        ?string $ipAddress = null,
        bool $honeypotTriggered = false,
    ): ContactMessage {
        // Create the contact message record (for logging and rate limiting)
        $contactMessage = ContactMessage::create([
            'resource_id' => $resource->id,
            'sender_name' => $senderName,
            'sender_email' => $senderEmail,
            'recipient_contributor_ids' => $recipientContributorIds,
            'message' => $message,
            'ip_address' => $ipAddress,
            'honeypot_triggered' => $honeypotTriggered,
            'send_copy_to_sender' => $sendCopyToSender,
        ]);

        // If honeypot was triggered, log but don't send emails (spam)
        if ($honeypotTriggered) {
            return $contactMessage;
        }

        // Get recipients and send emails
        $recipients = $this->getRecipients($recipientContributorIds);

        foreach ($recipients as $recipient) {
            $this->sendToRecipient($contactMessage, $resource, $recipient);
        }

        // Mark as sent
        $contactMessage->update(['sent_at' => now()]);

        // Send copy to sender if requested
        if ($sendCopyToSender) {
            $recipientNames = $recipients->map(function (ResourceContributor $contributor) {
                return $this->getContributorDisplayName($contributor);
            })->toArray();

            Mail::to($senderEmail)->queue(
                new ContactMessageCopy($contactMessage, $resource, $recipientNames)
            );
        }

        return $contactMessage;
    }

    /**
     * Check if an IP address has exceeded the rate limit.
     *
     * @param  string|null  $ipAddress  The IP address to check
     * @return bool True if rate limited, false if allowed
     */
    public function isRateLimited(?string $ipAddress): bool
    {
        if ($ipAddress === null) {
            return false;
        }

        return ContactMessage::countRecentFromIp($ipAddress) >= self::RATE_LIMIT_MAX_PER_HOUR;
    }

    /**
     * Get the number of remaining messages for an IP address.
     *
     * @param  string|null  $ipAddress  The IP address to check
     * @return int Number of remaining messages allowed
     */
    public function getRemainingMessages(?string $ipAddress): int
    {
        if ($ipAddress === null) {
            return self::RATE_LIMIT_MAX_PER_HOUR;
        }

        $used = ContactMessage::countRecentFromIp($ipAddress);

        return max(0, self::RATE_LIMIT_MAX_PER_HOUR - $used);
    }

    /**
     * Get recipient contributors by their IDs.
     *
     * @param  array<int>  $contributorIds
     * @return \Illuminate\Database\Eloquent\Collection<int, ResourceContributor>
     */
    private function getRecipients(array $contributorIds): \Illuminate\Database\Eloquent\Collection
    {
        return ResourceContributor::whereIn('id', $contributorIds)
            ->with('contributorable')
            ->get();
    }

    /**
     * Send email to a single recipient.
     */
    private function sendToRecipient(
        ContactMessage $contactMessage,
        Resource $resource,
        ResourceContributor $recipient
    ): void {
        $email = $recipient->email;

        if ($email === null || $email === '') {
            return;
        }

        $recipientName = $this->getContributorDisplayName($recipient);

        Mail::to($email)->queue(
            new ContactPersonMessage($contactMessage, $resource, $recipientName)
        );
    }

    /**
     * Get the display name for a contributor.
     */
    private function getContributorDisplayName(ResourceContributor $contributor): string
    {
        $contributorable = $contributor->contributorable;

        // Check if it's a Person (use full_name accessor)
        if ($contributorable instanceof \App\Models\Person) {
            return $contributorable->full_name !== '' ? $contributorable->full_name : 'Contact Person';
        }

        // It's an Institution (has name property)
        return $contributorable->name ?? 'Contact Person';
    }
}
