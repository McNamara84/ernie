import { act, renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mocks = vi.hoisted(() => ({
    eject: vi.fn(),
    initialize: vi.fn(),
    isCancel: vi.fn((error: { code?: string }) => error.code === 'ERR_CANCELED'),
    isAxiosError: vi.fn((error: { isAxiosError?: boolean }) => error.isAxiosError === true),
    path: vi.fn((value?: string) => {
        if (!value) return null;
        const url = new URL(value, window.location.origin);
        return url.origin === window.location.origin ? url.pathname : null;
    }),
    record: vi.fn(),
    removeFinish: vi.fn(),
    routerOn: vi.fn(),
    sanitize: vi.fn((value: string) => value),
    use: vi.fn(),
}));

vi.unmock('@/hooks/use-feedback-diagnostics');

vi.mock('@inertiajs/react', () => ({
    router: { on: mocks.routerOn },
    usePage: () => ({ props: { auth: { user: { id: 17 } } } }),
}));

vi.mock('axios', () => ({
    default: {
        interceptors: { response: { eject: mocks.eject, use: mocks.use } },
        isAxiosError: mocks.isAxiosError,
        isCancel: mocks.isCancel,
    },
}));

vi.mock('@/lib/feedback-diagnostics', () => ({
    feedbackPathFromUrl: mocks.path,
    initializeFeedbackDiagnostics: mocks.initialize,
    recordFeedbackDiagnostic: mocks.record,
    sanitizeFeedbackDiagnosticMessage: mocks.sanitize,
}));

import { useFeedbackDiagnostics } from '@/hooks/use-feedback-diagnostics';

describe('useFeedbackDiagnostics', () => {
    let finish: (event: { detail: { visit: { completed: boolean } } }) => void;
    let rejected: (error: unknown) => Promise<never>;

    beforeEach(() => {
        vi.clearAllMocks();
        window.history.replaceState({}, '', '/dashboard?private=yes');
        mocks.routerOn.mockImplementation((_event: string, callback: typeof finish) => {
            finish = callback;
            return mocks.removeFinish;
        });
        mocks.use.mockImplementation((_fulfilled: unknown, callback: (error: unknown) => Promise<never>) => {
            rejected = callback;
            return 42;
        });
    });

    it('registers the initial navigation and cleans up every listener', () => {
        const removeError = vi.spyOn(window, 'removeEventListener');
        const { unmount } = renderHook(() => useFeedbackDiagnostics());

        expect(mocks.initialize).toHaveBeenCalledWith(17);
        expect(mocks.record).toHaveBeenCalledWith(17, expect.objectContaining({ type: 'navigation', path: '/dashboard' }));
        expect(mocks.routerOn).toHaveBeenCalledWith('finish', expect.any(Function));
        expect(mocks.use).toHaveBeenCalledOnce();

        unmount();

        expect(mocks.removeFinish).toHaveBeenCalledOnce();
        expect(mocks.eject).toHaveBeenCalledWith(42);
        expect(removeError).toHaveBeenCalledWith('error', expect.any(Function));
        expect(removeError).toHaveBeenCalledWith('unhandledrejection', expect.any(Function));
    });

    it('records completed Inertia navigation with no query string', () => {
        renderHook(() => useFeedbackDiagnostics());
        mocks.record.mockClear();
        window.history.replaceState({}, '', '/resources?secret=yes#row');

        act(() => finish({ detail: { visit: { completed: true } } }));

        expect(mocks.record).toHaveBeenCalledWith(17, expect.objectContaining({ type: 'navigation', path: '/resources' }));
    });

    it('ignores cancelled or interrupted Inertia visits', () => {
        renderHook(() => useFeedbackDiagnostics());
        mocks.record.mockClear();

        act(() => finish({ detail: { visit: { completed: false } } }));

        expect(mocks.record).not.toHaveBeenCalled();
    });

    it('records terminal same-origin HTTP errors without request contents', async () => {
        renderHook(() => useFeedbackDiagnostics());
        mocks.record.mockClear();
        const error = {
            isAxiosError: true,
            config: { method: 'post', url: '/resources?token=secret', data: { private: 'body' } },
            response: { status: 503, data: { private: 'response' } },
        };

        await expect(rejected(error)).rejects.toBe(error);

        expect(mocks.record).toHaveBeenCalledWith(17, {
            type: 'http_error',
            occurred_at: expect.any(String),
            method: 'POST',
            path: '/resources',
            status: 503,
            message: 'HTTP request failed (503)',
        });
        expect(JSON.stringify(mocks.record.mock.calls)).not.toContain('private');
    });

    it.each([
        ['a cancelled request', { isAxiosError: true, code: 'ERR_CANCELED', config: { url: '/resources' } }],
        ['the feedback request itself', { isAxiosError: true, config: { url: '/feedback', method: 'post' }, response: { status: 503 } }],
        ['a foreign-origin request', { isAxiosError: true, config: { url: 'https://external.example/data' }, response: { status: 500 } }],
        ['a non-Axios rejection', new Error('unrelated')],
    ])('ignores %s', async (_label, error) => {
        renderHook(() => useFeedbackDiagnostics());
        mocks.record.mockClear();

        await expect(rejected(error)).rejects.toBe(error);

        expect(mocks.record).not.toHaveBeenCalled();
    });

    it('records sanitized JavaScript errors and unhandled rejections without stack traces', () => {
        renderHook(() => useFeedbackDiagnostics());
        mocks.record.mockClear();
        mocks.sanitize.mockImplementation((value: string) => value.replace('jane@example.org', '[redacted-email]'));

        act(() => {
            window.dispatchEvent(new ErrorEvent('error', { error: new Error('Failed for jane@example.org'), message: 'fallback' }));
            const rejection = new Event('unhandledrejection') as PromiseRejectionEvent;
            Object.defineProperty(rejection, 'reason', { value: 'Rejected for jane@example.org' });
            window.dispatchEvent(rejection);
        });

        expect(mocks.record).toHaveBeenNthCalledWith(
            1,
            17,
            expect.objectContaining({ type: 'javascript_error', message: 'Error: Failed for [redacted-email]' }),
        );
        expect(mocks.record).toHaveBeenNthCalledWith(
            2,
            17,
            expect.objectContaining({ type: 'javascript_error', message: 'Rejected for [redacted-email]' }),
        );
        expect(JSON.stringify(mocks.record.mock.calls)).not.toContain('at ');
    });
});
