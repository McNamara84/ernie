import type { InputHTMLAttributes } from 'react';

import { Input } from '@/components/ui/input';

import { EditorField, type EditorFieldProps } from './editor-field';

type InputFieldProps = EditorFieldProps &
    InputHTMLAttributes<HTMLInputElement> & {
        inputClassName?: string;
        onValidationBlur?: () => void;
    };

export function InputField({
    id,
    label,
    hideLabel,
    type = 'text',
    className,
    required,
    containerProps,
    inputClassName,
    validationMessages,
    touched,
    showSuccessFeedback,
    helpText,
    labelTooltip,
    'aria-describedby': describedBy,
    'aria-invalid': invalid,
    onValidationBlur,
    onBlur,
    ...inputProps
}: InputFieldProps) {
    return (
        <EditorField
            id={id}
            label={label}
            hideLabel={hideLabel}
            required={required}
            className={className}
            containerProps={containerProps}
            validationMessages={validationMessages}
            touched={touched}
            showSuccessFeedback={showSuccessFeedback}
            helpText={helpText}
            labelTooltip={labelTooltip}
            aria-describedby={describedBy}
            aria-invalid={invalid}
        >
            {(attributes) => (
                <Input
                    id={id}
                    type={type}
                    required={required}
                    className={inputClassName}
                    {...attributes}
                    {...inputProps}
                    onBlur={(event) => {
                        onValidationBlur?.();
                        onBlur?.(event);
                    }}
                />
            )}
        </EditorField>
    );
}

export default InputField;
