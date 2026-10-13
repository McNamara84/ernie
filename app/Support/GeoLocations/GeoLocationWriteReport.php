<?php

declare(strict_types=1);

namespace App\Support\GeoLocations;

/** One report per operation, passed explicitly through import/save calls. */
final class GeoLocationWriteReport
{
    public int $removedCount = 0;

    public function record(GeoLocationWriteResult $result): void
    {
        $this->removedCount += (int) $result->duplicate;
    }

    public function mergeWarning(string $identifier): string
    {
        return sprintf('%s: %d identical GeoLocation %s merged.', $identifier, $this->removedCount,
            $this->removedCount === 1 ? 'entry was' : 'entries were');
    }
}
