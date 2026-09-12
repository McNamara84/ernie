<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\GcmdUriHelper;
use App\Support\LegacyMslScheme;
use App\Support\PortalSubjectNormalizer;
use Normalizer;

/**
 * Merges DataCite and legacy subjects in source-priority order.
 *
 * DataCite subjects remain in their original order. Equivalent legacy subjects
 * only fill missing metadata; genuinely new legacy subjects are appended.
 */
final class DataCiteSubjectMergeService
{
    /**
     * @param  array<string, mixed>  $doiRecord
     * @param  array<int, mixed>  $legacySubjects
     * @return array<string, mixed>
     */
    public function mergeIntoDoiRecord(array $doiRecord, array $legacySubjects): array
    {
        if ($legacySubjects === []) {
            return $doiRecord;
        }

        if (is_array($doiRecord['attributes'] ?? null)) {
            $dataCiteSubjects = is_array($doiRecord['attributes']['subjects'] ?? null)
                ? $doiRecord['attributes']['subjects']
                : [];
            $doiRecord['attributes']['subjects'] = $this->merge($dataCiteSubjects, $legacySubjects);

            return $doiRecord;
        }

        $dataCiteSubjects = is_array($doiRecord['subjects'] ?? null)
            ? $doiRecord['subjects']
            : [];
        $doiRecord['subjects'] = $this->merge($dataCiteSubjects, $legacySubjects);

        return $doiRecord;
    }

    /**
     * @param  array<int, mixed>  $dataCiteSubjects
     * @param  array<int, mixed>  $legacySubjects
     * @return list<mixed>
     */
    public function merge(array $dataCiteSubjects, array $legacySubjects): array
    {
        $merged = array_values($dataCiteSubjects);
        /** @var array<string, int> $identityIndexes */
        $identityIndexes = [];

        foreach ($merged as $index => $subject) {
            if (! is_array($subject)) {
                continue;
            }

            foreach ($this->identities($subject) as $identity) {
                $identityIndexes[$identity] ??= $index;
            }
        }

        foreach ($legacySubjects as $subject) {
            if (! is_array($subject) || $this->stringValue($subject, ['subject']) === null) {
                continue;
            }

            $candidateIdentities = $this->identities($subject);
            if ($candidateIdentities === []) {
                continue;
            }

            $matchingIndex = null;
            foreach ($candidateIdentities as $identity) {
                if (isset($identityIndexes[$identity])) {
                    $matchingIndex = $identityIndexes[$identity];
                    break;
                }
            }

            if ($matchingIndex !== null && is_array($merged[$matchingIndex])) {
                $merged[$matchingIndex] = $this->enrichMissingMetadata($merged[$matchingIndex], $subject);

                foreach ($this->identities($merged[$matchingIndex]) as $identity) {
                    $identityIndexes[$identity] ??= $matchingIndex;
                }

                continue;
            }

            $merged[] = $subject;
            $newIndex = count($merged) - 1;
            foreach ($candidateIdentities as $identity) {
                $identityIndexes[$identity] ??= $newIndex;
            }
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $legacy
     * @return array<string, mixed>
     */
    private function enrichMissingMetadata(array $existing, array $legacy): array
    {
        $fieldAliases = [
            'subjectScheme' => ['subjectScheme', 'scheme', 'subject_scheme'],
            'schemeUri' => ['schemeUri', 'schemeURI', 'scheme_uri'],
            'valueUri' => ['valueUri', 'valueURI', 'value_uri', 'id'],
            'classificationCode' => ['classificationCode', 'classification_code'],
            'lang' => ['lang', 'language', 'xml:lang'],
        ];

        foreach ($fieldAliases as $targetKey => $aliases) {
            if ($this->stringValue($existing, $aliases) !== null) {
                continue;
            }

            $legacyValue = $this->stringValue($legacy, $aliases);
            if ($legacyValue !== null) {
                $existing[$targetKey] = $legacyValue;
            }
        }

        return $existing;
    }

    /**
     * @param  array<string, mixed>  $subject
     * @return list<string>
     */
    private function identities(array $subject): array
    {
        $value = $this->stringValue($subject, ['subject']);
        if ($value === null) {
            return [];
        }

        $scheme = $this->stringValue($subject, ['subjectScheme', 'scheme', 'subject_scheme']);
        $schemeUri = $this->stringValue($subject, ['schemeUri', 'schemeURI', 'scheme_uri']);
        $valueUri = $this->stringValue($subject, ['valueUri', 'valueURI', 'value_uri', 'id']);
        $classificationCode = $this->stringValue($subject, ['classificationCode', 'classification_code']);
        $isControlled = $scheme !== null || $schemeUri !== null || $valueUri !== null || $classificationCode !== null;
        $preserveSourceScheme = LegacyMslScheme::isSupported($scheme);

        if (! $isControlled) {
            return ['free|'.$this->normalizeText($value)];
        }

        $normalizedScheme = $this->normalizeIdentityScheme($scheme, $schemeUri, $valueUri);
        $identities = [];

        if ($valueUri !== null) {
            $identities[] = 'uri|'
                .($preserveSourceScheme ? $normalizedScheme.'|' : '')
                .$this->normalizeUri($valueUri);
        }

        if ($classificationCode !== null && $normalizedScheme !== '') {
            $identities[] = 'classification|'.$normalizedScheme.'|'.$this->normalizeText($classificationCode);
        }

        $normalizedPath = $this->normalizeControlledPath($value, $normalizedScheme);
        if ($normalizedScheme !== '' && $normalizedPath !== '') {
            $identities[] = 'controlled|'.$normalizedScheme.'|'.$normalizedPath;
        }

        return array_values(array_unique($identities));
    }

    /**
     * @param  array<string, mixed>  $subject
     * @param  list<string>  $keys
     */
    private function stringValue(array $subject, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (! isset($subject[$key]) || ! is_scalar($subject[$key])) {
                continue;
            }

            $value = trim((string) $subject[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function normalizeIdentityScheme(?string $scheme, ?string $schemeUri, ?string $valueUri): string
    {
        if (LegacyMslScheme::isSupported($scheme)) {
            return $this->normalizeText((string) $scheme);
        }

        $normalizedScheme = PortalSubjectNormalizer::normalizeScheme($scheme);

        if ($normalizedScheme === null) {
            $uri = mb_strtolower(($schemeUri ?? '').' '.($valueUri ?? ''));
            $normalizedScheme = match (true) {
                str_contains($uri, 'sciencekeyword') => 'Science Keywords',
                str_contains($uri, 'platform') => 'Platforms',
                str_contains($uri, 'instrument') => 'Instruments',
                default => '',
            };
        }

        return $this->normalizeText($normalizedScheme);
    }

    private function normalizeControlledPath(string $value, string $normalizedScheme): string
    {
        $path = PortalSubjectNormalizer::normalizeControlledSubjectValue($value);
        if ($path === null) {
            return '';
        }

        $segments = array_values(array_filter(
            array_map('trim', explode(PortalSubjectNormalizer::BREADCRUMB_SEPARATOR, $path)),
            static fn (string $segment): bool => $segment !== '',
        ));

        if ($segments !== [] && $normalizedScheme !== '') {
            $firstSegmentScheme = $this->normalizeIdentityScheme($segments[0], null, null);
            if ($firstSegmentScheme === $normalizedScheme) {
                array_shift($segments);
            }
        }

        return $this->normalizeText(implode(PortalSubjectNormalizer::BREADCRUMB_SEPARATOR, $segments));
    }

    private function normalizeUri(string $uri): string
    {
        $uri = trim($uri);

        if (str_contains(mb_strtolower($uri), 'gcmd')) {
            $uri = GcmdUriHelper::convertToNewUri($uri) ?? $uri;
        }

        return mb_strtolower(rtrim($uri, '/'));
    }

    private function normalizeText(string $value): string
    {
        if (class_exists(Normalizer::class)) {
            $value = Normalizer::normalize($value, Normalizer::FORM_C) ?: $value;
        }

        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return mb_strtolower($value);
    }
}
