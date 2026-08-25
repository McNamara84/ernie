<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OldDataset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class LegacyKeywordService
{
    /**
     * Load controlled vocabulary keywords for a legacy SUMARIOPMD resource.
     *
     * @return list<array<string, string|bool|null>>
     */
    public function controlledKeywords(OldDataset $dataset): array
    {
        $rows = DB::connection($dataset->getConnectionName())
            ->table('thesauruskeyword as tk')
            ->join('thesaurusvalue as tv', function ($join): void {
                $join->on('tk.keyword', '=', 'tv.keyword')
                    ->on('tk.thesaurus', '=', 'tv.thesaurus');
            })
            ->where('tk.resource_id', $dataset->id)
            ->whereIn('tk.thesaurus', OldDatasetKeywordTransformer::getSupportedThesauri())
            ->select('tv.keyword', 'tv.thesaurus', 'tv.uri', 'tv.description')
            ->orderBy('tk.thesaurus')
            ->orderBy('tk.keyword')
            ->get();

        $keywords = [];

        foreach ($rows as $row) {
            try {
                $keyword = OldDatasetKeywordTransformer::transform($row);
            } catch (\Throwable $exception) {
                Log::warning('Skipping legacy controlled keyword after transformation failure', [
                    'doi' => $dataset->identifier,
                    'legacy_resource_id' => $dataset->id,
                    'keyword' => isset($row->keyword) ? (string) $row->keyword : null,
                    'thesaurus' => isset($row->thesaurus) ? (string) $row->thesaurus : null,
                    'error' => $exception->getMessage(),
                ]);

                continue;
            }

            if ($keyword === null || trim((string) ($keyword['path'] ?? '')) === '') {
                Log::warning('Skipping invalid legacy controlled keyword', [
                    'doi' => $dataset->identifier,
                    'legacy_resource_id' => $dataset->id,
                    'keyword' => isset($row->keyword) ? (string) $row->keyword : null,
                    'thesaurus' => isset($row->thesaurus) ? (string) $row->thesaurus : null,
                ]);

                continue;
            }

            $keywords[] = $keyword;
        }

        return $keywords;
    }

    /**
     * Load comma-separated free keywords for a legacy SUMARIOPMD resource.
     *
     * @return list<string>
     */
    public function freeKeywords(OldDataset $dataset): array
    {
        if (! is_string($dataset->keywords) || trim($dataset->keywords) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn (string $keyword): string => trim($keyword),
                explode(',', $dataset->keywords),
            ),
            static fn (string $keyword): bool => $keyword !== '',
        ));
    }

    /**
     * Convert all supported legacy keywords to canonical DataCite subjects.
     * Controlled subjects are returned before free subjects.
     *
     * @return list<array<string, string>>
     */
    public function dataCiteSubjects(OldDataset $dataset): array
    {
        $subjects = [];

        foreach ($this->controlledKeywords($dataset) as $keyword) {
            $subject = trim((string) $keyword['path']);
            $subjectScheme = trim((string) $keyword['scheme']);
            $schemeUri = trim((string) ($keyword['schemeURI'] ?? ''));
            $valueUri = trim((string) $keyword['id']);

            $controlledSubject = [
                'subject' => $subject,
                'subjectScheme' => $subjectScheme,
                'lang' => 'en',
            ];

            if (filter_var($valueUri, FILTER_VALIDATE_URL)) {
                $controlledSubject['valueUri'] = $valueUri;
            }

            if ($schemeUri !== '') {
                $controlledSubject['schemeUri'] = $schemeUri;
            }

            $subjects[] = $controlledSubject;
        }

        foreach ($this->freeKeywords($dataset) as $keyword) {
            $subjects[] = ['subject' => $keyword];
        }

        return $subjects;
    }
}
