import '@testing-library/jest-dom/vitest';

import { act, render, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { GuidedTourAutostart } from '@/components/tours/guided-tour-autostart';

const { apiRequestMock, runGuidedTourMock } = vi.hoisted(() => ({
    apiRequestMock: vi.fn(),
    runGuidedTourMock: vi.fn(),
}));

vi.mock('@/lib/api-client', () => ({
    apiRequest: apiRequestMock,
}));

vi.mock('@/lib/tours/run-guided-tour', () => ({
    runGuidedTour: runGuidedTourMock,
}));

describe('GuidedTourAutostart', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        document.body.innerHTML = '';
        apiRequestMock.mockResolvedValue(null);
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('starts and completes the guided tour lifecycle when a pending tour is provided', async () => {
        document.body.innerHTML = `
            <div data-tour="dashboard-welcome"></div>
            <div data-tour="sidebar-root"></div>
            <div data-tour="dashboard-upload"></div>
            <div data-tour="sidebar-data-editor"></div>
            <div data-tour="sidebar-resources"></div>
            <div data-tour="sidebar-igsns-list"></div>
            <div data-tour="sidebar-igsns-map"></div>
            <div data-tour="sidebar-documentation"></div>
        `;

        runGuidedTourMock.mockImplementation(async ({ onComplete }) => {
            await onComplete();
            return { destroy: vi.fn() };
        });

        render(
            <GuidedTourAutostart
                guidedTour={{
                    assignmentId: 42,
                    key: 'beginner-dashboard-main-menu',
                    version: 1,
                    startRoute: 'dashboard',
                    status: 'pending',
                    autostart: true,
                }}
            />,
        );

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenNthCalledWith(
                1,
                '/guided-tours/assignments/42/start',
                expect.objectContaining({ method: 'POST', credentials: 'same-origin' }),
            );
        });

        await waitFor(() => {
            expect(runGuidedTourMock).toHaveBeenCalledTimes(1);
        });

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenNthCalledWith(
                2,
                '/guided-tours/assignments/42/complete',
                expect.objectContaining({ method: 'POST', credentials: 'same-origin' }),
            );
        });
    });

    it('posts the close lifecycle when the tour is dismissed', async () => {
        document.body.innerHTML = `
            <div data-tour="dashboard-welcome"></div>
            <div data-tour="sidebar-root"></div>
        `;

        runGuidedTourMock.mockImplementation(async ({ onClose }) => {
            await onClose();
            return { destroy: vi.fn() };
        });

        render(
            <GuidedTourAutostart
                guidedTour={{
                    assignmentId: 43,
                    key: 'beginner-dashboard-main-menu',
                    version: 1,
                    startRoute: 'dashboard',
                    status: 'pending',
                    autostart: true,
                }}
            />,
        );

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenNthCalledWith(
                1,
                '/guided-tours/assignments/43/start',
                expect.objectContaining({ method: 'POST', credentials: 'same-origin' }),
            );
        });

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenNthCalledWith(
                2,
                '/guided-tours/assignments/43/close',
                expect.objectContaining({ method: 'POST', credentials: 'same-origin' }),
            );
        });
    });

    it('does not run when no guided tour payload is present', () => {
        render(<GuidedTourAutostart guidedTour={null} />);

        expect(runGuidedTourMock).not.toHaveBeenCalled();
        expect(apiRequestMock).not.toHaveBeenCalled();
    });

    it('does not run when autostart is disabled', () => {
        render(
            <GuidedTourAutostart
                guidedTour={{
                    assignmentId: 42,
                    key: 'beginner-dashboard-main-menu',
                    version: 1,
                    startRoute: 'dashboard',
                    status: 'pending',
                    autostart: false,
                }}
            />,
        );

        expect(runGuidedTourMock).not.toHaveBeenCalled();
        expect(apiRequestMock).not.toHaveBeenCalled();
    });

    it('does not run when the guided tour definition is missing', () => {
        render(
            <GuidedTourAutostart
                guidedTour={{
                    assignmentId: 7,
                    key: 'unknown-tour',
                    version: 99,
                    startRoute: 'dashboard',
                    status: 'pending',
                    autostart: true,
                }}
            />,
        );

        expect(runGuidedTourMock).not.toHaveBeenCalled();
        expect(apiRequestMock).not.toHaveBeenCalled();
    });

    it('does not run when no configured step is present in the DOM', () => {
        render(
            <GuidedTourAutostart
                guidedTour={{
                    assignmentId: 9,
                    key: 'beginner-dashboard-main-menu',
                    version: 1,
                    startRoute: 'dashboard',
                    status: 'pending',
                    autostart: true,
                }}
            />,
        );

        expect(runGuidedTourMock).not.toHaveBeenCalled();
        expect(apiRequestMock).not.toHaveBeenCalled();
    });

    it('filters out tour steps that are not mounted in the DOM', async () => {
        document.body.innerHTML = `
            <div data-tour="dashboard-welcome"></div>
            <div data-tour="sidebar-root"></div>
        `;

        runGuidedTourMock.mockResolvedValue({ destroy: vi.fn() });

        render(
            <GuidedTourAutostart
                guidedTour={{
                    assignmentId: 11,
                    key: 'beginner-dashboard-main-menu',
                    version: 1,
                    startRoute: 'dashboard',
                    status: 'pending',
                    autostart: true,
                }}
            />,
        );

        await waitFor(() => {
            expect(runGuidedTourMock).toHaveBeenCalledTimes(1);
        });

        expect(runGuidedTourMock).toHaveBeenCalledWith(
            expect.objectContaining({
                definition: expect.objectContaining({
                    steps: [
                        expect.objectContaining({ id: 'dashboard-welcome' }),
                        expect.objectContaining({ id: 'sidebar-root' }),
                    ],
                }),
            }),
        );
    });

    it('does not restart the same assignment when the payload rerenders', async () => {
        document.body.innerHTML = `
            <div data-tour="dashboard-welcome"></div>
            <div data-tour="sidebar-root"></div>
        `;

        runGuidedTourMock.mockResolvedValue({ destroy: vi.fn() });

        const guidedTour = {
            assignmentId: 15,
            key: 'beginner-dashboard-main-menu',
            version: 1,
            startRoute: 'dashboard',
            status: 'pending',
            autostart: true,
        };

        const { rerender } = render(<GuidedTourAutostart guidedTour={guidedTour} />);

        await waitFor(() => {
            expect(runGuidedTourMock).toHaveBeenCalledTimes(1);
        });

        rerender(<GuidedTourAutostart guidedTour={{ ...guidedTour }} />);

        expect(runGuidedTourMock).toHaveBeenCalledTimes(1);
    });

    it('destroys the active tour when the component unmounts', async () => {
        document.body.innerHTML = `
            <div data-tour="dashboard-welcome"></div>
            <div data-tour="sidebar-root"></div>
        `;

        const destroy = vi.fn();
        runGuidedTourMock.mockResolvedValue({ destroy });

        const { unmount } = render(
            <GuidedTourAutostart
                guidedTour={{
                    assignmentId: 21,
                    key: 'beginner-dashboard-main-menu',
                    version: 1,
                    startRoute: 'dashboard',
                    status: 'pending',
                    autostart: true,
                }}
            />,
        );

        await waitFor(() => {
            expect(runGuidedTourMock).toHaveBeenCalledTimes(1);
        });

        unmount();

        expect(destroy).toHaveBeenCalledTimes(1);
    });

    it('does not launch the tour if the component unmounts before the start request resolves', async () => {
        document.body.innerHTML = `
            <div data-tour="dashboard-welcome"></div>
            <div data-tour="sidebar-root"></div>
        `;

        let resolveFetch: ((value: Response) => void) | undefined;
        const startRequest = new Promise<null>((resolve) => {
            resolveFetch = resolve;
        });

        apiRequestMock.mockReturnValueOnce(startRequest);

        const { unmount } = render(
            <GuidedTourAutostart
                guidedTour={{
                    assignmentId: 31,
                    key: 'beginner-dashboard-main-menu',
                    version: 1,
                    startRoute: 'dashboard',
                    status: 'pending',
                    autostart: true,
                }}
            />,
        );

        unmount();

        await act(async () => {
            resolveFetch?.(null);
            await startRequest;
        });

        expect(runGuidedTourMock).not.toHaveBeenCalled();
    });

    it('retries the same assignment after a failed start request', async () => {
        document.body.innerHTML = `
            <div data-tour="dashboard-welcome"></div>
            <div data-tour="sidebar-root"></div>
        `;

        apiRequestMock
            .mockRejectedValueOnce(new Error('network failed'))
            .mockResolvedValue(null);

        runGuidedTourMock.mockResolvedValue({ destroy: vi.fn() });

        const guidedTour = {
            assignmentId: 51,
            key: 'beginner-dashboard-main-menu',
            version: 1,
            startRoute: 'dashboard',
            status: 'pending',
            autostart: true,
        };

        const { rerender } = render(<GuidedTourAutostart guidedTour={guidedTour} />);

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledTimes(1);
        });

        expect(runGuidedTourMock).not.toHaveBeenCalled();

        rerender(<GuidedTourAutostart guidedTour={{ ...guidedTour }} />);

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledTimes(2);
            expect(runGuidedTourMock).toHaveBeenCalledTimes(1);
        });
    });

    it('retries the same assignment after a non-ok start response', async () => {
        document.body.innerHTML = `
            <div data-tour="dashboard-welcome"></div>
            <div data-tour="sidebar-root"></div>
        `;

        apiRequestMock
            .mockRejectedValueOnce(new Error('unauthenticated'))
            .mockResolvedValue(null);

        runGuidedTourMock.mockResolvedValue({ destroy: vi.fn() });

        const guidedTour = {
            assignmentId: 61,
            key: 'beginner-dashboard-main-menu',
            version: 1,
            startRoute: 'dashboard',
            status: 'pending',
            autostart: true,
        };

        const { rerender } = render(<GuidedTourAutostart guidedTour={guidedTour} />);

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledTimes(1);
        });

        expect(runGuidedTourMock).not.toHaveBeenCalled();

        rerender(<GuidedTourAutostart guidedTour={{ ...guidedTour }} />);

        await waitFor(() => {
            expect(apiRequestMock).toHaveBeenCalledTimes(2);
            expect(runGuidedTourMock).toHaveBeenCalledTimes(1);
        });
    });
});