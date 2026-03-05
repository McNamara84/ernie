<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PidSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Background job for updating PID4INST instruments from b2inst API.
 *
 * Wraps the get-pid4inst-instruments artisan command and provides
 * progress tracking via cache for the frontend to poll.
 */
class UpdatePidJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The maximum number of seconds the job can run.
     *
     * ROR updates require downloading a large data dump (~500MB)
     * and may take longer than PID4INST updates.
     */
    public int $timeout = 1200; // 20 minutes

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    /**
     * Create a new job instance.
     *
     * @param  string  $pidType  The PID type (pid4inst)
     * @param  string  $jobId  Unique identifier for progress tracking (UUID format)
     *
     * @throws \InvalidArgumentException If pidType is invalid or jobId is not a valid UUID
     */
    public function __construct(
        private readonly string $pidType,
        private readonly string $jobId
    ) {
        if (! in_array($pidType, PidSetting::getValidTypes(), true)) {
            throw new \InvalidArgumentException(
                "Invalid PID type: {$pidType}. Valid types: " . implode(', ', PidSetting::getValidTypes())
            );
        }

        if (! Str::isUuid($jobId)) {
            throw new \InvalidArgumentException(
                "Invalid jobId format. Expected UUID, got: {$jobId}"
            );
        }
    }

    /**
     * Get the cache key for this job's status.
     */
    public static function getCacheKey(string $jobId): string
    {
        return "pid_update:{$jobId}";
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $cacheKey = self::getCacheKey($this->jobId);

        Log::info('Starting PID update job', [
            'job_id' => $this->jobId,
            'pid_type' => $this->pidType,
        ]);

        $progressMessage = match ($this->pidType) {
            PidSetting::TYPE_PID4INST => 'Fetching instruments from b2inst API...',
            PidSetting::TYPE_ROR => 'Fetching organizations from ROR data dump...',
            default => 'Fetching data...',
        };

        Cache::put($cacheKey, [
            'status' => 'running',
            'pidType' => $this->pidType,
            'progress' => $progressMessage,
            'startedAt' => now()->toIso8601String(),
        ], now()->addHours(1));

        try {
            $setting = PidSetting::where('type', $this->pidType)->firstOrFail();
            $command = $setting->getArtisanCommand();

            Log::info('Executing artisan command', [
                'job_id' => $this->jobId,
                'command' => $command,
            ]);

            $exitCode = Artisan::call($command);

            if ($exitCode === 0) {
                Log::info('PID update completed successfully', [
                    'job_id' => $this->jobId,
                    'pid_type' => $this->pidType,
                ]);

                Cache::put($cacheKey, [
                    'status' => 'completed',
                    'pidType' => $this->pidType,
                    'progress' => 'Update completed successfully',
                    'completedAt' => now()->toIso8601String(),
                ], now()->addHours(1));
            } else {
                $output = Artisan::output();

                Log::error('PID update command failed', [
                    'job_id' => $this->jobId,
                    'pid_type' => $this->pidType,
                    'exit_code' => $exitCode,
                    'output' => $output,
                ]);

                Cache::put($cacheKey, [
                    'status' => 'failed',
                    'pidType' => $this->pidType,
                    'progress' => 'Update failed',
                    'error' => "Command exited with code {$exitCode}",
                    'failedAt' => now()->toIso8601String(),
                ], now()->addHours(1));
            }
        } catch (\Exception $e) {
            Log::error('PID update job failed with exception', [
                'job_id' => $this->jobId,
                'pid_type' => $this->pidType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            Cache::put($cacheKey, [
                'status' => 'failed',
                'pidType' => $this->pidType,
                'progress' => 'Update failed',
                'error' => $e->getMessage(),
                'failedAt' => now()->toIso8601String(),
            ], now()->addHours(1));

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?\Throwable $exception): void
    {
        $cacheKey = self::getCacheKey($this->jobId);

        Log::error('PID update job failed', [
            'job_id' => $this->jobId,
            'pid_type' => $this->pidType,
            'error' => $exception?->getMessage(),
        ]);

        Cache::put($cacheKey, [
            'status' => 'failed',
            'pidType' => $this->pidType,
            'progress' => 'Update failed',
            'error' => $exception?->getMessage() ?? 'Unknown error',
            'failedAt' => now()->toIso8601String(),
        ], now()->addHours(1));
    }
}
