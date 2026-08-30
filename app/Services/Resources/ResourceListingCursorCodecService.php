<?php

declare(strict_types=1);

namespace App\Services\Resources;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

/** Encrypts and binds Laravel's cursor to one filter/sort context. */
final class ResourceListingCursorCodecService
{
    public function encode(string $cursor, string $contextFingerprint): string
    {
        return Crypt::encryptString(json_encode([
            'cursor' => $cursor,
            'context' => $contextFingerprint,
        ], JSON_THROW_ON_ERROR));
    }

    /** @throws ValidationException */
    public function decode(string $token, string $contextFingerprint): string
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw ValidationException::withMessages([
                'cursor' => ['The cursor is invalid or has expired.'],
            ]);
        }

        if (! is_array($payload)
            || ! is_string($payload['cursor'] ?? null)
            || ! is_string($payload['context'] ?? null)
            || ! hash_equals($contextFingerprint, $payload['context'])) {
            throw ValidationException::withMessages([
                'cursor' => ['The cursor does not match the active filters and sorting.'],
            ]);
        }

        return $payload['cursor'];
    }
}
