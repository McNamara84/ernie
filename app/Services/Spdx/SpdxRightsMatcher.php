<?php

declare(strict_types=1);

namespace App\Services\Spdx;

use Illuminate\Support\Str;

/**
 * Matches one imported rights statement against the local SPDX catalog.
 *
 * The matcher is deliberately conservative. It only returns "matched" when the
 * evidence is strong enough for a reviewer-facing suggestion. Weak fuzzy
 * guesses are treated as unsupported so that curators are not nudged toward a
 * legally meaningful value without clear source evidence.
 */
final readonly class SpdxRightsMatcher
{
    /**
     * Phrases that usually mean a rights statement is custom text, not SPDX.
     *
     * This is not a legal classifier. It is just a safety rail for obvious
     * non-SPDX cases that should not become license suggestions.
     *
     * @var list<string>
     */
    private const CUSTOM_RIGHTS_MARKERS = [
        'commercial end user',
        'custom license',
        'custom licence',
        'individual agreement',
        'licence agreement',
        'license agreement',
        'licencing agreement',
        'licensing agreement',
        'not for commercial',
        'permission required',
        'proprietary',
        'restricted use',
        'written permission',
    ];

    /**
     * Match the imported statement using strong evidence, ordered by trust.
     *
     * SPDX identifiers and canonical URLs are the strongest evidence. Human
     * text still matters, especially for legacy imports where only "CC BY 4.0"
     * may be available, but text matching stays limited to exact names and
     * approved aliases.
     */
    public function match(SpdxRightsMatchInput $input, SpdxLicenseLookup $lookup): SpdxRightsMatchResult
    {
        if (! $input->hasEvidence()) {
            return SpdxRightsMatchResult::insufficient('The imported rights statement has no rights text, URI, or identifier.');
        }

        $identifierMatch = $this->matchIdentifier($input, $lookup);

        if ($identifierMatch !== null) {
            return $identifierMatch;
        }

        $uriMatch = $this->matchUri($input, $lookup);

        if ($uriMatch !== null) {
            return $uriMatch;
        }

        $nameMatch = $this->matchCanonicalName($input, $lookup);

        if ($nameMatch !== null) {
            return $nameMatch;
        }

        $aliasMatch = $this->matchApprovedAlias($input, $lookup);

        if ($aliasMatch !== null) {
            return $aliasMatch;
        }

        $strictVariantMatch = $this->matchStrictTextVariant($input, $lookup);

        if ($strictVariantMatch !== null) {
            return $strictVariantMatch;
        }

        if ($this->looksCustomOrUnsupported($input)) {
            return SpdxRightsMatchResult::unsupported('The rights statement looks custom, restricted, or non-SPDX.');
        }

        return SpdxRightsMatchResult::unsupported('No strong SPDX identifier, URI, canonical name, or approved alias matched.');
    }

    private function matchIdentifier(SpdxRightsMatchInput $input, SpdxLicenseLookup $lookup): ?SpdxRightsMatchResult
    {
        $identifier = trim((string) $input->rightsIdentifier);

        if ($identifier === '') {
            return null;
        }

        $scheme = SpdxLicenseLookup::normalizeText($input->rightsIdentifierScheme);
        $isSpdxScheme = $scheme === ''
            || $scheme === 'spdx'
            || $scheme === SpdxLicenseLookup::normalizeText(SpdxLicenseLookup::RIGHTS_IDENTIFIER_SCHEME);

        if (! $isSpdxScheme) {
            return null;
        }

        $license = $lookup->findByIdentifier($identifier);

        if ($license === null) {
            return null;
        }

        return SpdxRightsMatchResult::matched(
            license: $license,
            score: 1.0,
            matchType: 'resource_rights.rights_identifier',
            reason: 'The imported rights identifier exactly matches an SPDX identifier.',
        );
    }

    private function matchUri(SpdxRightsMatchInput $input, SpdxLicenseLookup $lookup): ?SpdxRightsMatchResult
    {
        $license = $lookup->findByUri($input->rightsUri);

        if ($license === null) {
            return null;
        }

        return SpdxRightsMatchResult::matched(
            license: $license,
            score: 0.98,
            matchType: 'resource_rights.rights_uri',
            reason: 'The imported rights URI matches a canonical or approved SPDX license URL.',
        );
    }

    private function matchCanonicalName(SpdxRightsMatchInput $input, SpdxLicenseLookup $lookup): ?SpdxRightsMatchResult
    {
        $license = $lookup->findByName($input->rightsText);

        if ($license === null) {
            return null;
        }

        return SpdxRightsMatchResult::matched(
            license: $license,
            score: 1.0,
            matchType: 'resource_rights.rights_text',
            reason: 'The imported rights text exactly matches the canonical SPDX license name.',
        );
    }

    private function matchApprovedAlias(SpdxRightsMatchInput $input, SpdxLicenseLookup $lookup): ?SpdxRightsMatchResult
    {
        $license = $lookup->findByAlias($input->rightsText);

        if ($license === null) {
            return null;
        }

        return SpdxRightsMatchResult::matched(
            license: $license,
            score: 0.95,
            matchType: 'resource_rights.rights_text_alias',
            reason: 'The imported rights text matches a reviewed SPDX alias.',
        );
    }

    private function matchStrictTextVariant(SpdxRightsMatchInput $input, SpdxLicenseLookup $lookup): ?SpdxRightsMatchResult
    {
        $text = SpdxLicenseLookup::normalizeText($input->rightsText);

        if ($text === '') {
            return null;
        }

        /*
         * Some imported strings contain a short SPDX-like phrase inside longer
         * text, for example "Dataset licensed as CC BY 4.0". We only accept
         * these tightly scoped patterns when the extracted phrase is already in
         * the approved alias table.
         */
        $patterns = [
            '/\bcc\s+by\s+4\.0\b/u',
            '/\bcc\s+by[-\s]?nc\s+4\.0\b/u',
            '/\bcc\s+by[-\s]?sa\s+4\.0\b/u',
            '/\bcc0\s+(?:universal\s+)?1\.0\b/u',
            '/\bmit\s+licen[cs]e\b/u',
            '/\bapache\s+licen[cs]e(?:,?\s+version)?\s+2\.0\b/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches) !== 1) {
                continue;
            }

            $license = $lookup->findByAlias($matches[0]);

            if ($license === null) {
                continue;
            }

            return SpdxRightsMatchResult::matched(
                license: $license,
                score: 0.90,
                matchType: 'resource_rights.rights_text_strict_variant',
                reason: 'A reviewed SPDX alias was found inside a longer rights statement.',
            );
        }

        return null;
    }

    private function looksCustomOrUnsupported(SpdxRightsMatchInput $input): bool
    {
        $haystack = SpdxLicenseLookup::normalizeText(implode(' ', array_filter([
            $input->rightsText,
            $input->rightsUri,
            $input->rightsIdentifier,
        ], fn (?string $value): bool => $value !== null && trim($value) !== '')));

        if ($haystack === '') {
            return false;
        }

        foreach (self::CUSTOM_RIGHTS_MARKERS as $marker) {
            if (Str::contains($haystack, $marker)) {
                return true;
            }
        }

        return false;
    }
}
