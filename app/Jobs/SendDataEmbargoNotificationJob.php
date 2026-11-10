<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\DataEmbargoExpired;
use App\Models\Resource;
use App\Services\DataPublicationTeamRecipientService;
use App\Services\EmbargoService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class SendDataEmbargoNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $notificationId) {}

    public function handle(DataPublicationTeamRecipientService $recipients, EmbargoService $policy): void
    {
        $recipient = $recipients->email(true);
        if ($recipient === null) {
            return; // Keep pending so corrected configuration is picked up by the next scan.
        }
        $notice = DB::table('data_embargo_notifications')->where('id', $this->notificationId)->first();
        if ($notice === null || $notice->status !== 'pending') {
            return;
        }
        $resource = Resource::query()->with(['dates.dateType', 'landingPage', 'titles.titleType'])->find((int) $notice->resource_id);
        if ($resource === null || ! $policy->isDue($resource) || $policy->availableDate($resource) !== $notice->available_date) {
            return; // A postponed or released embargo must not send an obsolete notice.
        }
        if (DB::table('data_embargo_notifications')->where('id', $this->notificationId)->where('status', 'pending')
            ->update(['status' => 'sending', 'claimed_at' => now(), 'updated_at' => now()]) !== 1) {
            return;
        }
        try {
            Mail::to($recipient)->send(new DataEmbargoExpired($resource, (string) $notice->available_date));
            DB::table('data_embargo_notifications')->where('id', $this->notificationId)
                ->update(['status' => 'sent', 'sent_at' => now(), 'last_error' => null, 'updated_at' => now()]);
        } catch (Throwable $exception) {
            // SMTP may have accepted the message before the connection failed. Never retry blindly.
            DB::table('data_embargo_notifications')->where('id', $this->notificationId)
                ->update(['status' => 'uncertain', 'last_error' => $exception->getMessage(), 'updated_at' => now()]);
            Log::error('Data embargo email delivery is uncertain; inspect before retrying.', ['notification_id' => $this->notificationId, 'error' => $exception->getMessage()]);
        }
    }
}
