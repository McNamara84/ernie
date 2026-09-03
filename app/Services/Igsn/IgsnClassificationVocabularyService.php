<?php

declare(strict_types=1);

namespace App\Services\Igsn;

use App\Enums\Igsn\IgsnClassificationType;
use JsonException;
use RuntimeException;

class IgsnClassificationVocabularyService
{
    /** @var array<string, array<string, string>> */
    private array $lookups = [];

    public function normalize(IgsnClassificationType $type, string $value): ?string
    {
        $trimmed = self::clean($value);
        if ($trimmed === '' || strcasecmp($trimmed, 'N/A') === 0) {
            return null;
        }

        $canonical = $this->lookup($type)[self::key($trimmed)] ?? null;
        if ($canonical === null) {
            throw new \InvalidArgumentException(
                sprintf('Unsupported IGSN %s classification: %s', $type->value, $trimmed),
            );
        }

        return $canonical;
    }

    public function contains(IgsnClassificationType $type, string $value): bool
    {
        return isset($this->lookup($type)[self::key($value)]);
    }

    public function uniqueTypeFor(string $value): ?IgsnClassificationType
    {
        $matches = array_values(array_filter(
            IgsnClassificationType::cases(),
            fn (IgsnClassificationType $type): bool => $this->contains($type, $value),
        ));

        return count($matches) === 1 ? $matches[0] : null;
    }

    /** @return list<string> */
    public function values(IgsnClassificationType $type): array
    {
        return array_values($this->lookup($type));
    }

    /** @return array<string, string> */
    private function lookup(IgsnClassificationType $type): array
    {
        return $this->lookups[$type->value] ??= $this->load($type);
    }

    /** @return array<string, string> */
    private function load(IgsnClassificationType $type): array
    {
        $path = resource_path("data/igsn/classification-{$type->value}.json");
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Unable to read IGSN classification vocabulary: {$path}");
        }

        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Invalid IGSN classification vocabulary: {$path}", previous: $exception);
        }

        if (! is_array($data) || ! is_array($data['values'] ?? null)) {
            throw new RuntimeException("Invalid IGSN classification vocabulary structure: {$path}");
        }

        $lookup = [];
        foreach ($data['values'] as $entry) {
            $value = is_array($entry) ? ($entry['value'] ?? null) : $entry;
            if (! is_string($value) || self::clean($value) === '') {
                continue;
            }

            $canonical = self::clean($value);
            $key = self::key($canonical);
            if (isset($lookup[$key]) && $lookup[$key] !== $canonical) {
                throw new RuntimeException("Duplicate IGSN classification value after normalization: {$canonical}");
            }

            $lookup[$key] = $canonical;
        }

        return $lookup;
    }

    private static function clean(string $value): string
    {
        return preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    }

    private static function key(string $value): string
    {
        return mb_strtolower(self::clean($value));
    }
}
