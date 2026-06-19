import { Minus, Plus } from 'lucide-react';

import { Button } from '@/components/ui/button';
import type { ValidationMessage } from '@/hooks/use-form-validation';
import { cn } from '@/lib/utils';

import type { CustomLicenseEntry, LicenseEntry } from '../types/datacite-form-types';
import InputField from './input-field';
import { SelectField } from './select-field';

interface Option {
    value: string;
    label: string;
}

interface LicenseFieldProps {
    id: string;
    entry: LicenseEntry;
    options: Option[];
    onModeChange: (mode: LicenseEntry['mode']) => void;
    onCatalogLicenseChange: (value: string) => void;
    onCustomLicenseChange: (field: keyof Pick<CustomLicenseEntry, 'name' | 'uri'>, value: string) => void;
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
    customNameTestId?: string;
    customUriTestId?: string;
}

export function LicenseField({
    id,
    entry,
    options,
    onModeChange,
    onCatalogLicenseChange,
    onCustomLicenseChange,
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
    customNameTestId,
    customUriTestId,
}: LicenseFieldProps) {
    const actionButton = isFirst ? (
        canAdd ? (
            <Button type="button" variant="outline" size="icon" aria-label="Add license" onClick={onAdd}>
                <Plus className="h-4 w-4" />
            </Button>
        ) : null
    ) : (
        <Button type="button" variant="outline" size="icon" aria-label="Remove license" onClick={onRemove}>
            <Minus className="h-4 w-4" />
        </Button>
    );

    return (
        <div className={cn('grid gap-4 md:grid-cols-[1fr_40px]', className)}>
            <div className="space-y-3">
                <div className="inline-flex rounded-md border bg-background p-1" aria-label="License entry type">
                    <Button
                        type="button"
                        variant={entry.mode === 'catalog' ? 'default' : 'ghost'}
                        size="sm"
                        className="h-8 px-3"
                        aria-pressed={entry.mode === 'catalog'}
                        onClick={() => onModeChange('catalog')}
                    >
                        Catalog
                    </Button>
                    <Button
                        type="button"
                        variant={entry.mode === 'custom' ? 'default' : 'ghost'}
                        size="sm"
                        className="h-8 px-3"
                        aria-pressed={entry.mode === 'custom'}
                        onClick={() => onModeChange('custom')}
                    >
                        Custom
                    </Button>
                </div>

                {entry.mode === 'catalog' ? (
                    <SelectField
                        id={`${id}-license`}
                        label="License"
                        value={entry.license}
                        onValueChange={onCatalogLicenseChange}
                        onValidationBlur={onValidationBlur}
                        validationMessages={validationMessages}
                        touched={touched}
                        options={options}
                        hideLabel={!isFirst}
                        required={required}
                        data-testid={dataTestId}
                    />
                ) : (
                    <div className="grid gap-3 md:grid-cols-2">
                        <InputField
                            id={`${id}-custom-name`}
                            label="License name"
                            value={entry.name}
                            onChange={(event) => onCustomLicenseChange('name', event.target.value)}
                            onValidationBlur={onValidationBlur}
                            validationMessages={validationMessages}
                            touched={touched}
                            required={required}
                            data-testid={customNameTestId}
                        />
                        <InputField
                            id={`${id}-custom-uri`}
                            label="License text URL"
                            type="url"
                            value={entry.uri}
                            onChange={(event) => onCustomLicenseChange('uri', event.target.value)}
                            onValidationBlur={onValidationBlur}
                            validationMessages={validationMessages}
                            touched={touched}
                            required={required}
                            data-testid={customUriTestId}
                        />
                    </div>
                )}
            </div>
            <div className="flex items-end">{actionButton}</div>
        </div>
    );
}

export default LicenseField;