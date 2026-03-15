import { useCallback, useMemo, useRef, useState } from 'react';

import { FieldValidationFeedback } from '@/components/ui/field-validation-feedback';
import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import type { ValidationMessage } from '@/hooks/use-form-validation';
import type { DescriptionType as DescriptionTypeFromApi } from '@/types';

export type DescriptionType = 'Abstract' | 'Methods' | 'SeriesInformation' | 'TableOfContents' | 'TechnicalInfo' | 'Other';

export interface DescriptionEntry {
    type: DescriptionType;
    value: string;
}

interface DescriptionFieldProps {
    descriptions: DescriptionEntry[];
    onChange: (descriptions: DescriptionEntry[]) => void;
    availableTypes: DescriptionTypeFromApi[];
    // Validation props for Abstract field
    abstractValidationMessages?: ValidationMessage[];
    abstractTouched?: boolean;
    onAbstractValidationBlur?: () => void;
}

/** UI metadata for each description type (labels, placeholders, help texts). */
const DESCRIPTION_TYPE_META: Record<
    string,
    {
        label: string;
        placeholder: string;
        required?: boolean;
        helpText?: string;
    }
> = {
    Abstract: {
        label: 'Abstract',
        placeholder: 'Enter a brief summary of the resource...',
        required: true,
        helpText:
            'A brief description of the resource and the context in which the resource was created. Use "<br>" to indicate a line break for improved rendering of multiple paragraphs, but otherwise no HTML markup.',
    },
    Methods: {
        label: 'Methods',
        placeholder: 'Describe the methods used to create or collect this resource...',
        helpText:
            'The methodology employed for the study or research. Recommended for discovery. Full documentation about methods supports open science.',
    },
    SeriesInformation: {
        label: 'Series Information',
        placeholder: 'Provide information about the series this resource belongs to...',
        helpText:
            'Information about a repeating series, such as volume, issue, number. Note: This information should now be explicitly provided using the RelatedItem property with relationType "IsPublishedIn".',
    },
    TableOfContents: {
        label: 'Table of Contents',
        placeholder: 'Enter the table of contents...',
        helpText:
            'A listing of the Table of Contents. Use "<br>" to indicate a line break for improved rendering of multiple paragraphs, but otherwise no HTML markup.',
    },
    TechnicalInfo: {
        label: 'Technical Info',
        placeholder: 'Provide technical details about the resource...',
        helpText:
            'Detailed information that may be associated with design, implementation, operation, use, and/or maintenance of a process, system, or instrument. For software, this may include readme contents and environmental information.',
    },
    Other: {
        label: 'Other',
        placeholder: 'Enter other relevant description information...',
        helpText: 'Other description information that does not fit into an existing category.',
    },
};

export default function DescriptionField({
    descriptions,
    onChange,
    availableTypes,
    abstractValidationMessages = [],
    abstractTouched = false,
    onAbstractValidationBlur,
}: DescriptionFieldProps) {
    // Build the visible types list from the canonical DESCRIPTION_TYPE_META order,
    // filtered by enabled types, with Abstract always first.
    const visibleTypes = useMemo(() => {
        const enabledSlugs = new Set(availableTypes.map((t) => t.slug));
        return (Object.keys(DESCRIPTION_TYPE_META) as DescriptionType[]).filter(
            (slug) => slug === 'Abstract' || enabledSlugs.has(slug),
        );
    }, [availableTypes]);

    const [activeTab, setActiveTab] = useState<DescriptionType>('Abstract');

    // Use ref to always access current descriptions without recreating the callback.
    // This prevents handleDescriptionChange from being recreated on every keystroke.
    const descriptionsRef = useRef(descriptions);
    descriptionsRef.current = descriptions;

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

    // Stable callback that uses ref to access current descriptions.
    // This prevents unnecessary re-renders of child Textarea components during typing.
    const handleDescriptionChange = useCallback(
        (type: DescriptionType, value: string) => {
            const currentDescriptions = descriptionsRef.current;
            const existingIndex = currentDescriptions.findIndex((d) => d.type === type);

            if (existingIndex >= 0) {
                // Update existing description
                const updated = [...currentDescriptions];
                updated[existingIndex] = { type, value };
                onChange(updated);
            } else {
                // Add new description
                onChange([...currentDescriptions, { type, value }]);
            }
        },
        [onChange],
    );

    // Memoize content checks to avoid recalculating on every render.
    // Uses descriptionValuesMap to avoid duplicating the Map lookup logic.
    // Optional chaining (?.) with fallback (?? 0) handles edge cases gracefully.
    const contentStatus = useMemo(() => {
        const status = new Map<DescriptionType, { hasContent: boolean; charCount: number }>();
        for (const type of visibleTypes) {
            const value = descriptionValuesMap.get(type) || '';
            status.set(type, {
                hasContent: value.trim().length > 0,
                charCount: value.length,
            });
        }
        return status;
    }, [descriptionValuesMap, visibleTypes]);

    return (
        <div className="space-y-4">
            <Tabs value={activeTab} onValueChange={(value) => setActiveTab(value as DescriptionType)}>
                <TabsList className={`grid w-full`} style={{ gridTemplateColumns: `repeat(${visibleTypes.length}, minmax(0, 1fr))` }}>
                    {visibleTypes.map((type) => {
                        const meta = DESCRIPTION_TYPE_META[type];
                        return (
                            <TabsTrigger key={type} value={type} className="relative">
                                {meta.label}
                                {meta.required && (
                                    <span className="ml-0.5 text-destructive" aria-label="Required">
                                        *
                                    </span>
                                )}
                                {contentStatus.get(type)?.hasContent && (
                                    <span
                                        className="ml-1 inline-block h-2 w-2 rounded-full bg-green-500"
                                        aria-label="Has content"
                                        title="This description has content"
                                    />
                                )}
                            </TabsTrigger>
                        );
                    })}
                </TabsList>

                {visibleTypes.map((type) => {
                    const meta = DESCRIPTION_TYPE_META[type];
                    const isAbstract = type === 'Abstract';
                    const hasValidationError = isAbstract && abstractTouched && abstractValidationMessages.length > 0;
                    const charCount = contentStatus.get(type)?.charCount ?? 0;
                    const isNearLimit = charCount > 15750; // 90% of 17500
                    const isTooShort = charCount > 0 && charCount < 50;

                    return (
                        <TabsContent key={type} value={type} className="space-y-2">
                            <div className="space-y-2">
                                <Label htmlFor={`description-${type}`}>
                                    {meta.label}
                                    {meta.required ? (
                                        <span className="ml-2 text-sm font-normal text-destructive">(Required)</span>
                                    ) : (
                                        <span className="ml-2 text-sm font-normal text-muted-foreground">(Optional)</span>
                                    )}
                                </Label>
                                {meta.helpText && <p className="text-sm text-muted-foreground">{meta.helpText}</p>}
                                <Textarea
                                    id={`description-${type}`}
                                    value={getDescriptionValue(type)}
                                    onChange={(e) => handleDescriptionChange(type, e.target.value)}
                                    onBlur={() => {
                                        if (isAbstract && onAbstractValidationBlur) {
                                            onAbstractValidationBlur();
                                        }
                                    }}
                                    placeholder={meta.placeholder}
                                    rows={8}
                                    className="resize-y"
                                    aria-describedby={`description-${type}-count ${isAbstract ? 'description-abstract-validation' : ''}`}
                                    aria-invalid={hasValidationError}
                                    required={meta.required}
                                    data-testid={isAbstract ? 'abstract-textarea' : undefined}
                                />
                                {isAbstract && abstractTouched && <FieldValidationFeedback messages={abstractValidationMessages} />}
                                <div
                                    id={`description-${type}-count`}
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
