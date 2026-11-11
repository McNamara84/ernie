<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccessLevel;
use App\Jobs\SendDataEmbargoNotificationJob;
use App\Models\Resource;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DataEmbargoNotificationService
{
    public function dispatchDue(): int
    {
        $count = 0;
        $hasRecipient = app(DataPublicationTeamRecipientService::class)->email(true) !== null;
        Resource::query()->where('access_level', AccessLevel::EMBARGOED->value)
            ->with(['dates.dateType', 'landingPage'])
            ->chunkById(200, function ($resources) use (&$count, $hasRecipient): void {
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
                    if (! $hasRecipient || $notification === null || $notification->status !== 'pending') {
                        continue;
                    }
                    $notificationId = (int) $notification->id;
                    if (DB::table('data_embargo_notifications')->where('id', $notificationId)->where('status', 'pending')
                        ->update(['status' => 'queued', 'updated_at' => now()]) !== 1) {
                        continue;
                    }
                    // Reserve durably before dispatch, including delayed queues.
                    // The callback itself handles broker failures after commit.
                    DB::afterCommit(static function () use ($notificationId): void {
                        try {
                            Bus::dispatch(new SendDataEmbargoNotificationJob($notificationId));
                        } catch (Throwable $exception) {
                            DB::table('data_embargo_notifications')->where('id', $notificationId)->where('status', 'queued')
                                ->update(['status' => 'pending', 'updated_at' => now()]);
                            throw $exception;
                        }
                    });
                    $count++;
                }
            });

        return $count;
    }
}
