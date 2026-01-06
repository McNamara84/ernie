import { AlertCircle, AlertTriangle, Info } from 'lucide-react';
import * as React from 'react';

import { cn } from '@/lib/utils';

type ValidationSeverity = 'error' | 'warning' | 'info';

interface ValidationAlertProps {
    /** Severity level determines styling and icon */
    severity: ValidationSeverity;
    /** Optional title displayed prominently */
    title?: string;
    /** List of validation messages to display */
    messages: string[];
    /** Additional CSS classes */
    className?: string;
    /** Test ID for Playwright tests */
    'data-testid'?: string;
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

/**
 * ValidationAlert Component
 *
 * Displays validation feedback with consistent styling based on severity.
 * Used throughout the editor to show errors, warnings, and informational messages.
 *
 * Severity levels:
 * - **error**: Red styling, for blocking validation issues
 * - **warning**: Amber/yellow styling, for non-blocking warnings
 * - **info**: Blue styling, for informational/recommendation messages
 *
 * @example
 * ```tsx
 * // Error example
 * <ValidationAlert
 *   severity="error"
 *   title="Required fields missing"
 *   messages={["Author last name is required", "Email is required for contact person"]}
 * />
 *
 * // Info example
 * <ValidationAlert
 *   severity="info"
 *   messages={["Consider adding MSL laboratories to improve discoverability."]}
 * />
 * ```
 */
export function ValidationAlert({ severity, title, messages, className, 'data-testid': dataTestId }: ValidationAlertProps) {
    const config = severityConfig[severity];

    // Don't render if no messages
    if (messages.length === 0) {
        return null;
    }

    return (
        <div
            className={cn('mb-4 rounded-md border p-3 text-sm', config.containerClass, className)}
            role={config.role}
            aria-live="polite"
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

export default ValidationAlert;
