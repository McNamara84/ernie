import { describe, expect, it } from 'vitest';

import { parseOldDatasetFiltersFromUrl, parseResourceFiltersFromUrl } from '@/utils/filter-parser';

describe('parseResourceFiltersFromUrl', () => {
    it('should return empty object for empty search string', () => {
        const result = parseResourceFiltersFromUrl('');
        expect(result).toEqual({});
    });

    it('should parse single resource_type with array notation', () => {
        const result = parseResourceFiltersFromUrl('?resource_type[]=dataset');
        expect(result).toEqual({
            resource_type: ['dataset'],
        });
    });

    it('should parse multiple resource_type values', () => {
        const result = parseResourceFiltersFromUrl('?resource_type[]=dataset&resource_type[]=collection');
        expect(result).toEqual({
            resource_type: ['dataset', 'collection'],
        });
    });

    it('should parse single resource_type without array notation', () => {
        const result = parseResourceFiltersFromUrl('?resource_type=dataset');
        expect(result).toEqual({
            resource_type: ['dataset'],
        });
    });

    it('should parse status filter', () => {
        const result = parseResourceFiltersFromUrl('?status[]=published');
        expect(result).toEqual({
            status: ['published'],
        });
    });

    it('should parse multiple status values', () => {
        const result = parseResourceFiltersFromUrl('?status[]=published&status[]=review');
        expect(result).toEqual({
            status: ['published', 'review'],
        });
    });

    it('should parse curator filter', () => {
        const result = parseResourceFiltersFromUrl('?curator[]=John+Doe');
        expect(result).toEqual({
            curator: ['John Doe'],
        });
    });

    it('should parse year_from as number', () => {
        const result = parseResourceFiltersFromUrl('?year_from=2020');
        expect(result).toEqual({
            year_from: 2020,
        });
    });

    it('should parse year_to as number', () => {
        const result = parseResourceFiltersFromUrl('?year_to=2023');
        expect(result).toEqual({
            year_to: 2023,
        });
    });

    it('should ignore invalid year_from values', () => {
        const result = parseResourceFiltersFromUrl('?year_from=abc');
        expect(result).toEqual({});
    });

    it('should ignore negative year values', () => {
        const result = parseResourceFiltersFromUrl('?year_from=-2020');
        expect(result).toEqual({});
    });

    it('should ignore zero year values', () => {
        const result = parseResourceFiltersFromUrl('?year_from=0');
        expect(result).toEqual({});
    });

    it('should parse search query', () => {
        const result = parseResourceFiltersFromUrl('?search=test+query');
        expect(result).toEqual({
            search: 'test query',
        });
    });

    it('should trim search query', () => {
        const result = parseResourceFiltersFromUrl('?search=++test++');
        expect(result).toEqual({
            search: 'test',
        });
    });

    it('should ignore empty search query', () => {
        const result = parseResourceFiltersFromUrl('?search=');
        expect(result).toEqual({});
    });

    it('should ignore whitespace-only search query', () => {
        const result = parseResourceFiltersFromUrl('?search=+++');
        expect(result).toEqual({});
    });

    it('should parse created_from date', () => {
        const result = parseResourceFiltersFromUrl('?created_from=2023-01-01');
        expect(result).toEqual({
            created_from: '2023-01-01',
        });
    });

    it('should parse created_to date', () => {
        const result = parseResourceFiltersFromUrl('?created_to=2023-12-31');
        expect(result).toEqual({
            created_to: '2023-12-31',
        });
    });

    it('should parse updated_from date', () => {
        const result = parseResourceFiltersFromUrl('?updated_from=2023-06-01');
        expect(result).toEqual({
            updated_from: '2023-06-01',
        });
    });

    it('should parse updated_to date', () => {
        const result = parseResourceFiltersFromUrl('?updated_to=2023-06-30');
        expect(result).toEqual({
            updated_to: '2023-06-30',
        });
    });

    it('should trim date values', () => {
        const result = parseResourceFiltersFromUrl('?created_from=++2023-01-01++');
        expect(result).toEqual({
            created_from: '2023-01-01',
        });
    });

    it('should ignore empty date values', () => {
        const result = parseResourceFiltersFromUrl('?created_from=');
        expect(result).toEqual({});
    });

    it('should parse multiple filters together', () => {
        const result = parseResourceFiltersFromUrl(
            '?resource_type[]=dataset&status[]=published&year_from=2020&year_to=2023&search=climate&created_from=2023-01-01'
        );
        expect(result).toEqual({
            resource_type: ['dataset'],
            status: ['published'],
            year_from: 2020,
            year_to: 2023,
            search: 'climate',
            created_from: '2023-01-01',
        });
    });

    it('should handle URL-encoded values correctly', () => {
        const result = parseResourceFiltersFromUrl('?search=climate%20change&curator[]=John%20Doe');
        expect(result).toEqual({
            search: 'climate change',
            curator: ['John Doe'],
        });
    });

    it('should handle question mark at start of search string', () => {
        const result = parseResourceFiltersFromUrl('?resource_type[]=dataset');
        expect(result).toEqual({
            resource_type: ['dataset'],
        });
    });

    it('should handle search string without question mark', () => {
        const result = parseResourceFiltersFromUrl('resource_type[]=dataset');
        expect(result).toEqual({
            resource_type: ['dataset'],
        });
    });
});

describe('parseOldDatasetFiltersFromUrl', () => {
    it('should return empty object for empty search string', () => {
        const result = parseOldDatasetFiltersFromUrl('');
        expect(result).toEqual({});
    });

    it('should parse resource_type filter', () => {
        const result = parseOldDatasetFiltersFromUrl('?resource_type[]=dataset');
        expect(result).toEqual({
            resource_type: ['dataset'],
        });
    });

    it('should parse multiple filters', () => {
        const result = parseOldDatasetFiltersFromUrl(
            '?resource_type[]=dataset&status[]=published&year_from=2020&search=test'
        );
        expect(result).toEqual({
            resource_type: ['dataset'],
            status: ['published'],
            year_from: 2020,
            search: 'test',
        });
    });

    it('should parse curator filter', () => {
        const result = parseOldDatasetFiltersFromUrl('?curator[]=Jane+Smith');
        expect(result).toEqual({
            curator: ['Jane Smith'],
        });
    });

    it('should parse year range', () => {
        const result = parseOldDatasetFiltersFromUrl('?year_from=2015&year_to=2025');
        expect(result).toEqual({
            year_from: 2015,
            year_to: 2025,
        });
    });

    it('should parse date ranges', () => {
        const result = parseOldDatasetFiltersFromUrl(
            '?created_from=2023-01-01&created_to=2023-12-31&updated_from=2023-06-01&updated_to=2023-06-30'
        );
        expect(result).toEqual({
            created_from: '2023-01-01',
            created_to: '2023-12-31',
            updated_from: '2023-06-01',
            updated_to: '2023-06-30',
        });
    });

    it('should handle both array and single value notation', () => {
        const result1 = parseOldDatasetFiltersFromUrl('?resource_type[]=dataset');
        const result2 = parseOldDatasetFiltersFromUrl('?resource_type=dataset');
        
        expect(result1).toEqual({ resource_type: ['dataset'] });
        expect(result2).toEqual({ resource_type: ['dataset'] });
    });
});
