<?php

declare(strict_types=1);

namespace App\Services\Igsn\Export;

use DOMDocument;

final readonly class IgsnCoreSource
{
    /** @param list<string> $omissions */
    public function __construct(public DOMDocument $document, public array $omissions) {}
}
