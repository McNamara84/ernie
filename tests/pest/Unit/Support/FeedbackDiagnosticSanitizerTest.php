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
    $message = "Failed https://example.test/path?token=secret#part for jane@example.test Bearer abc.def-123_xyz and {$jwtLikeToken}";

    $sanitized = $this->sanitizer->message($message);

    expect($sanitized)
        ->toContain('https://example.test/path')
        ->not->toContain('token=secret')
        ->not->toContain('jane@example.test')
        ->not->toContain('abc.def-123_xyz')
        ->not->toContain($jwtLikeToken)
        ->toContain('[redacted-email]')
        ->toContain('[redacted-token]');
});

it('handles null and bounds diagnostic messages', function (): void {
    expect($this->sanitizer->message(null))->toBeNull()
        ->and(mb_strlen((string) $this->sanitizer->message(str_repeat('x', 700))))->toBe(500);
});

it('removes control characters and bounds user agents', function (): void {
    $userAgent = "Browser\0".str_repeat('x', 700);

    expect($this->sanitizer->userAgent(null))->toBe('Unknown')
        ->and($this->sanitizer->userAgent("\0\n"))->toBe('Unknown')
        ->and($this->sanitizer->userAgent($userAgent))->not->toContain("\0")
        ->and(mb_strlen($this->sanitizer->userAgent($userAgent)))->toBe(512);
});
