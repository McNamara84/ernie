<?php

declare(strict_types=1);

use App\Listeners\MarkContactMessageAsSent;
use App\Models\ContactMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\SentMessage as IlluminateSentMessage;
use Symfony\Component\Mailer\Envelope as SymfonyEnvelope;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

uses(RefreshDatabase::class);

covers(MarkContactMessageAsSent::class);

it('marks the contact message as sent when the tracking header is present', function () {
    $contactMessage = ContactMessage::factory()->pending()->create();

    $email = (new Email)
        ->from('noreply@example.com')
        ->to('recipient@example.com')
        ->subject('Contact request')
        ->text('Body');

    $email->getHeaders()->addTextHeader('X-Contact-Message-Id', (string) $contactMessage->id);

    $sentMessage = new IlluminateSentMessage(
        new SymfonySentMessage(
            $email,
            new SymfonyEnvelope(new Address('noreply@example.com'), [new Address('recipient@example.com')]),
        ),
    );

    (new MarkContactMessageAsSent)->handle(new MessageSent($sentMessage));

    $contactMessage->refresh();

    expect($contactMessage->sent_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

it('ignores unrelated sent messages without the tracking header', function () {
    $contactMessage = ContactMessage::factory()->pending()->create();

    $email = (new Email)
        ->from('noreply@example.com')
        ->to('recipient@example.com')
        ->subject('Contact request')
        ->text('Body');

    $sentMessage = new IlluminateSentMessage(
        new SymfonySentMessage(
            $email,
            new SymfonyEnvelope(new Address('noreply@example.com'), [new Address('recipient@example.com')]),
        ),
    );

    (new MarkContactMessageAsSent)->handle(new MessageSent($sentMessage));

    $contactMessage->refresh();

    expect($contactMessage->sent_at)->toBeNull();
});