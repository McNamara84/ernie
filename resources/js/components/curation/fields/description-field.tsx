import { useState } from 'react';

import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';

export type DescriptionType =
    | 'Abstract'
    | 'Methods'
    | 'SeriesInformation'
    | 'TableOfContents'
    | 'TechnicalInfo'
    | 'Other';

export interface DescriptionEntry {
    type: DescriptionType;
    value: string;
}

interface DescriptionFieldProps {
    descriptions: DescriptionEntry[];
    onChange: (descriptions: DescriptionEntry[]) => void;
}

const DESCRIPTION_TYPES: { 
    value: DescriptionType; 
    label: string; 
    placeholder: string;
    required?: boolean;
}[] = [
    {
        value: 'Abstract',
        label: 'Abstract',
        placeholder: 'Enter a brief summary of the resource...',
        required: true,
    },
    {
        value: 'Methods',
        label: 'Methods',
        placeholder: 'Describe the methods used to create or collect this resource...',
    },
    {
        value: 'SeriesInformation',
        label: 'Series Information',
        placeholder: 'Provide information about the series this resource belongs to...',
    },
    {
        value: 'TableOfContents',
        label: 'Table of Contents',
        placeholder: 'Enter the table of contents...',
    },
    {
        value: 'TechnicalInfo',
        label: 'Technical Info',
        placeholder: 'Provide technical details about the resource...',
    },
    {
        value: 'Other',
        label: 'Other',
        placeholder: 'Enter other relevant description information...',
    },
];

export default function DescriptionField({ descriptions, onChange }: DescriptionFieldProps) {
    const [activeTab, setActiveTab] = useState<DescriptionType>('Abstract');

    const getDescriptionValue = (type: DescriptionType): string => {
        const description = descriptions.find((d) => d.type === type);
        return description?.value || '';
    };

    const handleDescriptionChange = (type: DescriptionType, value: string) => {
        const existingIndex = descriptions.findIndex((d) => d.type === type);

        if (existingIndex >= 0) {
            // Update existing description
            const updated = [...descriptions];
            updated[existingIndex] = { type, value };
            onChange(updated);
        } else {
            // Add new description
            onChange([...descriptions, { type, value }]);
        }
    };

    const getCharacterCount = (type: DescriptionType): number => {
        return getDescriptionValue(type).length;
    };

    const hasContent = (type: DescriptionType): boolean => {
        return getDescriptionValue(type).trim().length > 0;
    };

    return (
        <div className="space-y-4">
            <Tabs value={activeTab} onValueChange={(value) => setActiveTab(value as DescriptionType)}>
                <TabsList className="grid w-full grid-cols-6">
                    {DESCRIPTION_TYPES.map((desc) => (
                        <TabsTrigger
                            key={desc.value}
                            value={desc.value}
                            className="relative"
                        >
                            {desc.label}
                            {desc.required && (
                                <span className="ml-0.5 text-destructive" aria-label="Required">
                                    *
                                </span>
                            )}
                            {hasContent(desc.value) && (
                                <span
                                    className="ml-1 inline-block h-2 w-2 rounded-full bg-green-500"
                                    aria-label="Has content"
                                    title="This description has content"
                                />
                            )}
                        </TabsTrigger>
                    ))}
                </TabsList>

                {DESCRIPTION_TYPES.map((desc) => (
                    <TabsContent key={desc.value} value={desc.value} className="space-y-2">
                        <div className="space-y-2">
                            <Label htmlFor={`description-${desc.value}`}>
                                {desc.label}
                                {desc.required ? (
                                    <span className="ml-2 text-sm font-normal text-destructive">
                                        (Required)
                                    </span>
                                ) : (
                                    <span className="ml-2 text-sm font-normal text-muted-foreground">
                                        (Optional)
                                    </span>
                                )}
                            </Label>
                            <Textarea
                                id={`description-${desc.value}`}
                                value={getDescriptionValue(desc.value)}
                                onChange={(e) =>
                                    handleDescriptionChange(desc.value, e.target.value)
                                }
                                placeholder={desc.placeholder}
                                rows={8}
                                className="resize-y"
                                aria-describedby={`description-${desc.value}-count`}
                                required={desc.required}
                            />
                            <div
                                id={`description-${desc.value}-count`}
                                className="text-right text-sm text-muted-foreground"
                            >
                                {getCharacterCount(desc.value)} characters
                            </div>
                        </div>
                    </TabsContent>
                ))}
            </Tabs>
        </div>
    );
}
