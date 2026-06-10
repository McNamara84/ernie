<?php

declare(strict_types=1);

namespace App\Services\Spdx;

use App\Models\Right;

/**
 * Small value object for one SPDX catalog entry.
 *
 * The database model `Right` contains many application concerns (activation
 * flags, usage counters, resource-type exclusions). The matcher only needs the
 * stable catalog facts, so we copy those facts into this simple object before
 * matching imported rights text.
 */
final readonly class SpdxLicenseData
{
    public function __construct(
        public string $identifier,
        public string $name,
        public ?string $rightsUri,
        public ?string $schemeUri,
    ) {}

    public static function fromRight(Right $right): self
    {
        return new self(
            identifier: $right->identifier,
            name: $right->name,
            rightsUri: $right->uri,
            schemeUri: $right->scheme_uri,
        );
    }
}
