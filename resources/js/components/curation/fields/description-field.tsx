import { Plus, Trash2 } from 'lucide-react';
import { useMemo } from 'react';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { FieldValidationFeedback } from '@/components/ui/field-validation-feedback';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { ValidationMessage } from '@/hooks/use-form-validation';
import type { DescriptionType as DescriptionTypeFromApi, Language } from '@/types';

import { SelectField } from './select-field';

export type DescriptionType = 'Abstract' | 'Methods' | 'SeriesInformation' | 'TableOfContents' | 'TechnicalInfo' | 'Other';

export interface DescriptionEntry {
    id: string;
    type: DescriptionType;
    value: string;
    language?: string | null;
}

interface DescriptionFieldProps {
    descriptions: DescriptionEntry[];
    onChange: (descriptions: DescriptionEntry[]) => void;
    availableTypes: DescriptionTypeFromApi[];
    languages: Language[];
    validationMessages?: ValidationMessage[];
    validationTouched?: boolean;
    onAbstractValidationBlur?: () => void;
}

/** UI metadata for each description type (labels, placeholders, help texts). */
const DESCRIPTION_TYPE_META: Record<DescriptionType, { label: string; placeholder: string; helpText: string }> = {
    Abstract: {
        label: 'Abstract',
        placeholder: 'Enter a brief summary of the resource...',
        helpText: 'A brief description of the resource and the context in which the resource was created.',
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
        helpText: 'A listing of the Table of Contents.',
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
    languages,
    validationMessages = [],
    validationTouched = false,
    onAbstractValidationBlur,
}: DescriptionFieldProps) {
    const typeOptions = useMemo(() => {
        const enabledSlugs = new Set(availableTypes.map((type) => type.slug));
        const currentSlugs = new Set(descriptions.map((description) => description.type));

        return (Object.keys(DESCRIPTION_TYPE_META) as DescriptionType[])
            .filter((type) => type === 'Abstract' || enabledSlugs.has(type) || currentSlugs.has(type))
            .map((type) => ({ value: type, label: DESCRIPTION_TYPE_META[type].label }));
    }, [availableTypes, descriptions]);

    const languageOptions = useMemo(
        () => languages.map((language) => ({ value: language.code, label: `${language.name} (${language.code})` })),
        [languages],
    );

    const updateDescription = (id: string, changes: Partial<Omit<DescriptionEntry, 'id'>>) => {
        onChange(descriptions.map((description) => (description.id === id ? { ...description, ...changes } : description)));
    };

    const addDescription = () => {
        const defaultType = (typeOptions.find((option) => option.value === 'Other')?.value ?? typeOptions[0]?.value ?? 'Abstract') as DescriptionType;
        onChange([...descriptions, { id: crypto.randomUUID(), type: defaultType, value: '', language: null }]);
    };

    const removeDescription = (id: string) => {
        onChange(descriptions.filter((description) => description.id !== id));
    };

    const firstAbstractIndex = descriptions.findIndex((description) => description.type === 'Abstract');
    const populatedAbstracts = descriptions.filter((description) => description.type === 'Abstract' && description.value.trim() !== '');
    const hasPopulatedAbstract = populatedAbstracts.length > 0;
    const requiredAbstractId =
        populatedAbstracts.length === 1
            ? populatedAbstracts[0].id
            : populatedAbstracts.length === 0 && firstAbstractIndex >= 0
              ? descriptions[firstAbstractIndex].id
              : null;
    const groupValidationMessages = validationMessages.filter((message) => message.fieldId === 'abstract' || message.fieldId === undefined);

    return (
        <div className="space-y-4">
            <Alert className="bg-muted/40">
                <AlertDescription>
                    Landing pages support a limited HTML subset in descriptions: &lt;p&gt;, &lt;br&gt;, &lt;strong&gt;, &lt;em&gt;, &lt;ul&gt;,
                    &lt;ol&gt;, &lt;li&gt;, &lt;a&gt;, &lt;sub&gt;, &lt;sup&gt;, and &lt;code&gt;. DataCite JSON and XML retain line breaks as
                    &lt;br&gt;; all other formatting remains landing-page only. JSON-LD and Schema.org outputs remain plain text.
                </AlertDescription>
            </Alert>

            {firstAbstractIndex === -1 && validationTouched && groupValidationMessages.length > 0 && (
                <FieldValidationFeedback messages={groupValidationMessages} />
            )}

            <div className="space-y-4">
                {descriptions.map((description, index) => {
                    const meta = DESCRIPTION_TYPE_META[description.type];
                    const isAbstract = description.type === 'Abstract';
                    const isFirstAbstract = index === firstAbstractIndex;
                    const isRequiredAbstract = isAbstract && description.id === requiredAbstractId;
                    const charCount = description.value.length;
                    const trimmedCharCount = description.value.trim().length;
                    const hasLocalAbstractError = isAbstract && trimmedCharCount > 0 && (trimmedCharCount < 50 || trimmedCharCount > 17_500);
                    const entryValidationMessages = validationMessages.filter(
                        (message) =>
                            message.fieldId === description.id ||
                            (isFirstAbstract && message.fieldId === 'abstract') ||
                            (isFirstAbstract && !hasPopulatedAbstract && message.fieldId === undefined),
                    );
                    const hasEntryValidationError = entryValidationMessages.some((message) => message.severity === 'error');
                    const hasValidationError = validationTouched && (hasLocalAbstractError || hasEntryValidationError);
                    const descriptionId = `description-${description.id}`;

                    return (
                        <div key={description.id} className="space-y-4 rounded-md border p-4" data-testid="description-entry">
                            <div className="flex items-center justify-between gap-4">
                                <h3 className="font-medium">Description {index + 1}</h3>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label={`Remove description ${index + 1}`}
                                    onClick={() => removeDescription(description.id)}
                                >
                                    <Trash2 className="h-4 w-4" />
                                </Button>
                            </div>

                            <div className="grid gap-4 md:grid-cols-2">
                                <SelectField
                                    id={`${descriptionId}-type`}
                                    label="Description Type"
                                    value={description.type}
                                    onValueChange={(value) => updateDescription(description.id, { type: value as DescriptionType })}
                                    options={typeOptions}
                                    required
                                />
                                <SelectField
                                    id={`${descriptionId}-language`}
                                    label="Language"
                                    value={description.language ?? ''}
                                    onValueChange={(value) => updateDescription(description.id, { language: value || null })}
                                    options={languageOptions}
                                    placeholder="No language specified"
                                    clearable
                                    clearLabel="No language specified"
                                />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor={`${descriptionId}-value`}>
                                    {meta.label}
                                    {isRequiredAbstract ? (
                                        <span className="ml-2 text-sm font-normal text-destructive">(Required)</span>
                                    ) : (
                                        <span className="ml-2 text-sm font-normal text-muted-foreground">(Optional)</span>
                                    )}
                                </Label>
                                <p className="text-sm text-muted-foreground">{meta.helpText}</p>
                                <Textarea
                                    id={`${descriptionId}-value`}
                                    value={description.value}
                                    onChange={(event) => updateDescription(description.id, { value: event.target.value })}
                                    onBlur={() => {
                                        if (isAbstract) {
                                            onAbstractValidationBlur?.();
                                        }
                                    }}
                                    placeholder={meta.placeholder}
                                    rows={8}
                                    className="resize-y"
                                    aria-describedby={`${descriptionId}-count`}
                                    aria-invalid={hasValidationError}
                                    required={isRequiredAbstract}
                                    data-testid={isFirstAbstract ? 'abstract-textarea' : undefined}
                                />
                                {validationTouched && entryValidationMessages.length > 0 && (
                                    <FieldValidationFeedback messages={entryValidationMessages} />
                                )}
                                {isAbstract && validationTouched && entryValidationMessages.length === 0 && hasLocalAbstractError && (
                                    <p className="text-sm text-destructive" role="alert">
                                        Abstract must be between 50 and 17,500 characters.
                                    </p>
                                )}
                                <div
                                    id={`${descriptionId}-count`}
                                    data-testid={isFirstAbstract ? 'abstract-character-count' : undefined}
                                    className={`text-right text-sm ${
                                        hasValidationError || (isAbstract && charCount > 15_750)
                                            ? 'font-medium text-destructive'
                                            : isAbstract && charCount > 0 && charCount < 50
                                              ? 'font-medium text-yellow-600'
                                              : 'text-muted-foreground'
                                    }`}
                                >
                                    {charCount.toLocaleString('en-US')} characters
                                    {isAbstract && charCount > 0 && (
                                        <span className="ml-1">({charCount < 50 ? `${50 - charCount} more needed` : 'of 17,500'})</span>
                                    )}
                                </div>
                            </div>
                        </div>
                    );
                })}
            </div>

            <Button type="button" variant="outline" onClick={addDescription}>
                <Plus className="mr-2 h-4 w-4" />
                Add Description
            </Button>
        </div>
    );
}
