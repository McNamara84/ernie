<?php

declare(strict_types=1);

namespace App\Services\Citations;

/**
 * Maps Crossref `type` values to DataCite `resourceTypeGeneral` values.
 *
 * Reference:
 *  - Crossref types: https://api.crossref.org/types
 *  - DataCite 4.7 resourceTypeGeneral vocabulary
 */
final class CrossrefTypeMapper
{
    /**
     * @var array<string, string>
     */
    private const MAP = [
        'journal-article' => 'JournalArticle',
        'journal-issue' => 'JournalArticle',
        'journal-volume' => 'JournalArticle',
        'journal' => 'Journal',
        'book' => 'Book',
        'book-chapter' => 'BookChapter',
        'book-part' => 'BookChapter',
        'book-section' => 'BookChapter',
        'book-track' => 'BookChapter',
        'edited-book' => 'Book',
        'monograph' => 'Book',
        'reference-book' => 'Book',
        'proceedings' => 'ConferenceProceeding',
        'proceedings-article' => 'ConferencePaper',
        'proceedings-series' => 'ConferenceProceeding',
        'dissertation' => 'Dissertation',
        'report' => 'Report',
        'report-series' => 'Report',
        'dataset' => 'Dataset',
        'preprint' => 'Preprint',
        'posted-content' => 'Preprint',
        'component' => 'Other',
        'standard' => 'Standard',
        'peer-review' => 'PeerReview',
    ];

    public function map(?string $crossrefType): string
    {
        if ($crossrefType === null || $crossrefType === '') {
            return 'Text';
        }

        return self::MAP[strtolower($crossrefType)] ?? 'Text';
    }
}
