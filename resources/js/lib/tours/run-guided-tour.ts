import { driver } from 'driver.js';

import { type GuidedTourDefinition } from '@/lib/tours/definitions';

type GuidedTourLifecycleHandlers = {
    definition: GuidedTourDefinition;
    onClose: () => void | Promise<void>;
    onComplete: () => void | Promise<void>;
};

function invokeLifecycleHandler(handler: () => void | Promise<void>): void {
    void Promise.resolve(handler()).catch(() => undefined);
}

export async function runGuidedTour({ definition, onClose, onComplete }: GuidedTourLifecycleHandlers): Promise<{ destroy: () => void }> {
    let exitState: 'idle' | 'closing' | 'completing' = 'idle';

    const driverInstance = driver({
        animate: true,
        allowClose: true,
        allowKeyboardControl: true,
        overlayOpacity: 0.42,
        stagePadding: 14,
        stageRadius: 18,
        showProgress: true,
        progressText: 'Step {{current}} of {{total}}',
        nextBtnText: 'Next',
        prevBtnText: 'Back',
        doneBtnText: 'Finish',
        showButtons: ['previous', 'next', 'close'],
        popoverClass: 'ernie-guided-tour',
        steps: definition.steps.map((step) => ({
            element: step.element,
            popover: {
                title: step.title,
                description: step.description,
                side: step.side,
                align: step.align,
            },
        })),
        onNextClick: (_element, _step, { state, driver: activeDriver }) => {
            const activeIndex = state.activeIndex ?? 0;

            if (activeIndex >= definition.steps.length - 1) {
                exitState = 'completing';
                invokeLifecycleHandler(onComplete);
                activeDriver.destroy();
                return;
            }

            activeDriver.moveNext();
        },
        onPrevClick: (_element, _step, { driver: activeDriver }) => {
            activeDriver.movePrevious();
        },
        onCloseClick: (_element, _step, { driver: activeDriver }) => {
            exitState = 'closing';
            invokeLifecycleHandler(onClose);
            activeDriver.destroy();
        },
        onDestroyed: () => {
            if (exitState === 'idle') {
                exitState = 'closing';
                invokeLifecycleHandler(onClose);
            }
        },
    });

    driverInstance.drive();

    return {
        destroy: () => driverInstance.destroy(),
    };
}