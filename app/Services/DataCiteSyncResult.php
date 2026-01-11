<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Data Transfer Object for DataCite synchronization results.
 *
 * Encapsulates the outcome of an automatic DataCite sync attempt,
 * including success/failure status and any error details.
 *
 * This DTO is used by DataCiteSyncService to communicate sync results
 * back to the controller without throwing exceptions for expected failures
 * (like API timeouts or validation errors).
 *
 * @see DataCiteSyncService::syncIfRegistered()
 */
final readonly class DataCiteSyncResult
{
    /**
     * @param  bool  $attempted  Whether a sync attempt was made (false if resource has no DOI)
     * @param  bool  $success  Whether the sync completed successfully
     * @param  string|null  $errorMessage  Human-readable error message if sync failed
     * @param  string|null  $doi  The DOI that was synced (or attempted to sync)
     */
    public function __construct(
        public bool $attempted,
        public bool $success,
        public ?string $errorMessage,
        public ?string $doi,
    ) {}

    /**
     * Create a result indicating no sync was required.
     *
     * Used when the resource doesn't have a DOI registered with DataCite.
     */
    public static function notRequired(): self
    {
        return new self(
            attempted: false,
            success: true,
            errorMessage: null,
            doi: null,
        );
    }

    /**
     * Create a result indicating successful synchronization.
     *
     * @param  string  $doi  The DOI that was successfully synced
     */
    public static function succeeded(string $doi): self
    {
        return new self(
            attempted: true,
            success: true,
            errorMessage: null,
            doi: $doi,
        );
    }

    /**
     * Create a result indicating failed synchronization.
     *
     * The database save should still succeed; this only indicates
     * that the DataCite API update failed.
     *
     * @param  string  $doi  The DOI that failed to sync
     * @param  string  $errorMessage  Human-readable error description
     */
    public static function failed(string $doi, string $errorMessage): self
    {
        return new self(
            attempted: true,
            success: false,
            errorMessage: $errorMessage,
            doi: $doi,
        );
    }

    /**
     * Check if sync was attempted but failed.
     */
    public function hasFailed(): bool
    {
        return $this->attempted && ! $this->success;
    }

    /**
     * Convert to array for JSON response.
     *
     * @return array{attempted: bool, success: bool, errorMessage: string|null, doi: string|null}
     */
    public function toArray(): array
    {
        return [
            'attempted' => $this->attempted,
            'success' => $this->success,
            'errorMessage' => $this->errorMessage,
            'doi' => $this->doi,
        ];
    }
}
