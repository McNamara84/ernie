<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OldDataset;

class LegacyResourceLookupService
{
    public function existsByDoi(string $doi): bool
    {
        return OldDataset::query()
            ->whereRaw('LOWER(identifier) = ?', [strtolower(trim($doi))])
            ->exists();
    }
}

