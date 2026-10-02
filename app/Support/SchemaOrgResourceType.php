<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\SchemaOrgProfile;

final readonly class SchemaOrgResourceType
{
    /** @param list<string> $additionalTypes */
    public function __construct(
        public string $primaryType,
        public array $additionalTypes,
        public SchemaOrgProfile $profile,
        public ?string $fallbackReason,
        public ?string $additionalType,
    ) {}

    /** @return string|list<string> */
    public function jsonLdType(): string|array
    {
        $types = array_map(
            static fn (string $type): string => str_replace('https://schema.org/', '', $type),
            [$this->primaryType, ...$this->additionalTypes],
        );

        return count($types) === 1 ? $types[0] : $types;
    }
}
