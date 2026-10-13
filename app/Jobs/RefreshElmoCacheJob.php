<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\EditorContext;
use App\Exceptions\ElmoCacheRefreshException;
use App\Services\ElmoCacheRefreshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RefreshElmoCacheJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout;

    public function __construct(
        public readonly EditorContext $editor,
        public readonly string $trigger,
        public readonly string $correlationId,
    ) {
        $this->timeout = (int) config('elmo.job_timeout');
        $this->onConnection('elmo')->onQueue('elmo-cache-refresh');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(ElmoCacheRefreshService $service): void
    {
        if (! $service->isEnabled($this->editor)) {
            Log::info('ELMO cache refresh skipped: target is not enabled.', $this->context());

            return;
        }

        $started = microtime(true);
        try {
            $service->refresh($this->editor);
        } catch (ElmoCacheRefreshException $exception) {
            Log::warning('ELMO cache refresh attempt failed.', [
                ...$this->context(),
                'http_status' => $exception->status,
                'failed_caches' => $exception->failedCaches,
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
            ]);
            if (! $exception->retryable) {
                $this->fail($exception);

                return;
            }
            if ($exception->retryAfter !== null && $this->attempts() < $this->tries) {
                $this->release($exception->retryAfter);

                return;
            }

            throw $exception;
        }

        Log::info('ELMO cache refresh completed.', [
            ...$this->context(),
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('ELMO cache refresh failed permanently.', [
            ...$this->context(),
            'http_status' => $exception instanceof ElmoCacheRefreshException ? $exception->status : null,
        ]);
    }

    /** @return array{editor: string, trigger: string, correlation_id: string, attempt: int} */
    private function context(): array
    {
        return ['editor' => $this->editor->value, 'trigger' => $this->trigger, 'correlation_id' => $this->correlationId, 'attempt' => $this->attempts()];
    }
}
