<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use DateTimeInterface;

/**
 * Local ERNIE facts used to choose accurate, state-aware FAIR guidance.
 *
 * F-UJI remains the only source for score gaps. This context may only select
 * wording, enforce prerequisites, or suppress advice that is not applicable.
 */
final readonly class FairImprovementContext
{
    public function __construct(
        public bool $hasDoi = false,
        public bool $landingPageExists = false,
        public bool $landingPagePublished = false,
        public bool $landingPageIsInternal = false,
        public bool $landingPageUsesHttps = false,
        public bool $hasConfiguredDownloads = false,
        public bool $hasIgsnMetadata = false,
        public bool $igsnRegistered = false,
        public bool $machineReadableDistributionVerified = false,
        public ?string $currentIdentifier = null,
        public ?string $assessedIdentifier = null,
        public ?DateTimeInterface $assessedAt = null,
        public ?DateTimeInterface $latestRelevantChangeAt = null,
    ) {}

    public function isValidForScope(string $scope): bool
    {
        if (! $this->landingPageExists && (
            $this->landingPagePublished
            || $this->landingPageIsInternal
            || $this->landingPageUsesHttps
            || $this->hasConfiguredDownloads
        )) {
            return false;
        }

        if ($this->igsnRegistered && ! $this->hasIgsnMetadata) {
            return false;
        }

        return $scope !== FairImprovementOpportunityResolver::SCOPE_IGSN
            || $this->hasIgsnMetadata;
    }

    public function requiresReassessment(): bool
    {
        if ($this->identifierChangedSinceAssessment()) {
            return true;
        }

        if ($this->assessedAt === null || $this->latestRelevantChangeAt === null) {
            return false;
        }

        return (float) $this->latestRelevantChangeAt->format('U.u')
            > (float) $this->assessedAt->format('U.u');
    }

    private function identifierChangedSinceAssessment(): bool
    {
        $current = $this->normalizedIdentifier($this->currentIdentifier);
        $assessed = $this->normalizedIdentifier($this->assessedIdentifier);

        return $current !== $assessed;
    }

    private function normalizedIdentifier(?string $identifier): ?string
    {
        if ($identifier === null || trim($identifier) === '') {
            return null;
        }

        return strtolower(trim($identifier));
    }
}
