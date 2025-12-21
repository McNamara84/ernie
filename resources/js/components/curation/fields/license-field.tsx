import { Minus, Plus } from 'lucide-react';

import { Button } from '@/components/ui/button';
import type { ValidationMessage } from '@/hooks/use-form-validation';
import { cn } from '@/lib/utils';

import { SelectField } from './select-field';

interface Option {
    value: string;
    label: string;
}

interface LicenseFieldProps {
    id: string;
    license: string;
    options: Option[];
    onLicenseChange: (value: string) => void;
    onAdd: () => void;
    onRemove: () => void;
    isFirst: boolean;
    canAdd?: boolean;
    className?: string;
    required?: boolean;
    validationMessages?: ValidationMessage[];
    touched?: boolean;
    onValidationBlur?: () => void;
    'data-testid'?: string;
}

export function LicenseField({
    id,
    license,
    options,
    onLicenseChange,
    onAdd,
    onRemove,
    isFirst,
    canAdd = true,
    className,
    required = false,
    validationMessages,
    touched,
    onValidationBlur,
    'data-testid': dataTestId,
}: LicenseFieldProps) {
    return (
        <div className={cn('grid gap-4 md:grid-cols-12', className)}>
            <SelectField
                id={`${id}-license`}
                label="License"
                value={license}
                onValueChange={onLicenseChange}
                onValidationBlur={onValidationBlur}
                validationMessages={validationMessages}
                touched={touched}
                options={options}
                hideLabel={!isFirst}
                className="md:col-span-11"
                required={required}
                data-testid={dataTestId}
            />
            <div className="flex items-end md:col-span-1">
                {isFirst ? (
                    canAdd ? (
                        <Button type="button" variant="outline" size="icon" aria-label="Add license" onClick={onAdd}>
                            <Plus className="h-4 w-4" />
                        </Button>
                    ) : null
                ) : (
                    <Button type="button" variant="outline" size="icon" aria-label="Remove license" onClick={onRemove}>
                        <Minus className="h-4 w-4" />
                    </Button>
                )}
            </div>
        </div>
    );
}

export default LicenseField;
