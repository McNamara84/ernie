<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\ContactMessage;
use Illuminate\Mail\Events\MessageSent;

class MarkContactMessageAsSent
{
    public function handle(MessageSent $event): void
    {
        $message = $event->message;

        $contactMessageId = $message->getHeaders()->get('X-Contact-Message-Id')?->getBodyAsString();

        if (! is_string($contactMessageId) || ! ctype_digit($contactMessageId)) {
            return;
        }

        $contactMessage = ContactMessage::query()->find((int) $contactMessageId);

        if ($contactMessage === null) {
            return;
        }

        $contactMessage->markRecipientDelivered();
    }
}