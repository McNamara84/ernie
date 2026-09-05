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

it('uses the repository name without a redundant contact suffix for a plain email address', function (
    string $type,
    string $repository,
): void {
    $descriptor = $this->service->publicDescriptor($type, 'archive@example.org', $repository);

    expect($descriptor)->toBe([
        'type' => $type,
        'label' => $repository,
        'has_email' => true,
    ])->and(json_encode($descriptor))->not->toContain('@')
        ->and($this->service->recipients($type, 'archive@example.org', $repository))->toBe([
            ['email' => 'archive@example.org', 'name' => $repository],
        ]);
})->with([
    'current repository' => [IgsnRepositoryContactService::TYPE_CURRENT, "Sawyer's Bay Repository"],
    'original repository' => [IgsnRepositoryContactService::TYPE_ORIGINAL, 'Core Archive'],
]);

it('uses a generic repository contact label when a plain email has no repository name', function (): void {
    expect($this->service->publicDescriptor('current', 'archive@example.org', null))
        ->toBe([
            'type' => 'current',
            'label' => 'Current repository contact',
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
