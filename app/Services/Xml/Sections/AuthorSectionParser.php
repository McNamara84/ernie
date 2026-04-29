<?php

declare(strict_types=1);

namespace App\Services\Xml\Sections;

use App\Services\RorLookupService;
use App\Support\UriHelper;
use App\Support\Xml\XmlElementHelpers;
use Illuminate\Support\Str;
use Saloon\XmlWrangler\Data\Element;
use Saloon\XmlWrangler\XmlReader;

/**
 * Parses `<creators>/<creator>` into the editor's authors payload.
 */
final readonly class AuthorSectionParser
{
    public function __construct(
        private RorLookupService $rorLookupService,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parse(XmlReader $reader): array
    {
        $creatorElements = $reader
            ->xpathElement('//*[local-name()="resource"]/*[local-name()="creators"]/*[local-name()="creator"]')
            ->get();

        $authors = [];

        foreach ($creatorElements as $creator) {
            $content = $creator->getContent();

            if (! is_array($content)) {
                continue;
            }

            $creatorName = XmlElementHelpers::firstElementByKey($content, 'creatorName');
            $nameType = $creatorName?->getAttribute('nameType');
            $type = is_string($nameType) && Str::lower($nameType) === 'organizational' ? 'institution' : 'person';

            $affiliations = $this->extractAffiliations($content);

            if ($type === 'institution') {
                $authors[] = [
                    'type' => 'institution',
                    'institutionName' => XmlElementHelpers::stringValue($creatorName) ?? '',
                    'affiliations' => $affiliations,
                ];

                continue;
            }

            $givenName = XmlElementHelpers::stringValue(XmlElementHelpers::firstElementByKey($content, 'givenName'));
            $familyName = XmlElementHelpers::stringValue(XmlElementHelpers::firstElementByKey($content, 'familyName'));

            if ((! $givenName || ! $familyName) && $creatorName instanceof Element) {
                $resolved = XmlElementHelpers::splitCreatorName(XmlElementHelpers::stringValue($creatorName));
                $familyName ??= $resolved['familyName'];
                $givenName ??= $resolved['givenName'];
            }

            $authors[] = [
                'type' => 'person',
                'orcid' => $this->extractOrcid($content),
                'firstName' => $givenName ?? '',
                'lastName' => $familyName ?? (XmlElementHelpers::stringValue($creatorName) ?? ''),
                'affiliations' => $affiliations,
            ];
        }

        return $authors;
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<int, array{value: string, rorId: string|null}>
     */
    public function extractAffiliations(array $content): array
    {
        $affiliationElements = XmlElementHelpers::allElementsByKey($content, 'affiliation');

        /** @var array<int, array{value: string, rorId: string|null}> $affiliations */
        $affiliations = [];

        foreach ($affiliationElements as $element) {
            $rawValue = XmlElementHelpers::stringValue($element);

            $identifier = $element->getAttribute('affiliationIdentifier');
            $scheme = $element->getAttribute('affiliationIdentifierScheme');

            $resolved = null;

            if (is_string($identifier) && $identifier !== '') {
                $resolved = $this->resolveAffiliationByRor($identifier, is_string($scheme) ? $scheme : null, $rawValue);
            }

            if ($resolved !== null) {
                $affiliations[] = $resolved;

                continue;
            }

            if (is_string($rawValue) && $rawValue !== '') {
                $affiliations[] = [
                    'value' => $rawValue,
                    'rorId' => null,
                ];
            }
        }

        return $affiliations;
    }

    /**
     * @param  array<string, mixed>  $content
     */
    public function extractOrcid(array $content): ?string
    {
        $identifierElements = XmlElementHelpers::allElementsByKey($content, 'nameIdentifier');

        foreach ($identifierElements as $element) {
            $scheme = $element->getAttribute('nameIdentifierScheme');

            if (is_string($scheme) && Str::lower($scheme) !== 'orcid') {
                continue;
            }

            $value = XmlElementHelpers::stringValue($element);

            if (! is_string($value) || $value === '') {
                continue;
            }

            $orcid = self::canonicaliseOrcid($value);

            if ($orcid !== null) {
                return $orcid;
            }
        }

        return null;
    }

    public static function canonicaliseOrcid(string $value): ?string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        if (preg_match('#^https?://orcid\.org/#i', $trimmed) === 1) {
            $path = UriHelper::getPath($trimmed);
            $identifier = is_string($path) ? trim($path, '/') : '';
        } else {
            $identifier = trim($trimmed, '/');
        }

        if ($identifier === '') {
            return null;
        }

        return $identifier;
    }

    /**
     * @return array{value: string, rorId: string}|null
     */
    public function resolveAffiliationByRor(string $identifier, ?string $scheme, ?string $fallback): ?array
    {
        if (! self::isRorIdentifier($identifier, $scheme)) {
            return null;
        }

        return $this->rorLookupService->resolveWithFallback($identifier, $fallback);
    }

    public static function isRorIdentifier(string $identifier, ?string $scheme): bool
    {
        if (is_string($scheme) && Str::lower($scheme) === 'ror') {
            return true;
        }

        return Str::contains(Str::lower($identifier), 'ror.org');
    }
}
