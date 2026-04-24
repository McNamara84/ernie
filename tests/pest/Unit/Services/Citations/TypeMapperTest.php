<?php

declare(strict_types=1);

use App\Services\Citations\CrossrefTypeMapper;
use App\Services\Citations\DataCiteTypeMapper;

covers(CrossrefTypeMapper::class, DataCiteTypeMapper::class);

describe('CrossrefTypeMapper', function () {
    it('maps known Crossref types to DataCite values', function () {
        $m = new CrossrefTypeMapper();
        expect($m->map('journal-article'))->toBe('JournalArticle');
        expect($m->map('book-chapter'))->toBe('BookChapter');
        expect($m->map('proceedings-article'))->toBe('ConferencePaper');
        expect($m->map('dissertation'))->toBe('Dissertation');
        expect($m->map('dataset'))->toBe('Dataset');
        expect($m->map('preprint'))->toBe('Preprint');
        expect($m->map('posted-content'))->toBe('Preprint');
        expect($m->map('standard'))->toBe('Standard');
    });

    it('is case-insensitive', function () {
        expect((new CrossrefTypeMapper())->map('JOURNAL-ARTICLE'))->toBe('JournalArticle');
    });

    it('falls back to Text for unknown or empty types', function () {
        $m = new CrossrefTypeMapper();
        expect($m->map(null))->toBe('Text');
        expect($m->map(''))->toBe('Text');
        expect($m->map('some-unknown-type'))->toBe('Text');
    });
});

describe('DataCiteTypeMapper', function () {
    it('returns canonical value for exact matches', function () {
        expect((new DataCiteTypeMapper())->map('JournalArticle'))->toBe('JournalArticle');
        expect((new DataCiteTypeMapper())->map('Dataset'))->toBe('Dataset');
    });

    it('normalises different casings and separators', function () {
        $m = new DataCiteTypeMapper();
        expect($m->map('journal-article'))->toBe('JournalArticle');
        expect($m->map('book_chapter'))->toBe('BookChapter');
        expect($m->map('conference paper'))->toBe('ConferencePaper');
        expect($m->map('PEERREVIEW'))->toBe('PeerReview');
    });

    it('falls back to Text for unknown / empty / null', function () {
        $m = new DataCiteTypeMapper();
        expect($m->map(null))->toBe('Text');
        expect($m->map(''))->toBe('Text');
        expect($m->map('not-a-real-type'))->toBe('Text');
    });
});
