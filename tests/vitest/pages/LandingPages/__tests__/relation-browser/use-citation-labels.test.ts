import { renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import {
    extractAuthorYear,
    getCitationKey,
    useCitationLabels,
} from '@/pages/LandingPages/components/relation-browser/use-citation-labels';

describe('extractAuthorYear', () => {
    it('extracts author and year from APA citation', () => {
        expect(extractAuthorYear('Doe, J., Smith, A. (2024). Title. Publisher.')).toBe('Doe, 2024');
    });

    it('returns author only when no year is found', () => {
        expect(extractAuthorYear('Doe, J. Title without year.')).toBe('Doe');
    });
});

describe('getCitationKey', () => {
    it('normalizes DOI identifiers', () => {
        expect(getCitationKey('DOI', 'https://doi.org/10.5880/test')).toBe('10.5880/test');
        expect(getCitationKey('DOI', '10.5880/test')).toBe('10.5880/test');
    });

    it('creates composite keys for non-DOI identifiers', () => {
        expect(getCitationKey('URL', 'https://example.com')).toBe('URL:https://example.com');
    });
});

describe('useCitationLabels', () => {
    beforeEach(() => {
        vi.resetAllMocks();
        global.fetch = vi.fn();
    });

    it('returns immediate labels for non-DOI identifiers', () => {
        const identifiers = [
            { identifier: '978-3-06-024810-5', identifier_type: 'ISBN' },
            { identifier: 'https://example.com', identifier_type: 'URL' },
        ];

        const { result } = renderHook(() => useCitationLabels(identifiers));

        expect(result.current.get('ISBN:978-3-06-024810-5')).toEqual({
            shortLabel: 'ISBN: 978-3-06-024810-5',
            fullCitation: '978-3-06-024810-5',
            loading: false,
        });
        expect(result.current.get('URL:https://example.com')).toEqual({
            shortLabel: 'URL: https://example.com',
            fullCitation: 'https://example.com',
            loading: false,
        });
        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('uses persisted citation labels for DOI identifiers', () => {
        const { result } = renderHook(() => useCitationLabels([
            {
                identifier: '10.5880/test.2024',
                identifier_type: 'DOI',
                citation_label: 'Doe, J. (2024). Test Dataset. GFZ.',
            },
        ]));

        expect(result.current.get('10.5880/test.2024')).toEqual({
            shortLabel: 'Doe, 2024',
            fullCitation: 'Doe, J. (2024). Test Dataset. GFZ.',
            loading: false,
        });
    });

    it('falls back to the DOI when no citation label exists', () => {
        const { result } = renderHook(() => useCitationLabels([
            { identifier: '10.5880/bad-doi', identifier_type: 'DOI' },
        ]));

        expect(result.current.get('10.5880/bad-doi')).toEqual({
            shortLabel: '10.5880/bad-doi',
            fullCitation: '10.5880/bad-doi',
            loading: false,
        });
    });

    it('deduplicates repeated DOI entries by normalized key', () => {
        const { result } = renderHook(() => useCitationLabels([
            { identifier: '10.5880/test', identifier_type: 'DOI', citation_label: 'Doe, J. (2024). Test. GFZ.' },
            { identifier: 'https://doi.org/10.5880/test', identifier_type: 'DOI' },
        ]));

        expect(result.current.size).toBe(1);
        expect(result.current.get('10.5880/test')?.fullCitation).toBe('Doe, J. (2024). Test. GFZ.');
    });

    it('uses pre-provided citationTexts when identifier data lacks citation_label', () => {
        const citationTexts = new Map([
            ['10.5880/provided', 'Doe, J. (2024). Provided Citation. GFZ.'],
        ]);

        const { result } = renderHook(() => useCitationLabels([
            { identifier: '10.5880/provided', identifier_type: 'DOI' },
        ], citationTexts));

        expect(result.current.get('10.5880/provided')).toEqual({
            shortLabel: 'Doe, 2024',
            fullCitation: 'Doe, J. (2024). Provided Citation. GFZ.',
            loading: false,
        });
        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('falls back synchronously for DOIs not covered by citationTexts', () => {
        const citationTexts = new Map([
            ['10.5880/provided', 'Doe, J. (2024). Provided. GFZ.'],
        ]);

        const { result } = renderHook(() => useCitationLabels([
            { identifier: '10.5880/provided', identifier_type: 'DOI' },
            { identifier: '10.5880/missing', identifier_type: 'DOI' },
        ], citationTexts));

        expect(result.current.get('10.5880/provided')).toEqual({
            shortLabel: 'Doe, 2024',
            fullCitation: 'Doe, J. (2024). Provided. GFZ.',
            loading: false,
        });
        expect(result.current.get('10.5880/missing')).toEqual({
            shortLabel: '10.5880/missing',
            fullCitation: '10.5880/missing',
            loading: false,
        });
        expect(global.fetch).not.toHaveBeenCalled();
    });
});