import { type HTMLAttributes } from 'react';

import { FieldValidationFeedback } from '@/components/ui/field-validation-feedback';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import type { ValidationMessage } from '@/hooks/use-form-validation';
import { cn } from '@/lib/utils';

interface Option {
    value: string;
    label: string;
}

interface SelectFieldProps {
    id: string;
    label: string;
    value: string;
    onValueChange: (value: string) => void;
    options: Option[];
    placeholder?: string;
    className?: string;
    hideLabel?: boolean;
    required?: boolean;
    containerProps?: HTMLAttributes<HTMLDivElement> & { 'data-testid'?: string };
    triggerClassName?: string;
    'data-testid'?: string;

    // Validation props
    validationMessages?: ValidationMessage[];
    touched?: boolean;
    onValidationBlur?: () => void;
    showSuccessFeedback?: boolean;
    helpText?: string;
    labelTooltip?: string;
}

export function SelectField({
    id,
    label,
    value,
    onValueChange,
    options,
    placeholder = 'Select',
    className,
    hideLabel = false,
    required = false,
    containerProps,
    triggerClassName,
    'data-testid': dataTestId,
    validationMessages = [],
    touched = false,
    onValidationBlur,
    showSuccessFeedback = true,
    helpText,
    labelTooltip,
}: SelectFieldProps) {
    const labelId = `${id}-label`;
    const helpTextId = helpText ? `${id}-help` : undefined;
    const feedbackId = validationMessages.length > 0 ? `${id}-feedback` : undefined;

    const mergedClassName = cn('flex flex-col gap-2', containerProps?.className, className);

    // Determine if field has error
    const hasError = validationMessages.some((m) => m.severity === 'error');
    const isInvalid = hasError && touched;

    // Build aria-describedby
    const ariaDescribedBy = [helpTextId, feedbackId].filter(Boolean).join(' ') || undefined;

    // Only use aria-label when label is hidden; otherwise use aria-labelledby
    const ariaProps = hideLabel ? { 'aria-label': label } : { 'aria-labelledby': labelId };

    // Handle value change with optional blur callback
    const handleValueChange = (newValue: string) => {
        onValueChange(newValue);
        // Call validation blur after value changes (select acts like blur)
        if (onValidationBlur) {
            // Small delay to ensure state updates
            setTimeout(() => {
                onValidationBlur();
            }, 0);
        }
    };

    const labelContent = (
        <>
            {label}
            {required && (
                <span aria-hidden="true" className="ml-1 text-destructive">
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
                        <Label id={labelId} className={cn(hideLabel ? 'sr-only' : 'cursor-help')}>
                            {labelContent}
                        </Label>
                    </TooltipTrigger>
                    <TooltipContent>
                        <p>{labelTooltip}</p>
                    </TooltipContent>
                </Tooltip>
            ) : (
                <Label id={labelId} className={hideLabel ? 'sr-only' : undefined}>
                    {labelContent}
                </Label>
            )}

            {helpText && (
                <p id={helpTextId} className="text-sm text-muted-foreground">
                    {helpText}
                </p>
            )}

            <Select value={value} onValueChange={handleValueChange} required={required}>
                <SelectTrigger
                    id={id}
                    aria-required={required || undefined}
                    aria-invalid={isInvalid}
                    aria-describedby={ariaDescribedBy}
                    className={triggerClassName}
                    data-testid={dataTestId}
                    {...ariaProps}
                >
                    <SelectValue placeholder={placeholder} />
                </SelectTrigger>
                <SelectContent>
                    {options.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            {touched && validationMessages.length > 0 && (
                <FieldValidationFeedback id={feedbackId} messages={validationMessages} showSuccess={showSuccessFeedback} />
            )}
        </div>
    );
}
