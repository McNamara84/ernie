<?php

declare(strict_types=1);

namespace App\Services\Igsn\Export;

use RuntimeException;

final class IgsnCoreMetadataException extends RuntimeException
{
    /** @param list<string> $fields */
    public function __construct(public readonly array $fields)
    {
        parent::__construct('Required IGSN Core metadata is missing or unsupported.');
    }
}
