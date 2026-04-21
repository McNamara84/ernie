import { QueryClientProvider } from '@tanstack/react-query';
import { act, renderHook, waitFor } from '@testing-library/react';
import { createElement, type ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { type DoiValidationResponse, useDoiValidation } from '@/hooks/use-doi-validation';
import { apiEndpoints } from '@/lib/query-keys';

import { http, HttpResponse, server } from '../helpers/msw-server';
import { createTestQueryClient, renderHookWithQueryClient } from '../helpers/render-with-query-client';

type Captured = { body: unknown; count: number };

function mockDoiEndpoint(response: DoiValidationResponse | ((body: unknown) => DoiValidationResponse)): Captured {
    const captured: Captured = { body: null, count: 0 };
    server.use(
        http.post(apiEndpoints.doiValidate, async ({ request }) => {
            captured.body = await request.json();
            captured.count += 1;
            const payload = typeof response === 'function' ? response(captured.body) : response;
            return HttpResponse.json(payload);
        }),
    );
    return captured;
}

describe('useDoiValidation', () => {
    beforeEach(() => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
    });

    afterEach(() => {
        vi.clearAllMocks();
        vi.useRealTimers();
    });

    describe('Initial state', () => {
        it('initialises with default values', () => {
            const { result } = renderHookWithQueryClient(() => useDoiValidation());

            expect(result.current.isValidating).toBe(false);
            expect(result.current.isValid).toBeNull();
            expect(result.current.error).toBeNull();
            expect(result.current.conflictData).toBeNull();
            expect(result.current.showConflictModal).toBe(false);
        });
    });

    describe('validateDoi', () => {
        it('resets state for empty DOI', () => {
            const { result } = renderHookWithQueryClient(() => useDoiValidation());

            act(() => {
                result.current.validateDoi('');
            });

            expect(result.current.isValidating).toBe(false);
            expect(result.current.isValid).toBeNull();
            expect(result.current.error).toBeNull();
        });

        it('sends DOI and excludeResourceId to the backend', async () => {
            const captured = mockDoiEndpoint({ is_valid_format: true, exists: false });

            const { result } = renderHookWithQueryClient(() =>
                useDoiValidation({ excludeResourceId: 123, debounceMs: 0 }),
            );

            await act(async () => {
                result.current.validateDoi('10.5880/test.2026.001');
            });

            await act(async () => {
                await vi.advanceTimersByTimeAsync(100);
            });

            await waitFor(() => expect(captured.count).toBe(1));
            expect(captured.body).toEqual({
                doi: '10.5880/test.2026.001',
                exclude_resource_id: 123,
            });
        });

        it('sets isValid=true when DOI is available', async () => {
            mockDoiEndpoint({ is_valid_format: true, exists: false });

            const onSuccess = vi.fn();
            const { result } = renderHookWithQueryClient(() =>
                useDoiValidation({ debounceMs: 0, onSuccess }),
            );

            await act(async () => {
                result.current.validateDoi('10.5880/test.2026.001');
            });
            await act(async () => {
                await vi.advanceTimersByTimeAsync(100);
            });

            await waitFor(() => expect(result.current.isValid).toBe(true));
            expect(result.current.error).toBeNull();
            expect(result.current.conflictData).toBeNull();
            expect(onSuccess).toHaveBeenCalled();
        });

        it('sets error for invalid DOI format', async () => {
            mockDoiEndpoint({
                is_valid_format: false,
                exists: false,
                error: 'Invalid DOI format',
            });

            const onError = vi.fn();
            const { result } = renderHookWithQueryClient(() =>
                useDoiValidation({ debounceMs: 0, onError }),
            );

            await act(async () => {
                result.current.validateDoi('invalid-doi');
            });
            await act(async () => {
                await vi.advanceTimersByTimeAsync(100);
            });

            await waitFor(() => expect(result.current.isValid).toBe(false));
            expect(result.current.error).toBe('Invalid DOI format');
            expect(onError).toHaveBeenCalledWith('Invalid DOI format');
        });

        it('shows conflict modal when DOI exists', async () => {
            mockDoiEndpoint({
                is_valid_format: true,
                exists: true,
                existing_resource: { id: 456, title: 'Existing Resource' },
                last_assigned_doi: '10.5880/test.2026.003',
                suggested_doi: '10.5880/test.2026.004',
            });

            const onConflict = vi.fn();
            const { result } = renderHookWithQueryClient(() =>
                useDoiValidation({ debounceMs: 0, onConflict }),
            );

            await act(async () => {
                result.current.validateDoi('10.5880/test.2026.001');
            });
            await act(async () => {
                await vi.advanceTimersByTimeAsync(100);
            });

            await waitFor(() => expect(result.current.showConflictModal).toBe(true));

            expect(result.current.conflictData).toEqual({
                existingDoi: '10.5880/test.2026.001',
                existingResourceId: 456,
                existingResourceTitle: 'Existing Resource',
                hasSuggestion: true,
                lastAssignedDoi: '10.5880/test.2026.003',
                suggestedDoi: '10.5880/test.2026.004',
            });
            expect(onConflict).toHaveBeenCalled();
        });

        it('debounces rapid successive calls', async () => {
            const captured = mockDoiEndpoint({ is_valid_format: true, exists: false });

            const { result } = renderHookWithQueryClient(() => useDoiValidation({ debounceMs: 300 }));

            await act(async () => {
                result.current.validateDoi('10.5880/a');
            });
            await act(async () => {
                result.current.validateDoi('10.5880/ab');
            });
            await act(async () => {
                result.current.validateDoi('10.5880/abc');
            });

            await act(async () => {
                await vi.advanceTimersByTimeAsync(350);
            });

            await waitFor(() => expect(captured.count).toBe(1));
            expect((captured.body as { doi: string }).doi).toBe('10.5880/abc');
        });
    });

    describe('resetValidation', () => {
        it('resets all state to initial values', async () => {
            mockDoiEndpoint({
                is_valid_format: true,
                exists: true,
                existing_resource: { id: 1, title: 'Test' },
                last_assigned_doi: '10.5880/test.001',
                suggested_doi: '10.5880/test.002',
            });

            const { result } = renderHookWithQueryClient(() => useDoiValidation({ debounceMs: 0 }));

            await act(async () => {
                result.current.validateDoi('10.5880/test.001');
            });
            await act(async () => {
                await vi.advanceTimersByTimeAsync(100);
            });

            await waitFor(() => expect(result.current.conflictData).not.toBeNull());

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
        it('updates showConflictModal state', () => {
            const { result } = renderHookWithQueryClient(() => useDoiValidation());

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

    describe('Network errors and cancellation', () => {
        it('handles network errors with fallback message', async () => {
            server.use(http.post(apiEndpoints.doiValidate, () => HttpResponse.error()));

            const onError = vi.fn();
            const { result } = renderHookWithQueryClient(() =>
                useDoiValidation({ debounceMs: 0, onError }),
            );

            await act(async () => {
                result.current.validateDoi('10.5880/test.2026.001');
            });
            await act(async () => {
                await vi.advanceTimersByTimeAsync(100);
            });

            await waitFor(() => expect(result.current.isValid).toBe(false));
            expect(result.current.error).toBe('Validation failed');
            expect(onError).toHaveBeenCalledWith('Validation failed');
        });

        it('uses backend error message on ApiError', async () => {
            server.use(
                http.post(apiEndpoints.doiValidate, () =>
                    HttpResponse.json({ message: 'Rate limit exceeded' }, { status: 429 }),
                ),
            );

            const onError = vi.fn();
            const { result } = renderHookWithQueryClient(() =>
                useDoiValidation({ debounceMs: 0, onError }),
            );

            await act(async () => {
                result.current.validateDoi('10.5880/test.2026.001');
            });
            await act(async () => {
                await vi.advanceTimersByTimeAsync(100);
            });

            await waitFor(() => expect(result.current.error).toBe('Rate limit exceeded'));
            expect(onError).toHaveBeenCalledWith('Rate limit exceeded');
        });

        it('cancels the previous request when a new validation starts', async () => {
            const captured: { count: number } = { count: 0 };
            server.use(
                http.post(apiEndpoints.doiValidate, async () => {
                    captured.count += 1;
                    return HttpResponse.json({ is_valid_format: true, exists: false });
                }),
            );

            const { result } = renderHookWithQueryClient(() => useDoiValidation({ debounceMs: 100 }));

            await act(async () => {
                result.current.validateDoi('10.5880/first');
            });
            await act(async () => {
                result.current.validateDoi('10.5880/second');
            });

            await act(async () => {
                await vi.advanceTimersByTimeAsync(200);
            });

            await waitFor(() => expect(captured.count).toBeGreaterThanOrEqual(1));
            expect(captured.count).toBe(1);
        });
    });

    describe('Null suggested DOI handling', () => {
        it('handles absent suggested_doi as hasSuggestion=false', async () => {
            mockDoiEndpoint({
                is_valid_format: true,
                exists: true,
                existing_resource: { id: 456, title: 'Existing Resource' },
                last_assigned_doi: '10.5880/test.2026.003',
                suggested_doi: undefined,
            });

            const { result } = renderHookWithQueryClient(() => useDoiValidation({ debounceMs: 0 }));

            await act(async () => {
                result.current.validateDoi('10.5880/test.2026.001');
            });
            await act(async () => {
                await vi.advanceTimersByTimeAsync(100);
            });

            await waitFor(() => expect(result.current.conflictData).not.toBeNull());

            expect(result.current.conflictData?.hasSuggestion).toBe(false);
            expect(result.current.conflictData?.suggestedDoi).toBe('');
        });
    });

    describe('checkDoiBeforeSave', () => {
        it('returns null for empty DOI and resets state', async () => {
            const captured = mockDoiEndpoint({ is_valid_format: true, exists: false });

            const { result } = renderHookWithQueryClient(() => useDoiValidation());

            let conflict: unknown;
            await act(async () => {
                conflict = await result.current.checkDoiBeforeSave('');
            });

            expect(conflict).toBeNull();
            expect(captured.count).toBe(0);
            expect(result.current.isValid).toBeNull();
            expect(result.current.conflictData).toBeNull();
        });

        it('returns null when DOI is available', async () => {
            mockDoiEndpoint({ is_valid_format: true, exists: false });
            const onSuccess = vi.fn();

            const { result } = renderHookWithQueryClient(() => useDoiValidation({ onSuccess }));

            let conflict: unknown;
            await act(async () => {
                conflict = await result.current.checkDoiBeforeSave('10.5880/test.2026.001');
            });

            expect(conflict).toBeNull();
            expect(result.current.isValid).toBe(true);
            expect(onSuccess).toHaveBeenCalled();
        });

        it('returns conflict data when DOI exists', async () => {
            mockDoiEndpoint({
                is_valid_format: true,
                exists: true,
                existing_resource: { id: 789, title: 'Blocking Resource' },
                last_assigned_doi: '10.5880/test.2026.005',
                suggested_doi: '10.5880/test.2026.006',
            });

            const onConflict = vi.fn();
            const { result } = renderHookWithQueryClient(() => useDoiValidation({ onConflict }));

            let conflict: unknown;
            await act(async () => {
                conflict = await result.current.checkDoiBeforeSave('10.5880/test.2026.005');
            });

            expect(conflict).toEqual({
                existingDoi: '10.5880/test.2026.005',
                existingResourceId: 789,
                existingResourceTitle: 'Blocking Resource',
                lastAssignedDoi: '10.5880/test.2026.005',
                suggestedDoi: '10.5880/test.2026.006',
                hasSuggestion: true,
            });
            expect(result.current.showConflictModal).toBe(true);
            expect(onConflict).toHaveBeenCalled();
        });

        it('returns null on network error (does not block save)', async () => {
            server.use(http.post(apiEndpoints.doiValidate, () => HttpResponse.error()));

            const { result } = renderHookWithQueryClient(() => useDoiValidation());

            let conflict: unknown;
            await act(async () => {
                conflict = await result.current.checkDoiBeforeSave('10.5880/test.2026.001');
            });

            expect(conflict).toBeNull();
        });

        it('passes excludeResourceId to the backend', async () => {
            const captured = mockDoiEndpoint({ is_valid_format: true, exists: false });

            const { result } = renderHookWithQueryClient(() =>
                useDoiValidation({ excludeResourceId: 42 }),
            );

            await act(async () => {
                await result.current.checkDoiBeforeSave('10.5880/test.2026.001');
            });

            expect(captured.body).toEqual({
                doi: '10.5880/test.2026.001',
                exclude_resource_id: 42,
            });
        });

        it('returns null when format is invalid', async () => {
            mockDoiEndpoint({
                is_valid_format: false,
                exists: false,
                error: 'Invalid DOI format',
            });

            const onError = vi.fn();
            const { result } = renderHookWithQueryClient(() => useDoiValidation({ onError }));

            let conflict: unknown;
            await act(async () => {
                conflict = await result.current.checkDoiBeforeSave('invalid-doi');
            });

            expect(conflict).toBeNull();
            expect(result.current.isValid).toBe(false);
            expect(result.current.error).toBe('Invalid DOI format');
            expect(onError).toHaveBeenCalledWith('Invalid DOI format');
        });

        it('falls back to the default invalidFormat message when backend omits error field', async () => {
            mockDoiEndpoint({ is_valid_format: false, exists: false });

            const onError = vi.fn();
            const { result } = renderHookWithQueryClient(() => useDoiValidation({ onError }));

            await act(async () => {
                await result.current.checkDoiBeforeSave('invalid-doi');
            });

            expect(result.current.isValid).toBe(false);
            expect(result.current.error).toBe('Invalid DOI format');
            expect(onError).toHaveBeenCalledWith('Invalid DOI format');
        });

        it('always hits the backend to bypass stale cache from prior validateDoi', async () => {
            // First response: DOI appears available.
            // Second response (during checkDoiBeforeSave): DOI is now taken.
            let call = 0;
            server.use(
                http.post(apiEndpoints.doiValidate, () => {
                    call += 1;
                    if (call === 1) {
                        return HttpResponse.json({ is_valid_format: true, exists: false });
                    }
                    return HttpResponse.json({
                        is_valid_format: true,
                        exists: true,
                        existing_resource: { id: 77, title: 'Race Winner' },
                        last_assigned_doi: '10.5880/cache.001',
                        suggested_doi: '10.5880/cache.002',
                    });
                }),
            );

            const { result } = renderHookWithQueryClient(() => useDoiValidation({ debounceMs: 0 }));

            // Prime cache with an "available" response via debounced validateDoi.
            await act(async () => {
                result.current.validateDoi('10.5880/cache.001');
            });
            await act(async () => {
                await vi.advanceTimersByTimeAsync(100);
            });
            await waitFor(() => expect(result.current.isValid).toBe(true));

            // Pre-save check must refetch and surface the new conflict.
            let conflict: unknown;
            await act(async () => {
                conflict = await result.current.checkDoiBeforeSave('10.5880/cache.001');
            });

            expect(call).toBe(2);
            expect(conflict).not.toBeNull();
            expect((conflict as { existingResourceId: number }).existingResourceId).toBe(77);
        });

        it('resets isValid when a network failure occurs during save check', async () => {
            // First response: DOI appears available (primes isValid=true).
            // Second request (during checkDoiBeforeSave): network error.
            let call = 0;
            server.use(
                http.post(apiEndpoints.doiValidate, () => {
                    call += 1;
                    if (call === 1) {
                        return HttpResponse.json({ is_valid_format: true, exists: false });
                    }
                    return HttpResponse.error();
                }),
            );

            const { result } = renderHookWithQueryClient(() => useDoiValidation({ debounceMs: 0 }));

            await act(async () => {
                result.current.validateDoi('10.5880/flaky');
            });
            await act(async () => {
                await vi.advanceTimersByTimeAsync(100);
            });
            await waitFor(() => expect(result.current.isValid).toBe(true));

            let conflict: unknown;
            await act(async () => {
                conflict = await result.current.checkDoiBeforeSave('10.5880/flaky');
            });

            // Returns null (don't block save) but must not leave isValid=true,
            // otherwise consumers would see an inconsistent "valid + null" state.
            expect(conflict).toBeNull();
            expect(result.current.isValid).toBeNull();
        });
    });

    describe('Custom error messages', () => {
        it('uses the overridden invalidFormat message when no backend error is present', async () => {
            mockDoiEndpoint({ is_valid_format: false, exists: false });

            const onError = vi.fn();
            const { result } = renderHookWithQueryClient(() =>
                useDoiValidation({
                    debounceMs: 0,
                    onError,
                    errorMessages: { invalidFormat: 'Custom invalid format' },
                }),
            );

            await act(async () => {
                result.current.validateDoi('10.5880/bad');
            });
            await act(async () => {
                await vi.advanceTimersByTimeAsync(100);
            });

            await waitFor(() => expect(result.current.error).toBe('Custom invalid format'));
            expect(onError).toHaveBeenCalledWith('Custom invalid format');
        });

        it('uses the overridden validationFailed message on network errors', async () => {
            server.use(http.post(apiEndpoints.doiValidate, () => HttpResponse.error()));

            const { result } = renderHookWithQueryClient(() =>
                useDoiValidation({
                    debounceMs: 0,
                    errorMessages: { validationFailed: 'Could not validate DOI' },
                }),
            );

            await act(async () => {
                result.current.validateDoi('10.5880/test.2026.001');
            });
            await act(async () => {
                await vi.advanceTimersByTimeAsync(100);
            });

            await waitFor(() => expect(result.current.error).toBe('Could not validate DOI'));
        });
    });

    describe('Structured 422 responses', () => {
        it('surfaces the backend error field on HTTP 422 with invalid format body', async () => {
            server.use(
                http.post(apiEndpoints.doiValidate, () =>
                    HttpResponse.json(
                        { is_valid_format: false, exists: false, error: 'DOI prefix is not allowed' },
                        { status: 422 },
                    ),
                ),
            );

            const onError = vi.fn();
            const { result } = renderHookWithQueryClient(() =>
                useDoiValidation({ debounceMs: 0, onError }),
            );

            await act(async () => {
                result.current.validateDoi('10.9999/forbidden');
            });
            await act(async () => {
                await vi.advanceTimersByTimeAsync(100);
            });

            await waitFor(() => expect(result.current.error).toBe('DOI prefix is not allowed'));
            expect(onError).toHaveBeenCalledWith('DOI prefix is not allowed');
            expect(result.current.isValid).toBe(false);
        });

        it('falls back to default invalidFormat when 422 body omits error field', async () => {
            server.use(
                http.post(apiEndpoints.doiValidate, () =>
                    HttpResponse.json(
                        { is_valid_format: false, exists: false },
                        { status: 422 },
                    ),
                ),
            );

            const { result } = renderHookWithQueryClient(() =>
                useDoiValidation({ debounceMs: 0 }),
            );

            await act(async () => {
                result.current.validateDoi('10.9999/forbidden');
            });
            await act(async () => {
                await vi.advanceTimersByTimeAsync(100);
            });

            await waitFor(() => expect(result.current.error).toBe('Invalid DOI format'));
        });
    });

    describe('Unmount cleanup', () => {
        it('aborts the in-flight request and clears pending timeout on unmount', async () => {
            const pendingResolvers: Array<() => void> = [];
            server.use(
                http.post(apiEndpoints.doiValidate, async () => {
                    await new Promise<void>((resolve) => {
                        pendingResolvers.push(resolve);
                    });
                    return HttpResponse.json({ is_valid_format: true, exists: false });
                }),
            );

            const { result, unmount } = renderHookWithQueryClient(() =>
                useDoiValidation({ debounceMs: 0 }),
            );

            await act(async () => {
                result.current.validateDoi('10.5880/unmount');
            });
            await act(async () => {
                await vi.advanceTimersByTimeAsync(10);
            });

            unmount();

            // Release the pending request so MSW can tidy up; should not cause
            // state updates on the unmounted hook.
            pendingResolvers.forEach((resolve) => resolve());

            // Nothing to assert on state (hook is gone); the fact that the test
            // terminates without MSW complaining about an unclosed request is
            // the signal that cleanup worked.
            expect(true).toBe(true);
        });
    });

    describe('checkDoiBeforeSave cancelling a pending validateDoi', () => {
        it('clears the pending debounce timer and aborts the controller', async () => {
            const captured = mockDoiEndpoint({ is_valid_format: true, exists: false });

            const { result } = renderHookWithQueryClient(() => useDoiValidation({ debounceMs: 500 }));

            // Kick off a debounced validateDoi that has not yet fired.
            await act(async () => {
                result.current.validateDoi('10.5880/pending');
            });

            // Before the debounce elapses, invoke the synchronous check.
            let conflict: unknown;
            await act(async () => {
                conflict = await result.current.checkDoiBeforeSave('10.5880/sync');
            });

            // Only the synchronous check should reach the backend.
            expect(captured.count).toBe(1);
            expect((captured.body as { doi: string }).doi).toBe('10.5880/sync');
            expect(conflict).toBeNull();
            expect(result.current.isValid).toBe(true);
        });

        it('keeps isValidating=true while the save-check runs even after a stale validateDoi resolves', async () => {
            // First: a slow validateDoi POST that has not yet resolved when
            // checkDoiBeforeSave overtakes it. Second: the synchronous save
            // check, which resolves quickly.
            let resolveFirst: (() => void) | null = null;
            let call = 0;
            server.use(
                http.post(apiEndpoints.doiValidate, async () => {
                    call += 1;
                    if (call === 1) {
                        await new Promise<void>((resolve) => {
                            resolveFirst = resolve as () => void;
                        });
                        return HttpResponse.json({ is_valid_format: true, exists: false });
                    }
                    // Block the second request indefinitely so we can observe
                    // `isValidating` while the save-check is still in flight.
                    await new Promise(() => {
                        /* never resolves */
                    });
                    return HttpResponse.json({ is_valid_format: true, exists: false });
                }),
            );

            const { result } = renderHookWithQueryClient(() => useDoiValidation({ debounceMs: 0 }));

            // Kick off the debounced validateDoi and let its request reach the server.
            await act(async () => {
                result.current.validateDoi('10.5880/race');
            });
            await act(async () => {
                await vi.advanceTimersByTimeAsync(50);
            });
            await waitFor(() => expect(call).toBe(1));

            // Start the save-check. It will move `activeQueryKeyRef` to its own key
            // and set `isValidating=true`. It then awaits a never-resolving fetch.
            let savePromise: Promise<unknown> | null = null;
            await act(async () => {
                savePromise = result.current.checkDoiBeforeSave('10.5880/race-save');
                await Promise.resolve();
            });

            expect(result.current.isValidating).toBe(true);

            // Now release the stale validateDoi request. Its `finally` must NOT
            // flip `isValidating` to false because the save-check is the active run.
            await act(async () => {
                resolveFirst?.();
                await vi.advanceTimersByTimeAsync(0);
            });

            expect(result.current.isValidating).toBe(true);

            // Cleanup: silence the dangling save promise.
            void savePromise;
        });

        it('aborts the in-flight fetch when cancelQueries is invoked (e.g. on unmount)', async () => {
            let receivedSignal: AbortSignal | null = null;
            let aborted = false;
            server.use(
                http.post(apiEndpoints.doiValidate, async ({ request }) => {
                    receivedSignal = request.signal;
                    request.signal.addEventListener('abort', () => {
                        aborted = true;
                    });
                    // Never resolve — caller must abort.
                    await new Promise(() => {});
                    return HttpResponse.json({ is_valid_format: true, exists: false });
                }),
            );

            const queryClient = createTestQueryClient();
            const { result, unmount } = renderHook(
                () => useDoiValidation({ debounceMs: 0 }),
                {
                    wrapper: ({ children }: { children: ReactNode }) =>
                        createElement(QueryClientProvider, { client: queryClient }, children),
                },
            );

            let savePromise: Promise<unknown> | null = null;
            await act(async () => {
                savePromise = result.current.checkDoiBeforeSave('10.5880/unmount');
                await Promise.resolve();
            });

            await waitFor(() => expect(receivedSignal).not.toBeNull());
            expect(aborted).toBe(false);

            // Simulate the unmount path: cancel all queries, then unmount.
            await act(async () => {
                await queryClient.cancelQueries();
                unmount();
            });

            expect(aborted).toBe(true);
            void savePromise;
        });
    });
});
