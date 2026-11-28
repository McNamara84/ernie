import type { AriaAttributes, HTMLAttributes, ReactNode } from 'react';

import { Field, FieldDescription, FieldLabel } from '@/components/ui/field';
import { FieldValidationFeedback, hasMessageWithSeverity, visibleValidationMessages } from '@/components/ui/field-validation-feedback';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import type { ValidationMessage } from '@/hooks/use-form-validation';
import { cn } from '@/lib/utils';

export interface EditorFieldProps {
    id: string;
    label: string;
    hideLabel?: boolean;
    required?: boolean;
    className?: string;
    containerProps?: HTMLAttributes<HTMLDivElement> & { 'data-testid'?: string };
    helpText?: string;
    labelTooltip?: string;
    validationMessages?: ValidationMessage[];
    touched?: boolean;
    showSuccessFeedback?: boolean;
    'aria-describedby'?: string;
}

type ControlAttributes = Pick<AriaAttributes, 'aria-label' | 'aria-labelledby' | 'aria-describedby' | 'aria-invalid'>;

export function EditorField({
    id,
    label,
    hideLabel,
    required,
    className,
    containerProps,
    helpText,
    labelTooltip,
    validationMessages = [],
    touched,
    showSuccessFeedback = true,
    'aria-describedby': describedBy,
    'aria-invalid': callerInvalid,
    children,
}: EditorFieldProps & {
    'aria-invalid'?: AriaAttributes['aria-invalid'];
    children: (attributes: ControlAttributes) => ReactNode;
}) {
    const labelId = `${id}-label`;
    const helpId = `${id}-help`;
    const feedbackId = `${id}-feedback`;
    const messages = touched ? visibleValidationMessages(validationMessages, showSuccessFeedback) : [];
    const invalid = callerInvalid ?? hasMessageWithSeverity(messages, 'error');
    const descriptionIds = new Set(describedBy?.split(/\s+/).filter(Boolean));
    if (helpText) descriptionIds.add(helpId);
    if (messages.length) descriptionIds.add(feedbackId);

    const fieldLabel = (
        <FieldLabel id={labelId} htmlFor={id} className={cn(hideLabel && 'sr-only', labelTooltip && 'cursor-help')}>
            {label}
            {required && (
                <span aria-hidden="true" className="ml-1 font-bold text-destructive">
                    *
                </span>
            )}
        </FieldLabel>
    );

    return (
        <Field
            {...containerProps}
            data-invalid={invalid !== false && invalid !== 'false'}
            className={cn('gap-2', containerProps?.className, className)}
        >
            {labelTooltip ? (
                <Tooltip>
                    <TooltipTrigger asChild>{fieldLabel}</TooltipTrigger>
                    <TooltipContent>{labelTooltip}</TooltipContent>
                </Tooltip>
            ) : (
                fieldLabel
            )}
            {helpText && <FieldDescription id={helpId}>{helpText}</FieldDescription>}
            {children({
                ...(hideLabel ? { 'aria-label': label } : { 'aria-labelledby': labelId }),
                'aria-describedby': [...descriptionIds].join(' ') || undefined,
                'aria-invalid': invalid,
            })}
            {messages.length > 0 && <FieldValidationFeedback id={feedbackId} messages={messages} />}
        </Field>
    );
}
