import { AlertCircle, AlertTriangle, Info } from 'lucide-react';
import * as React from 'react';

import { cn } from '@/lib/utils';

type ValidationSeverity = 'error' | 'warning' | 'info';

interface ValidationAlertProps {
    severity: ValidationSeverity;
    title?: string;
    messages: string[];
    className?: string;
    ref?: React.Ref<HTMLDivElement>;
    'data-testid'?: string;
    assertive?: boolean;
    focusable?: boolean;
}

const severityConfig: Record<
    ValidationSeverity,
    {
        containerClass: string;
        icon: React.ReactNode;
        role: 'alert' | 'status';
    }
> = {
    error: {
        containerClass: 'border-destructive/50 bg-destructive/10 text-destructive',
        icon: <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />,
        role: 'alert',
    },
    warning: {
        containerClass: 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-100',
        icon: <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />,
        role: 'alert',
    },
    info: {
        containerClass: 'border-blue-200 bg-blue-50 text-blue-900 dark:border-blue-800 dark:bg-blue-900/20 dark:text-blue-100',
        icon: <Info className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />,
        role: 'status',
    },
};

function ValidationAlert({
    severity,
    title,
    messages,
    className,
    ref,
    'data-testid': dataTestId,
    assertive = false,
    focusable = false,
}: ValidationAlertProps) {
    const config = severityConfig[severity];

    if (messages.length === 0) {
        return null;
    }

    return (
        <div
            ref={ref}
            data-slot="validation-alert"
            className={cn('mb-4 rounded-md border p-3 text-sm', config.containerClass, className)}
            role={config.role}
            aria-live={assertive ? 'assertive' : 'polite'}
            tabIndex={focusable ? -1 : undefined}
            data-testid={dataTestId}
        >
            <div className="flex items-start gap-2">
                {config.icon}
                <div className="flex-1">
                    {title && <strong className="font-semibold">{title}</strong>}
                    {messages.length === 1 && !title ? (
                        <p>{messages[0]}</p>
                    ) : messages.length === 1 && title ? (
                        <p className="mt-1">{messages[0]}</p>
                    ) : (
                        <ul className={cn('list-disc space-y-1 pl-5', title ? 'mt-2' : '')}>
                            {messages.map((message, index) => (
                                <li key={index}>{message}</li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </div>
    );
}

export { ValidationAlert };
export default ValidationAlert;
