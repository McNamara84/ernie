import { useCallback, useMemo, useState } from 'react';

import { FieldValidationFeedback } from '@/components/ui/field-validation-feedback';
import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import type { ValidationMessage } from '@/hooks/use-form-validation';

export type DescriptionType = 'Abstract' | 'Methods' | 'SeriesInformation' | 'TableOfContents' | 'TechnicalInfo' | 'Other';

export interface DescriptionEntry {
    type: DescriptionType;
    value: string;
}

interface DescriptionFieldProps {
    descriptions: DescriptionEntry[];
    onChange: (descriptions: DescriptionEntry[]) => void;
    // Validation props for Abstract field
    abstractValidationMessages?: ValidationMessage[];
    abstractTouched?: boolean;
    onAbstractValidationBlur?: () => void;
}

const DESCRIPTION_TYPES: {
    value: DescriptionType;
    label: string;
    placeholder: string;
    required?: boolean;
    helpText?: string;
}[] = [
    {
        value: 'Abstract',
        label: 'Abstract',
        placeholder: 'Enter a brief summary of the resource...',
        required: true,
        helpText:
            'A brief description of the resource and the context in which the resource was created. Use "<br>" to indicate a line break for improved rendering of multiple paragraphs, but otherwise no HTML markup.',
    },
    {
        value: 'Methods',
        label: 'Methods',
        placeholder: 'Describe the methods used to create or collect this resource...',
        helpText:
            'The methodology employed for the study or research. Recommended for discovery. Full documentation about methods supports open science.',
    },
    {
        value: 'SeriesInformation',
        label: 'Series Information',
        placeholder: 'Provide information about the series this resource belongs to...',
        helpText:
            'Information about a repeating series, such as volume, issue, number. Note: This information should now be explicitly provided using the RelatedItem property with relationType "IsPublishedIn".',
    },
    {
        value: 'TableOfContents',
        label: 'Table of Contents',
        placeholder: 'Enter the table of contents...',
        helpText:
            'A listing of the Table of Contents. Use "<br>" to indicate a line break for improved rendering of multiple paragraphs, but otherwise no HTML markup.',
    },
    {
        value: 'TechnicalInfo',
        label: 'Technical Info',
        placeholder: 'Provide technical details about the resource...',
        helpText:
            'Detailed information that may be associated with design, implementation, operation, use, and/or maintenance of a process, system, or instrument. For software, this may include readme contents and environmental information.',
    },
    {
        value: 'Other',
        label: 'Other',
        placeholder: 'Enter other relevant description information...',
        helpText: 'Other description information that does not fit into an existing category.',
    },
];

export default function DescriptionField({
    descriptions,
    onChange,
    abstractValidationMessages = [],
    abstractTouched = false,
    onAbstractValidationBlur,
}: DescriptionFieldProps) {
    const [activeTab, setActiveTab] = useState<DescriptionType>('Abstract');

    // Memoize description values map to avoid repeated find() calls
    const descriptionValuesMap = useMemo(() => {
        const map = new Map<DescriptionType, string>();
        for (const d of descriptions) {
            map.set(d.type, d.value);
        }
        return map;
    }, [descriptions]);

    const getDescriptionValue = useCallback(
        (type: DescriptionType): string => {
            return descriptionValuesMap.get(type) || '';
        },
        [descriptionValuesMap],
    );

    const handleDescriptionChange = useCallback(
        (type: DescriptionType, value: string) => {
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
        },
        [descriptions, onChange],
    );

    // Memoize content checks to avoid recalculating on every render
    // This depends on descriptionValuesMap intentionally - both memos share the same
    // dependency (descriptions), but this separation allows getDescriptionValue to
    // also use descriptionValuesMap without duplicating the Map creation logic
    const contentStatus = useMemo(() => {
        const status: Record<DescriptionType, { hasContent: boolean; charCount: number }> = {} as Record<
            DescriptionType,
            { hasContent: boolean; charCount: number }
        >;
        for (const type of DESCRIPTION_TYPES) {
            const value = descriptionValuesMap.get(type.value) || '';
            status[type.value] = {
                hasContent: value.trim().length > 0,
                charCount: value.length,
            };
        }
        return status;
    }, [descriptionValuesMap]);

    return (
        <div className="space-y-4">
            <Tabs value={activeTab} onValueChange={(value) => setActiveTab(value as DescriptionType)}>
                <TabsList className="grid w-full grid-cols-6">
                    {DESCRIPTION_TYPES.map((desc) => (
                        <TabsTrigger key={desc.value} value={desc.value} className="relative">
                            {desc.label}
                            {desc.required && (
                                <span className="ml-0.5 text-destructive" aria-label="Required">
                                    *
                                </span>
                            )}
                            {contentStatus[desc.value]?.hasContent && (
                                <span
                                    className="ml-1 inline-block h-2 w-2 rounded-full bg-green-500"
                                    aria-label="Has content"
                                    title="This description has content"
                                />
                            )}
                        </TabsTrigger>
                    ))}
                </TabsList>

                {DESCRIPTION_TYPES.map((desc) => {
                    const isAbstract = desc.value === 'Abstract';
                    const hasValidationError = isAbstract && abstractTouched && abstractValidationMessages.length > 0;
                    const charCount = contentStatus[desc.value]?.charCount ?? 0;
                    const isNearLimit = charCount > 15750; // 90% of 17500
                    const isTooShort = charCount > 0 && charCount < 50;

                    return (
                        <TabsContent key={desc.value} value={desc.value} className="space-y-2">
                            <div className="space-y-2">
                                <Label htmlFor={`description-${desc.value}`}>
                                    {desc.label}
                                    {desc.required ? (
                                        <span className="ml-2 text-sm font-normal text-destructive">(Required)</span>
                                    ) : (
                                        <span className="ml-2 text-sm font-normal text-muted-foreground">(Optional)</span>
                                    )}
                                </Label>
                                {desc.helpText && <p className="text-sm text-muted-foreground">{desc.helpText}</p>}
                                <Textarea
                                    id={`description-${desc.value}`}
                                    value={getDescriptionValue(desc.value)}
                                    onChange={(e) => handleDescriptionChange(desc.value, e.target.value)}
                                    onBlur={() => {
                                        if (isAbstract && onAbstractValidationBlur) {
                                            onAbstractValidationBlur();
                                        }
                                    }}
                                    placeholder={desc.placeholder}
                                    rows={8}
                                    className="resize-y"
                                    aria-describedby={`description-${desc.value}-count ${isAbstract ? 'description-abstract-validation' : ''}`}
                                    aria-invalid={hasValidationError}
                                    required={desc.required}
                                    data-testid={isAbstract ? 'abstract-textarea' : undefined}
                                />
                                {isAbstract && abstractTouched && <FieldValidationFeedback messages={abstractValidationMessages} />}
                                <div
                                    id={`description-${desc.value}-count`}
                                    className={`text-right text-sm ${
                                        hasValidationError
                                            ? 'text-destructive'
                                            : isNearLimit || isTooShort
                                              ? 'font-medium text-yellow-600'
                                              : 'text-muted-foreground'
                                    }`}
                                >
                                    {charCount} characters
                                    {isAbstract && charCount > 0 && (
                                        <span className="ml-1">({charCount < 50 ? `${50 - charCount} more needed` : `of 17,500`})</span>
                                    )}
                                </div>
                            </div>
                        </TabsContent>
                    );
                })}
            </Tabs>
        </div>
    );
}
