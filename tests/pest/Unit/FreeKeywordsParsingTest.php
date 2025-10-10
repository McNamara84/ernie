<?php

describe('Free Keywords Parsing', function () {
    it('parses comma-separated keywords correctly', function () {
        $keywordsString = 'climate change,temperature,precipitation,global warming';
        
        $keywords = array_map(
            fn($keyword) => trim($keyword),
            explode(',', $keywordsString)
        );
        
        $keywords = array_filter($keywords, fn($keyword) => $keyword !== '');
        $keywords = array_values($keywords);
        
        expect($keywords)->toBe([
            'climate change',
            'temperature',
            'precipitation',
            'global warming',
        ]);
    });

    it('trims whitespace from keywords', function () {
        $keywordsString = ' keyword1 , keyword2  ,  keyword3,keyword4 ';
        
        $keywords = array_map(
            fn($keyword) => trim($keyword),
            explode(',', $keywordsString)
        );
        
        $keywords = array_filter($keywords, fn($keyword) => $keyword !== '');
        $keywords = array_values($keywords);
        
        expect($keywords)->toBe([
            'keyword1',
            'keyword2',
            'keyword3',
            'keyword4',
        ]);
    });

    it('filters out empty keywords', function () {
        $keywordsString = 'keyword1,,keyword2,  ,keyword3';
        
        $keywords = array_map(
            fn($keyword) => trim($keyword),
            explode(',', $keywordsString)
        );
        
        $keywords = array_filter($keywords, fn($keyword) => $keyword !== '');
        $keywords = array_values($keywords);
        
        expect($keywords)->toBe([
            'keyword1',
            'keyword2',
            'keyword3',
        ]);
    });

    it('handles single keyword', function () {
        $keywordsString = 'single-keyword';
        
        $keywords = array_map(
            fn($keyword) => trim($keyword),
            explode(',', $keywordsString)
        );
        
        $keywords = array_filter($keywords, fn($keyword) => $keyword !== '');
        $keywords = array_values($keywords);
        
        expect($keywords)->toBe(['single-keyword']);
    });

    it('handles null keywords', function () {
        $keywordsString = null;
        
        $keywords = [];
        if (!empty($keywordsString)) {
            $keywords = array_map(
                fn($keyword) => trim($keyword),
                explode(',', $keywordsString)
            );
            
            $keywords = array_filter($keywords, fn($keyword) => $keyword !== '');
            $keywords = array_values($keywords);
        }
        
        expect($keywords)->toBe([]);
    });

    it('handles empty string keywords', function () {
        $keywordsString = '';
        
        $keywords = [];
        if (!empty($keywordsString)) {
            $keywords = array_map(
                fn($keyword) => trim($keyword),
                explode(',', $keywordsString)
            );
            
            $keywords = array_filter($keywords, fn($keyword) => $keyword !== '');
            $keywords = array_values($keywords);
        }
        
        expect($keywords)->toBe([]);
    });

    it('preserves mixed case', function () {
        $keywordsString = 'InSAR,GNSS,CO2 storage,pH Level';
        
        $keywords = array_map(
            fn($keyword) => trim($keyword),
            explode(',', $keywordsString)
        );
        
        $keywords = array_filter($keywords, fn($keyword) => $keyword !== '');
        $keywords = array_values($keywords);
        
        expect($keywords)->toBe([
            'InSAR',
            'GNSS',
            'CO2 storage',
            'pH Level',
        ]);
    });
});
