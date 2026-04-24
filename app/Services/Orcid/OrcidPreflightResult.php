<?php

declare(strict_types=1);

namespace App\Services\Orcid;

/**
 * Result of an {@see OrcidPreflightValidator::validate()} run.
 *
 * Callers must inspect {@see self::$shouldBlock} first (hard stop on confirmed
 * invalid ORCIDs) and then {@see self::$needsConfirmation} to decide whether
 * the curator must explicitly override transient failures via `force=true`.
 */
final readonly class OrcidPreflightResult
{
    /**
     * @param  list<OrcidPreflightIssue>  $invalid   Blocking issues (confirmed bad ORCIDs).
     * @param  list<OrcidPreflightIssue>  $warnings  Transient issues (ORCID service unreachable).
     */
    public function __construct(
        public array $invalid,
        public array $warnings,
        public bool $shouldBlock,
        public bool $needsConfirmation,
    ) {}

    /**
     * Build a pristine "all-clear" result.
     */
    public static function clean(): self
    {
        return new self([], [], false, false);
    }

    /**
     * @return array{invalid: list<array<string, mixed>>, warnings: list<array<string, mixed>>}
     */
    public function toPayload(): array
    {
        return [
            'invalid' => array_map(static fn (OrcidPreflightIssue $i): array => $i->toArray(), $this->invalid),
            'warnings' => array_map(static fn (OrcidPreflightIssue $i): array => $i->toArray(), $this->warnings),
        ];
    }
}
