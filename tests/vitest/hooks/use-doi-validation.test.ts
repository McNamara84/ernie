import { act, renderHook, waitFor } from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { useDoiValidation, type DoiValidationResponse } from '@/hooks/use-doi-validation';

// Mock axios
vi.mock('axios', () => ({
    default: {
        post: vi.fn(),
        isCancel: vi.fn(() => false),
    },
    isAxiosError: vi.fn(),
}));

describe('useDoiValidation', () => {
    beforeEach(() => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
    });

    afterEach(() => {
        vi.clearAllMocks();
        vi.useRealTimers();
    });

    describe('Initial state', () => {
        it('should initialize with default values', () => {
            const { result } = renderHook(() => useDoiValidation());

            expect(result.current.isValidating).toBe(false);
            expect(result.current.isValid).toBeNull();
            expect(result.current.error).toBeNull();
            expect(result.current.conflictData).toBeNull();
            expect(result.current.showConflictModal).toBe(false);
        });
    });

    describe('validateDoi', () => {
        it('should reset state for empty DOI', async () => {
            const { result } = renderHook(() => useDoiValidation());

            act(() => {
                result.current.validateDoi('');
            });

            expect(result.current.isValidating).toBe(false);
            expect(result.current.isValid).toBeNull();
            expect(result.current.error).toBeNull();
        });

        it('should call API with correct parameters', async () => {
            const mockResponse: DoiValidationResponse = {
                is_valid_format: true,
                exists: false,
            };

            vi.mocked(axios.post).mockResolvedValueOnce({ data: mockResponse });

            const { result } = renderHook(() =>
                useDoiValidation({ excludeResourceId: 123, debounceMs: 0 })
            );

            await act(async () => {
                result.current.validateDoi('10.5880/test.2026.001');
            });

            // Advance timers to trigger debounced call
            await act(async () => {
                await vi.advanceTimersByTimeAsync(100);
            });

            expect(axios.post).toHaveBeenCalledWith(
                '/api/v1/doi/validate',
                {
                    doi: '10.5880/test.2026.001',
                    exclude_resource_id: 123,
                },
                expect.objectContaining({ signal: expect.any(AbortSignal) })
            );
        });

        it('should set isValid to true when DOI is available', async () => {
            const mockResponse: DoiValidationResponse = {
                is_valid_format: true,
                exists: false,
            };

            vi.mocked(axios.post).mockResolvedValueOnce({ data: mockResponse });

            const onSuccess = vi.fn();
            const { result } = renderHook(() =>
                useDoiValidation({ debounceMs: 0, onSuccess })
            );

            await act(async () => {
                result.current.validateDoi('10.5880/test.2026.001');
            });

            await act(async () => {
                await vi.advanceTimersByTimeAsync(100);
            });

            await waitFor(() => {
                expect(result.current.isValid).toBe(true);
            });
            
            expect(result.current.error).toBeNull();
            expect(result.current.conflictData).toBeNull();
            expect(onSuccess).toHaveBeenCalled();
        });

        it('should set error for invalid DOI format', async () => {
            const mockResponse: DoiValidationResponse = {
                is_valid_format: false,
                exists: false,
                error: 'Invalid DOI format',
            };

            vi.mocked(axios.post).mockResolvedValueOnce({ data: mockResponse });

            const onError = vi.fn();
            const { result } = renderHook(() =>
                useDoiValidation({ debounceMs: 0, onError })
            );

            await act(async () => {
                result.current.validateDoi('invalid-doi');
            });

            await act(async () => {
                await vi.advanceTimersByTimeAsync(100);
            });

            await waitFor(() => {
                expect(result.current.isValid).toBe(false);
            });
            
            expect(result.current.error).toBe('Invalid DOI format');
            expect(onError).toHaveBeenCalledWith('Invalid DOI format');
        });

        it('should show conflict modal when DOI exists', async () => {
            const mockResponse: DoiValidationResponse = {
                is_valid_format: true,
                exists: true,
                existing_resource: {
                    id: 456,
                    title: 'Existing Resource',
                },
                last_assigned_doi: '10.5880/test.2026.003',
                suggested_doi: '10.5880/test.2026.004',
            };

            vi.mocked(axios.post).mockResolvedValueOnce({ data: mockResponse });

            const onConflict = vi.fn();
            const { result } = renderHook(() =>
                useDoiValidation({ debounceMs: 0, onConflict })
            );

            await act(async () => {
                result.current.validateDoi('10.5880/test.2026.001');
            });

            await act(async () => {
                await vi.advanceTimersByTimeAsync(100);
            });

            await waitFor(() => {
                expect(result.current.showConflictModal).toBe(true);
            });
            
            expect(result.current.conflictData).toEqual({
                existingDoi: '10.5880/test.2026.001',
                existingResourceId: 456,
                existingResourceTitle: 'Existing Resource',
                lastAssignedDoi: '10.5880/test.2026.003',
                suggestedDoi: '10.5880/test.2026.004',
            });
            expect(onConflict).toHaveBeenCalledWith(expect.objectContaining({
                existingDoi: '10.5880/test.2026.001',
            }));
        });

        it('should debounce multiple rapid calls', async () => {
            const mockResponse: DoiValidationResponse = {
                is_valid_format: true,
                exists: false,
            };

            vi.mocked(axios.post).mockResolvedValue({ data: mockResponse });

            const { result } = renderHook(() =>
                useDoiValidation({ debounceMs: 300 })
            );

            // Make multiple rapid calls
            await act(async () => {
                result.current.validateDoi('10.5880/a');
            });
            await act(async () => {
                result.current.validateDoi('10.5880/ab');
            });
            await act(async () => {
                result.current.validateDoi('10.5880/abc');
            });

            // Advance just past the debounce time
            await act(async () => {
                await vi.advanceTimersByTimeAsync(350);
            });

            // Should only have made one API call with the last value
            expect(axios.post).toHaveBeenCalledTimes(1);
            expect(axios.post).toHaveBeenCalledWith(
                '/api/v1/doi/validate',
                expect.objectContaining({ doi: '10.5880/abc' }),
                expect.any(Object)
            );
        });
    });

    describe('resetValidation', () => {
        it('should reset all state to initial values', async () => {
            const mockResponse: DoiValidationResponse = {
                is_valid_format: true,
                exists: true,
                existing_resource: { id: 1, title: 'Test' },
                last_assigned_doi: '10.5880/test.001',
                suggested_doi: '10.5880/test.002',
            };

            vi.mocked(axios.post).mockResolvedValueOnce({ data: mockResponse });

            const { result } = renderHook(() => useDoiValidation({ debounceMs: 0 }));

            // First, trigger a conflict
            await act(async () => {
                result.current.validateDoi('10.5880/test.001');
            });

            await act(async () => {
                await vi.advanceTimersByTimeAsync(100);
            });

            await waitFor(() => {
                expect(result.current.conflictData).not.toBeNull();
            });

            // Then reset
            act(() => {
                result.current.resetValidation();
            });

            expect(result.current.isValidating).toBe(false);
            expect(result.current.isValid).toBeNull();
            expect(result.current.error).toBeNull();
            expect(result.current.conflictData).toBeNull();
            expect(result.current.showConflictModal).toBe(false);
        });
    });

    describe('setShowConflictModal', () => {
        it('should update showConflictModal state', () => {
            const { result } = renderHook(() => useDoiValidation());

            act(() => {
                result.current.setShowConflictModal(true);
            });

            expect(result.current.showConflictModal).toBe(true);

            act(() => {
                result.current.setShowConflictModal(false);
            });

            expect(result.current.showConflictModal).toBe(false);
        });
    });
});
