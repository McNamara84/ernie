import { Plus, Minus } from 'lucide-react';
import { SelectField } from './select-field';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

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
}: LicenseFieldProps) {
    return (
        <div className={cn('grid gap-4 md:grid-cols-12', className)}>
            <SelectField
                id={`${id}-license`}
                label="License"
                value={license}
                onValueChange={onLicenseChange}
                options={options}
                hideLabel={!isFirst}
                className="md:col-span-11"
                required={required}
            />
            <div className="flex items-end md:col-span-1">
                {isFirst ? (
                    canAdd ? (
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            aria-label="Add license"
                            onClick={onAdd}
                        >
                            <Plus className="h-4 w-4" />
                        </Button>
                    ) : null
                ) : (
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        aria-label="Remove license"
                        onClick={onRemove}
                    >
                        <Minus className="h-4 w-4" />
                    </Button>
                )}
            </div>
        </div>
    );
}

export default LicenseField;

