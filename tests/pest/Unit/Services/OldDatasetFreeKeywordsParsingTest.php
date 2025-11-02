<?php

describe('Old Dataset Free Keywords Parsing Logic', function () {
    it('parses comma-separated keywords from old database format', function () {
        $keywordsString = 'climate change,temperature,precipitation';

        $keywords = [];
        if (! empty($keywordsString)) {
            $keywords = array_map(
                fn ($keyword) => trim($keyword),
                explode(',', $keywordsString)
            );

            $keywords = array_filter($keywords, fn ($keyword) => $keyword !== '');
            $keywords = array_values($keywords);
        }

        expect($keywords)->toBe([
            'climate change',
            'temperature',
            'precipitation',
        ]);
    });

    it('trims whitespace from keywords in old database format', function () {
        $keywordsString = ' keyword1 , keyword2  ,  keyword3,keyword4 ';

        $keywords = [];
        if (! empty($keywordsString)) {
            $keywords = array_map(
                fn ($keyword) => trim($keyword),
                explode(',', $keywordsString)
            );

            $keywords = array_filter($keywords, fn ($keyword) => $keyword !== '');
            $keywords = array_values($keywords);
        }

        expect($keywords)->toBe([
            'keyword1',
            'keyword2',
            'keyword3',
            'keyword4',
        ]);
    });

    it('filters out empty keywords from old database format', function () {
        $keywordsString = 'keyword1,,keyword2,  ,keyword3';

        $keywords = [];
        if (! empty($keywordsString)) {
            $keywords = array_map(
                fn ($keyword) => trim($keyword),
                explode(',', $keywordsString)
            );

            $keywords = array_filter($keywords, fn ($keyword) => $keyword !== '');
            $keywords = array_values($keywords);
        }

        expect($keywords)->toBe([
            'keyword1',
            'keyword2',
            'keyword3',
        ]);
    });

    it('handles null keywords from old database', function () {
        $keywordsString = null;

        $keywords = [];
        if (! empty($keywordsString)) {
            $keywords = array_map(
                fn ($keyword) => trim($keyword),
                explode(',', $keywordsString)
            );

            $keywords = array_filter($keywords, fn ($keyword) => $keyword !== '');
            $keywords = array_values($keywords);
        }

        expect($keywords)->toBe([]);
    });

    it('handles empty string keywords from old database', function () {
        $keywordsString = '';

        $keywords = [];
        if (! empty($keywordsString)) {
            $keywords = array_map(
                fn ($keyword) => trim($keyword),
                explode(',', $keywordsString)
            );

            $keywords = array_filter($keywords, fn ($keyword) => $keyword !== '');
            $keywords = array_values($keywords);
        }

        expect($keywords)->toBe([]);
    });

    it('handles single keyword from old database', function () {
        $keywordsString = 'single-keyword';

        $keywords = [];
        if (! empty($keywordsString)) {
            $keywords = array_map(
                fn ($keyword) => trim($keyword),
                explode(',', $keywordsString)
            );

            $keywords = array_filter($keywords, fn ($keyword) => $keyword !== '');
            $keywords = array_values($keywords);
        }

        expect($keywords)->toBe(['single-keyword']);
    });

    it('preserves mixed case in old database keywords', function () {
        $keywordsString = 'InSAR,GNSS,CO2 storage,pH Level';

        $keywords = [];
        if (! empty($keywordsString)) {
            $keywords = array_map(
                fn ($keyword) => trim($keyword),
                explode(',', $keywordsString)
            );

            $keywords = array_filter($keywords, fn ($keyword) => $keyword !== '');
            $keywords = array_values($keywords);
        }

        expect($keywords)->toBe([
            'InSAR',
            'GNSS',
            'CO2 storage',
            'pH Level',
        ]);
    });

    it('ensures sequential array keys after filtering', function () {
        $keywordsString = 'keyword1,,,keyword2,,keyword3,,,';

        $keywords = [];
        if (! empty($keywordsString)) {
            $keywords = array_map(
                fn ($keyword) => trim($keyword),
                explode(',', $keywordsString)
            );

            $keywords = array_filter($keywords, fn ($keyword) => $keyword !== '');
            $keywords = array_values($keywords);
        }

        // Verify keys are sequential 0, 1, 2
        expect(array_keys($keywords))->toBe([0, 1, 2]);
        expect($keywords)->toBe([
            'keyword1',
            'keyword2',
            'keyword3',
        ]);
    });

    it('handles keywords with special characters from old database', function () {
        $keywordsString = 'CO2 storage, μ-CT imaging, 3D modeling, β-diversity';

        $keywords = [];
        if (! empty($keywordsString)) {
            $keywords = array_map(
                fn ($keyword) => trim($keyword),
                explode(',', $keywordsString)
            );

            $keywords = array_filter($keywords, fn ($keyword) => $keyword !== '');
            $keywords = array_values($keywords);
        }

        expect($keywords)->toBe([
            'CO2 storage',
            'μ-CT imaging',
            '3D modeling',
            'β-diversity',
        ]);
    });

    it('handles keywords with hyphens and underscores', function () {
        $keywordsString = 'climate-change, earth_science, geo-physics, data_analysis';

        $keywords = [];
        if (! empty($keywordsString)) {
            $keywords = array_map(
                fn ($keyword) => trim($keyword),
                explode(',', $keywordsString)
            );

            $keywords = array_filter($keywords, fn ($keyword) => $keyword !== '');
            $keywords = array_values($keywords);
        }

        expect($keywords)->toBe([
            'climate-change',
            'earth_science',
            'geo-physics',
            'data_analysis',
        ]);
    });

    it('handles very long keyword strings from old database', function () {
        // Simulate a long list of keywords
        $keywordArray = array_map(fn ($i) => "keyword{$i}", range(1, 50));
        $keywordsString = implode(',', $keywordArray);

        $keywords = [];
        if (! empty($keywordsString)) {
            $keywords = array_map(
                fn ($keyword) => trim($keyword),
                explode(',', $keywordsString)
            );

            $keywords = array_filter($keywords, fn ($keyword) => $keyword !== '');
            $keywords = array_values($keywords);
        }

        expect($keywords)->toHaveCount(50);
        expect($keywords[0])->toBe('keyword1');
        expect($keywords[49])->toBe('keyword50');
    });
});
