<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** Safe to persist in failed_jobs: never contains HTTP headers, bodies or previous exceptions. */
final class ElmoCacheRefreshException extends RuntimeException
{
    /** @param list<string> $failedCaches */
    public function __construct(
        string $message,
        public readonly bool $retryable = false,
        public readonly ?int $status = null,
        public readonly ?int $retryAfter = null,
        public readonly array $failedCaches = [],
    ) {
        parent::__construct($message);
    }
}
