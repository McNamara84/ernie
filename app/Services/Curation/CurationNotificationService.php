<?php

declare(strict_types=1);

namespace App\Services\Curation;

use App\Mail\CurationNotification;
use App\Models\Resource;
use App\Models\ResourceCurationEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class CurationNotificationService
{
    public function dispatch(): void
    {
        if (! Schema::hasTable('resource_curation_notifications')) {
            return;
        }
        foreach (DB::table('resource_curation_notifications')->whereNull('queued_at')->orderBy('id')->limit(100)->pluck('id') as $id) {
            try {
                DB::transaction(function () use ($id): void {
                    $row = DB::table('resource_curation_notifications')->where('id', $id)->lockForUpdate()->first();
                    if ($row === null || $row->queued_at !== null) {
                        return;
                    }
                    $event = ResourceCurationEvent::find((int) $row->event_id);
                    $user = User::find((int) $row->user_id);
                    $resource = $event === null ? null : Resource::find($event->resource_id);
                    if ($event !== null && $event->deleted_at === null && $user?->is_active && $resource !== null) {
                        Mail::to($user->email)->queue((new CurationNotification(
                            $resource->main_title ?? 'Untitled Resource',
                            route('editor', ['resourceId' => $resource->id]),
                            $event->body ?? '', $event->actor_name, $row->type,
                        ))->onConnection('curation'));
                    }
                    DB::table('resource_curation_notifications')->where('id', $id)->update(['queued_at' => now()]);
                });
            } catch (Throwable $exception) {
                report($exception); // The durable row is retried by the scheduler.
            }
        }
    }
}
