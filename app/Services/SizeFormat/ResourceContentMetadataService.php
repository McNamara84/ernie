<?php

declare(strict_types=1);

namespace App\Services\SizeFormat;

use App\Models\Format;
use App\Models\Resource;
use App\Models\Size;
use Illuminate\Validation\ValidationException;

/** Resource-level metadata shared by the editor, uploads, and the assistance workflow. */
final readonly class ResourceContentMetadataService
{
    public const UNITS = ['B', 'kB', 'MB', 'GB', 'TB', 'PB', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'];

    public function __construct(private MimeTypeRegistry $mimeTypes, private ContentMetadataImportEvidenceService $imports) {}

    public static function number(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = str_replace(',', '.', trim($value));
        if (preg_match('/\A\d{1,16}(?:\.\d{1,4})?\z/', $value) !== 1) {
            return null;
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';
        $fraction = rtrim($fraction, '0');

        return $whole.($fraction === '' ? '' : '.'.$fraction);
    }

    public static function unit(?string $value): ?string
    {
        return match ($value) {
            'bytes', 'byte' => 'B',
            'KB' => 'kB',
            default => $value,
        };
    }

    public function editable(Size $size, Resource $resource): bool
    {
        $number = self::number($size->numeric_value);

        return ! $resource->isIgsn() && $number !== null && $number !== '0'
            && in_array(self::unit($size->unit), self::UNITS, true);
    }

    /** @return array{formats: list<array{id: int, value: string}>, sizes: list<array{id: int, numeric_value: string|null, unit: string|null, type: string|null, editable: bool, label: string}>, fingerprint: string} */
    public function state(Resource $resource): array
    {
        $resource->loadMissing(['resourceType', 'formats', 'sizes']);
        $formats = array_values($resource->formats->sortBy('id')->map(static fn (Format $format): array => [
            'id' => $format->id,
            'value' => $format->value,
        ])->all());
        $sizes = array_values($resource->sizes->sortBy('id')->map(fn (Size $size): array => [
            'id' => $size->id,
            'numeric_value' => $size->numeric_value,
            'unit' => $size->unit,
            'type' => $size->type,
            'editable' => $this->editable($size, $resource),
            'label' => $size->export_string,
        ])->all());

        return [
            'formats' => $formats,
            'sizes' => $sizes,
            'fingerprint' => hash('sha256', json_encode([$formats, array_map(static fn (array $size): array => array_intersect_key($size, array_flip(['id', 'numeric_value', 'unit', 'type'])), $sizes)], JSON_THROW_ON_ERROR)),
        ];
    }

    /** @param array<string, mixed> $data */
    public function sync(Resource $resource, array $data, bool $isUpdate, ?int $userId): void
    {
        if (($data['contentMetadataChanged'] ?? true) === false || (! array_key_exists('formats', $data) && ! array_key_exists('sizes', $data))) {
            return;
        }
        $resource->load(['formats', 'sizes']);
        if ($isUpdate && ($data['contentMetadataFingerprint'] ?? null) !== $this->state($resource)['fingerprint']) {
            $this->fail('contentMetadataFingerprint', 'Format or size metadata changed in another editor or assistant. Reload the resource and review your changes.');
        }
        $evidence = $this->imports->consume($data, $userId, $isUpdate);
        if (array_key_exists('formats', $data)) {
            $this->syncFormats($resource, $data['formats'], $evidence);
        }
        if (array_key_exists('sizes', $data)) {
            $this->syncSizes($resource, $data['sizes'], $evidence);
        }
        $resource->unsetRelation('formats')->unsetRelation('sizes');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, array{kind: string, row: array<string, mixed>}>  $evidence
     */
    private function syncFormats(Resource $resource, array $rows, array $evidence): void
    {
        $retained = [];
        $seen = [];
        foreach ($rows as $index => $row) {
            $rawValue = (string) ($row['value'] ?? '');
            $value = trim($rawValue);
            if ($value === '' && empty($row['id'])) {
                continue;
            }
            $format = isset($row['id']) ? $resource->formats->find((int) $row['id']) : null;
            if (isset($row['id']) && $format === null) {
                $this->fail("formats.{$index}.id", 'This format does not belong to this resource.');
            }
            $unchanged = $format !== null && $value === trim($format->value);
            if ($unchanged) {
                $value = $format->value;
            }
            $imported = $format === null && $this->importMatches('format', $row, $evidence["formats.{$index}"] ?? null);
            if (! $unchanged && ! $this->mimeTypes->contains($value) && ! $imported) {
                $this->fail("formats.{$index}.value", 'Select an officially registered IANA MIME type.');
            }
            $normalized = $unchanged || $imported ? $value : strtolower($value);
            $key = SizeFormatFormatNormalizerService::normalize($normalized);
            if (isset($seen[$key]) && (! $unchanged || ! $seen[$key])) {
                $this->fail("formats.{$index}.value", 'This format is already present.');
            }
            $seen[$key] = $unchanged;
            $format ??= new Format(['resource_id' => $resource->id]);
            $format->value = $normalized;
            if (! $format->exists || $format->isDirty()) {
                $format->save();
            }
            $retained[] = $format->id;
        }
        foreach ($resource->formats as $format) {
            if (! in_array($format->id, $retained, true)) {
                $format->delete();
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, array{kind: string, row: array<string, mixed>}>  $evidence
     */
    private function syncSizes(Resource $resource, array $rows, array $evidence): void
    {
        $retained = [];
        foreach ($rows as $index => $row) {
            $size = isset($row['id']) ? $resource->sizes->find((int) $row['id']) : null;
            if (isset($row['id']) && $size === null) {
                $this->fail("sizes.{$index}.id", 'This size does not belong to this resource.');
            }
            $raw = [
                'numeric_value' => $row['numeric_value'] ?? null,
                'unit' => $row['unit'] ?? null,
                'type' => $row['type'] ?? null,
            ];
            if ($size === null && ($raw['numeric_value'] === null || $raw['numeric_value'] === '') && ($raw['unit'] === null || $raw['unit'] === '')) {
                continue;
            }
            $stored = $size === null ? null : ['numeric_value' => $size->numeric_value, 'unit' => $size->unit, 'type' => $size->type];
            $trim = static fn (mixed $value): string => trim((string) $value);
            $unchanged = $stored !== null && array_map($trim, $raw) === array_map($trim, $stored);
            if ($unchanged) {
                $raw = $stored;
            } elseif ($size !== null && $trim($raw['type']) === $trim($size->type)) {
                $raw['type'] = $size->type;
            }
            if ($size !== null && ! $this->editable($size, $resource) && ! $unchanged) {
                $this->fail("sizes.{$index}.numeric_value", 'This imported or physical size must be preserved unchanged.');
            }
            $source = $evidence["sizes.{$index}"] ?? null;
            $imported = $size === null && $this->importMatches('size', $row, $source);
            $importedType = $source !== null && $source['kind'] === 'size' ? ($source['row']['type'] ?? null) : null;
            if (! $unchanged && ! $imported) {
                $number = self::number($raw['numeric_value']);
                if ($number === null || $number === '0') {
                    $this->fail("sizes.{$index}.numeric_value", 'Enter a positive number with up to 16 integer digits and 4 decimal places.');
                }
                if (! in_array(self::unit($raw['unit']), self::UNITS, true)) {
                    $this->fail("sizes.{$index}.unit", 'Select a supported digital size unit.');
                }
                if ($resource->isIgsn()) {
                    $this->fail("sizes.{$index}.unit", 'Physical objects do not use digital size metadata.');
                }
                if (($size !== null && $raw['type'] !== $size->type)
                    || ($size === null && $raw['type'] !== null && $raw['type'] !== $importedType)) {
                    $this->fail("sizes.{$index}.type", 'The meaning of this size must be preserved.');
                }
                $raw['numeric_value'] = $number;
            }
            $size ??= new Size(['resource_id' => $resource->id]);
            $size->fill($raw);
            if (! $size->exists || $size->isDirty()) {
                $size->save();
            }
            $retained[] = $size->id;
        }
        foreach ($resource->sizes as $size) {
            if ($this->editable($size, $resource) && ! in_array($size->id, $retained, true)) {
                $size->delete();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{kind: string, row: array<string, mixed>}|null  $source
     */
    private function importMatches(string $kind, array $row, ?array $source): bool
    {
        if ($source === null || $source['kind'] !== $kind) {
            return false;
        }
        $fields = $kind === 'format' ? ['value'] : ['numeric_value', 'unit', 'type'];
        $values = array_intersect_key($row, array_flip($fields));
        $original = $source['row'];
        ksort($original);
        ksort($values);

        return $original === $values;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => '[Format & Size] '.$message]);
    }
}
