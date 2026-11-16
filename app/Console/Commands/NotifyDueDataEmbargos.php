<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DataEmbargoNotificationService;
use Illuminate\Console\Command;

final class NotifyDueDataEmbargos extends Command
{
    protected $signature = 'embargo:notify-due';

    protected $description = 'Queue one team email per resource and due Available day without releasing data';

    public function handle(DataEmbargoNotificationService $notifications): int
    {
        $this->info('Queued '.$notifications->dispatchDue().' data embargo notifications.');

        return self::SUCCESS;
    }
}
