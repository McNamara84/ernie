<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\PortalSubjectNormalizer;
use App\Support\SubjectBreadcrumbPath;

final readonly class ControlledSubjectImportNormalizerService
{
    public function __construct(
        private SubjectBreadcrumbPathResolverService $pathResolver,
    ) {}

    /**
     * Normalize CGI Simple Lithology metadata. A null return means the supplied
     * scheme is not a recognized Simple Lithology scheme.
     *
     * @return array{
     *     uuid: string,
     *     id: string,
     *     text: string,
     *     path: string,
     *     scheme: string,
     *     schemeURI: string,
     *     language: string,
     *     classificationCode?: string,
     *     isLegacy?: true
     * }|null
     */
    public function simpleLithology(
        ?string $scheme,
        string $value,
        ?string $schemeUri = null,
        ?string $valueUri = null,
        ?string $classificationCode = null,
        ?string $language = null,
    ): ?array {
        if (PortalSubjectNormalizer::normalizeScheme($scheme) !== PortalSubjectNormalizer::SCHEME_SIMPLE_LITHOLOGY) {
            return null;
        }

        $path = SubjectBreadcrumbPath::normalize($value) ?? trim($value);
        $resolved = null;
        if ($this->filled($valueUri) === null) {
            $resolved = $this->pathResolver->resolveKeywordFromPath(
                PortalSubjectNormalizer::SCHEME_SIMPLE_LITHOLOGY,
                $path,
            );
        }

        $canonicalValueUri = $this->canonicalConceptUri($this->filled($valueUri))
            ?? $this->filled($resolved['id'] ?? null);
        $canonicalClassificationCode = $this->filled($classificationCode);
        $canonicalPath = $this->filled($resolved['path'] ?? null) ?? $path;
        $isLegacy = $canonicalValueUri === null && $canonicalClassificationCode === null;
        $id = $canonicalValueUri
            ?? $canonicalClassificationCode
            ?? 'legacy:'.hash('sha256', mb_strtolower(PortalSubjectNormalizer::SCHEME_SIMPLE_LITHOLOGY.'|'.$canonicalPath));

        $keyword = [
            'uuid' => '',
            'id' => $id,
            'text' => $this->filled($resolved['text'] ?? null)
                ?? SubjectBreadcrumbPath::leaf($canonicalPath, $value)
                ?? trim($value),
            'path' => $canonicalPath,
            'scheme' => PortalSubjectNormalizer::SCHEME_SIMPLE_LITHOLOGY,
            'schemeURI' => (string) config('simple_lithology.scheme_uri'),
            'language' => $this->filled($language) ?? 'en',
        ];

        if ($canonicalClassificationCode !== null) {
            $keyword['classificationCode'] = $canonicalClassificationCode;
        }

        if ($isLegacy) {
            $keyword['isLegacy'] = true;
        }

        return $keyword;
    }

    private function canonicalConceptUri(?string $uri): ?string
    {
        if ($uri === null) {
            return null;
        }

        $collection = rtrim((string) config('simple_lithology.collection_uri'), '/');
        $pattern = '~^https?://resource\.geosciml\.org/classifier/cgi/lithology/(.+)$~i';
        if (preg_match($pattern, $uri, $matches) !== 1 || trim($matches[1], '/') === '') {
            return null;
        }

        return $collection.'/'.trim($matches[1], '/');
    }

    private function filled(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
