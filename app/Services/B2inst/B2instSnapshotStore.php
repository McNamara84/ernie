<?php

declare(strict_types=1);

namespace App\Services\B2inst;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;

class B2instSnapshotStore
{
    public const FILE = 'pid4inst-instruments.json';

    /** @return array<string, mixed>|null */
    public function read(): ?array
    {
        if (! Storage::exists(self::FILE)) {
            return null;
        }
        $payload = json_decode(Storage::get(self::FILE) ?? '', true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($payload) || ! is_array($payload['data'] ?? null) || ! array_is_list($payload['data'])) {
            throw new RuntimeException('Invalid existing B2INST snapshot; restore a valid backup first.');
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    public function publish(array $payload, bool $backup = false): void
    {
        // Local Flysystem move uses rename on the same filesystem.
        if (! (Storage::disk()->getAdapter() instanceof LocalFilesystemAdapter)) {
            throw new RuntimeException('B2INST snapshots require a local filesystem for atomic replacement.');
        }
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $temporary = self::FILE.'.'.Str::uuid().'.tmp';
        try {
            if (! Storage::put($temporary, $json) || Storage::get($temporary) !== $json) {
                throw new RuntimeException('Could not write and verify the B2INST candidate snapshot.');
            }
            if ($backup && Storage::exists(self::FILE) && ! Storage::copy(self::FILE, self::FILE.'.previous')) {
                throw new RuntimeException('Could not preserve the previous B2INST snapshot.');
            }
            if (! Storage::move($temporary, self::FILE)) {
                throw new RuntimeException('Could not atomically publish the B2INST snapshot.');
            }
        } finally {
            try {
                Storage::delete($temporary);
            } catch (\Throwable $exception) {
                Log::warning('Could not remove B2INST temporary file.', ['path' => $temporary, 'error' => $exception->getMessage()]);
            }
        }
    }
}
