<?php

declare(strict_types=1);

namespace App\Services\Orcid;

/**
 * Single ORCID issue discovered during {@see OrcidPreflightValidator::validate()}.
 *
 * Issues come in two flavors determined by {@see self::$severity}:
 *  - "blocking"  – confirmed invalid/unknown ORCID (reason = not_found|checksum|format).
 *  - "warning"   – transient error (reason = network|timeout|api_error|unknown).
 *
 * The curator-facing modal displays both lists separately.
 */
final readonly class OrcidPreflightIssue
{
    /**
     * @param  'blocking'|'warning'  $severity
     * @param  'not_found'|'checksum'|'format'|'network'|'timeout'|'api_error'|'unknown'  $reason
     * @param  'creator'|'contributor'  $role
     */
    public function __construct(
        public string $severity,
        public string $reason,
        public string $role,
        public int $position,
        public string $orcid,
        public string $displayName,
    ) {}

    /**
     * @return array{severity: string, reason: string, role: string, position: int, orcid: string, displayName: string}
     */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity,
            'reason' => $this->reason,
            'role' => $this->role,
            'position' => $this->position,
            'orcid' => $this->orcid,
            'displayName' => $this->displayName,
        ];
    }
}
