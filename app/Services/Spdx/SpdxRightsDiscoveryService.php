<?php

declare(strict_types=1);

namespace App\Services\Spdx;

use Closure;

/**
 * Coordinates SPDX discovery for the assistant module.
 *
 * This service keeps the module class small: the assistant owns UI-facing
 * storage through GenericTableAssistant, while this service owns the SPDX
 * workflow. That split makes the code easier for students to reuse for other
 * DataCite elements.
 */
final readonly class SpdxRightsDiscoveryService
{
    public function __construct(
        private SpdxRightsMatchInputProvider $inputProvider,
        private SpdxRightsMatcher $matcher,
    ) {}

    /**
     * Discover and store reviewer-facing SPDX suggestions.
     *
     * @param  Closure(int, string, int, string, string, float|null, array<string, mixed>|null): bool  $storeSuggestion
     * @param  Closure(string): void  $onProgress
     * @return int Number of newly stored suggestions
     */
    public function discover(Closure $storeSuggestion, Closure $onProgress): int
    {
        $inputs = $this->inputProvider->pendingInputs();
        $inputCount = $inputs->count();

        $onProgress("Checking {$inputCount} unresolved rights statement(s) against the local SPDX catalog.");

        if ($inputCount === 0) {
            $onProgress('No unresolved raw rights statements found for SPDX matching.');

            return 0;
        }

        $lookup = SpdxLicenseLookup::fromRightsCatalog();
        $stored = 0;
        $unsupported = 0;
        $insufficient = 0;

        foreach ($inputs as $input) {
            $result = $this->matcher->match($input, $lookup);

            if (! $result->isMatched()) {
                if ($result->status === 'insufficient') {
                    $insufficient++;
                } else {
                    $unsupported++;
                }

                continue;
            }

            /** @var SpdxLicenseData $license */
            $license = $result->license;

            $wasStored = $storeSuggestion(
                $input->resourceId,
                $input->targetType,
                $input->targetId,
                $license->identifier,
                $license->name,
                $result->score,
                $result->toSuggestionMetadata($input),
            );

            if ($wasStored) {
                $stored++;
            }
        }

        $onProgress("Stored {$stored} SPDX suggestion(s); skipped {$unsupported} unsupported and {$insufficient} insufficient statement(s).");

        return $stored;
    }
}
