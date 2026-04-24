<?php

declare(strict_types=1);

namespace App\Services\Citations;

/**
 * Immutable DTO representing the result of a DOI lookup against either
 * Crossref or the DataCite API. The `data` array matches the schema the
 * React Citation Manager form expects (camelCased sub-fields).
 */
final readonly class CitationLookupResult
{
    /**
     * @param array<string, mixed>|null $data
     */
    public function __construct(
        public string $source,
        public bool $found,
        public ?array $data = null,
        public ?string $error = null,
    ) {}

    public static function notFound(string $source): self
    {
        return new self($source, false);
    }

    public static function error(string $source, string $message): self
    {
        return new self($source, false, null, $message);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function hit(string $source, array $data): self
    {
        return new self($source, true, $data);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'found' => $this->found,
            'data' => $this->data,
            'error' => $this->error,
        ];
    }
}
