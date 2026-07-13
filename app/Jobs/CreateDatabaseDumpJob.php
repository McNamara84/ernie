<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\DatabaseDumpExport;
use App\Services\DatabaseDumps\DatabaseDumpService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CreateDatabaseDumpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 7200;

    public int $tries = 1;

    public function __construct(
        public readonly string $exportId,
    ) {}

    public function handle(DatabaseDumpService $databaseDumpService): void
    {
        $export = DatabaseDumpExport::query()->find($this->exportId);

        if (! $export instanceof DatabaseDumpExport) {
            Log::warning('Database dump export disappeared before job execution.', [
                'export_id' => $this->exportId,
            ]);

            return;
        }

        $databaseDumpService->createDump($export);
    }

    public function failed(?\Throwable $exception): void
    {
        $export = DatabaseDumpExport::query()->find($this->exportId);

        if (! $export instanceof DatabaseDumpExport) {
            return;
        }

        $export->forceFill([
            'status' => DatabaseDumpExport::STATUS_FAILED,
            'finished_at' => now(),
            'error_message' => $exception?->getMessage() !== null
                ? str($exception->getMessage())->limit(1000)->toString()
                : 'Database dump job failed.',
        ])->save();
    }
}
