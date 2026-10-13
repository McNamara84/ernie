<?php

declare(strict_types=1);

namespace App\Services\OaiPmh;

use App\Models\Resource;
use Illuminate\Database\Eloquent\Builder;

final class OaiPmhDatestampService
{
    /** Update synchronously with the metadata, without triggering Resource observers. */
    public function touchResource(int $resourceId): void
    {
        $datestamp = now()->startOfSecond();

        Resource::query()->whereKey($resourceId)
            ->where(fn (Builder $query) => $query->whereNull('updated_at')->orWhere('updated_at', '<', $datestamp))
            ->update(['updated_at' => $datestamp]);
    }
}
