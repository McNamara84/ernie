import { Plus, Minus } from 'lucide-react';
import InputField from './input-field';
import { SelectField } from './select-field';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

interface Option {
    value: string;
    label: string;
}

interface TitleFieldProps {
    index: number;
    title: string;
    titleType: string;
    options: Option[];
    onTitleChange: (value: string) => void;
    onTypeChange: (value: string) => void;
    onAdd: () => void;
    onRemove: () => void;
    isFirst: boolean;
    className?: string;
}

export function TitleField({
    index,
    title,
    titleType,
    options,
    onTitleChange,
    onTypeChange,
    onAdd,
    onRemove,
    isFirst,
    className,
}: TitleFieldProps) {
    return (
        <div className={cn('grid gap-4 md:grid-cols-12', className)}>
            <InputField
                id={`title-${index}`}
                label="Title"
                value={title}
                onChange={(e) => onTitleChange(e.target.value)}
                className="md:col-span-6"
            />
            <SelectField
                id={`titleType-${index}`}
                label="Title Type"
                value={titleType}
                onValueChange={onTypeChange}
                options={options}
                className="md:col-span-5"
            />
            <div className="flex items-end md:col-span-1">
                {isFirst ? (
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        aria-label="Add title"
                        onClick={onAdd}
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
