<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * Welcome Email for New Users
 *
 * Sent to new users when their account is created by a Group Leader or Admin.
 * Contains a signed URL to set their initial password.
 * Written from ERNIE's perspective as the metadata curation assistant.
 *
 * Note: This mailable is sent synchronously (not queued) to provide immediate
 * feedback if email delivery fails, allowing the admin to take action.
 */
class WelcomeNewUser extends Mailable
{
    use SerializesModels;

    /**
     * The signed URL for password setup.
     */
    public string $welcomeUrl;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public User $user,
    ) {
        // Generate signed URL valid for 72 hours
        $this->welcomeUrl = URL::temporarySignedRoute(
            'welcome.show',
            now()->addHours(72),
            ['user' => $user->id]
        );
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to ERNIE - Set Your Password',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.welcome-new-user',
            text: 'emails.welcome-new-user-text',
            with: [
                'userName' => $this->user->name,
                'welcomeUrl' => $this->welcomeUrl,
                'expiresIn' => '72 hours',
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
