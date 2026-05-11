import { useEffect, useRef } from 'react';

import { buildCsrfHeaders } from '@/lib/csrf-token';
import { getGuidedTourDefinition, type GuidedTourAutostartPayload } from '@/lib/tours/definitions';
import { runGuidedTour } from '@/lib/tours/run-guided-tour';

interface GuidedTourAutostartProps {
    guidedTour: GuidedTourAutostartPayload | null | undefined;
}

async function postGuidedTourLifecycle(assignmentId: number, action: 'start' | 'close' | 'complete'): Promise<void> {
    const response = await fetch(`/guided-tours/assignments/${assignmentId}/${action}`, {
        method: 'POST',
        headers: buildCsrfHeaders(),
        credentials: 'same-origin',
    });

    if (!response.ok) {
        throw new Error(`Failed to ${action} guided tour assignment ${assignmentId}.`);
    }
}

export function GuidedTourAutostart({ guidedTour }: GuidedTourAutostartProps) {
    const startedAssignmentsRef = useRef<Set<number>>(new Set());

    useEffect(() => {
        if (!guidedTour?.autostart) {
            return;
        }

        if (startedAssignmentsRef.current.has(guidedTour.assignmentId)) {
            return;
        }

        const definition = getGuidedTourDefinition(guidedTour.key, guidedTour.version);
        if (definition === null) {
            return;
        }

        const availableSteps = definition.steps.filter((step) => document.querySelector(step.element) !== null);
        if (availableSteps.length === 0) {
            return;
        }

        startedAssignmentsRef.current.add(guidedTour.assignmentId);

        const resetStartedAssignment = () => {
            startedAssignmentsRef.current.delete(guidedTour.assignmentId);
        };

        const handleLifecycleAction = async (action: 'start' | 'close' | 'complete') => {
            try {
                await postGuidedTourLifecycle(guidedTour.assignmentId, action);
            } catch (error) {
                resetStartedAssignment();
                throw error;
            }
        };

        let isCancelled = false;
        let cleanupHandle: { destroy: () => void } | null = null;

        const startTour = async () => {
            try {
                await handleLifecycleAction('start');

                if (isCancelled) {
                    return;
                }

                cleanupHandle = await runGuidedTour({
                    definition: {
                        ...definition,
                        steps: availableSteps,
                    },
                    onClose: () => handleLifecycleAction('close'),
                    onComplete: () => handleLifecycleAction('complete'),
                });
            } catch {
                resetStartedAssignment();
            }
        };

        void startTour();

        return () => {
            isCancelled = true;
            cleanupHandle?.destroy();
        };
    }, [guidedTour]);

    return null;
}