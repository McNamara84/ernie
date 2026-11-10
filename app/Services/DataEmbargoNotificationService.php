<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccessLevel;
use App\Jobs\SendDataEmbargoNotificationJob;
use App\Models\Resource;
use Illuminate\Support\Facades\DB;

final class DataEmbargoNotificationService
{
    public function dispatchDue(): int
    {
        $count = 0;
        Resource::query()->where('access_level', AccessLevel::EMBARGOED->value)
            ->with(['dates.dateType', 'landingPage'])
            ->chunkById(200, function ($resources) use (&$count): void {
                foreach ($resources as $resource) {
                    $policy = app(EmbargoService::class);
                    if (! $policy->isDue($resource)) {
                        continue;
                    }
                    $date = $policy->availableDate($resource);
                    DB::table('data_embargo_notifications')->insertOrIgnore([
                        'resource_id' => $resource->id, 'available_date' => $date,
                        'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $notification = DB::table('data_embargo_notifications')->where('resource_id', $resource->id)->where('available_date', $date)->first();
                    if ($notification !== null && $notification->status === 'pending') {
                        SendDataEmbargoNotificationJob::dispatch((int) $notification->id)->afterCommit();
                        $count++;
                    }
                }
            });

        return $count;
    }
}
