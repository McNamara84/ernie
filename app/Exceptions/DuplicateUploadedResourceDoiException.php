<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class DuplicateUploadedResourceDoiException extends RuntimeException
{
    public function __construct(
        public readonly string $doi,
        public readonly int $resourceId,
    ) {
        parent::__construct("The uploaded DOI {$doi} already exists on resource {$resourceId}.");
    }
}
