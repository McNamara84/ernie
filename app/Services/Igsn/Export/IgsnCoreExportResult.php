<?php

declare(strict_types=1);

namespace App\Services\Igsn\Export;

final readonly class IgsnCoreExportResult
{
    /** @param list<string> $omissions */
    public function __construct(public string $xml, public array $omissions) {}
}
