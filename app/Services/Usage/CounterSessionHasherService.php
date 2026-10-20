<?php

declare(strict_types=1);

namespace App\Services\Usage;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use RuntimeException;

final class CounterSessionHasherService
{
    public function hash(Request $request, CarbonImmutable $at): string
    {
        $secret = (string) config('usage.session_secret');
        if (strlen($secret) < 32) {
            throw new RuntimeException('COUNTER_SESSION_SECRET must contain at least 32 characters.');
        }

        return hash_hmac('sha256', json_encode([
            $at->utc()->format('Y-m-d H'),
            (string) $request->ip(),
            (string) $request->userAgent(),
        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), $secret);
    }

    public function target(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return hash('sha256', $url);
        }
        // Preserve duplicate keys and significant spelling (parse_str would
        // collapse repeated parameters and turn dots/spaces into underscores).
        $query = [];
        foreach (explode('&', $parts['query'] ?? '') as $pair) {
            if ($pair === '') {
                continue;
            }
            $key = strtolower(rawurldecode(explode('=', $pair, 2)[0]));
            if (! str_starts_with($key, 'utm_') && ! in_array($key, ['preview', 'fbclid', 'gclid'], true)) {
                $query[] = $pair;
            }
        }
        sort($query);

        return hash('sha256', json_encode([
            strtolower($parts['scheme'] ?? ''),
            strtolower($parts['host'] ?? ''),
            $parts['port'] ?? null,
            $parts['path'] ?? '/',
            $query,
        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
