<?php

declare(strict_types=1);

namespace App\Services\SizeFormat;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

/** User-owned, one-time evidence for rows parsed from an uploaded metadata file. */
final readonly class ContentMetadataImportEvidenceService
{
    /**
     * Called only with server-parsed upload metadata, never an editor request payload.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function issue(array $metadata, ?int $userId): array
    {
        $records = [];
        foreach (['formats' => 'format', 'sizes' => 'size'] as $group => $kind) {
            foreach ($metadata[$group] ?? [] as $index => $row) {
                $key = (string) Str::uuid();
                $fields = $kind === 'format' ? ['value'] : ['numeric_value', 'unit', 'type'];
                $records[] = [
                    'id' => $key,
                    'user_id' => $userId,
                    'kind' => $kind,
                    'payload' => json_encode(array_intersect_key($row, array_flip($fields)), JSON_THROW_ON_ERROR),
                    'expires_at' => now()->addDay(),
                ];
                $metadata[$group][$index]['importKey'] = $key;
            }
        }
        if ($records !== []) {
            DB::table('content_metadata_import_rows')->where('expires_at', '<=', now())->delete();
            foreach (array_chunk($records, 500) as $chunk) {
                DB::table('content_metadata_import_rows')->insert($chunk);
            }
        }

        return $metadata;
    }

    /**
     * Consume evidence inside the resource write transaction. A failed save rolls
     * consumption back; concurrent saves cannot reuse the same row. Imports are
     * available only while creating a resource, as in the editor upload workflow.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, array{kind: string, row: array<string, mixed>}>
     */
    public function consume(array $data, ?int $userId, bool $isUpdate): array
    {
        if ($isUpdate) {
            return [];
        }
        $targets = [];
        foreach (['formats', 'sizes'] as $group) {
            foreach ($data[$group] ?? [] as $index => $row) {
                $key = $row['importKey'] ?? null;
                if (! is_string($key) || ! Str::isUuid($key)) {
                    continue;
                }
                $key = strtolower($key);
                if (isset($targets[$key])) {
                    throw ValidationException::withMessages(["{$group}.{$index}.importKey" => '[Format & Size] Each imported row can only be used once.']);
                }
                $targets[$key] = "{$group}.{$index}";
            }
        }
        if ($targets === []) {
            return [];
        }
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Import evidence must be consumed in the resource write transaction.');
        }
        $records = DB::table('content_metadata_import_rows')
            ->whereIn('id', array_keys($targets))
            ->where('user_id', $userId)
            ->where('expires_at', '>', now())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $evidence = [];
        foreach ($records as $record) {
            $evidence[$targets[$record->id]] = [
                'kind' => $record->kind,
                'row' => json_decode($record->payload, true, flags: JSON_THROW_ON_ERROR),
            ];
        }
        DB::table('content_metadata_import_rows')->whereIn('id', $records->pluck('id'))->delete();

        return $evidence;
    }
}
