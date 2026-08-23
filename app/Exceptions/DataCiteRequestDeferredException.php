<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class DataCiteRequestDeferredException extends RuntimeException
{
    public function __construct(
        public readonly int $retryAfterMilliseconds,
    ) {
        parent::__construct('The shared DataCite request limit is currently exhausted.');
    }
}
