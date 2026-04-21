import { AlertCircle, ChevronRight } from 'lucide-react';
import * as React from 'react';

import { cn } from '@/lib/utils';

import { groupErrorsBySection, type MappedError } from './utils/error-field-mapper';

interface ClickableValidationAlertProps {
    errors: MappedError[];
    onErrorClick: (error: MappedError) => void;
    headerMessage?: string;
    className?: string;
    ref?: React.Ref<HTMLDivElement>;
    'data-testid'?: string;
    focusable?: boolean;
}

function ClickableValidationAlert({ errors, onErrorClick, headerMessage, className, ref, 'data-testid': dataTestId, focusable = false }: ClickableValidationAlertProps) {
    if (errors.length === 0) {
        return null;
    }

    const groupedErrors = groupErrorsBySection(errors);

    return (
        <div
            ref={ref}
            data-slot="clickable-validation-alert"
            className={cn('mb-4 rounded-md border border-destructive/50 bg-destructive/10 text-destructive text-sm', className)}
            role="alert"
            aria-live="assertive"
            tabIndex={focusable ? -1 : undefined}
            data-testid={dataTestId}
        >
            <div className="flex items-start gap-2 border-b border-destructive/20 p-3">
                <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                <strong className="font-semibold">
                    {headerMessage ?? 'Unable to save resource. Please review the highlighted issues.'}
                </strong>
            </div>
            <div className="space-y-1 p-3 pt-2">
                {Array.from(groupedErrors.entries()).map(([sectionId, group]) => (
                    <div key={sectionId} data-testid={`error-group-${sectionId}`}>
                        <p className="text-destructive/80 mt-1 mb-0.5 text-xs font-semibold uppercase tracking-wide">
                            {group.sectionName}
                            <span className="ml-1 font-normal">
                                ({group.errors.length} {group.errors.length === 1 ? 'issue' : 'issues'})
                            </span>
                        </p>
                        <ul className="space-y-0.5">
                            {group.errors.map((error, index) => (
                                <li key={`${error.backendKey}-${index}`}>
                                    <button
                                        type="button"
                                        onClick={() => onErrorClick(error)}
                                        className="group flex w-full items-center gap-1 rounded px-1.5 py-0.5 text-left text-sm transition-colors hover:bg-destructive/10 focus-visible:ring-destructive/50 focus-visible:outline-none focus-visible:ring-2"
                                        data-testid={`error-link-${error.backendKey}-${index}`}
                                    >
                                        <ChevronRight className="h-3 w-3 shrink-0 opacity-0 transition-opacity group-hover:opacity-100" aria-hidden="true" />
                                        <span className="group-hover:underline">{error.message}</span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </div>
                ))}
            </div>
        </div>
    );
}

export { ClickableValidationAlert };
export type { ClickableValidationAlertProps, MappedError };
