<?php

declare(strict_types=1);

namespace App\Services\DataCite\Mapping;

final class DataCiteDescriptionMappingService
{
    /**
     * Split a canonical plain-text description into DataCite text segments.
     *
     * Empty segments are intentionally retained so consecutive, leading, and
     * trailing line breaks can be serialized without data loss.
     *
     * @return list<string>
     */
    public function segments(string $description): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $description);

        return explode("\n", $normalized);
    }

    /**
     * Serialize canonical line breaks for the DataCite JSON/API representation.
     */
    public function toJsonValue(string $description): string
    {
        $segments = array_map(
            fn (string $segment): string => $this->escapeTagLikeLessThan($segment),
            $this->segments($description),
        );

        return implode('<br>', $segments);
    }

    /**
     * Restore canonical line breaks for non-DataCite derivative formats.
     */
    public function fromJsonValue(string $description): string
    {
        return str_replace('<br>', chr(10), $description);
    }

    private function escapeTagLikeLessThan(string $description): string
    {
        return preg_replace('/<(?=\/?[A-Za-z]|[!?])/', '&lt;', $description) ?? $description;
    }
}
