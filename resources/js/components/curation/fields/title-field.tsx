import { Minus, Plus } from 'lucide-react';

import { Button } from '@/components/ui/button';
import type { ValidationMessage } from '@/hooks/use-form-validation';
import { cn } from '@/lib/utils';

import InputField from './input-field';
import { SelectField } from './select-field';

interface Option {
    value: string;
    label: string;
}

interface TitleFieldProps {
    id: string;
    title: string;
    titleType: string;
    options: Option[];
    onTitleChange: (value: string) => void;
    onTypeChange: (value: string) => void;
    onAdd: () => void;
    onRemove: () => void;
    isFirst: boolean;
    canAdd?: boolean;
    className?: string;
    validationMessages?: ValidationMessage[];
    touched?: boolean;
    onValidationBlur?: () => void;
    'data-testid'?: string;
}

export function TitleField({
    id,
    title,
    titleType,
    options,
    onTitleChange,
    onTypeChange,
    onAdd,
    onRemove,
    isFirst,
    canAdd = true,
    className,
    validationMessages,
    touched,
    onValidationBlur,
    'data-testid': dataTestId,
}: TitleFieldProps) {
    // Determine if this is the main title for adding test-id
    const isMainTitle = titleType === 'main-title';
    
    return (
        <div className={cn('grid gap-4 md:grid-cols-12', className)}>
            <InputField
                id={`${id}-title`}
                label="Title"
                value={title}
                onChange={(e) => onTitleChange(e.target.value)}
                onValidationBlur={onValidationBlur}
                validationMessages={validationMessages}
                touched={touched}
                hideLabel={!isFirst}
                className="md:col-span-8"
                required={titleType === 'main-title'}
                labelTooltip="Enter a title between 1 and 325 characters"
                data-testid={isMainTitle ? 'main-title-input' : dataTestId}
            />
            <SelectField
                id={`${id}-titleType`}
                label="Title Type"
                value={titleType}
                onValueChange={onTypeChange}
                options={options}
                hideLabel={!isFirst}
                className="md:col-span-3"
                required
            />
            <div className="flex items-end md:col-span-1">
                {isFirst ? (
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        aria-label="Add title"
                        onClick={onAdd}
                        disabled={!canAdd}
                    >
                        <Plus className="h-4 w-4" />
                    </Button>
                ) : (
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        aria-label="Remove title"
                        onClick={onRemove}
                    >
                        <Minus className="h-4 w-4" />
                    </Button>
                )}
            </div>
        </div>
    );
}

export default TitleField;
