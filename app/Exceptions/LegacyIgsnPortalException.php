<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class LegacyIgsnPortalException extends RuntimeException
{
    public const INVALID_CONFIGURATION = 'legacy_source_not_configured';

    public const UNAVAILABLE = 'legacy_source_unavailable';

    public const INVALID_PAYLOAD = 'legacy_invalid_payload';

    public function __construct(
        string $message,
        public readonly string $failureCode,
        public readonly bool $retryable,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    public static function invalidConfiguration(string $message): self
    {
        return new self($message, self::INVALID_CONFIGURATION, false);
    }

    public static function unavailable(string $message, ?Throwable $previous = null): self
    {
        return new self($message, self::UNAVAILABLE, true, $previous);
    }

    public static function invalidPayload(string $message): self
    {
        return new self($message, self::INVALID_PAYLOAD, true);
    }
}
