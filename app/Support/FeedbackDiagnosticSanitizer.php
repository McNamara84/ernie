<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

final class FeedbackDiagnosticSanitizer
{
    public function message(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        $sanitized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $message) ?? '';
        $sanitized = preg_replace('~(https?://[^\s?#]+)[?#][^\s]*~iu', '$1', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/iu', 'Bearer [redacted-token]', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/\b[A-Za-z0-9_-]{12,}\.[A-Za-z0-9_-]{12,}\.[A-Za-z0-9_-]{12,}\b/u', '[redacted-token]', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/iu', '[redacted-email]', $sanitized) ?? $sanitized;
        $sanitized = preg_replace('/\b[A-F0-9]{32,}\b/iu', '[redacted-token]', $sanitized) ?? $sanitized;

        return Str::limit(trim($sanitized), 500, '');
    }

    public function userAgent(?string $userAgent): string
    {
        if ($userAgent === null) {
            return 'Unknown';
        }

        $sanitized = preg_replace('/[\x00-\x1F\x7F]/u', '', $userAgent) ?? '';
        $sanitized = trim($sanitized);

        return $sanitized === '' ? 'Unknown' : Str::limit($sanitized, 512, '');
    }
}
