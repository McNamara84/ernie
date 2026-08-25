import { CircleAlert, Globe2, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { FieldValidationFeedback } from '@/components/ui/field-validation-feedback';
import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import type { ValidationMessage } from '@/hooks/use-form-validation';
import type { DescriptionType as DescriptionTypeFromApi, Language } from '@/types';

import { ABSTRACT_MAX_LENGTH } from '../utils/description-rules';
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

interface DescriptionGroup {
    type: DescriptionType;
    entries: DescriptionEntry[];
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

const normalizedLanguage = (language?: string | null): string => language?.trim().toLowerCase().replaceAll('_', '-') ?? '';

export default function DescriptionField({
    descriptions,
    onChange,
    availableTypes,
    languages,
    validationMessages = [],
    validationTouched = false,
    onAbstractValidationBlur,
}: DescriptionFieldProps) {
    const [activeEntryIds, setActiveEntryIds] = useState<Partial<Record<DescriptionType, string>>>({});

    const typeOptions = useMemo(() => {
        const enabledSlugs = new Set(availableTypes.map((type) => type.slug));
        const currentSlugs = new Set(descriptions.map((description) => description.type));

        return (Object.keys(DESCRIPTION_TYPE_META) as DescriptionType[])
            .filter((type) => type === 'Abstract' || enabledSlugs.has(type) || currentSlugs.has(type))
            .map((type) => ({ value: type, label: DESCRIPTION_TYPE_META[type].label }));
    }, [availableTypes, descriptions]);

    const groups = useMemo<DescriptionGroup[]>(() => {
        const byType = new Map<DescriptionType, DescriptionEntry[]>();

        for (const description of descriptions) {
            const entries = byType.get(description.type) ?? [];
            entries.push(description);
            byType.set(description.type, entries);
        }

        return typeOptions.map(({ value }) => ({ type: value, entries: byType.get(value) ?? [] })).filter((group) => group.entries.length > 0);
    }, [descriptions, typeOptions]);

    const descriptionLanguages = useMemo(() => {
        const seenCodes = new Set<string>();

        return languages.flatMap((language) => {
            const code = normalizedLanguage(language.code);

            if (!code || seenCodes.has(code)) {
                return [];
            }

            seenCodes.add(code);

            return [{ ...language, code }];
        });
    }, [languages]);
    const languageByCode = useMemo(() => new Map(descriptionLanguages.map((language) => [language.code, language])), [descriptionLanguages]);

    const updateDescription = (id: string, changes: Partial<Omit<DescriptionEntry, 'id'>>) => {
        onChange(descriptions.map((description) => (description.id === id ? { ...description, ...changes } : description)));
    };

    const addDescriptionGroup = (type: DescriptionType) => {
        const id = crypto.randomUUID();
        onChange([...descriptions, { id, type, value: '', language: null }]);
        setActiveEntryIds((current) => ({ ...current, [type]: id }));
    };

    const addLanguageVersion = (type: DescriptionType, language: string) => {
        const id = crypto.randomUUID();
        onChange([...descriptions, { id, type, value: '', language }]);
        setActiveEntryIds((current) => ({ ...current, [type]: id }));
    };

    const removeDescription = (entry: DescriptionEntry) => {
        const remaining = descriptions.filter((description) => description.id !== entry.id);
        onChange(remaining);

        if (activeEntryIds[entry.type] === entry.id) {
            const nextEntry = remaining.find((description) => description.type === entry.type);
            setActiveEntryIds((current) => ({ ...current, [entry.type]: nextEntry?.id }));
        }
    };

    const currentTypes = new Set(groups.map((group) => group.type));
    const addableTypes = typeOptions.filter((option) => !currentTypes.has(option.value));
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
        <div className="space-y-4" data-testid="description-field">
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

            <div className="space-y-5">
                {groups.map((group) => {
                    const meta = DESCRIPTION_TYPE_META[group.type];
                    const configuredActiveId = activeEntryIds[group.type];
                    const activeEntryId = group.entries.some((entry) => entry.id === configuredActiveId) ? configuredActiveId! : group.entries[0].id;
                    const usedLanguages = new Set(group.entries.map((entry) => normalizedLanguage(entry.language)).filter(Boolean));
                    const addableLanguages = descriptionLanguages.filter((language) => !usedLanguages.has(normalizedLanguage(language.code)));
                    const validationByEntryId = new Map(
                        group.entries.map((description) => {
                            const isAbstract = description.type === 'Abstract';
                            const isFirstAbstract = descriptions[firstAbstractIndex]?.id === description.id;
                            const isRequiredAbstract = isAbstract && description.id === requiredAbstractId;
                            const trimmedCharCount = description.value.trim().length;
                            const hasLocalAbstractError = isAbstract && trimmedCharCount > ABSTRACT_MAX_LENGTH;
                            const entryValidationMessages = validationMessages.filter(
                                (message) =>
                                    message.fieldId === description.id ||
                                    (isFirstAbstract && message.fieldId === 'abstract') ||
                                    (isFirstAbstract && !hasPopulatedAbstract && message.fieldId === undefined),
                            );
                            const hasEntryValidationError = entryValidationMessages.some((message) => message.severity === 'error');

                            return [
                                description.id,
                                {
                                    isAbstract,
                                    isFirstAbstract,
                                    isRequiredAbstract,
                                    hasLocalAbstractError,
                                    entryValidationMessages,
                                    hasValidationError: validationTouched && (hasLocalAbstractError || hasEntryValidationError),
                                },
                            ] as const;
                        }),
                    );

                    return (
                        <section
                            key={group.type}
                            className="space-y-4 rounded-lg border bg-card p-4 shadow-xs"
                            data-testid="description-entry"
                            data-description-type={group.type}
                        >
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div className="space-y-1">
                                    <h3 className="text-base font-semibold">{meta.label}</h3>
                                    <p className="text-sm text-muted-foreground">{meta.helpText}</p>
                                </div>

                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button type="button" variant="outline" size="sm" disabled={addableLanguages.length === 0}>
                                            <Globe2 />
                                            Add language version
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        <DropdownMenuLabel>Language</DropdownMenuLabel>
                                        {descriptionLanguages.map((language) => {
                                            const code = normalizedLanguage(language.code);
                                            const alreadyUsed = usedLanguages.has(code);

                                            return (
                                                <DropdownMenuItem
                                                    key={code}
                                                    disabled={alreadyUsed}
                                                    onSelect={() => addLanguageVersion(group.type, code)}
                                                >
                                                    {language.name} ({code}){alreadyUsed ? ' — already added' : ''}
                                                </DropdownMenuItem>
                                            );
                                        })}
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>

                            <Tabs value={activeEntryId} onValueChange={(id) => setActiveEntryIds((current) => ({ ...current, [group.type]: id }))}>
                                <TabsList className="h-auto max-w-full flex-wrap justify-start" aria-label={`${meta.label} language versions`}>
                                    {group.entries.map((entry) => {
                                        const code = normalizedLanguage(entry.language);
                                        const matchingEntries = group.entries.filter((candidate) => normalizedLanguage(candidate.language) === code);
                                        const duplicateIndex = matchingEntries.findIndex((candidate) => candidate.id === entry.id);
                                        const language = code ? languageByCode.get(code) : undefined;
                                        const baseLabel = code ? `${language?.name ?? 'Imported language'} (${code})` : 'Language not specified';
                                        const label = matchingEntries.length > 1 ? `${baseLabel} ${duplicateIndex + 1}` : baseLabel;
                                        const hasValidationError = validationByEntryId.get(entry.id)?.hasValidationError ?? false;

                                        return (
                                            <TabsTrigger key={entry.id} value={entry.id} aria-invalid={hasValidationError || undefined}>
                                                {label}
                                                {hasValidationError && (
                                                    <>
                                                        <CircleAlert className="size-3.5 text-destructive" aria-hidden="true" />
                                                        <span className="sr-only"> has validation errors</span>
                                                    </>
                                                )}
                                            </TabsTrigger>
                                        );
                                    })}
                                </TabsList>

                                {group.entries.map((description) => {
                                    const {
                                        isAbstract,
                                        isFirstAbstract,
                                        isRequiredAbstract,
                                        hasLocalAbstractError,
                                        entryValidationMessages,
                                        hasValidationError,
                                    } = validationByEntryId.get(description.id)!;
                                    const charCount = description.value.length;
                                    const descriptionId = `description-${description.id}`;
                                    const currentLanguage = normalizedLanguage(description.language);
                                    const siblingLanguages = new Set(
                                        group.entries
                                            .filter((entry) => entry.id !== description.id)
                                            .map((entry) => normalizedLanguage(entry.language))
                                            .filter(Boolean),
                                    );
                                    const selectableLanguages = descriptionLanguages
                                        .filter((language) => !siblingLanguages.has(normalizedLanguage(language.code)))
                                        .map((language) => ({
                                            value: normalizedLanguage(language.code),
                                            label: `${language.name} (${normalizedLanguage(language.code)})`,
                                        }));

                                    if (currentLanguage && !selectableLanguages.some((option) => option.value === currentLanguage)) {
                                        const currentLanguageName = languageByCode.get(currentLanguage)?.name;
                                        selectableLanguages.push({
                                            value: currentLanguage,
                                            label: currentLanguageName
                                                ? `${currentLanguageName} (${currentLanguage})`
                                                : `Imported language (${currentLanguage})`,
                                        });
                                    }

                                    return (
                                        <TabsContent key={description.id} value={description.id} className="space-y-4 pt-2">
                                            <div className="flex flex-wrap items-end justify-between gap-3">
                                                <SelectField
                                                    id={`${descriptionId}-language`}
                                                    label="Language"
                                                    value={currentLanguage}
                                                    onValueChange={(value) => updateDescription(description.id, { language: value || null })}
                                                    options={selectableLanguages}
                                                    placeholder="No language specified"
                                                    clearable
                                                    clearLabel="No language specified"
                                                    containerProps={{ className: 'min-w-56 flex-1 sm:max-w-xs' }}
                                                />
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    aria-label={`Remove ${meta.label} ${currentLanguage || 'without language'}`}
                                                    onClick={() => removeDescription(description)}
                                                >
                                                    <Trash2 />
                                                    Remove version
                                                </Button>
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
                                                        Abstract must not exceed {ABSTRACT_MAX_LENGTH.toLocaleString('en-US')} characters.
                                                    </p>
                                                )}
                                                <div
                                                    id={`${descriptionId}-count`}
                                                    data-testid={isFirstAbstract ? 'abstract-character-count' : undefined}
                                                    className={`text-right text-sm ${
                                                        hasValidationError || (isAbstract && charCount > ABSTRACT_MAX_LENGTH * 0.9)
                                                            ? 'font-medium text-destructive'
                                                            : 'text-muted-foreground'
                                                    }`}
                                                >
                                                    {charCount.toLocaleString('en-US')} characters
                                                    {isAbstract && charCount > 0 && (
                                                        <span className="ml-1">(of {ABSTRACT_MAX_LENGTH.toLocaleString('en-US')})</span>
                                                    )}
                                                </div>
                                            </div>
                                        </TabsContent>
                                    );
                                })}
                            </Tabs>
                        </section>
                    );
                })}
            </div>

            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button type="button" variant="outline" disabled={addableTypes.length === 0}>
                        <Plus />
                        Add Description Type
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start">
                    <DropdownMenuLabel>Description Type</DropdownMenuLabel>
                    {addableTypes.map((option) => (
                        <DropdownMenuItem key={option.value} onSelect={() => addDescriptionGroup(option.value)}>
                            {option.label}
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    );
}
