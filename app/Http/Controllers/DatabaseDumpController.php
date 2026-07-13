<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\CreateDatabaseDumpJob;
use App\Models\DatabaseDumpExport;
use App\Models\User;
use App\Services\DatabaseDumps\DatabaseDumpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DatabaseDumpController extends Controller
{
    public function __construct(
        private readonly DatabaseDumpService $databaseDumpService,
    ) {}

    public function index(): Response
    {
        return Inertia::render('database', [
            'targets' => $this->targetPayload(),
        ]);
    }

    public function store(Request $request, string $target): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! array_key_exists($target, $this->databaseDumpService->targets())) {
            abort(404);
        }

        $lock = Cache::lock("database-dumps:user:{$user->id}", 10);

        if (! $lock->get()) {
            return response()->json([
                'message' => 'Another database dump request is already being prepared. Please try again shortly.',
            ], 409);
        }

        try {
            $targetConfig = $this->databaseDumpService->target($target);
            $maxParallel = max(1, (int) config('database_dumps.max_parallel_per_user', 1));
            $activeCount = DatabaseDumpExport::query()
                ->activeForUser($user->id)
                ->count();

            if ($activeCount >= $maxParallel) {
                return response()->json([
                    'message' => 'Another database dump is already running. Please wait for it to finish.',
                ], 409);
            }

            $disk = (string) config('database_dumps.disk', 'local');

            try {
                $this->databaseDumpService->assertLocalDisk($disk);
            } catch (\RuntimeException $exception) {
                return response()->json([
                    'message' => $exception->getMessage(),
                ], 500);
            }

            $export = DatabaseDumpExport::query()->create([
                'user_id' => $user->id,
                'target_key' => $target,
                'target_label' => (string) $targetConfig['label'],
                'connection_name' => (string) $targetConfig['connection'],
                'database_name' => $this->databaseDumpService->databaseNameForTarget($targetConfig),
                'status' => DatabaseDumpExport::STATUS_PENDING,
                'disk' => $disk,
                'requested_at' => now(),
                'expires_at' => now()->addHours(max(1, (int) config('database_dumps.expiry_hours', 24))),
            ]);

            $export->forceFill([
                'path' => $this->databaseDumpService->buildPath($export),
            ])->save();
            $export->forceFill([
                'filename' => basename((string) $export->path),
            ])->save();

            CreateDatabaseDumpJob::dispatch($export->id);

            return response()->json([
                'export' => $this->exportPayload($export->fresh() ?? $export),
            ], 202);
        } finally {
            $lock->release();
        }
    }

    public function status(DatabaseDumpExport $export): JsonResponse
    {
        return response()->json([
            'export' => $this->exportPayload($this->markExpiredIfNeeded($export)),
        ]);
    }

    public function download(Request $request, DatabaseDumpExport $export): BinaryFileResponse|JsonResponse
    {
        $export = $this->markExpiredIfNeeded($export);

        if ($export->status === DatabaseDumpExport::STATUS_EXPIRED || $export->isExpired()) {
            return response()->json(['message' => 'This database dump has expired.'], HttpResponse::HTTP_GONE);
        }

        if (! $export->isDownloadable()) {
            return response()->json(['message' => 'This database dump is not ready for download.'], 409);
        }

        $disk = Storage::disk($export->disk);
        $path = $export->path;

        if (! is_string($path) || ! $disk->exists($path)) {
            return response()->json(['message' => 'The database dump file could not be found.'], 404);
        }

        /** @var User $user */
        $user = $request->user();

        $export->downloads()->create([
            'user_id' => $user->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'downloaded_at' => now(),
        ]);

        $export->increment('download_count', 1, [
            'last_downloaded_at' => now(),
        ]);

        return response()->download(
            $disk->path($path),
            $export->filename ?? basename($path),
            ['Content-Type' => 'application/gzip'],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function targetPayload(): array
    {
        $payload = [];

        foreach ($this->databaseDumpService->targets() as $key => $target) {
            $latestExport = DatabaseDumpExport::query()
                ->where('target_key', $key)
                ->latest('requested_at')
                ->first();

            $payload[] = [
                'key' => $key,
                'label' => (string) $target['label'],
                'description' => (string) ($target['description'] ?? ''),
                'connection' => (string) $target['connection'],
                'database' => $this->safeDatabaseName($target),
                'legacy' => (bool) ($target['legacy'] ?? false),
                'requiresLegacySslProbe' => (bool) ($target['requires_legacy_ssl_probe'] ?? false),
                'serverVersionHint' => $target['server_version_hint'] ?? null,
                'latestExport' => $latestExport instanceof DatabaseDumpExport
                    ? $this->exportPayload($this->markExpiredIfNeeded($latestExport))
                    : null,
            ];
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function exportPayload(DatabaseDumpExport $export): array
    {
        return [
            'id' => $export->id,
            'targetKey' => $export->target_key,
            'targetLabel' => $export->target_label,
            'connectionName' => $export->connection_name,
            'databaseName' => $export->database_name,
            'status' => $export->status,
            'filename' => $export->filename,
            'sizeBytes' => $export->size_bytes,
            'sha256' => $export->sha256,
            'serverVersion' => $export->server_version,
            'dumpClient' => $export->dump_client,
            'errorMessage' => $export->error_message,
            'requestedAt' => $export->requested_at?->toIso8601String(),
            'startedAt' => $export->started_at?->toIso8601String(),
            'finishedAt' => $export->finished_at?->toIso8601String(),
            'expiresAt' => $export->expires_at?->toIso8601String(),
            'downloadCount' => $export->download_count,
            'lastDownloadedAt' => $export->last_downloaded_at?->toIso8601String(),
            'downloadUrl' => $export->isDownloadable()
                ? route('database.dumps.download', $export)
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private function safeDatabaseName(array $target): ?string
    {
        try {
            return $this->databaseDumpService->databaseNameForTarget($target);
        } catch (\Throwable) {
            return null;
        }
    }

    private function markExpiredIfNeeded(DatabaseDumpExport $export): DatabaseDumpExport
    {
        if ($export->status === DatabaseDumpExport::STATUS_COMPLETED && $export->isExpired()) {
            $export->forceFill(['status' => DatabaseDumpExport::STATUS_EXPIRED])->save();
        }

        return $export;
    }
}
