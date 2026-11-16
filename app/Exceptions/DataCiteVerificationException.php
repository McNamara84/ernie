<?php

declare(strict_types=1);

namespace App\Exceptions;

final class DataCiteVerificationException extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }
}
