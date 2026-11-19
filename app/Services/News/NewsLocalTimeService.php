<?php

declare(strict_types=1);

namespace App\Services\News;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class NewsLocalTimeService
{
    public const ZONE = 'Europe/Berlin';

    public function parse(string $local, ?string $offset, string $field): CarbonImmutable
    {
        // Resolve both possible Berlin offsets, never the server or browser's implicit timezone.
        $candidates = [];
        foreach (['+01:00', '+02:00'] as $candidateOffset) {
            $candidate = CarbonImmutable::createFromFormat('!Y-m-d\TH:iP', $local.$candidateOffset);
            if ($candidate instanceof CarbonImmutable && $candidate->setTimezone(self::ZONE)->format('Y-m-d\TH:iP') === $local.$candidateOffset) {
                $candidates[$candidateOffset] = $candidate->utc();
            }
        }
        if ($candidates === []) {
            throw ValidationException::withMessages([$field => 'This local time does not exist in Europe/Berlin. Choose a valid date and time.']);
        }
        if ($offset !== null && isset($candidates[$offset])) {
            return $candidates[$offset];
        }
        if ($offset !== null || count($candidates) > 1) {
            throw ValidationException::withMessages([$field.'_offset' => 'Choose the valid UTC offset: +02:00 (summer time) or +01:00 (standard time).']);
        }

        return array_values($candidates)[0];
    }
}
