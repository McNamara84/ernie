<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\AssessmentFailureType;
use RuntimeException;
use Throwable;

final class FujiAssessmentException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable,
        public readonly ?int $httpStatus = null,
        public readonly ?int $retryAfterSeconds = null,
        public readonly AssessmentFailureType $failureType = AssessmentFailureType::SERVICE,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorDetail = null,
        public readonly ?int $durationMs = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
