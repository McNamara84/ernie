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
        validateChecksum: vi.fn(),
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
                        { type: 'employment', name: 'University of Testing', role: null, department: null, rorId: null },
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

        it('stores new affiliations as pending data instead of merging', async () => {
            vi.mocked(OrcidService.fetchOrcidRecord).mockResolvedValue({
                success: true,
                data: {
                    orcid: '0000-0001-2345-6789',
                    firstName: 'Jane',
                    lastName: 'Smith',
                    creditName: null,
                    emails: [],
                    affiliations: [
                        { type: 'employment', name: 'New University', role: null, department: null, rorId: null },
                        { type: 'education', name: 'Should be ignored', role: null, department: null, rorId: null }, // Only employment is used
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

            // Affiliations should NOT be merged — existing affiliations stay unchanged
            const call = onEntryChange.mock.calls[0][0];
            expect(call.affiliations).toEqual([{ value: 'Existing Affiliation', rorId: null }]);

            // New affiliations should be in pendingOrcidData instead
            expect(result.current.pendingOrcidData).not.toBeNull();
            expect(result.current.pendingOrcidData?.affiliations.length).toBeGreaterThan(0);
        });

        it('does not add existing affiliations to pending data', async () => {
            vi.mocked(OrcidService.fetchOrcidRecord).mockResolvedValue({
                success: true,
                data: {
                    orcid: '0000-0001-2345-6789',
                    firstName: 'Jane',
                    lastName: 'Smith',
                    creditName: null,
                    emails: [],
                    affiliations: [
                        { type: 'employment', name: 'Same University', role: null, department: null, rorId: null },
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

            // Existing affiliations should not be modified
            const call = onEntryChange.mock.calls[0][0];
            expect(call.affiliations).toEqual([{ value: 'Same University', rorId: null }]);

            // No pending data since affiliation already exists
            expect(result.current.pendingOrcidData).toBeNull();
        });
    });

    describe('isFormatValid', () => {
        it('returns false for empty orcid', () => {
            vi.mocked(OrcidService.isValidFormat).mockReturnValue(false);
            vi.mocked(OrcidService.validateChecksum).mockReturnValue(false);

            const entry = createPersonEntry({ orcid: '' });
            const onEntryChange = vi.fn();

            const { result } = renderHook(() =>
                useOrcidAutofill({ entry, onEntryChange, hasUserInteracted: false }),
            );

            expect(result.current.isFormatValid).toBe(false);
        });

        it('returns true when format and checksum are both valid', () => {
            vi.mocked(OrcidService.isValidFormat).mockReturnValue(true);
            vi.mocked(OrcidService.validateChecksum).mockReturnValue(true);

            const entry = createPersonEntry({ orcid: '0000-0001-2345-6789' });
            const onEntryChange = vi.fn();

            const { result } = renderHook(() =>
                useOrcidAutofill({ entry, onEntryChange, hasUserInteracted: false }),
            );

            expect(result.current.isFormatValid).toBe(true);
        });

        it('returns false when format is valid but checksum fails', () => {
            vi.mocked(OrcidService.isValidFormat).mockReturnValue(true);
            vi.mocked(OrcidService.validateChecksum).mockReturnValue(false);

            const entry = createPersonEntry({ orcid: '0000-0001-2345-6780' });
            const onEntryChange = vi.fn();

            const { result } = renderHook(() =>
                useOrcidAutofill({ entry, onEntryChange, hasUserInteracted: false }),
            );

            expect(result.current.isFormatValid).toBe(false);
        });

        it('returns false for institution entries', () => {
            const entry = {
                id: 'test-1',
                type: 'institution' as const,
                affiliations: [],
                affiliationsInput: '',
            };
            const onEntryChange = vi.fn();

            const { result } = renderHook(() =>
                useOrcidAutofill({ entry, onEntryChange, hasUserInteracted: false }),
            );

            expect(result.current.isFormatValid).toBe(false);
        });
    });

    describe('auto-verify ORCID', () => {
        beforeEach(() => {
            vi.useFakeTimers({ shouldAdvanceTime: true });
        });

        afterEach(() => {
            vi.useRealTimers();
        });

        it('auto-verifies a valid ORCID after debounce', async () => {
            vi.mocked(OrcidService.isValidFormat).mockReturnValue(true);
            vi.mocked(OrcidService.validateChecksum).mockReturnValue(true);
            vi.mocked(OrcidService.validateOrcid).mockResolvedValue({
                success: true,
                data: { valid: true, exists: true, message: 'Valid', errorType: null },
            });
            vi.mocked(OrcidService.fetchOrcidRecord).mockResolvedValue({
                success: true,
                data: {
                    orcid: '0000-0001-2345-6789',
                    firstName: 'Jane',
                    lastName: 'Smith',
                    creditName: null,
                    emails: [],
                    affiliations: [],
                    verifiedAt: new Date().toISOString(),
                },
            });

            const entry = createPersonEntry({ orcid: '0000-0001-2345-6789' });
            const onEntryChange = vi.fn();

            renderHook(() =>
                useOrcidAutofill({ entry, onEntryChange, hasUserInteracted: false }),
            );

            // Advance past debounce (500ms)
            await act(async () => {
                vi.advanceTimersByTime(600);
                await vi.runAllTimersAsync();
            });

            expect(OrcidService.validateOrcid).toHaveBeenCalledWith('0000-0001-2345-6789');
            expect(OrcidService.fetchOrcidRecord).toHaveBeenCalledWith('0000-0001-2345-6789');
            expect(onEntryChange).toHaveBeenCalledWith(
                expect.objectContaining({
                    orcidVerified: true,
                    firstName: 'Jane',
                    lastName: 'Smith',
                }),
            );
        });

        it('sets checksum error when checksum validation fails', async () => {
            vi.mocked(OrcidService.isValidFormat).mockReturnValue(true);
            vi.mocked(OrcidService.validateChecksum).mockReturnValue(false);

            const entry = createPersonEntry({ orcid: '0000-0001-2345-6780' });
            const onEntryChange = vi.fn();

            const { result } = renderHook(() =>
                useOrcidAutofill({ entry, onEntryChange, hasUserInteracted: false }),
            );

            await act(async () => {
                vi.advanceTimersByTime(600);
                await vi.runAllTimersAsync();
            });

            expect(result.current.verificationError).toBe('Invalid ORCID checksum');
            expect(result.current.errorType).toBe('checksum');
            expect(result.current.canRetry).toBe(false);
        });

        it('handles not_found error type', async () => {
            vi.mocked(OrcidService.isValidFormat).mockReturnValue(true);
            vi.mocked(OrcidService.validateChecksum).mockReturnValue(true);
            vi.mocked(OrcidService.validateOrcid).mockResolvedValue({
                success: true,
                data: { valid: false, exists: false, message: 'Not found', errorType: 'not_found' },
            });

            const entry = createPersonEntry({ orcid: '0000-0001-2345-6789' });
            const onEntryChange = vi.fn();

            const { result } = renderHook(() =>
                useOrcidAutofill({ entry, onEntryChange, hasUserInteracted: false }),
            );

            await act(async () => {
                vi.advanceTimersByTime(600);
                await vi.runAllTimersAsync();
            });

            expect(result.current.verificationError).toBe('ORCID not found');
            expect(result.current.errorType).toBe('not_found');
            expect(result.current.canRetry).toBe(false);
        });

        it('handles timeout error with retry option', async () => {
            vi.mocked(OrcidService.isValidFormat).mockReturnValue(true);
            vi.mocked(OrcidService.validateChecksum).mockReturnValue(true);
            vi.mocked(OrcidService.validateOrcid).mockResolvedValue({
                success: true,
                data: { valid: false, exists: null, message: 'Timeout', errorType: 'timeout' },
            });

            const entry = createPersonEntry({ orcid: '0000-0001-2345-6789' });
            const onEntryChange = vi.fn();

            const { result } = renderHook(() =>
                useOrcidAutofill({ entry, onEntryChange, hasUserInteracted: false }),
            );

            await act(async () => {
                vi.advanceTimersByTime(600);
                await vi.runAllTimersAsync();
            });

            expect(result.current.verificationError).toBe('ORCID service temporarily unavailable');
            expect(result.current.errorType).toBe('timeout');
            expect(result.current.canRetry).toBe(true);
        });

        it('handles network error from validateOrcid', async () => {
            vi.mocked(OrcidService.isValidFormat).mockReturnValue(true);
            vi.mocked(OrcidService.validateChecksum).mockReturnValue(true);
            vi.mocked(OrcidService.validateOrcid).mockResolvedValue({
                success: false,
                error: 'Network error',
            });

            const entry = createPersonEntry({ orcid: '0000-0001-2345-6789' });
            const onEntryChange = vi.fn();

            const { result } = renderHook(() =>
                useOrcidAutofill({ entry, onEntryChange, hasUserInteracted: false }),
            );

            await act(async () => {
                vi.advanceTimersByTime(600);
                await vi.runAllTimersAsync();
            });

            expect(result.current.verificationError).toBe('Network error - please check connection');
            expect(result.current.errorType).toBe('network');
            expect(result.current.canRetry).toBe(true);
        });

        it('does not auto-verify if already verified', async () => {
            vi.mocked(OrcidService.isValidFormat).mockReturnValue(true);
            vi.mocked(OrcidService.validateChecksum).mockReturnValue(true);

            const entry = createPersonEntry({ orcid: '0000-0001-2345-6789', orcidVerified: true });
            const onEntryChange = vi.fn();

            renderHook(() =>
                useOrcidAutofill({ entry, onEntryChange, hasUserInteracted: false }),
            );

            await act(async () => {
                vi.advanceTimersByTime(600);
                await vi.runAllTimersAsync();
            });

            expect(OrcidService.validateOrcid).not.toHaveBeenCalled();
        });

        it('does not auto-verify with invalid format', async () => {
            vi.mocked(OrcidService.isValidFormat).mockReturnValue(false);

            const entry = createPersonEntry({ orcid: 'invalid' });
            const onEntryChange = vi.fn();

            renderHook(() =>
                useOrcidAutofill({ entry, onEntryChange, hasUserInteracted: false }),
            );

            await act(async () => {
                vi.advanceTimersByTime(600);
                await vi.runAllTimersAsync();
            });

            expect(OrcidService.validateOrcid).not.toHaveBeenCalled();
        });

        it('handles failed fetchOrcidRecord during auto-verify', async () => {
            vi.mocked(OrcidService.isValidFormat).mockReturnValue(true);
            vi.mocked(OrcidService.validateChecksum).mockReturnValue(true);
            vi.mocked(OrcidService.validateOrcid).mockResolvedValue({
                success: true,
                data: { valid: true, exists: true, message: 'Valid', errorType: null },
            });
            vi.mocked(OrcidService.fetchOrcidRecord).mockResolvedValue({
                success: false,
                error: 'API error',
            });

            const entry = createPersonEntry({ orcid: '0000-0001-2345-6789' });
            const onEntryChange = vi.fn();

            const { result } = renderHook(() =>
                useOrcidAutofill({ entry, onEntryChange, hasUserInteracted: false }),
            );

            await act(async () => {
                vi.advanceTimersByTime(600);
                await vi.runAllTimersAsync();
            });

            expect(result.current.verificationError).toBe('Failed to fetch ORCID data');
            expect(result.current.errorType).toBe('api_error');
            expect(result.current.canRetry).toBe(true);
        });

        it('handles unknown error when exists is false and no errorType', async () => {
            vi.mocked(OrcidService.isValidFormat).mockReturnValue(true);
            vi.mocked(OrcidService.validateChecksum).mockReturnValue(true);
            vi.mocked(OrcidService.validateOrcid).mockResolvedValue({
                success: true,
                data: { valid: false, exists: false, message: 'Unknown', errorType: null },
            });

            const entry = createPersonEntry({ orcid: '0000-0001-2345-6789' });
            const onEntryChange = vi.fn();

            const { result } = renderHook(() =>
                useOrcidAutofill({ entry, onEntryChange, hasUserInteracted: false }),
            );

            await act(async () => {
                vi.advanceTimersByTime(600);
                await vi.runAllTimersAsync();
            });

            expect(result.current.verificationError).toBe('Could not verify ORCID');
            expect(result.current.errorType).toBe('unknown');
        });

        it('includes email for authors during auto-verify', async () => {
            vi.mocked(OrcidService.isValidFormat).mockReturnValue(true);
            vi.mocked(OrcidService.validateChecksum).mockReturnValue(true);
            vi.mocked(OrcidService.validateOrcid).mockResolvedValue({
                success: true,
                data: { valid: true, exists: true, message: 'Valid', errorType: null },
            });
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

            const entry = { ...createPersonEntry({ orcid: '0000-0001-2345-6789' }), email: '' };
            const onEntryChange = vi.fn();

            renderHook(() =>
                useOrcidAutofill({ entry, onEntryChange, hasUserInteracted: false, includeEmail: true }),
            );

            await act(async () => {
                vi.advanceTimersByTime(600);
                await vi.runAllTimersAsync();
            });

            expect(onEntryChange).toHaveBeenCalledWith(
                expect.objectContaining({
                    email: 'jane@example.com',
                    orcidVerified: true,
                }),
            );
        });
    });

    describe('ORCID suggestions', () => {
        beforeEach(() => {
            vi.useFakeTimers({ shouldAdvanceTime: true });
        });

        afterEach(() => {
            vi.useRealTimers();
        });

        it('searches for ORCID suggestions when user has interacted and has name', async () => {
            vi.mocked(OrcidService.isValidFormat).mockReturnValue(false);
            vi.mocked(OrcidService.validateChecksum).mockReturnValue(false);
            vi.mocked(OrcidService.searchOrcid).mockResolvedValue({
                success: true,
                data: {
                    results: [
                        { orcid: '0000-0001-2345-6789', firstName: 'Jane', lastName: 'Smith', creditName: null, institutions: [] },
                    ],
                    total: 1,
                },
            });

            const entry = createPersonEntry({ firstName: 'Jane', lastName: 'Smith' });
            const onEntryChange = vi.fn();

            const { result } = renderHook(() =>
                useOrcidAutofill({ entry, onEntryChange, hasUserInteracted: true }),
            );

            // Advance past suggestion debounce (800ms)
            await act(async () => {
                vi.advanceTimersByTime(900);
                await vi.runAllTimersAsync();
            });

            expect(OrcidService.searchOrcid).toHaveBeenCalledWith('Jane Smith');
            expect(result.current.orcidSuggestions).toHaveLength(1);
            expect(result.current.showSuggestions).toBe(true);
        });

        it('does not search when hasUserInteracted is false', async () => {
            const entry = createPersonEntry({ firstName: 'Jane', lastName: 'Smith' });
            const onEntryChange = vi.fn();

            renderHook(() =>
                useOrcidAutofill({ entry, onEntryChange, hasUserInteracted: false }),
            );

            await act(async () => {
                vi.advanceTimersByTime(900);
                await vi.runAllTimersAsync();
            });

            expect(OrcidService.searchOrcid).not.toHaveBeenCalled();
        });

        it('does not search when ORCID is already set', async () => {
            vi.mocked(OrcidService.isValidFormat).mockReturnValue(true);
            vi.mocked(OrcidService.validateChecksum).mockReturnValue(true);

            const entry = createPersonEntry({
                firstName: 'Jane',
                lastName: 'Smith',
                orcid: '0000-0001-2345-6789',
                orcidVerified: true,
            });
            const onEntryChange = vi.fn();

            renderHook(() =>
                useOrcidAutofill({ entry, onEntryChange, hasUserInteracted: true }),
            );

            await act(async () => {
                vi.advanceTimersByTime(900);
                await vi.runAllTimersAsync();
            });

            expect(OrcidService.searchOrcid).not.toHaveBeenCalled();
        });

        it('includes first affiliation in search query', async () => {
            vi.mocked(OrcidService.isValidFormat).mockReturnValue(false);
            vi.mocked(OrcidService.validateChecksum).mockReturnValue(false);
            vi.mocked(OrcidService.searchOrcid).mockResolvedValue({
                success: true,
                data: { results: [], total: 0 },
            });

            const entry = createPersonEntry({
                firstName: 'Jane',
                lastName: 'Smith',
                affiliations: [{ value: 'GFZ Potsdam', rorId: null }],
            });
            const onEntryChange = vi.fn();

            renderHook(() =>
                useOrcidAutofill({ entry, onEntryChange, hasUserInteracted: true }),
            );

            await act(async () => {
                vi.advanceTimersByTime(900);
                await vi.runAllTimersAsync();
            });

            expect(OrcidService.searchOrcid).toHaveBeenCalledWith('Jane Smith GFZ Potsdam');
        });

        it('handles search error gracefully', async () => {
            const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
            vi.mocked(OrcidService.isValidFormat).mockReturnValue(false);
            vi.mocked(OrcidService.validateChecksum).mockReturnValue(false);
            vi.mocked(OrcidService.searchOrcid).mockRejectedValue(new Error('Network error'));

            const entry = createPersonEntry({ firstName: 'Jane', lastName: 'Smith' });
            const onEntryChange = vi.fn();

            const { result } = renderHook(() =>
                useOrcidAutofill({ entry, onEntryChange, hasUserInteracted: true }),
            );

            await act(async () => {
                vi.advanceTimersByTime(900);
                await vi.runAllTimersAsync();
            });

            expect(result.current.orcidSuggestions).toEqual([]);
            consoleSpy.mockRestore();
        });

        it('limits suggestions to 5 results', async () => {
            vi.mocked(OrcidService.isValidFormat).mockReturnValue(false);
            vi.mocked(OrcidService.validateChecksum).mockReturnValue(false);

            const tenResults = Array.from({ length: 10 }, (_, i) => ({
                orcid: `0000-0001-0000-000${i}`,
                firstName: 'Jane',
                lastName: `Smith${i}`,
                creditName: null,
                institutions: [],
            }));

            vi.mocked(OrcidService.searchOrcid).mockResolvedValue({
                success: true,
                data: { results: tenResults, total: 10 },
            });

            const entry = createPersonEntry({ firstName: 'Jane', lastName: 'Smith' });
            const onEntryChange = vi.fn();

            const { result } = renderHook(() =>
                useOrcidAutofill({ entry, onEntryChange, hasUserInteracted: true }),
            );

            await act(async () => {
                vi.advanceTimersByTime(900);
                await vi.runAllTimersAsync();
            });

            expect(result.current.orcidSuggestions).toHaveLength(5);
        });
    });

    describe('retryVerification', () => {
        it('is callable and clears error', () => {
            const entry = createPersonEntry();
            const onEntryChange = vi.fn();

            const { result } = renderHook(() =>
                useOrcidAutofill({ entry, onEntryChange, hasUserInteracted: false }),
            );

            act(() => {
                result.current.retryVerification();
            });

            expect(result.current.verificationError).toBeNull();
            expect(result.current.errorType).toBeNull();
        });
    });

    describe('processOrcidData (via handleOrcidSelect)', () => {
        it('computes name diffs when firstName differs', async () => {
            vi.mocked(OrcidService.fetchOrcidRecord).mockResolvedValue({
                success: true,
                data: {
                    orcid: '0000-0001-2345-6789',
                    firstName: 'Johann',
                    lastName: 'Doe',
                    creditName: null,
                    emails: [],
                    affiliations: [],
                    verifiedAt: new Date().toISOString(),
                },
            });

            const entry = createPersonEntry({ firstName: 'John', lastName: 'Doe' });
            const onEntryChange = vi.fn();

            const { result } = renderHook(() =>
                useOrcidAutofill({ entry, onEntryChange, hasUserInteracted: false }),
            );

            await act(async () => {
                await result.current.handleOrcidSelect('0000-0001-2345-6789');
            });

            expect(result.current.pendingOrcidData).not.toBeNull();
            expect(result.current.pendingOrcidData?.firstNameDiff).toEqual({
                orcid: 'Johann',
                current: 'John',
            });
            expect(result.current.pendingOrcidData?.lastNameDiff).toBeNull();
        });

        it('computes name diffs when lastName differs', async () => {
            vi.mocked(OrcidService.fetchOrcidRecord).mockResolvedValue({
                success: true,
                data: {
                    orcid: '0000-0001-2345-6789',
                    firstName: 'John',
                    lastName: 'Mueller',
                    creditName: null,
                    emails: [],
                    affiliations: [],
                    verifiedAt: new Date().toISOString(),
                },
            });

            const entry = createPersonEntry({ firstName: 'John', lastName: 'Müller' });
            const onEntryChange = vi.fn();

            const { result } = renderHook(() =>
                useOrcidAutofill({ entry, onEntryChange, hasUserInteracted: false }),
            );

            await act(async () => {
                await result.current.handleOrcidSelect('0000-0001-2345-6789');
            });

            expect(result.current.pendingOrcidData?.lastNameDiff).toEqual({
                orcid: 'Mueller',
                current: 'Müller',
            });
        });

        it('computes email suggestion when email differs', async () => {
            vi.mocked(OrcidService.fetchOrcidRecord).mockResolvedValue({
                success: true,
                data: {
                    orcid: '0000-0001-2345-6789',
                    firstName: 'John',
                    lastName: 'Doe',
                    creditName: null,
                    emails: ['new@example.com'],
                    affiliations: [],
                    verifiedAt: new Date().toISOString(),
                },
            });

            const entry = { ...createPersonEntry({ firstName: 'John', lastName: 'Doe' }), email: 'old@example.com' };
            const onEntryChange = vi.fn();

            const { result } = renderHook(() =>
                useOrcidAutofill({ entry, onEntryChange, hasUserInteracted: false, includeEmail: true }),
            );

            await act(async () => {
                await result.current.handleOrcidSelect('0000-0001-2345-6789');
            });

            expect(result.current.pendingOrcidData?.emailSuggestion).toBe('new@example.com');
        });

        it('does not suggest email when same as current', async () => {
            vi.mocked(OrcidService.fetchOrcidRecord).mockResolvedValue({
                success: true,
                data: {
                    orcid: '0000-0001-2345-6789',
                    firstName: 'John',
                    lastName: 'Doe',
                    creditName: null,
                    emails: ['same@example.com'],
                    affiliations: [],
                    verifiedAt: new Date().toISOString(),
                },
            });

            const entry = { ...createPersonEntry({ firstName: 'John', lastName: 'Doe' }), email: 'same@example.com' };
            const onEntryChange = vi.fn();

            const { result } = renderHook(() =>
                useOrcidAutofill({ entry, onEntryChange, hasUserInteracted: false, includeEmail: true }),
            );

            await act(async () => {
                await result.current.handleOrcidSelect('0000-0001-2345-6789');
            });

            expect(result.current.pendingOrcidData).toBeNull();
        });

        it('returns null pendingData when no diffs exist', async () => {
            vi.mocked(OrcidService.fetchOrcidRecord).mockResolvedValue({
                success: true,
                data: {
                    orcid: '0000-0001-2345-6789',
                    firstName: 'John',
                    lastName: 'Doe',
                    creditName: null,
                    emails: [],
                    affiliations: [],
                    verifiedAt: new Date().toISOString(),
                },
            });

            const entry = createPersonEntry({ firstName: 'John', lastName: 'Doe' });
            const onEntryChange = vi.fn();

            const { result } = renderHook(() =>
                useOrcidAutofill({ entry, onEntryChange, hasUserInteracted: false }),
            );

            await act(async () => {
                await result.current.handleOrcidSelect('0000-0001-2345-6789');
            });

            expect(result.current.pendingOrcidData).toBeNull();
        });
    });

    describe('computePendingAffiliations (via handleOrcidSelect)', () => {
        beforeEach(() => {
            // Mock fetch for ROR resolution
            global.fetch = vi.fn().mockResolvedValue({
                ok: true,
                json: () => Promise.resolve({ results: [] }),
            });
        });

        afterEach(() => {
            vi.restoreAllMocks();
        });

        it('marks truly new affiliations as "new"', async () => {
            vi.mocked(OrcidService.fetchOrcidRecord).mockResolvedValue({
                success: true,
                data: {
                    orcid: '0000-0001-2345-6789',
                    firstName: 'John',
                    lastName: 'Doe',
                    creditName: null,
                    emails: [],
                    affiliations: [
                        { type: 'employment', name: 'New University', role: null, department: null, rorId: null },
                    ],
                    verifiedAt: new Date().toISOString(),
                },
            });

            const entry = createPersonEntry({ firstName: 'John', lastName: 'Doe' });
            const onEntryChange = vi.fn();

            const { result } = renderHook(() =>
                useOrcidAutofill({ entry, onEntryChange, hasUserInteracted: false }),
            );

            await act(async () => {
                await result.current.handleOrcidSelect('0000-0001-2345-6789');
            });

            expect(result.current.pendingOrcidData?.affiliations).toEqual([
                expect.objectContaining({
                    value: 'New University',
                    status: 'new',
                }),
            ]);
        });

        it('skips affiliations that match existing by exact name', async () => {
            vi.mocked(OrcidService.fetchOrcidRecord).mockResolvedValue({
                success: true,
                data: {
                    orcid: '0000-0001-2345-6789',
                    firstName: 'John',
                    lastName: 'Doe',
                    creditName: null,
                    emails: [],
                    affiliations: [
                        { type: 'employment', name: 'Existing University', role: null, department: null, rorId: null },
                    ],
                    verifiedAt: new Date().toISOString(),
                },
            });

            const entry = createPersonEntry({
                firstName: 'John',
                lastName: 'Doe',
                affiliations: [{ value: 'Existing University', rorId: null }],
            });
            const onEntryChange = vi.fn();

            const { result } = renderHook(() =>
                useOrcidAutofill({ entry, onEntryChange, hasUserInteracted: false }),
            );

            await act(async () => {
                await result.current.handleOrcidSelect('0000-0001-2345-6789');
            });

            expect(result.current.pendingOrcidData).toBeNull();
        });

        it('marks as "different" when same ROR but different name', async () => {
            vi.mocked(OrcidService.fetchOrcidRecord).mockResolvedValue({
                success: true,
                data: {
                    orcid: '0000-0001-2345-6789',
                    firstName: 'John',
                    lastName: 'Doe',
                    creditName: null,
                    emails: [],
                    affiliations: [
                        {
                            type: 'employment',
                            name: 'Helmholtz-Zentrum Potsdam',
                            role: null,
                            department: null,
                            rorId: 'https://ror.org/04z8jg394',
                        },
                    ],
                    verifiedAt: new Date().toISOString(),
                },
            });

            const entry = createPersonEntry({
                firstName: 'John',
                lastName: 'Doe',
                affiliations: [{ value: 'GFZ German Research Centre', rorId: 'https://ror.org/04z8jg394' }],
            });
            const onEntryChange = vi.fn();

            const { result } = renderHook(() =>
                useOrcidAutofill({ entry, onEntryChange, hasUserInteracted: false }),
            );

            await act(async () => {
                await result.current.handleOrcidSelect('0000-0001-2345-6789');
            });

            expect(result.current.pendingOrcidData?.affiliations).toEqual([
                expect.objectContaining({
                    value: 'Helmholtz-Zentrum Potsdam',
                    status: 'different',
                    existingValue: 'GFZ German Research Centre',
                    rorId: 'https://ror.org/04z8jg394',
                }),
            ]);
        });

        it('resolves names to ROR via fetch and marks resolved matches as "different"', async () => {
            (global.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
                ok: true,
                json: () =>
                    Promise.resolve({
                        results: [
                            {
                                name: 'Helmholtz Centre Potsdam',
                                rorId: 'https://ror.org/04z8jg394',
                                matchedName: 'GFZ German Research Centre',
                            },
                        ],
                    }),
            });

            vi.mocked(OrcidService.fetchOrcidRecord).mockResolvedValue({
                success: true,
                data: {
                    orcid: '0000-0001-2345-6789',
                    firstName: 'John',
                    lastName: 'Doe',
                    creditName: null,
                    emails: [],
                    affiliations: [
                        {
                            type: 'employment',
                            name: 'Helmholtz Centre Potsdam',
                            role: null,
                            department: null,
                            rorId: null,
                        },
                    ],
                    verifiedAt: new Date().toISOString(),
                },
            });

            const entry = createPersonEntry({
                firstName: 'John',
                lastName: 'Doe',
                affiliations: [{ value: 'GFZ German Research Centre', rorId: 'https://ror.org/04z8jg394' }],
            });
            const onEntryChange = vi.fn();

            const { result } = renderHook(() =>
                useOrcidAutofill({ entry, onEntryChange, hasUserInteracted: false }),
            );

            await act(async () => {
                await result.current.handleOrcidSelect('0000-0001-2345-6789');
            });

            // Should have called /api/v1/ror-resolve
            expect(global.fetch).toHaveBeenCalledWith(
                '/api/v1/ror-resolve',
                expect.objectContaining({
                    method: 'POST',
                    body: JSON.stringify({ names: ['Helmholtz Centre Potsdam'] }),
                }),
            );

            expect(result.current.pendingOrcidData?.affiliations).toEqual([
                expect.objectContaining({
                    value: 'Helmholtz Centre Potsdam',
                    status: 'different',
                    rorId: 'https://ror.org/04z8jg394',
                }),
            ]);
        });

        it('handles fetch error for ROR resolution gracefully', async () => {
            (global.fetch as ReturnType<typeof vi.fn>).mockRejectedValue(new Error('Network error'));

            vi.mocked(OrcidService.fetchOrcidRecord).mockResolvedValue({
                success: true,
                data: {
                    orcid: '0000-0001-2345-6789',
                    firstName: 'John',
                    lastName: 'Doe',
                    creditName: null,
                    emails: [],
                    affiliations: [
                        { type: 'employment', name: 'Some University', role: null, department: null, rorId: null },
                    ],
                    verifiedAt: new Date().toISOString(),
                },
            });

            const entry = createPersonEntry({ firstName: 'John', lastName: 'Doe' });
            const onEntryChange = vi.fn();

            const { result } = renderHook(() =>
                useOrcidAutofill({ entry, onEntryChange, hasUserInteracted: false }),
            );

            await act(async () => {
                await result.current.handleOrcidSelect('0000-0001-2345-6789');
            });

            // Should still have created pending data despite fetch failure
            expect(result.current.pendingOrcidData?.affiliations).toEqual([
                expect.objectContaining({
                    value: 'Some University',
                    status: 'new',
                    rorId: null,
                }),
            ]);
        });

        it('deduplicates ORCID affiliations by ROR ID', async () => {
            vi.mocked(OrcidService.fetchOrcidRecord).mockResolvedValue({
                success: true,
                data: {
                    orcid: '0000-0001-2345-6789',
                    firstName: 'John',
                    lastName: 'Doe',
                    creditName: null,
                    emails: [],
                    affiliations: [
                        {
                            type: 'employment',
                            name: 'GFZ Potsdam',
                            role: null,
                            department: null,
                            rorId: 'https://ror.org/04z8jg394',
                        },
                        {
                            type: 'education',
                            name: 'GFZ German Research Centre for Geosciences',
                            role: null,
                            department: null,
                            rorId: 'https://ror.org/04z8jg394',
                        },
                    ],
                    verifiedAt: new Date().toISOString(),
                },
            });

            const entry = createPersonEntry({ firstName: 'John', lastName: 'Doe' });
            const onEntryChange = vi.fn();

            const { result } = renderHook(() =>
                useOrcidAutofill({ entry, onEntryChange, hasUserInteracted: false }),
            );

            await act(async () => {
                await result.current.handleOrcidSelect('0000-0001-2345-6789');
            });

            // Only the first one should be in pending (deduplicated)
            expect(result.current.pendingOrcidData?.affiliations).toHaveLength(1);
            expect(result.current.pendingOrcidData?.affiliations[0].value).toBe('GFZ Potsdam');
        });
    });

    describe('applyPendingData', () => {
        it('applies selected affiliations to entry', async () => {
            vi.mocked(OrcidService.fetchOrcidRecord).mockResolvedValue({
                success: true,
                data: {
                    orcid: '0000-0001-2345-6789',
                    firstName: 'John',
                    lastName: 'Doe',
                    creditName: null,
                    emails: [],
                    affiliations: [
                        { type: 'employment', name: 'New University', role: null, department: null, rorId: 'https://ror.org/xxx' },
                    ],
                    verifiedAt: new Date().toISOString(),
                },
            });

            const entry = createPersonEntry({
                firstName: 'John',
                lastName: 'Doe',
                affiliations: [{ value: 'Old University', rorId: null }],
            });
            const onEntryChange = vi.fn();

            const { result } = renderHook(() =>
                useOrcidAutofill({ entry, onEntryChange, hasUserInteracted: false }),
            );

            await act(async () => {
                await result.current.handleOrcidSelect('0000-0001-2345-6789');
            });

            // Now apply the pending data
            onEntryChange.mockClear();
            act(() => {
                result.current.applyPendingData({
                    affiliations: result.current.pendingOrcidData!.affiliations,
                    applyFirstName: false,
                    applyLastName: false,
                    applyEmail: false,
                });
            });

            expect(onEntryChange).toHaveBeenCalledWith(
                expect.objectContaining({
                    affiliations: [
                        { value: 'Old University', rorId: null },
                        { value: 'New University', rorId: 'https://ror.org/xxx' },
                    ],
                }),
            );
            // pendingOrcidData should be cleared
            expect(result.current.pendingOrcidData).toBeNull();
        });

        it('applies name corrections when selected', async () => {
            vi.mocked(OrcidService.fetchOrcidRecord).mockResolvedValue({
                success: true,
                data: {
                    orcid: '0000-0001-2345-6789',
                    firstName: 'Johann',
                    lastName: 'Mueller',
                    creditName: null,
                    emails: [],
                    affiliations: [],
                    verifiedAt: new Date().toISOString(),
                },
            });

            const entry = createPersonEntry({ firstName: 'John', lastName: 'Muller' });
            const onEntryChange = vi.fn();

            const { result } = renderHook(() =>
                useOrcidAutofill({ entry, onEntryChange, hasUserInteracted: false }),
            );

            await act(async () => {
                await result.current.handleOrcidSelect('0000-0001-2345-6789');
            });

            onEntryChange.mockClear();
            act(() => {
                result.current.applyPendingData({
                    affiliations: [],
                    applyFirstName: true,
                    applyLastName: true,
                    applyEmail: false,
                });
            });

            expect(onEntryChange).toHaveBeenCalledWith(
                expect.objectContaining({
                    firstName: 'Johann',
                    lastName: 'Mueller',
                }),
            );
        });

        it('applies email when selected', async () => {
            vi.mocked(OrcidService.fetchOrcidRecord).mockResolvedValue({
                success: true,
                data: {
                    orcid: '0000-0001-2345-6789',
                    firstName: 'John',
                    lastName: 'Doe',
                    creditName: null,
                    emails: ['new@example.com'],
                    affiliations: [],
                    verifiedAt: new Date().toISOString(),
                },
            });

            const entry = { ...createPersonEntry({ firstName: 'John', lastName: 'Doe' }), email: 'old@example.com' };
            const onEntryChange = vi.fn();

            const { result } = renderHook(() =>
                useOrcidAutofill({ entry, onEntryChange, hasUserInteracted: false, includeEmail: true }),
            );

            await act(async () => {
                await result.current.handleOrcidSelect('0000-0001-2345-6789');
            });

            onEntryChange.mockClear();
            act(() => {
                result.current.applyPendingData({
                    affiliations: [],
                    applyFirstName: false,
                    applyLastName: false,
                    applyEmail: true,
                });
            });

            expect(onEntryChange).toHaveBeenCalledWith(
                expect.objectContaining({
                    email: 'new@example.com',
                }),
            );
        });

        it('does nothing for institution entries', () => {
            const entry = {
                id: 'test-1',
                type: 'institution' as const,
                affiliations: [],
                affiliationsInput: '',
            };
            const onEntryChange = vi.fn();

            const { result } = renderHook(() =>
                useOrcidAutofill({ entry, onEntryChange, hasUserInteracted: false }),
            );

            act(() => {
                result.current.applyPendingData({
                    affiliations: [],
                    applyFirstName: false,
                    applyLastName: false,
                    applyEmail: false,
                });
            });

            expect(onEntryChange).not.toHaveBeenCalled();
        });
    });

    describe('clearPendingOrcidData', () => {
        it('clears pending data', async () => {
            vi.mocked(OrcidService.fetchOrcidRecord).mockResolvedValue({
                success: true,
                data: {
                    orcid: '0000-0001-2345-6789',
                    firstName: 'Johann',
                    lastName: 'Doe',
                    creditName: null,
                    emails: [],
                    affiliations: [],
                    verifiedAt: new Date().toISOString(),
                },
            });

            const entry = createPersonEntry({ firstName: 'John', lastName: 'Doe' });
            const onEntryChange = vi.fn();

            const { result } = renderHook(() =>
                useOrcidAutofill({ entry, onEntryChange, hasUserInteracted: false }),
            );

            await act(async () => {
                await result.current.handleOrcidSelect('0000-0001-2345-6789');
            });

            expect(result.current.pendingOrcidData).not.toBeNull();

            act(() => {
                result.current.clearPendingOrcidData();
            });

            expect(result.current.pendingOrcidData).toBeNull();
        });
    });
});
