<?php

declare(strict_types=1);

use App\Services\SlugGeneratorService;

describe('SlugGeneratorService', function () {
    beforeEach(function () {
        $this->service = new SlugGeneratorService();
    });

    describe('basic slug generation', function () {
        it('converts title to lowercase slug', function () {
            expect($this->service->generateFromTitle('Hello World'))
                ->toBe('hello-world');
        });

        it('replaces spaces with hyphens', function () {
            expect($this->service->generateFromTitle('Multiple   Spaces   Here'))
                ->toBe('multiple-spaces-here');
        });

        it('removes special characters', function () {
            expect($this->service->generateFromTitle('Test & Analysis: Results (2024)'))
                ->toBe('test-analysis-results-2024');
        });

        it('handles underscores as word separators', function () {
            expect($this->service->generateFromTitle('some_snake_case_title'))
                ->toBe('some-snake-case-title');
        });

        it('removes multiple consecutive hyphens', function () {
            expect($this->service->generateFromTitle('Test - - - Multiple'))
                ->toBe('test-multiple');
        });

        it('trims hyphens from start and end', function () {
            expect($this->service->generateFromTitle('- Leading and Trailing -'))
                ->toBe('leading-and-trailing');
        });

        it('returns fallback for empty string', function () {
            expect($this->service->generateFromTitle(''))
                ->toBe('dataset');
        });

        it('returns fallback for string with only special characters', function () {
            expect($this->service->generateFromTitle('!@#$%^&*()'))
                ->toBe('dataset');
        });
    });

    describe('transliteration', function () {
        it('transliterates German umlauts', function () {
            expect($this->service->generateFromTitle('Böden der Südalpen'))
                ->toBe('boeden-der-suedalpen');
        });

        it('transliterates German sharp s', function () {
            expect($this->service->generateFromTitle('Straße'))
                ->toBe('strasse');
        });

        it('transliterates French accents', function () {
            expect($this->service->generateFromTitle('Café résumé'))
                ->toBe('cafe-resume');
        });

        it('transliterates Spanish characters', function () {
            expect($this->service->generateFromTitle('Niño español'))
                ->toBe('nino-espanol');
        });

        it('transliterates Nordic characters', function () {
            expect($this->service->generateFromTitle('Ærø Øresund Åland'))
                ->toBe('aero-oresund-aland');
        });

        it('transliterates mixed special characters', function () {
            expect($this->service->generateFromTitle('Müller & Associés: Données géologiques'))
                ->toBe('mueller-associes-donnees-geologiques');
        });

        it('handles smart quotes and dashes', function () {
            expect($this->service->generateFromTitle('Test "quoted" text – with em-dash'))
                ->toBe('test-quoted-text-with-em-dash');
        });
    });

    describe('truncation at word boundary', function () {
        it('does not truncate titles shorter than minimum length', function () {
            $slug = $this->service->generateFromTitle('Short Title', 40);

            expect($slug)->toBe('short-title');
            expect(strlen($slug))->toBeLessThan(40);
        });

        it('truncates at word boundary after minimum length', function () {
            $title = 'Superconducting Gravimeter Data from Buchenbach Observatory Germany';
            $slug = $this->service->generateFromTitle($title, 40);

            // Should be at least 40 chars
            expect(strlen($slug))->toBeGreaterThanOrEqual(40);
            // Should not end with a hyphen
            expect($slug)->not->toEndWith('-');
            // Should be a valid truncation
            expect($slug)->toBe('superconducting-gravimeter-data-from-buchenbach');
        });

        it('keeps full text if no hyphen after minimum length', function () {
            // This title when slugified has no hyphen after position 40
            $title = 'averylongwordwithoutanyspacesorhyphensatall';
            $slug = $this->service->generateFromTitle($title, 10);

            expect($slug)->toBe('averylongwordwithoutanyspacesorhyphensatall');
        });

        it('uses custom minimum length', function () {
            $title = 'This is a test title with many words';
            $slug20 = $this->service->generateFromTitle($title, 20);
            $slug10 = $this->service->generateFromTitle($title, 10);

            expect(strlen($slug10))->toBeLessThanOrEqual(strlen($slug20));
        });

        it('truncates exactly at minimum 40 chars by default for long titles', function () {
            $title = 'Seismological data analysis of the 2024 earthquake swarm in the Alpine region of Central Europe';
            $slug = $this->service->generateFromTitle($title);

            // Default is 40, should truncate at next word boundary after 40
            expect(strlen($slug))->toBeGreaterThanOrEqual(40);
        });
    });

    describe('real-world examples', function () {
        it('handles typical scientific dataset title', function () {
            $title = 'Superconducting Gravimeter Data from Buchenbach (BU) - Level 1';
            $slug = $this->service->generateFromTitle($title);

            // Truncates at word boundary after 40 chars
            expect($slug)->toBe('superconducting-gravimeter-data-from-buchenbach');
        });

        it('handles German scientific title', function () {
            $title = 'Geologische Übersichtskarte der Bundesrepublik Deutschland 1:200.000';
            $slug = $this->service->generateFromTitle($title);

            expect($slug)->toStartWith('geologische-uebersichtskarte');
        });

        /**
         * Tests slug generation for titles containing version numbers.
         *
         * Known limitation: Version dots are removed since periods are stripped as
         * special characters. For example, "v2.1" becomes "v21". Preserving the dot
         * would require special handling for version patterns, which risks breaking
         * other cases where dots should be removed (file extensions, abbreviations).
         * For scientific datasets, version info is typically in metadata, not the URL slug.
         *
         * @see SlugGeneratorService::generateFromTitle() for the stripping logic
         */
        it('handles title with year and version', function () {
            $title = 'Global Temperature Dataset v2.1 (2024)';
            $slug = $this->service->generateFromTitle($title);

            expect($slug)->toBe('global-temperature-dataset-v21-2024');
        });

        it('handles title with coordinates', function () {
            // Note: Degree symbol (°) transliteration is system/locale-dependent via iconv.
            // Some systems strip the degree symbol entirely, others convert it to '0'.
            // We test the key behavior: coordinates are converted to URL-safe format.
            $title = 'Borehole Data 52.5°N 13.4°E Berlin';
            $slug = $this->service->generateFromTitle($title);

            // Core assertion: slug is URL-safe and contains coordinate digits
            expect($slug)->toMatch('/^borehole-data-52.*n-13.*e-berlin$/');
            // Verify no invalid characters
            expect($slug)->toMatch('/^[a-z0-9-]+$/');
        });
    });
});
