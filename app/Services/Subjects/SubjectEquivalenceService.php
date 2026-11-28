<?php

declare(strict_types=1);

namespace App\Services\Subjects;

use App\Models\Subject;
use App\Services\SubjectEnrichment\SubjectVocabularyConcept;
use App\Services\SubjectEnrichment\SubjectVocabularyLookupService;
use App\Support\GcmdUriHelper;
use App\Support\LegacyMslScheme;
use App\Support\PortalSubjectNormalizer;
use App\Support\SubjectBreadcrumbPath;
use Illuminate\Support\Collection;

/** One conservative identity policy for imports, persistence, display and reviewed cleanup. */
final class SubjectEquivalenceService
{
    public const string VERSION = '1';

    public const array FIELDS = ['value', 'language', 'subject_scheme', 'scheme_uri', 'value_uri', 'classification_code', 'breadcrumb_path'];

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{rows: list<array<string, mixed>>, groups: list<array{indexes: list<int>, survivor: int, row: array<string, mixed>, reason: string}>, diagnostics: list<array{indexes: list<int>, reason: string}>, evidence: array<string, string|null>}
     */
    public function analyze(array $rows): array
    {
        // A fresh lookup is essential in long-running workers and between report/revalidation.
        $lookup = new SubjectVocabularyLookupService;
        $buckets = $profiles = $candidates = $evidence = [];
        foreach ($rows as $index => $row) {
            $scheme = $this->scheme($row);
            if ($scheme === null) {
                continue;
            }
            $prefix = json_encode([$scheme, $row['language'] ?? null], JSON_THROW_ON_ERROR);
            $label = SubjectBreadcrumbPath::leaf(self::text($row['breadcrumb_path'] ?? null), self::text($row['value'] ?? null));
            $candidates[$prefix.'|label|'.mb_strtolower($label ?? '')][] = $index;
            $uri = self::text($row['value_uri'] ?? null);
            if ($uri !== null) {
                $candidates[$prefix.'|uri|'.$this->canonicalUri($uri, $scheme)][] = $index;
            }
        }
        $needsResolution = [];
        foreach ($candidates as $indexes) {
            if (count($indexes) > 1) {
                foreach ($indexes as $index) {
                    $needsResolution[$index] = true;
                }
            }
        }
        foreach ($rows as $index => $row) {
            $fields = self::fields($row);
            $scheme = $this->scheme($fields);
            $profile = $fields;
            $reason = 'exact';
            if (isset($needsResolution[$index]) && $scheme !== null && SubjectBreadcrumbPath::isSchemeHeading((string) $fields['subject_scheme'], $scheme)
                && ! LegacyMslScheme::isSupported(self::text($fields['subject_scheme']))) {
                $concept = $this->resolve($fields, $scheme, $lookup);
                $evidence[$scheme] = $lookup->snapshotFingerprint($scheme);
                if ($concept !== null) {
                    $profile = [
                        'value' => $concept->label,
                        'language' => $fields['language'],
                        'subject_scheme' => $lookup->canonicalSubjectScheme($scheme) ?? $scheme,
                        'scheme_uri' => $concept->schemeUri,
                        'value_uri' => $concept->valueUri(),
                        'classification_code' => $concept->classificationCode,
                        'breadcrumb_path' => $concept->path,
                    ];
                    $reason = 'local_vocabulary';
                }
            }
            $profiles[$index] = ['row' => $profile, 'reason' => $reason];
            // Unresolved rows only share an exact key: no missing-value bridges or label guesses.
            $key = $reason.'|'.self::fingerprint($profile);
            $buckets[$key][] = $index;
        }

        $groups = $diagnostics = $replacement = $removed = $membership = [];
        foreach ($buckets as $indexes) {
            if (count($indexes) < 2) {
                continue;
            }
            $ranked = $indexes;
            usort($ranked, fn (int $a, int $b): int => $this->quality($rows[$b]) <=> $this->quality($rows[$a])
                ?: ((int) ($rows[$a]['id'] ?? $a) <=> (int) ($rows[$b]['id'] ?? $b)));
            $survivor = $ranked[0];
            $exact = count(array_unique(array_map(fn (int $i): string => self::fingerprint($rows[$i]), $indexes))) === 1;
            if ($exact) {
                // Preserve the established smallest-ID survivor and all original metadata.
                usort($ranked, fn (int $a, int $b): int => (int) ($rows[$a]['id'] ?? $a) <=> (int) ($rows[$b]['id'] ?? $b));
                $survivor = $ranked[0];
            }
            $merged = array_replace($rows[$survivor], $exact ? [] : $profiles[$survivor]['row']);
            $groups[] = ['indexes' => $indexes, 'survivor' => $survivor, 'row' => $merged, 'reason' => $exact ? 'exact' : 'local_vocabulary'];
            $replacement[$indexes[0]] = $merged;
            foreach ($indexes as $index) {
                $membership[$index] = $indexes[0];
                if ($index !== $indexes[0]) {
                    $removed[$index] = true;
                }
            }
        }
        $reported = [];
        foreach ($candidates as $indexes) {
            if (count($indexes) > 1 && count(array_unique(array_map(fn (int $i): int => $membership[$i] ?? $i, $indexes))) > 1) {
                $key = implode(',', $indexes);
                if (isset($reported[$key])) {
                    continue;
                }
                $reported[$key] = true;
                $diagnostics[] = ['indexes' => $indexes, 'reason' => 'Conflicting metadata or no unique local vocabulary evidence; variants retained.'];
            }
        }
        $result = [];
        foreach ($rows as $index => $row) {
            if (! isset($removed[$index])) {
                $result[] = $replacement[$index] ?? $row;
            }
        }
        ksort($evidence);

        return ['rows' => $result, 'groups' => $groups, 'diagnostics' => $diagnostics, 'evidence' => $evidence];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function fields(array $row): array
    {
        return array_combine(self::FIELDS, array_map(static fn (string $field): mixed => $row[$field] ?? null, self::FIELDS));
    }

    /** @param array<string, mixed> $row */
    public static function fingerprint(array $row): string
    {
        return hash('sha256', json_encode(self::fields($row), JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $row */
    private function scheme(array $row): ?string
    {
        return PortalSubjectNormalizer::normalizeScheme(self::text($row['subject_scheme'] ?? null));
    }

    /** @param array<string, mixed> $row */
    private function resolve(array $row, string $scheme, SubjectVocabularyLookupService $lookup): ?SubjectVocabularyConcept
    {
        if (! $lookup->isSchemeAvailable($scheme)) {
            return null;
        }
        $uri = self::text($row['value_uri']);
        $value = self::text($row['value']);
        if ($value === null) {
            return null;
        }
        $match = SubjectBreadcrumbPath::hasHierarchy($value)
            ? $lookup->findExactPath($scheme, $value)
            : $lookup->findUniqueLeafInScheme($scheme, $value);
        if ($match->isEmpty() && SubjectBreadcrumbPath::hasHierarchy($value)) {
            $match = $lookup->findUniqueLegacyPath($scheme, $value);
        }
        $concept = $match->sole();
        if ($concept === null || $concept->valueUri() === null || $row['language'] !== $concept->language) {
            return null;
        }
        if ($uri !== null && $this->canonicalUri($uri, $scheme) !== $this->canonicalUri($concept->id, $scheme)) {
            return null;
        }
        foreach (['scheme_uri' => $concept->schemeUri, 'classification_code' => $concept->classificationCode] as $field => $expected) {
            $actual = self::text($row[$field]);
            if ($actual !== null && $actual !== $expected) {
                return null;
            }
        }
        $path = self::text($row['breadcrumb_path']);
        if ($path !== null) {
            $pathMatch = $lookup->findExactPath($scheme, $path);
            if ($pathMatch->isEmpty()) {
                $pathMatch = $lookup->findUniqueLegacyPath($scheme, $path);
            }
            if ($pathMatch->sole()?->id !== $concept->id) {
                return null;
            }
        }

        return $concept;
    }

    private function canonicalUri(string $uri, string $scheme): string
    {
        if (in_array($scheme, ['Science Keywords', 'Platforms', 'Instruments'], true)) {
            return GcmdUriHelper::canonicalizeKnownUri($uri);
        }

        return $uri;
    }

    /** @param array<string, mixed> $row */
    private function quality(array $row): int
    {
        return (self::text($row['value_uri'] ?? null) !== null ? 100 : 0)
            + count(array_filter(self::fields($row), static fn (mixed $value): bool => self::text($value) !== null));
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @param Collection<int, Subject> $subjects
     * @return Collection<int, Subject>
     */
    public function forDisplay(Collection $subjects): Collection
    {
        $originals = $subjects->values();
        $result = $this->analyze(array_values($originals->map(static fn (Subject $subject, int $index): array => [...$subject->getAttributes(), '_source' => $index])->all()));

        return collect($result['rows'])->map(static function (array $row) use ($originals): Subject {
            /** @var Subject $original */
            $original = $originals->get($row['_source']);
            $subject = clone $original;
            unset($row['_source']);
            $subject->setRawAttributes($row);

            return $subject;
        });
    }

    /** @param list<array<string, mixed>> $keywords
     * @return list<array<string, mixed>>
     */
    public function keywords(array $keywords): array
    {
        $rows = array_map(static fn (array $keyword): array => [
            'value' => $keyword['text'] ?? null,
            'language' => $keyword['language'] ?? 'en',
            'subject_scheme' => $keyword['scheme'] ?? null,
            'scheme_uri' => $keyword['schemeURI'] ?? $keyword['schemeUri'] ?? null,
            'value_uri' => filter_var($keyword['id'] ?? '', FILTER_VALIDATE_URL) ? $keyword['id'] : null,
            'classification_code' => $keyword['classificationCode'] ?? null,
            'breadcrumb_path' => $keyword['path'] ?? null,
            '_keyword' => $keyword,
        ], $keywords);

        return array_map(static function (array $row): array {
            /** @var array<string, mixed> $keyword */
            $keyword = $row['_keyword'];
            $keyword = array_replace($keyword, [
                'id' => $row['value_uri'] ?? $keyword['id'], 'text' => $row['value'],
                'path' => $row['breadcrumb_path'], 'scheme' => $row['subject_scheme'],
                'schemeURI' => $row['scheme_uri'], 'language' => $row['language'],
            ]);
            if ($row['classification_code'] !== null) {
                $keyword['classificationCode'] = $row['classification_code'];
            }
            if ($row['value_uri'] !== null) {
                unset($keyword['isLegacy']);
            }

            return $keyword;
        }, $this->analyze($rows)['rows']);
    }
}
