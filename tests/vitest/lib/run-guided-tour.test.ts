import { beforeEach, describe, expect, it, vi } from 'vitest';

import { runGuidedTour } from '@/lib/tours/run-guided-tour';

type CapturedDriverConfig = {
    steps: Array<{
        element: string;
        popover: {
            title: string;
            description: string;
            side?: 'top' | 'right' | 'bottom' | 'left';
            align?: 'start' | 'center' | 'end';
        };
    }>;
    showProgress: boolean;
    progressText: string;
    nextBtnText: string;
    prevBtnText: string;
    doneBtnText: string;
    popoverClass: string;
    onNextClick: (
        element: unknown,
        step: unknown,
        context: {
            state: { activeIndex?: number };
            driver: { destroy: () => void; moveNext: () => void; movePrevious: () => void };
        },
    ) => void;
    onPrevClick: (
        element: unknown,
        step: unknown,
        context: { driver: { destroy: () => void; moveNext: () => void; movePrevious: () => void } },
    ) => void;
    onCloseClick: (
        element: unknown,
        step: unknown,
        context: { driver: { destroy: () => void; moveNext: () => void; movePrevious: () => void } },
    ) => void;
    onDestroyed: () => void;
};

const driverState = vi.hoisted(() => ({
    driverMock: vi.fn(),
    driveMock: vi.fn(),
    destroyMock: vi.fn(),
    moveNextMock: vi.fn(),
    movePreviousMock: vi.fn(),
    config: null as CapturedDriverConfig | null,
}));

vi.mock('driver.js', () => ({
    driver: driverState.driverMock,
}));

describe('runGuidedTour', () => {
    const definition = {
        key: 'beginner-dashboard-main-menu',
        version: 1,
        steps: [
            {
                id: 'welcome',
                element: '[data-tour="dashboard-welcome"]',
                title: 'Welcome to ERNIE',
                description: 'Start here.',
                side: 'bottom' as const,
                align: 'start' as const,
            },
            {
                id: 'menu',
                element: '[data-tour="sidebar-root"]',
                title: 'Main Menu',
                description: 'Navigate from here.',
                side: 'right' as const,
                align: 'center' as const,
            },
        ],
    };

    beforeEach(() => {
        driverState.config = null;
        driverState.driverMock.mockReset();
        driverState.driveMock.mockReset();
        driverState.destroyMock.mockReset();
        driverState.moveNextMock.mockReset();
        driverState.movePreviousMock.mockReset();

        driverState.driverMock.mockImplementation((config: CapturedDriverConfig) => {
            driverState.config = config;

            return {
                drive: driverState.driveMock,
                destroy: driverState.destroyMock,
                moveNext: driverState.moveNextMock,
                movePrevious: driverState.movePreviousMock,
            };
        });
    });

    it('maps the guided tour definition into the driver configuration and starts the tour', async () => {
        const onClose = vi.fn();
        const onComplete = vi.fn();

        const cleanupHandle = await runGuidedTour({ definition, onClose, onComplete });

        expect(driverState.driverMock).toHaveBeenCalledTimes(1);
        expect(driverState.driveMock).toHaveBeenCalledTimes(1);
        expect(driverState.config).toMatchObject({
            showProgress: true,
            progressText: 'Step {{current}} of {{total}}',
            nextBtnText: 'Next',
            prevBtnText: 'Back',
            doneBtnText: 'Finish',
            popoverClass: 'ernie-guided-tour',
            steps: [
                {
                    element: '[data-tour="dashboard-welcome"]',
                    popover: {
                        title: 'Welcome to ERNIE',
                        description: 'Start here.',
                        side: 'bottom',
                        align: 'start',
                    },
                },
                {
                    element: '[data-tour="sidebar-root"]',
                    popover: {
                        title: 'Main Menu',
                        description: 'Navigate from here.',
                        side: 'right',
                        align: 'center',
                    },
                },
            ],
        });

        cleanupHandle.destroy();

        expect(driverState.destroyMock).toHaveBeenCalledTimes(1);
    });

    it('moves to the next step while intermediate steps remain', async () => {
        await runGuidedTour({
            definition,
            onClose: vi.fn(),
            onComplete: vi.fn(),
        });

        const activeDriver = {
            destroy: vi.fn(),
            moveNext: vi.fn(),
            movePrevious: vi.fn(),
        };

        driverState.config?.onNextClick(null, null, {
            state: { activeIndex: 0 },
            driver: activeDriver,
        });

        expect(activeDriver.moveNext).toHaveBeenCalledTimes(1);
    });

    it('completes the tour on the final step instead of moving forward', async () => {
        const onComplete = vi.fn();

        await runGuidedTour({
            definition,
            onClose: vi.fn(),
            onComplete,
        });

        const activeDriver = {
            destroy: vi.fn(),
            moveNext: vi.fn(),
            movePrevious: vi.fn(),
        };

        driverState.config?.onNextClick(null, null, {
            state: { activeIndex: 1 },
            driver: activeDriver,
        });

        expect(onComplete).toHaveBeenCalledTimes(1);
        expect(activeDriver.destroy).toHaveBeenCalledTimes(1);
        expect(activeDriver.moveNext).not.toHaveBeenCalled();
    });

    it('treats a missing active index as the first step', async () => {
        const onComplete = vi.fn();

        await runGuidedTour({
            definition: {
                ...definition,
                steps: [definition.steps[0]],
            },
            onClose: vi.fn(),
            onComplete,
        });

        const activeDriver = {
            destroy: vi.fn(),
            moveNext: vi.fn(),
            movePrevious: vi.fn(),
        };

        driverState.config?.onNextClick(null, null, {
            state: {},
            driver: activeDriver,
        });

        expect(onComplete).toHaveBeenCalledTimes(1);
        expect(activeDriver.destroy).toHaveBeenCalledTimes(1);
        expect(activeDriver.moveNext).not.toHaveBeenCalled();
    });

    it('moves backward and treats an explicit close as a close event only once', async () => {
        const onClose = vi.fn();

        await runGuidedTour({
            definition,
            onClose,
            onComplete: vi.fn(),
        });

        const activeDriver = {
            destroy: vi.fn(),
            moveNext: vi.fn(),
            movePrevious: vi.fn(),
        };

        driverState.config?.onPrevClick(null, null, { driver: activeDriver });
        driverState.config?.onCloseClick(null, null, { driver: activeDriver });
        driverState.config?.onDestroyed();

        expect(activeDriver.movePrevious).toHaveBeenCalledTimes(1);
        expect(activeDriver.destroy).toHaveBeenCalledTimes(1);
        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('treats passive destruction as a close action', async () => {
        const onClose = vi.fn();

        await runGuidedTour({
            definition,
            onClose,
            onComplete: vi.fn(),
        });

        driverState.config?.onDestroyed();

        expect(onClose).toHaveBeenCalledTimes(1);
    });
});