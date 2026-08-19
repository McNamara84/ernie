import { act, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import EditorLoadingPage from '@/pages/editor-loading';

const { axiosGetMock, axiosPostMock, routerVisitMock } = vi.hoisted(() => ({
    axiosGetMock: vi.fn(),
    axiosPostMock: vi.fn(),
    routerVisitMock: vi.fn(),
}));

vi.mock('axios', () => ({
    default: {
        get: axiosGetMock,
        post: axiosPostMock,
    },
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { visit: routerVisitMock },
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/routes', () => ({
    editor: () => ({ url: '/editor' }),
}));

const editorLoad = {
    token: '11111111-1111-4111-8111-111111111111',
    resourceId: 42,
    serverProgress: 0,
    slowThresholdMs: 12_000,
};

describe('editor-loading page', () => {
    beforeEach(() => {
        window.history.replaceState({}, '', '/editor?resourceId=42');
        axiosGetMock.mockReset();
        axiosPostMock.mockReset();
        routerVisitMock.mockReset();
        axiosGetMock.mockResolvedValue({
            data: { status: 'loading', stage: 'content_relations_loaded', progress: 25, error: null },
        });
        axiosPostMock.mockResolvedValue({});
    });

    it('starts the canonical Inertia reload with the progress token', async () => {
        render(<EditorLoadingPage editorLoad={editorLoad} />);

        expect(screen.getByTestId('editor-loading-modal')).toBeInTheDocument();
        expect(routerVisitMock).toHaveBeenCalledWith(
            '/editor?resourceId=42',
            expect.objectContaining({
                method: 'get',
                replace: true,
                showProgress: false,
                headers: { 'X-Editor-Load-Token': editorLoad.token },
            }),
        );

        await waitFor(() => expect(screen.getByTestId('editor-loading-percentage')).toHaveTextContent('25%'));
        expect(axiosGetMock).toHaveBeenCalledWith(`/editor/resource-loads/${editorLoad.token}/status`);
    });

    it('shows a backend failure instead of starting another request', () => {
        render(<EditorLoadingPage editorLoad={{ ...editorLoad, serverProgress: 55 }} loadError="Unable to load this resource." />);

        expect(screen.getByRole('alert')).toHaveTextContent('Unable to load this resource.');
        expect(routerVisitMock).not.toHaveBeenCalled();
        expect(axiosGetMock).not.toHaveBeenCalled();
    });

    it('shows an actionable error when the editor navigation loses its connection', async () => {
        render(<EditorLoadingPage editorLoad={editorLoad} />);

        const visitOptions = routerVisitMock.mock.calls[0]?.[1] as { onNetworkError?: () => boolean };

        await act(async () => {
            expect(visitOptions.onNetworkError?.()).toBe(false);
        });

        expect(screen.getByRole('alert')).toHaveTextContent('Check your connection and try again.');
        expect(screen.getByRole('button', { name: 'Try again' })).toBeInTheDocument();
    });
});
