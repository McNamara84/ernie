<?php

declare(strict_types=1);

use App\Support\FeedbackDiagnosticSanitizer;

covers(FeedbackDiagnosticSanitizer::class);

beforeEach(function (): void {
    $this->sanitizer = new FeedbackDiagnosticSanitizer;
});

it('redacts sensitive diagnostic message fragments and strips URL queries', function (): void {
    $jwtLikeToken = implode('.', [
        str_repeat('a', 12),
        str_repeat('b', 12),
        str_repeat('c', 12),
    ]);
    $bearerToken = str_repeat('test_', 3).'token-'.str_repeat('padding', 2).'==';
    $message = "Failed https://example.test/path?token=secret#part and /api?token=relative-secret#part for jane@example.test Bearer {$bearerToken} and {$jwtLikeToken}";

    $sanitized = $this->sanitizer->message($message);

    expect($sanitized)
        ->toContain('https://example.test/path')
        ->toContain('/api')
        ->not->toContain('token=secret')
        ->not->toContain('relative-secret')
        ->not->toContain('jane@example.test')
        ->not->toContain($bearerToken)
        ->not->toContain($jwtLikeToken)
        ->toContain('[redacted-email]')
        ->toContain('Bearer [redacted-token] and');
});

it('handles null and bounds diagnostic messages', function (): void {
    expect($this->sanitizer->message(null))->toBeNull()
        ->and(mb_strlen((string) $this->sanitizer->message(str_repeat('x', 700))))->toBe(500);
});

it('redacts sensitive user-agent values, removes controls, and applies its own bound', function (): void {
    $bearerToken = str_repeat('test_', 3).'token-'.str_repeat('padding', 2).'==';
    $userAgent = "Browser\0 jane@example.test https://example.test/path?token=absolute /api?token=relative Bearer {$bearerToken} ".str_repeat('x', 700);
    $sanitized = $this->sanitizer->userAgent($userAgent);

    expect($this->sanitizer->userAgent(null))->toBe('Unknown')
        ->and($this->sanitizer->userAgent("\0\n"))->toBe('Unknown')
        ->and($sanitized)->not->toContain("\0")
        ->and($sanitized)->not->toContain('jane@example.test')
        ->and($sanitized)->not->toContain('token=absolute')
        ->and($sanitized)->not->toContain('token=relative')
        ->and($sanitized)->not->toContain($bearerToken)
        ->and($sanitized)->toContain('[redacted-email]')
        ->and($sanitized)->toContain('Bearer [redacted-token]')
        ->and(mb_strlen($sanitized))->toBe(512);
});
