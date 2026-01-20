/**
 * @vitest-environment jsdom
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { OrcidService } from '@/services/orcid';

// Mock global fetch
const mockFetch = vi.fn();

beforeEach(() => {
    vi.clearAllMocks();
    global.fetch = mockFetch;
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('OrcidService', () => {
    describe('isValidFormat', () => {
        it('returns true for valid ORCID format', () => {
            expect(OrcidService.isValidFormat('0000-0002-1825-0097')).toBe(true);
        });

        it('returns true for ORCID ending with X', () => {
            expect(OrcidService.isValidFormat('0000-0001-2345-678X')).toBe(true);
        });

        it('returns false for invalid format without dashes', () => {
            expect(OrcidService.isValidFormat('0000000218250097')).toBe(false);
        });

        it('returns false for incomplete ORCID', () => {
            expect(OrcidService.isValidFormat('0000-0002-1825')).toBe(false);
        });

        it('returns false for ORCID with letters in wrong position', () => {
            expect(OrcidService.isValidFormat('000A-0002-1825-0097')).toBe(false);
        });

        it('returns false for empty string', () => {
            expect(OrcidService.isValidFormat('')).toBe(false);
        });

        it('returns false for ORCID with extra characters', () => {
            expect(OrcidService.isValidFormat('0000-0002-1825-00971')).toBe(false);
        });
    });

    describe('formatOrcidUrl', () => {
        it('returns full ORCID URL', () => {
            expect(OrcidService.formatOrcidUrl('0000-0002-1825-0097'))
                .toBe('https://orcid.org/0000-0002-1825-0097');
        });

        it('handles any input string', () => {
            expect(OrcidService.formatOrcidUrl('test')).toBe('https://orcid.org/test');
        });
    });

    describe('fetchOrcidRecord', () => {
        it('returns success with person data on successful fetch', async () => {
            const mockData = {
                orcid: '0000-0002-1825-0097',
                firstName: 'John',
                lastName: 'Doe',
                creditName: 'J. Doe',
                emails: ['john@example.com'],
                affiliations: [{ type: 'employment', name: 'GFZ', role: 'Researcher', department: 'Geophysics' }],
                verifiedAt: '2024-01-01T00:00:00Z',
            };

            mockFetch.mockResolvedValueOnce({
                ok: true,
                json: async () => ({ success: true, data: mockData }),
            });

            const result = await OrcidService.fetchOrcidRecord('0000-0002-1825-0097');

            expect(result.success).toBe(true);
            expect(result.data).toEqual(mockData);
            expect(mockFetch).toHaveBeenCalledWith(
                '/api/v1/orcid/0000-0002-1825-0097',
                expect.objectContaining({
                    method: 'GET',
                    headers: { Accept: 'application/json' },
                }),
            );
        });

        it('returns error when API returns non-OK status', async () => {
            mockFetch.mockResolvedValueOnce({
                ok: false,
                status: 404,
                json: async () => ({ message: 'ORCID not found' }),
            });

            const result = await OrcidService.fetchOrcidRecord('0000-0000-0000-0000');

            expect(result.success).toBe(false);
            expect(result.error).toBe('ORCID not found');
        });

        it('returns fallback error when API error response cannot be parsed', async () => {
            mockFetch.mockResolvedValueOnce({
                ok: false,
                status: 500,
                json: async () => {
                    throw new Error('Invalid JSON');
                },
            });

            const result = await OrcidService.fetchOrcidRecord('0000-0000-0000-0000');

            expect(result.success).toBe(false);
            expect(result.error).toBe('HTTP 500: Failed to fetch ORCID');
        });

        it('returns error when API returns success=false', async () => {
            mockFetch.mockResolvedValueOnce({
                ok: true,
                json: async () => ({ success: false, message: 'Invalid ORCID' }),
            });

            const result = await OrcidService.fetchOrcidRecord('invalid');

            expect(result.success).toBe(false);
            expect(result.error).toBe('Invalid ORCID');
        });

        it('returns network error on fetch failure', async () => {
            mockFetch.mockRejectedValueOnce(new Error('Network error'));

            const result = await OrcidService.fetchOrcidRecord('0000-0002-1825-0097');

            expect(result.success).toBe(false);
            expect(result.error).toBe('Network error: Could not connect to ORCID service');
        });

        it('encodes ORCID in URL', async () => {
            mockFetch.mockResolvedValueOnce({
                ok: true,
                json: async () => ({ success: true, data: {} }),
            });

            await OrcidService.fetchOrcidRecord('0000-0002-1825-009X');

            expect(mockFetch).toHaveBeenCalledWith(
                expect.stringContaining('0000-0002-1825-009X'),
                expect.any(Object),
            );
        });
    });

    describe('validateOrcid', () => {
        it('returns success with validation result', async () => {
            const validationResult = { valid: true, exists: true, message: 'Valid ORCID' };

            mockFetch.mockResolvedValueOnce({
                ok: true,
                json: async () => validationResult,
            });

            const result = await OrcidService.validateOrcid('0000-0002-1825-0097');

            expect(result.success).toBe(true);
            expect(result.data).toEqual(validationResult);
        });

        it('returns error on non-OK response', async () => {
            mockFetch.mockResolvedValueOnce({
                ok: false,
                status: 400,
            });

            const result = await OrcidService.validateOrcid('invalid');

            expect(result.success).toBe(false);
            expect(result.error).toBe('HTTP 400: Validation failed');
        });

        it('returns network error on fetch failure', async () => {
            mockFetch.mockRejectedValueOnce(new Error('Connection refused'));

            const result = await OrcidService.validateOrcid('0000-0002-1825-0097');

            expect(result.success).toBe(false);
            expect(result.error).toBe('Network error: Could not validate ORCID');
        });
    });

    describe('searchOrcid', () => {
        it('returns search results on success', async () => {
            const searchResults = {
                results: [
                    { orcid: '0000-0002-1825-0097', firstName: 'John', lastName: 'Doe', creditName: null, institutions: ['GFZ'] },
                ],
                total: 1,
            };

            mockFetch.mockResolvedValueOnce({
                ok: true,
                json: async () => ({ success: true, data: searchResults }),
            });

            const result = await OrcidService.searchOrcid('John Doe');

            expect(result.success).toBe(true);
            expect(result.data).toEqual(searchResults);
        });

        it('sends correct query parameters', async () => {
            mockFetch.mockResolvedValueOnce({
                ok: true,
                json: async () => ({ success: true, data: { results: [], total: 0 } }),
            });

            await OrcidService.searchOrcid('John Doe', 20);

            expect(mockFetch).toHaveBeenCalledWith(
                expect.stringContaining('q=John+Doe'),
                expect.any(Object),
            );
            expect(mockFetch).toHaveBeenCalledWith(
                expect.stringContaining('limit=20'),
                expect.any(Object),
            );
        });

        it('limits results to maximum of 50', async () => {
            mockFetch.mockResolvedValueOnce({
                ok: true,
                json: async () => ({ success: true, data: { results: [], total: 0 } }),
            });

            await OrcidService.searchOrcid('John Doe', 100);

            expect(mockFetch).toHaveBeenCalledWith(
                expect.stringContaining('limit=50'),
                expect.any(Object),
            );
        });

        it('uses default limit of 10', async () => {
            mockFetch.mockResolvedValueOnce({
                ok: true,
                json: async () => ({ success: true, data: { results: [], total: 0 } }),
            });

            await OrcidService.searchOrcid('John Doe');

            expect(mockFetch).toHaveBeenCalledWith(
                expect.stringContaining('limit=10'),
                expect.any(Object),
            );
        });

        it('returns error message from API on failure', async () => {
            mockFetch.mockResolvedValueOnce({
                ok: false,
                status: 429,
                json: async () => ({ message: 'Rate limit exceeded' }),
            });

            const result = await OrcidService.searchOrcid('test');

            expect(result.success).toBe(false);
            expect(result.error).toBe('Rate limit exceeded');
        });

        it('returns fallback error when API error has no message', async () => {
            mockFetch.mockResolvedValueOnce({
                ok: false,
                status: 500,
                json: async () => {
                    throw new Error('Invalid JSON');
                },
            });

            const result = await OrcidService.searchOrcid('test');

            expect(result.success).toBe(false);
            expect(result.error).toBe('HTTP 500: Search failed');
        });

        it('returns error when API returns success=false', async () => {
            mockFetch.mockResolvedValueOnce({
                ok: true,
                json: async () => ({ success: false, error: 'Search error' }),
            });

            const result = await OrcidService.searchOrcid('test');

            expect(result.success).toBe(false);
            expect(result.error).toBe('Search error');
        });

        it('returns network error on fetch failure', async () => {
            mockFetch.mockRejectedValueOnce(new Error('Network error'));

            const result = await OrcidService.searchOrcid('test');

            expect(result.success).toBe(false);
            expect(result.error).toBe('Network error: Could not search ORCID');
        });
    });

    describe('validateChecksum', () => {
        it('returns true for valid ORCID with correct checksum', () => {
            // These are verified valid ORCIDs
            expect(OrcidService.validateChecksum('0000-0002-1825-0097')).toBe(true);
            expect(OrcidService.validateChecksum('0000-0001-5109-3700')).toBe(true);
            expect(OrcidService.validateChecksum('0000-0002-0275-1903')).toBe(true); // Issue #403 ORCID
        });

        it('returns true for valid ORCID ending with X', () => {
            expect(OrcidService.validateChecksum('0000-0002-9079-593X')).toBe(true);
            expect(OrcidService.validateChecksum('0000-0002-9079-593x')).toBe(true); // lowercase X
        });

        it('returns false for ORCID with invalid checksum', () => {
            expect(OrcidService.validateChecksum('0000-0002-1825-0098')).toBe(false); // Wrong check digit
            expect(OrcidService.validateChecksum('0000-0000-0000-0000')).toBe(false);
            expect(OrcidService.validateChecksum('1234-5678-9012-3456')).toBe(false);
        });

        it('returns false for invalid format', () => {
            expect(OrcidService.validateChecksum('0000-0002-1825')).toBe(false); // Too short
            expect(OrcidService.validateChecksum('')).toBe(false);
            expect(OrcidService.validateChecksum('invalid')).toBe(false);
        });

        it('returns false for ORCID with non-numeric characters in body', () => {
            expect(OrcidService.validateChecksum('000A-0002-1825-0097')).toBe(false);
        });
    });
});
