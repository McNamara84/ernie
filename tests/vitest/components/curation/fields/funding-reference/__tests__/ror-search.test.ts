import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { getFunderByRorId, loadRorFunders, searchRorFunders } from '@/components/curation/fields/funding-reference/ror-search';
import type { RorFunder } from '@/components/curation/fields/funding-reference/types';

describe('ror-search', () => {
    const mockFunders: RorFunder[] = [
        {
            prefLabel: 'Deutsche Forschungsgemeinschaft',
            otherLabel: ['DFG', 'German Research Foundation'],
            rorId: 'https://ror.org/018mejw64',
        },
        {
            prefLabel: 'National Science Foundation',
            otherLabel: ['NSF'],
            rorId: 'https://ror.org/021nxhr62',
        },
        {
            prefLabel: 'European Research Council',
            otherLabel: ['ERC'],
            rorId: 'https://ror.org/0472cxd90',
        },
        {
            prefLabel: 'Helmholtz Association',
            otherLabel: ['Helmholtz-Gemeinschaft', 'HGF'],
            rorId: 'https://ror.org/0281dp749',
        },
        {
            prefLabel: 'Max Planck Society',
            otherLabel: ['Max-Planck-Gesellschaft', 'MPG'],
            rorId: 'https://ror.org/01hhn8329',
        },
    ];

    describe('searchRorFunders', () => {
        it('returns empty array for empty query', () => {
            const result = searchRorFunders(mockFunders, '');
            expect(result).toEqual([]);
        });

        it('returns empty array for query with only whitespace', () => {
            const result = searchRorFunders(mockFunders, '   ');
            expect(result).toEqual([]);
        });

        it('returns empty array for query shorter than 2 characters', () => {
            const result = searchRorFunders(mockFunders, 'D');
            expect(result).toEqual([]);
        });

        it('finds funder by exact prefLabel match (case insensitive)', () => {
            const result = searchRorFunders(mockFunders, 'deutsche forschungsgemeinschaft');
            expect(result).toHaveLength(1);
            expect(result[0].prefLabel).toBe('Deutsche Forschungsgemeinschaft');
        });

        it('finds funder when prefLabel starts with query', () => {
            const result = searchRorFunders(mockFunders, 'National Sci');
            expect(result).toHaveLength(1);
            expect(result[0].prefLabel).toBe('National Science Foundation');
        });

        it('finds funder when query contains all words (multi-word search)', () => {
            const result = searchRorFunders(mockFunders, 'Science Foundation');
            expect(result.some((f) => f.prefLabel === 'National Science Foundation')).toBe(true);
        });

        it('finds funder when prefLabel contains query', () => {
            const result = searchRorFunders(mockFunders, 'Research');
            expect(result.length).toBeGreaterThanOrEqual(2);
            expect(result.some((f) => f.prefLabel.includes('Research'))).toBe(true);
        });

        it('finds funder by exact otherLabel match', () => {
            const result = searchRorFunders(mockFunders, 'DFG');
            expect(result).toHaveLength(1);
            expect(result[0].prefLabel).toBe('Deutsche Forschungsgemeinschaft');
        });

        it('finds funder when otherLabel starts with query', () => {
            const result = searchRorFunders(mockFunders, 'German Research');
            expect(result.some((f) => f.prefLabel === 'Deutsche Forschungsgemeinschaft')).toBe(true);
        });

        it('finds funder when otherLabel contains query', () => {
            const result = searchRorFunders(mockFunders, 'Planck');
            expect(result.some((f) => f.prefLabel === 'Max Planck Society')).toBe(true);
        });

        it('ranks exact prefLabel match higher than partial match', () => {
            // Add a funder with partial match
            const extendedFunders: RorFunder[] = [
                ...mockFunders,
                {
                    prefLabel: 'ERC Advanced Grant Program',
                    otherLabel: [],
                    rorId: 'https://ror.org/test123',
                },
            ];
            const result = searchRorFunders(extendedFunders, 'ERC');
            // Exact otherLabel match should come before partial prefLabel match
            expect(result[0].prefLabel).toBe('European Research Council');
        });

        it('ranks exact otherLabel match higher than partial prefLabel match', () => {
            const result = searchRorFunders(mockFunders, 'NSF');
            expect(result[0].prefLabel).toBe('National Science Foundation');
        });

        it('respects the limit parameter', () => {
            const result = searchRorFunders(mockFunders, 'Research', 1);
            expect(result).toHaveLength(1);
        });

        it('uses default limit of 10', () => {
            // Create more than 10 matching funders
            const manyFunders: RorFunder[] = Array.from({ length: 15 }, (_, i) => ({
                prefLabel: `Research Institute ${i}`,
                otherLabel: [],
                rorId: `https://ror.org/test${i}`,
            }));
            const result = searchRorFunders(manyFunders, 'Research');
            expect(result).toHaveLength(10);
        });

        it('handles funders without otherLabel array', () => {
            const fundersWithoutOtherLabel: RorFunder[] = [
                {
                    prefLabel: 'Test Funder',
                    otherLabel: undefined as unknown as string[],
                    rorId: 'https://ror.org/test',
                },
            ];
            const result = searchRorFunders(fundersWithoutOtherLabel, 'Test');
            expect(result).toHaveLength(1);
        });

        it('returns empty array when no matches found', () => {
            const result = searchRorFunders(mockFunders, 'XYZNOTFOUND');
            expect(result).toEqual([]);
        });
    });

    describe('getFunderByRorId', () => {
        it('returns undefined for empty rorId', () => {
            const result = getFunderByRorId(mockFunders, '');
            expect(result).toBeUndefined();
        });

        it('finds funder by full ROR URL', () => {
            const result = getFunderByRorId(mockFunders, 'https://ror.org/018mejw64');
            expect(result).toBeDefined();
            expect(result?.prefLabel).toBe('Deutsche Forschungsgemeinschaft');
        });

        it('finds funder by ROR ID without URL prefix', () => {
            const result = getFunderByRorId(mockFunders, '018mejw64');
            expect(result).toBeDefined();
            expect(result?.prefLabel).toBe('Deutsche Forschungsgemeinschaft');
        });

        it('handles http:// prefix', () => {
            const result = getFunderByRorId(mockFunders, 'http://ror.org/018mejw64');
            expect(result).toBeDefined();
            expect(result?.prefLabel).toBe('Deutsche Forschungsgemeinschaft');
        });

        it('returns undefined when ROR ID not found', () => {
            const result = getFunderByRorId(mockFunders, 'https://ror.org/notexistent');
            expect(result).toBeUndefined();
        });
    });

    describe('loadRorFunders', () => {
        const originalFetch = globalThis.fetch;

        beforeEach(() => {
            vi.clearAllMocks();
        });

        afterEach(() => {
            globalThis.fetch = originalFetch;
        });

        it('fetches funders from the API endpoint', async () => {
            const mockFetch = vi.fn().mockResolvedValue({
                ok: true,
                json: () => Promise.resolve(mockFunders),
            });
            globalThis.fetch = mockFetch;

            const result = await loadRorFunders();

            expect(mockFetch).toHaveBeenCalledWith('/api/v1/ror-affiliations');
            expect(result).toEqual(mockFunders);
        });

        it('returns empty array when response is not ok', async () => {
            const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
            const mockFetch = vi.fn().mockResolvedValue({
                ok: false,
                statusText: 'Not Found',
            });
            globalThis.fetch = mockFetch;

            const result = await loadRorFunders();

            expect(result).toEqual([]);
            expect(consoleSpy).toHaveBeenCalled();
        });

        it('returns empty array on network error', async () => {
            const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
            const mockFetch = vi.fn().mockRejectedValue(new Error('Network error'));
            globalThis.fetch = mockFetch;

            const result = await loadRorFunders();

            expect(result).toEqual([]);
            expect(consoleSpy).toHaveBeenCalledWith('Error loading ROR funders:', expect.any(Error));
        });
    });
});
