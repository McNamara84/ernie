<?php

declare(strict_types=1);

namespace App\Services\Imports\Subjects;

use App\Services\ControlledSubjectImportNormalizerService;
use App\Services\SubjectBreadcrumbPathResolverService;
use App\Support\GcmdUriHelper;
use App\Support\GemetVocabularyParser;
use App\Support\PortalSubjectNormalizer;
use App\Support\SubjectBreadcrumbPath;

final readonly class SubjectImportNormalizer
{
    public const string SCHEME_MSL = 'EPOS MSL vocabulary';

    public const string SCHEME_GEMET = GemetVocabularyParser::SCHEME_TITLE;

    private const array GCMD_SCHEMES = [
        'Science Keywords',
        'Platforms',
        'Instruments',
    ];

    private const array GCMD_PATH_PREFIXES = [
        'Science Keywords > ',
        'Platforms > ',
        'Instruments > ',
    ];

    public function __construct(
        private ControlledSubjectImportNormalizerService $controlledSubjectNormalizer,
        private SubjectBreadcrumbPathResolverService $pathResolver,
    ) {}

    /**
     * @param  iterable<ImportedSubjectData>  $subjects
     */
    public function normalize(iterable $subjects): SubjectImportResult
    {
        $freeKeywords = [];
        $controlledKeywords = [];
        $seenFreeKeywords = [];
        $seenControlledKeywords = [];

        foreach ($subjects as $subject) {
            $value = $this->filledString($subject->value);
            if ($value === null) {
                continue;
            }

            $scheme = $this->filledString($subject->subjectScheme);
            $schemeUri = $this->filledString($subject->schemeUri);
            $valueUri = $this->filledString($subject->valueUri);
            $classificationCode = $this->filledString($subject->classificationCode);
            $language = $this->filledString($subject->language) ?? 'en';

            if ($scheme === null && $schemeUri === null && $valueUri === null) {
                if (! isset($seenFreeKeywords[$value])) {
                    $freeKeywords[] = $value;
                    $seenFreeKeywords[$value] = true;
                }

                continue;
            }

            $simpleLithology = $this->controlledSubjectNormalizer->simpleLithology(
                $scheme,
                $value,
                $schemeUri,
                $valueUri,
                $classificationCode,
                $language,
            );

            $keyword = $simpleLithology ?? $this->normalizeControlledKeyword(
                $value,
                $scheme,
                $schemeUri,
                $valueUri,
                $classificationCode,
                $language,
            );

            if ($keyword === null) {
                continue;
            }

            $fingerprint = self::controlledKeywordFingerprint($keyword);
            if (isset($seenControlledKeywords[$fingerprint])) {
                continue;
            }

            $controlledKeywords[] = $keyword;
            $seenControlledKeywords[$fingerprint] = true;
        }

        return new SubjectImportResult($freeKeywords, $controlledKeywords);
    }

    /**
     * Fingerprint the fields that ultimately define an imported Subject row.
     *
     * @param  array<string, mixed>  $keyword
     */
    public static function controlledKeywordFingerprint(array $keyword): string
    {
        $id = self::staticFilledString($keyword['id'] ?? null);
        $classificationCode = self::staticFilledString($keyword['classificationCode'] ?? null);

        return hash('sha256', json_encode([
            'value' => self::staticFilledString($keyword['text'] ?? null),
            'language' => self::staticFilledString($keyword['language'] ?? null) ?? 'en',
            'subject_scheme' => self::staticFilledString($keyword['scheme'] ?? null),
            'scheme_uri' => self::staticFilledString($keyword['schemeURI'] ?? $keyword['schemeUri'] ?? null),
            'value_uri' => $id !== null && filter_var($id, FILTER_VALIDATE_URL) ? $id : null,
            'classification_code' => $classificationCode,
            'breadcrumb_path' => SubjectBreadcrumbPath::normalize(self::staticFilledString($keyword['path'] ?? null)),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeControlledKeyword(
        string $value,
        ?string $scheme,
        ?string $schemeUri,
        ?string $valueUri,
        ?string $classificationCode,
        string $language,
    ): ?array {
        $canonicalScheme = PortalSubjectNormalizer::normalizeScheme($scheme);
        if ($canonicalScheme === null) {
            return null;
        }

        if (in_array($canonicalScheme, self::GCMD_SCHEMES, true)) {
            return $this->normalizeGcmdKeyword(
                $value,
                $canonicalScheme,
                $schemeUri,
                $valueUri,
                $classificationCode,
                $language,
            );
        }

        if ($canonicalScheme === self::SCHEME_MSL || $canonicalScheme === self::SCHEME_GEMET) {
            return $this->normalizeSpecializedKeyword(
                $value,
                $canonicalScheme,
                $schemeUri,
                $valueUri,
                $classificationCode,
                $language,
            );
        }

        if ($valueUri === null && $classificationCode === null) {
            return null;
        }

        $path = SubjectBreadcrumbPath::normalize($value) ?? $value;
        $keyword = [
            'uuid' => '',
            'id' => $valueUri ?? $classificationCode,
            'text' => SubjectBreadcrumbPath::leaf($path, $value) ?? $value,
            'path' => $path,
            'language' => $language,
            'scheme' => $canonicalScheme,
        ];

        $resolvedSchemeUri = $schemeUri ?? $this->pathResolver->resolveSchemeUri($canonicalScheme);
        if ($resolvedSchemeUri !== null) {
            $keyword['schemeURI'] = $resolvedSchemeUri;
        }

        if ($classificationCode !== null) {
            $keyword['classificationCode'] = $classificationCode;
        }

        return $keyword;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeGcmdKeyword(
        string $value,
        string $scheme,
        ?string $schemeUri,
        ?string $valueUri,
        ?string $classificationCode,
        string $language,
    ): ?array {
        if ($valueUri === null) {
            return null;
        }

        $uuid = GcmdUriHelper::extractUuid($valueUri);
        if ($uuid === null) {
            return null;
        }

        $path = $this->normalizeGcmdPath($value);
        $keyword = [
            'uuid' => $uuid,
            'id' => GcmdUriHelper::buildConceptUri($uuid),
            'text' => SubjectBreadcrumbPath::leaf($path, $value) ?? $value,
            'path' => $path,
            'language' => $language,
            'scheme' => $scheme,
            'schemeURI' => $schemeUri ?? $this->pathResolver->resolveSchemeUri($scheme) ?? '',
        ];

        if ($classificationCode !== null) {
            $keyword['classificationCode'] = $classificationCode;
        }

        return $keyword;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeSpecializedKeyword(
        string $value,
        string $scheme,
        ?string $schemeUri,
        ?string $valueUri,
        ?string $classificationCode,
        string $language,
    ): ?array {
        if ($valueUri === null) {
            return null;
        }

        $path = SubjectBreadcrumbPath::normalize($value) ?? $value;
        $keyword = [
            'id' => $valueUri,
            'text' => SubjectBreadcrumbPath::leaf($path, $value) ?? $value,
            'path' => $path,
            'language' => $language,
            'scheme' => $scheme,
            'schemeURI' => $schemeUri
                ?? $this->pathResolver->resolveSchemeUri($scheme)
                ?? ($scheme === self::SCHEME_MSL
                    ? 'https://epos-msl.uu.nl/voc'
                    : 'http://www.eionet.europa.eu/gemet/concept/'),
        ];

        if ($classificationCode !== null) {
            $keyword['classificationCode'] = $classificationCode;
        }

        return $keyword;
    }

    private function normalizeGcmdPath(string $value): string
    {
        foreach (self::GCMD_PATH_PREFIXES as $prefix) {
            if (stripos($value, $prefix) === 0) {
                $value = substr($value, strlen($prefix));
                break;
            }
        }

        return SubjectBreadcrumbPath::normalize($value) ?? trim($value);
    }

    private function filledString(mixed $value): ?string
    {
        return self::staticFilledString($value);
    }

    private static function staticFilledString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
