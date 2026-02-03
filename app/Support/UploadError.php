<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\UploadErrorCode;

/**
 * Standardized upload error response structure.
 *
 * Provides a consistent format for upload errors that can be serialized
 * to JSON for frontend display and logged for admin visibility.
 */
final readonly class UploadError
{
    public function __construct(
        public UploadErrorCode $code,
        public ?string $customMessage = null,
        public ?string $field = null,
        public ?int $row = null,
        public ?string $identifier = null,
    ) {}

    /**
     * Create an UploadError from an error code with default message.
     */
    public static function fromCode(UploadErrorCode $code): self
    {
        return new self($code);
    }

    /**
     * Create an UploadError with a custom message.
     */
    public static function withMessage(UploadErrorCode $code, string $message): self
    {
        return new self($code, $message);
    }

    /**
     * Create an UploadError for a specific CSV row.
     */
    public static function forRow(UploadErrorCode $code, int $row, string $identifier, ?string $message = null): self
    {
        return new self($code, $message, null, $row, $identifier);
    }

    /**
     * Create an UploadError for a specific field.
     */
    public static function forField(UploadErrorCode $code, string $field, ?string $message = null): self
    {
        return new self($code, $message, $field);
    }

    /**
     * Get the error message (custom or default from code).
     */
    public function message(): string
    {
        return $this->customMessage ?? $this->code->message();
    }

    /**
     * Get the error category.
     */
    public function category(): string
    {
        return $this->code->category();
    }

    /**
     * Convert to array for JSON response.
     *
     * @return array{category: string, code: string, message: string, field: string|null, row: int|null, identifier: string|null}
     */
    public function toArray(): array
    {
        return [
            'category' => $this->code->category(),
            'code' => $this->code->value,
            'message' => $this->message(),
            'field' => $this->field,
            'row' => $this->row,
            'identifier' => $this->identifier,
        ];
    }

    /**
     * Convert legacy error format to UploadError.
     *
     * @param array{row?: int, igsn?: string, message?: string} $legacyError
     */
    public static function fromLegacyError(array $legacyError, UploadErrorCode $code): self
    {
        return new self(
            code: $code,
            customMessage: $legacyError['message'] ?? null,
            row: $legacyError['row'] ?? null,
            identifier: $legacyError['igsn'] ?? null,
        );
    }
}
