import '@testing-library/jest-dom/vitest';

import { render, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { GuidedTourAutostart } from '@/components/tours/guided-tour-autostart';

const { runGuidedTourMock } = vi.hoisted(() => ({
    runGuidedTourMock: vi.fn(),
}));

vi.mock('@/lib/tours/run-guided-tour', () => ({
    runGuidedTour: runGuidedTourMock,
}));

vi.mock('@/lib/csrf-token', () => ({
    buildCsrfHeaders: () => ({ 'X-CSRF-TOKEN': 'test-token' }),
}));

describe('GuidedTourAutostart', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.spyOn(global, 'fetch').mockResolvedValue({ ok: true } as Response);
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
            expect(fetch).toHaveBeenNthCalledWith(
                1,
                '/guided-tours/assignments/42/start',
                expect.objectContaining({ method: 'POST' }),
            );
        });

        await waitFor(() => {
            expect(runGuidedTourMock).toHaveBeenCalledTimes(1);
        });

        await waitFor(() => {
            expect(fetch).toHaveBeenNthCalledWith(
                2,
                '/guided-tours/assignments/42/complete',
                expect.objectContaining({ method: 'POST' }),
            );
        });
    });

    it('does not run when no guided tour payload is present', () => {
        render(<GuidedTourAutostart guidedTour={null} />);

        expect(runGuidedTourMock).not.toHaveBeenCalled();
        expect(fetch).not.toHaveBeenCalled();
    });
});