import { AlertCircle, AlertTriangle, CheckCircle, Info } from 'lucide-react';

import type { ValidationMessage, ValidationSeverity } from '@/hooks/use-form-validation';
import { cn } from '@/lib/utils';

interface FieldValidationFeedbackProps {
    messages: ValidationMessage[];
    className?: string;
    showSuccess?: boolean;
    compact?: boolean;
    id?: string;
}

/**
 * Configuration for the different validation severity levels
 */
const severityConfig: Record<
    ValidationSeverity,
    {
        icon: typeof AlertCircle;
        className: string;
        iconClassName: string;
        ariaLabel: string;
    }
> = {
    error: {
        icon: AlertCircle,
        className: 'text-destructive',
        iconClassName: 'text-destructive',
        ariaLabel: 'Error',
    },
    warning: {
        icon: AlertTriangle,
        className: 'text-amber-600 dark:text-amber-400',
        iconClassName: 'text-amber-600 dark:text-amber-400',
        ariaLabel: 'Warning',
    },
    success: {
        icon: CheckCircle,
        className: 'text-green-600 dark:text-green-400',
        iconClassName: 'text-green-600 dark:text-green-400',
        ariaLabel: 'Success',
    },
    info: {
        icon: Info,
        className: 'text-blue-600 dark:text-blue-400',
        iconClassName: 'text-blue-600 dark:text-blue-400',
        ariaLabel: 'Information',
    },
};

/**
 * Component for displaying validation feedback
 * 
 * Shows inline validation messages with icons for different
 * severity levels (error, warning, success, info).
 * 
 * @example
 * ```tsx
 * <FieldValidationFeedback
 *   messages={[
 *     { severity: 'error', message: 'This field is required' },
 *     { severity: 'info', message: 'Must be at least 8 characters' }
 *   ]}
 *   showSuccess={true}
 * />
 * ```
 */
export function FieldValidationFeedback({
    messages,
    className,
    showSuccess = true,
    compact = false,
    id,
}: FieldValidationFeedbackProps) {
    // Filter messages based on showSuccess
    const filteredMessages = showSuccess
        ? messages
        : messages.filter((m) => m.severity !== 'success');

    if (filteredMessages.length === 0) {
        return null;
    }

    // Sort messages by priority: error > warning > info > success
    const severityOrder: Record<ValidationSeverity, number> = {
        error: 0,
        warning: 1,
        info: 2,
        success: 3,
    };

    const sortedMessages = [...filteredMessages].sort(
        (a, b) => severityOrder[a.severity] - severityOrder[b.severity],
    );

    return (
        <div
            id={id}
            className={cn('space-y-1', className)}
            role="alert"
            aria-live="polite"
            aria-atomic="true"
        >
            {sortedMessages.map((msg, index) => {
                const config = severityConfig[msg.severity];
                const Icon = config.icon;

                return (
                    <div
                        key={`${msg.severity}-${index}`}
                        className={cn(
                            'flex items-start gap-2',
                            compact ? 'text-xs' : 'text-sm',
                            config.className,
                        )}
                        data-severity={msg.severity}
                        data-testid={`validation-message-${msg.severity}`}
                    >
                        <Icon
                            className={cn(
                                'flex-shrink-0',
                                compact ? 'h-3 w-3 mt-0.5' : 'h-4 w-4 mt-0.5',
                                config.iconClassName,
                            )}
                            aria-label={config.ariaLabel}
                        />
                        <p className="flex-1 leading-tight">{msg.message}</p>
                    </div>
                );
            })}
        </div>
    );
}

/**
 * Compact version of the feedback component for inline use
 */
export function CompactFieldValidationFeedback(
    props: Omit<FieldValidationFeedbackProps, 'compact'>,
) {
    return <FieldValidationFeedback {...props} compact={true} />;
}

/**
 * Helper function: Returns only the first message of a specific severity
 */
export function getFirstMessageBySeverity(
    messages: ValidationMessage[],
    severity: ValidationSeverity,
): ValidationMessage | undefined {
    return messages.find((msg) => msg.severity === severity);
}

/**
 * Helper function: Checks if messages contain a specific severity
 */
export function hasMessageWithSeverity(
    messages: ValidationMessage[],
    severity: ValidationSeverity,
): boolean {
    return messages.some((msg) => msg.severity === severity);
}

/**
 * Helper function: Filters messages by severity
 */
export function filterMessagesBySeverity(
    messages: ValidationMessage[],
    severities: ValidationSeverity[],
): ValidationMessage[] {
    return messages.filter((msg) => severities.includes(msg.severity));
}
