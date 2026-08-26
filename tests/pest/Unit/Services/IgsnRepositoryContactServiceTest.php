<?php

declare(strict_types=1);

use App\Services\IgsnRepositoryContactService;

covers(IgsnRepositoryContactService::class);

beforeEach(function (): void {
    $this->service = new IgsnRepositoryContactService;
});

it('exposes only a safe display name for a named email contact', function (): void {
    $descriptor = $this->service->publicDescriptor(
        IgsnRepositoryContactService::TYPE_CURRENT,
        'Tina Kollaske <Tina.Kollaske@BGR.DE>',
        'BGR Berlin',
    );

    expect($descriptor)->toBe([
        'type' => 'current',
        'label' => 'Tina Kollaske',
        'has_email' => true,
    ])->and(json_encode($descriptor))->not->toContain('@');
});

it('uses a repository label instead of exposing a plain email address', function (): void {
    expect($this->service->publicDescriptor('original', 'archive@example.org', 'Core Archive'))
        ->toBe([
            'type' => 'original',
            'label' => 'Core Archive contact',
            'has_email' => true,
        ]);
});

it('normalizes and deduplicates backend recipients', function (): void {
    expect($this->service->recipients(
        'current',
        'Archive Team <TEAM@EXAMPLE.ORG>; duplicate <team@example.org>; second@example.org',
        'Archive',
    ))->toBe([
        ['email' => 'team@example.org', 'name' => 'Archive Team duplicate'],
        ['email' => 'second@example.org', 'name' => 'Archive Team duplicate'],
    ]);
});

it('keeps a non-email contact label but disables the protected form', function (): void {
    expect($this->service->publicDescriptor('current', 'Repository help desk', 'Archive'))
        ->toBe([
            'type' => 'current',
            'label' => 'Repository help desk',
            'has_email' => false,
        ])
        ->and($this->service->recipients('current', 'Repository help desk', 'Archive'))->toBe([]);
});

it('redacts malformed address-like values and handles empty contacts', function (): void {
    expect($this->service->publicDescriptor('current', 'broken@address', null))
        ->toBe([
            'type' => 'current',
            'label' => 'Current repository contact',
            'has_email' => false,
        ])
        ->and($this->service->publicDescriptor('current', '  ', 'Archive'))->toBeNull();
});

it('rejects a valid-looking email substring inside a malformed address token', function (): void {
    $malformedContact = 'victim@example.org@attacker.com';

    expect($this->service->publicDescriptor('current', $malformedContact, null))
        ->toBe([
            'type' => 'current',
            'label' => 'Current repository contact',
            'has_email' => false,
        ])
        ->and($this->service->recipients('current', $malformedContact, null))->toBe([]);
});
