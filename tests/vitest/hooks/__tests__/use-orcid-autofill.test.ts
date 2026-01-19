import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { type PersonEntry,useOrcidAutofill } from '@/hooks/use-orcid-autofill';
import { OrcidService } from '@/services/orcid';

// Mock the OrcidService
vi.mock('@/services/orcid', () => ({
    OrcidService: {
        fetchOrcidRecord: vi.fn(),
        searchOrcid: vi.fn(),
        validateOrcid: vi.fn(),
        isValidFormat: vi.fn(),
    },
}));

const createPersonEntry = (overrides: Partial<PersonEntry> = {}): PersonEntry => ({
    id: 'test-1',
    type: 'person',
    orcid: '',
    firstName: '',
    lastName: '',
    affiliations: [],
    affiliationsInput: '',
    orcidVerified: false,
    orcidVerifiedAt: null,
    ...overrides,
});

describe('useOrcidAutofill', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    describe('initial state', () => {
        it('starts with default state', () => {
            const entry = createPersonEntry();
            const onEntryChange = vi.fn();

            const { result } = renderHook(() =>
                useOrcidAutofill({
                    entry,
                    onEntryChange,
                    hasUserInteracted: false,
                }),
            );

            expect(result.current.isVerifying).toBe(false);
            expect(result.current.verificationError).toBeNull();
            expect(result.current.orcidSuggestions).toEqual([]);
            expect(result.current.isLoadingSuggestions).toBe(false);
            expect(result.current.showSuggestions).toBe(false);
        });
    });

    describe('clearError', () => {
        it('clears verification error when called', () => {
            const entry = createPersonEntry();
            const onEntryChange = vi.fn();

            const { result } = renderHook(() =>
                useOrcidAutofill({
                    entry,
                    onEntryChange,
                    hasUserInteracted: false,
                }),
            );

            // clearError should be callable
            act(() => {
                result.current.clearError();
            });

            expect(result.current.verificationError).toBeNull();
        });
    });

    describe('hideSuggestions', () => {
        it('hides suggestions dropdown when called', () => {
            const entry = createPersonEntry();
            const onEntryChange = vi.fn();

            const { result } = renderHook(() =>
                useOrcidAutofill({
                    entry,
                    onEntryChange,
                    hasUserInteracted: false,
                }),
            );

            act(() => {
                result.current.hideSuggestions();
            });

            expect(result.current.showSuggestions).toBe(false);
        });
    });

    describe('handleOrcidSelect', () => {
        it('fetches and applies ORCID data', async () => {
            vi.mocked(OrcidService.fetchOrcidRecord).mockResolvedValue({
                success: true,
                data: {
                    orcid: '0000-0001-2345-6789',
                    firstName: 'Jane',
                    lastName: 'Smith',
                    creditName: null,
                    emails: ['jane@example.com'],
                    affiliations: [
                        { type: 'employment', name: 'University of Testing', role: null, department: null },
                    ],
                    verifiedAt: new Date().toISOString(),
                },
            });

            // Create entry with email field for author-like entries
            const entry = { ...createPersonEntry(), email: '' };
            const onEntryChange = vi.fn();

            const { result } = renderHook(() =>
                useOrcidAutofill({
                    entry,
                    onEntryChange,
                    hasUserInteracted: false,
                    includeEmail: true,
                }),
            );

            await act(async () => {
                await result.current.handleOrcidSelect('0000-0001-2345-6789');
            });

            expect(onEntryChange).toHaveBeenCalledWith(
                expect.objectContaining({
                    orcid: '0000-0001-2345-6789',
                    firstName: 'Jane',
                    lastName: 'Smith',
                    orcidVerified: true,
                    email: 'jane@example.com',
                }),
            );
        });

        it('handles fetch error', async () => {
            vi.mocked(OrcidService.fetchOrcidRecord).mockResolvedValue({
                success: false,
                error: 'Not found',
            });

            const entry = createPersonEntry();
            const onEntryChange = vi.fn();

            const { result } = renderHook(() =>
                useOrcidAutofill({
                    entry,
                    onEntryChange,
                    hasUserInteracted: false,
                }),
            );

            await act(async () => {
                await result.current.handleOrcidSelect('0000-0001-2345-6789');
            });

            expect(result.current.verificationError).toBe('Not found');
            expect(onEntryChange).not.toHaveBeenCalled();
        });

        it('handles network error', async () => {
            const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
            vi.mocked(OrcidService.fetchOrcidRecord).mockRejectedValue(new Error('Network error'));

            const entry = createPersonEntry();
            const onEntryChange = vi.fn();

            const { result } = renderHook(() =>
                useOrcidAutofill({
                    entry,
                    onEntryChange,
                    hasUserInteracted: false,
                }),
            );

            await act(async () => {
                await result.current.handleOrcidSelect('0000-0001-2345-6789');
            });

            expect(result.current.verificationError).toBe('Failed to fetch complete ORCID data');
            expect(consoleSpy).toHaveBeenCalled();
            consoleSpy.mockRestore();
        });

        it('does nothing for institution entries', async () => {
            const entry = {
                id: 'test-1',
                type: 'institution' as const,
                affiliations: [],
                affiliationsInput: '',
            };
            const onEntryChange = vi.fn();

            const { result } = renderHook(() =>
                useOrcidAutofill({
                    entry,
                    onEntryChange,
                    hasUserInteracted: false,
                }),
            );

            await act(async () => {
                await result.current.handleOrcidSelect('0000-0001-2345-6789');
            });

            expect(OrcidService.fetchOrcidRecord).not.toHaveBeenCalled();
            expect(onEntryChange).not.toHaveBeenCalled();
        });

        it('preserves existing firstName if ORCID has none', async () => {
            vi.mocked(OrcidService.fetchOrcidRecord).mockResolvedValue({
                success: true,
                data: {
                    orcid: '0000-0001-2345-6789',
                    firstName: '',
                    lastName: 'Smith',
                    creditName: null,
                    emails: [],
                    affiliations: [],
                    verifiedAt: new Date().toISOString(),
                },
            });

            const entry = createPersonEntry({ firstName: 'ExistingFirst' });
            const onEntryChange = vi.fn();

            const { result } = renderHook(() =>
                useOrcidAutofill({
                    entry,
                    onEntryChange,
                    hasUserInteracted: false,
                }),
            );

            await act(async () => {
                await result.current.handleOrcidSelect('0000-0001-2345-6789');
            });

            expect(onEntryChange).toHaveBeenCalledWith(
                expect.objectContaining({
                    firstName: 'ExistingFirst',
                    lastName: 'Smith',
                }),
            );
        });

        it('does not set email if not includeEmail', async () => {
            vi.mocked(OrcidService.fetchOrcidRecord).mockResolvedValue({
                success: true,
                data: {
                    orcid: '0000-0001-2345-6789',
                    firstName: 'Jane',
                    lastName: 'Smith',
                    creditName: null,
                    emails: ['jane@example.com'],
                    affiliations: [],
                    verifiedAt: new Date().toISOString(),
                },
            });

            const entry = createPersonEntry();
            const onEntryChange = vi.fn();

            const { result } = renderHook(() =>
                useOrcidAutofill({
                    entry,
                    onEntryChange,
                    hasUserInteracted: false,
                    includeEmail: false,
                }),
            );

            await act(async () => {
                await result.current.handleOrcidSelect('0000-0001-2345-6789');
            });

            expect(onEntryChange).toHaveBeenCalledWith(
                expect.not.objectContaining({
                    email: expect.any(String),
                }),
            );
        });

        it('merges new affiliations from ORCID', async () => {
            vi.mocked(OrcidService.fetchOrcidRecord).mockResolvedValue({
                success: true,
                data: {
                    orcid: '0000-0001-2345-6789',
                    firstName: 'Jane',
                    lastName: 'Smith',
                    creditName: null,
                    emails: [],
                    affiliations: [
                        { type: 'employment', name: 'New University', role: null, department: null },
                        { type: 'education', name: 'Should be ignored', role: null, department: null }, // Only employment is used
                    ],
                    verifiedAt: new Date().toISOString(),
                },
            });

            const entry = createPersonEntry({
                affiliations: [{ value: 'Existing Affiliation', rorId: null }],
            });
            const onEntryChange = vi.fn();

            const { result } = renderHook(() =>
                useOrcidAutofill({
                    entry,
                    onEntryChange,
                    hasUserInteracted: false,
                }),
            );

            await act(async () => {
                await result.current.handleOrcidSelect('0000-0001-2345-6789');
            });

            expect(onEntryChange).toHaveBeenCalledWith(
                expect.objectContaining({
                    affiliations: expect.arrayContaining([
                        { value: 'Existing Affiliation', rorId: null },
                        { value: 'New University', rorId: null },
                    ]),
                }),
            );
        });

        it('does not duplicate existing affiliations', async () => {
            vi.mocked(OrcidService.fetchOrcidRecord).mockResolvedValue({
                success: true,
                data: {
                    orcid: '0000-0001-2345-6789',
                    firstName: 'Jane',
                    lastName: 'Smith',
                    creditName: null,
                    emails: [],
                    affiliations: [
                        { type: 'employment', name: 'Same University', role: null, department: null },
                    ],
                    verifiedAt: new Date().toISOString(),
                },
            });

            const entry = createPersonEntry({
                affiliations: [{ value: 'Same University', rorId: null }],
            });
            const onEntryChange = vi.fn();

            const { result } = renderHook(() =>
                useOrcidAutofill({
                    entry,
                    onEntryChange,
                    hasUserInteracted: false,
                }),
            );

            await act(async () => {
                await result.current.handleOrcidSelect('0000-0001-2345-6789');
            });

            // affiliations should not be updated since they're the same
            const call = onEntryChange.mock.calls[0][0];
            expect(call.affiliations).toEqual([{ value: 'Same University', rorId: null }]);
        });
    });
});
