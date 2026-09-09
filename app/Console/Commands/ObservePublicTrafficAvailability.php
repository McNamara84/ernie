<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PublicTraffic\PublicTrafficAvailabilityService;
use App\Services\PublicTraffic\PublicTrafficWarningLoggerService;
use Illuminate\Console\Command;
use Throwable;

final class ObservePublicTrafficAvailability extends Command
{
    protected $signature = 'public-traffic:observe-availability';

    protected $description = 'Confirm one minute of public traffic analytics availability';

    public function handle(
        PublicTrafficAvailabilityService $availability,
        PublicTrafficWarningLoggerService $warningLogger,
    ): int {
        if (! config('public_traffic.enabled')) {
            $this->components->info('Public traffic analytics are disabled.');

            return self::SUCCESS;
        }

        try {
            $statistic = $availability->observe();
        } catch (Throwable $exception) {
            $warningLogger->warning(
                'availability',
                'Failed to observe public traffic analytics availability.',
                $exception,
            );
            $this->components->error('Public traffic analytics availability could not be confirmed.');

            return self::FAILURE;
        }

        $this->components->info(
            "Observed public traffic analytics availability for {$statistic?->last_observed_minute_at?->toIso8601String()}."
        );

        return self::SUCCESS;
    }
}
