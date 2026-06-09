<?php

declare(strict_types=1);

namespace App\Services\Spdx;

/**
 * One imported rights statement that should be checked against SPDX.
 *
 * This object intentionally mirrors the future raw `resource_rights` columns
 * from the design document. Today those columns may not exist yet; keeping this
 * object independent from Eloquent lets students test the matching logic before
 * database migrations or import code are finished.
 */
final readonly class SpdxRightsMatchInput
{
    public function __construct(
        public int $resourceId,
        public string $targetType,
        public int $targetId,
        public ?string $rightsText = null,
        public ?string $rightsUri = null,
        public ?string $rightsIdentifier = null,
        public ?string $rightsIdentifierScheme = null,
        public ?string $schemeUri = null,
        public ?string $language = null,
        public ?string $source = null,
    ) {}

    public function hasEvidence(): bool
    {
        return $this->filled($this->rightsText)
            || $this->filled($this->rightsUri)
            || $this->filled($this->rightsIdentifier);
    }

    /**
     * Build the "current" part of the suggestion payload.
     *
     * Reviewers should always see what was imported before they decide whether
     * the SPDX proposal is correct.
     *
     * @return array<string, string>
     */
    public function currentPayload(): array
    {
        $payload = [];

        foreach ([
            'rights' => $this->rightsText,
            'rights_uri' => $this->rightsUri,
            'rights_identifier' => $this->rightsIdentifier,
            'rights_identifier_scheme' => $this->rightsIdentifierScheme,
            'scheme_uri' => $this->schemeUri,
            'language' => $this->language,
            'source' => $this->source,
        ] as $key => $value) {
            if ($this->filled($value)) {
                $payload[$key] = trim((string) $value);
            }
        }

        return $payload;
    }

    private function filled(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }
}
