import { type HTMLAttributes, type InputHTMLAttributes } from 'react';

import { FieldValidationFeedback } from '@/components/ui/field-validation-feedback';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import type { ValidationMessage } from '@/hooks/use-form-validation';
import { cn } from '@/lib/utils';

interface InputFieldProps extends InputHTMLAttributes<HTMLInputElement> {
    id: string;
    label: string;
    hideLabel?: boolean;
    className?: string;
    containerProps?: HTMLAttributes<HTMLDivElement> & { 'data-testid'?: string };
    inputClassName?: string;

    // Validation props
    validationMessages?: ValidationMessage[];
    touched?: boolean;
    onValidationBlur?: () => void;
    showSuccessFeedback?: boolean;
    helpText?: string;
    labelTooltip?: string;
}

export function InputField({
    id,
    label,
    hideLabel = false,
    type = 'text',
    className,
    required,
    containerProps,
    inputClassName,
    validationMessages = [],
    touched = false,
    onValidationBlur,
    showSuccessFeedback = true,
    helpText,
    labelTooltip,
    onBlur,
    ...props
}: InputFieldProps) {
    const labelId = `${id}-label`;
    const helpTextId = helpText ? `${id}-help` : undefined;
    const feedbackId = validationMessages.length > 0 ? `${id}-feedback` : undefined;

    const mergedClassName = cn(
        'flex flex-col gap-2',
        containerProps?.className,
        className,
    );

    // Determine if field has error
    const hasError = validationMessages.some((m) => m.severity === 'error');
    const isInvalid = hasError && touched;

    // Handle blur event
    const handleBlur = (e: React.FocusEvent<HTMLInputElement>) => {
        onValidationBlur?.();
        onBlur?.(e);
    };

    // Build aria-describedby
    const ariaDescribedBy = [helpTextId, feedbackId].filter(Boolean).join(' ') || undefined;

    // Only use aria-label when label is hidden; otherwise use aria-labelledby
    const ariaProps = hideLabel
        ? { 'aria-label': label }
        : { 'aria-labelledby': labelId };

    const labelContent = (
        <>
            {label}
            {required && (
                <span aria-hidden="true" className="text-destructive ml-1">
                    *
                </span>
            )}
        </>
    );

    return (
        <div {...containerProps} className={mergedClassName}>
            {labelTooltip ? (
                <Tooltip>
                    <TooltipTrigger asChild>
                        <Label
                            id={labelId}
                            htmlFor={id}
                            className={cn(
                                hideLabel ? 'sr-only' : 'cursor-help',
                            )}
                        >
                            {labelContent}
                        </Label>
                    </TooltipTrigger>
                    <TooltipContent>
                        <p>{labelTooltip}</p>
                    </TooltipContent>
                </Tooltip>
            ) : (
                <Label
                    id={labelId}
                    htmlFor={id}
                    className={hideLabel ? 'sr-only' : undefined}
                >
                    {labelContent}
                </Label>
            )}

            {helpText && (
                <p id={helpTextId} className="text-sm text-muted-foreground">
                    {helpText}
                </p>
            )}

            <Input
                id={id}
                type={type}
                required={required}
                className={inputClassName}
                aria-invalid={isInvalid}
                aria-describedby={ariaDescribedBy}
                onBlur={handleBlur}
                {...ariaProps}
                {...props}
            />

            {touched && validationMessages.length > 0 && (
                <FieldValidationFeedback
                    messages={validationMessages}
                    showSuccess={showSuccessFeedback}
                />
            )}
        </div>
    );
}

export default InputField;
