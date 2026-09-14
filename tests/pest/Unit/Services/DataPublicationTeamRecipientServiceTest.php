<?php

declare(strict_types=1);

use App\Services\DataPublicationTeamRecipientService;
use Illuminate\Support\Facades\Log;

covers(DataPublicationTeamRecipientService::class);

test('resolves and normalizes a valid configured recipient', function (): void {
    config(['mail.landing_page_contact_cc' => '  datapub@example.test  ']);

    $service = app(DataPublicationTeamRecipientService::class);

    expect($service->email())->toBe('datapub@example.test')
        ->and($service->isAvailable())->toBeTrue();
});

test('treats missing and blank configuration as unavailable', function (mixed $configuredEmail): void {
    config(['mail.landing_page_contact_cc' => $configuredEmail]);

    $service = app(DataPublicationTeamRecipientService::class);

    expect($service->email())->toBeNull()
        ->and($service->isAvailable())->toBeFalse();
})->with([
    'missing' => null,
    'empty' => '',
    'whitespace' => '   ',
]);

test('rejects an invalid address and logs it when requested', function (): void {
    config(['mail.landing_page_contact_cc' => 'not-an-email']);
    Log::spy();

    $service = app(DataPublicationTeamRecipientService::class);

    expect($service->email(logInvalidConfiguration: true))->toBeNull();
    Log::shouldHaveReceived('warning')
        ->once()
        ->with('Invalid Cc email address in config', ['cc_email' => 'not-an-email']);
});

test('rejects a non-string address and logs it when requested', function (): void {
    config(['mail.landing_page_contact_cc' => ['datapub@example.test']]);
    Log::spy();

    $service = app(DataPublicationTeamRecipientService::class);

    expect($service->email(logInvalidConfiguration: true))->toBeNull();
    Log::shouldHaveReceived('warning')
        ->once()
        ->with('Invalid Cc email address type in config');
});
