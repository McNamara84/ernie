<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DatabaseDumpExport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanupDatabaseDumps extends Command
{
    protected $signature = 'database-dumps:cleanup';

    protected $description = 'Delete expired database dump files and mark their exports as expired';

    public function handle(): int
    {
        $count = 0;

        DatabaseDumpExport::query()
            ->where('status', DatabaseDumpExport::STATUS_COMPLETED)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->each(function (DatabaseDumpExport $export) use (&$count): void {
                if ($export->path !== null) {
                    try {
                        Storage::disk($export->disk)->delete($export->path);
                    } catch (\Throwable $exception) {
                        Log::warning('Failed to delete expired database dump file during cleanup.', [
                            'export_id' => $export->id,
                            'disk' => $export->disk,
                            'path' => $export->path,
                            'exception' => $exception->getMessage(),
                        ]);
                    }
                }

                $export->forceFill(['status' => DatabaseDumpExport::STATUS_EXPIRED])->save();
                $count++;
            });

        $this->info("Expired {$count} database dump export(s).");

        return self::SUCCESS;
    }
}
