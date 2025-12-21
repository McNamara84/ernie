import { CheckCircle2 } from 'lucide-react';
import React, { type ReactNode } from 'react';

import { cn } from '@/lib/utils';

interface WorkflowStepProps {
    number: number;
    title: string;
    children: ReactNode;
    isLast?: boolean;
}

function WorkflowStep({ number, title, children, isLast = false }: WorkflowStepProps) {
    return (
        <div className="relative flex gap-4">
            {/* Step Number Circle */}
            <div className="relative flex shrink-0 flex-col items-center">
                <div className="flex size-10 items-center justify-center rounded-full bg-primary font-semibold text-primary-foreground">{number}</div>
                {!isLast && <div className="mt-2 h-full w-0.5 bg-border" />}
            </div>

            {/* Step Content */}
            <div className={cn('flex-1 space-y-2', !isLast && 'pb-8')}>
                <h3 className="text-lg font-semibold">{title}</h3>
                <div className="text-muted-foreground">{children}</div>
            </div>
        </div>
    );
}

interface WorkflowStepsProps {
    children: ReactNode;
}

export function WorkflowSteps({ children }: WorkflowStepsProps) {
    const steps = React.Children.toArray(children);

    return (
        <div className="my-6 rounded-lg border bg-card p-6">
            {steps.map((step, index) => {
                if (React.isValidElement(step) && step.type === WorkflowStep) {
                    return React.cloneElement(step as React.ReactElement<WorkflowStepProps>, {
                        key: index,
                        isLast: index === steps.length - 1,
                    });
                }
                return step;
            })}
        </div>
    );
}

WorkflowSteps.Step = WorkflowStep;

// Success indicator for completed workflows
interface WorkflowSuccessProps {
    children: ReactNode;
}

export function WorkflowSuccess({ children }: WorkflowSuccessProps) {
    return (
        <div className="my-4 flex items-start gap-3 rounded-lg border border-green-200 bg-green-50 p-4 dark:border-green-900 dark:bg-green-950">
            <CheckCircle2 className="size-5 shrink-0 text-green-600 dark:text-green-400" />
            <div className="text-sm text-green-900 dark:text-green-100">{children}</div>
        </div>
    );
}
