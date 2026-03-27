import { renderHook, waitFor } from '@testing-library/react';
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

    it('extracts author and year from single-author citation', () => {
        expect(extractAuthorYear('Mueller, H. (2023). Some Dataset. GFZ.')).toBe('Mueller, 2023');
    });

    it('returns author only when no year found', () => {
        expect(extractAuthorYear('Doe, J. Title without year.')).toBe('Doe');
    });

    it('falls back to truncated string when no author pattern', () => {
        const longCitation = 'A'.repeat(50);
        // No comma → firstAuthor is the full string, no year → returns firstAuthor
        expect(extractAuthorYear(longCitation)).toBe('A'.repeat(50));
    });
});

describe('getCitationKey', () => {
    it('normalizes DOI identifiers', () => {
        expect(getCitationKey('DOI', 'https://doi.org/10.5880/test')).toBe('10.5880/test');
        expect(getCitationKey('DOI', '10.5880/test')).toBe('10.5880/test');
    });

    it('creates composite key for non-DOI identifiers', () => {
        expect(getCitationKey('URL', 'https://example.com')).toBe('URL:https://example.com');
        expect(getCitationKey('ISBN', '978-3-06-024')).toBe('ISBN:978-3-06-024');
    });
});

describe('useCitationLabels', () => {
    beforeEach(() => {
        vi.resetAllMocks();
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
    });

    it('fetches citations for DOI identifiers', async () => {
        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ citation: 'Doe, J. (2024). Test Dataset. GFZ.' }),
        });

        const identifiers = [
            { identifier: '10.5880/test.2024', identifier_type: 'DOI' },
        ];

        const { result } = renderHook(() => useCitationLabels(identifiers));

        // Initially loading
        expect(result.current.get('10.5880/test.2024')?.loading).toBe(true);

        await waitFor(() => {
            expect(result.current.get('10.5880/test.2024')?.loading).toBe(false);
        });

        expect(result.current.get('10.5880/test.2024')).toEqual({
            shortLabel: 'Doe, 2024',
            fullCitation: 'Doe, J. (2024). Test Dataset. GFZ.',
            loading: false,
        });
    });

    it('handles fetch errors gracefully', async () => {
        global.fetch = vi.fn().mockResolvedValue({
            ok: false,
        });

        const identifiers = [
            { identifier: '10.5880/bad-doi', identifier_type: 'DOI' },
        ];

        const { result } = renderHook(() => useCitationLabels(identifiers));

        await waitFor(() => {
            expect(result.current.get('10.5880/bad-doi')?.loading).toBe(false);
        });

        expect(result.current.get('10.5880/bad-doi')?.fullCitation).toBe('10.5880/bad-doi');
    });

    it('deduplicates DOI fetches', async () => {
        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ citation: 'Doe, J. (2024). Test. GFZ.' }),
        });

        const identifiers = [
            { identifier: '10.5880/test', identifier_type: 'DOI' },
            { identifier: '10.5880/test', identifier_type: 'DOI' },
        ];

        renderHook(() => useCitationLabels(identifiers));

        await waitFor(() => {
            expect(global.fetch).toHaveBeenCalledTimes(1);
        });
    });

    it('normalizes DOI resolver URLs before fetching', async () => {
        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ citation: 'Smith, A. (2023). Title. GFZ.' }),
        });

        const identifiers = [
            { identifier: 'https://doi.org/10.5880/test', identifier_type: 'DOI' },
        ];

        renderHook(() => useCitationLabels(identifiers));

        await waitFor(() => {
            expect(global.fetch).toHaveBeenCalledWith(
                '/api/datacite/citation/10.5880%2Ftest',
                expect.any(Object),
            );
        });
    });

    it('uses pre-provided citationTexts instead of fetching', async () => {
        global.fetch = vi.fn();

        const identifiers = [
            { identifier: '10.5880/provided', identifier_type: 'DOI' },
        ];
        const citationTexts = new Map([
            ['10.5880/provided', 'Doe, J. (2024). Provided Citation. GFZ.'],
        ]);

        const { result } = renderHook(() => useCitationLabels(identifiers, citationTexts));

        expect(result.current.get('10.5880/provided')).toEqual({
            shortLabel: 'Doe, 2024',
            fullCitation: 'Doe, J. (2024). Provided Citation. GFZ.',
            loading: false,
        });

        // Should not fetch since citation was pre-provided
        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('fetches only DOIs not covered by citationTexts', async () => {
        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ citation: 'Smith, A. (2023). Fetched. GFZ.' }),
        });

        const identifiers = [
            { identifier: '10.5880/provided', identifier_type: 'DOI' },
            { identifier: '10.5880/missing', identifier_type: 'DOI' },
        ];
        const citationTexts = new Map([
            ['10.5880/provided', 'Doe, J. (2024). Provided. GFZ.'],
        ]);

        const { result } = renderHook(() => useCitationLabels(identifiers, citationTexts));

        // Provided one is immediately available
        expect(result.current.get('10.5880/provided')?.loading).toBe(false);
        // Missing one still fetches
        expect(result.current.get('10.5880/missing')?.loading).toBe(true);

        await waitFor(() => {
            expect(global.fetch).toHaveBeenCalledTimes(1);
            expect(global.fetch).toHaveBeenCalledWith(
                '/api/datacite/citation/10.5880%2Fmissing',
                expect.any(Object),
            );
        });
    });
});
