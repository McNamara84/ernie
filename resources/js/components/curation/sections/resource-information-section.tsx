import type { ReactElement, RefObject } from 'react';

import { AccordionContent, AccordionItem, AccordionTrigger } from '@/components/ui/accordion';
import { InputField } from '@/components/curation/fields/input-field';
import { SelectField } from '@/components/curation/fields/select-field';
import { SectionHeader } from '@/components/curation/section-header';
import { createTitleValidationRules } from '@/components/curation/validation';
import type { FieldValidationState, ValidationRule } from '@/hooks/use-form-validation';
import type { Language, ResourceType, TitleType } from '@/types';

import TitleField from '../fields/title-field';

type DataCiteFormData = {
    doi: string;
    year: string;
    resourceType: string;
    version: string;
    language: string;
};

type TitleEntry = {
    id: string;
    title: string;
    titleType: string;
};

const MAIN_TITLE_SLUG = 'main-title';

type SectionStatus = 'valid' | 'invalid' | 'optional-empty';

type ResourceInformationSectionProps = {
    status: SectionStatus;
    renderStatusBadge: (status: SectionStatus) => ReactElement;
    contentRef: RefObject<HTMLDivElement | null>;

    form: DataCiteFormData;
    handleChange: <K extends keyof DataCiteFormData>(field: K, value: DataCiteFormData[K]) => void;
    handleFieldBlur: (fieldId: string, value: unknown, rules: ValidationRule[]) => void;
    markFieldTouched: (fieldId: string) => void;
    getFieldState: (fieldId: string) => FieldValidationState;

    yearValidationRules: ValidationRule[];

    resourceTypes: ResourceType[];
    titleTypes: TitleType[];
    languages: Language[];

    titles: TitleEntry[];
    mainTitleUsed: boolean;
    canAddAnotherTitle: boolean;
    addTitle: () => void;
    removeTitle: (index: number) => void;
    handleTitleChange: (index: number, field: 'title' | 'titleType', value: string) => void;
};

export function ResourceInformationSection({
    status,
    renderStatusBadge,
    contentRef,
    form,
    handleChange,
    handleFieldBlur,
    markFieldTouched,
    getFieldState,
    yearValidationRules,
    resourceTypes,
    titleTypes,
    languages,
    titles,
    mainTitleUsed,
    canAddAnotherTitle,
    addTitle,
    removeTitle,
    handleTitleChange,
}: ResourceInformationSectionProps) {
    return (
        <AccordionItem value="resource-info">
            <AccordionTrigger>
                <div className="flex items-center gap-2">
                    <span>Resource Information</span>
                    {renderStatusBadge(status)}
                </div>
            </AccordionTrigger>
            <AccordionContent ref={contentRef} className="space-y-6">
                <SectionHeader
                    label="Resource Information"
                    description="Basic metadata about your dataset including identifiers and type."
                    tooltip="Required fields: Year, Resource Type, Main Title, Language"
                    required
                />
                <div className="grid gap-4 md:grid-cols-12">
                    <InputField
                        id="doi"
                        label="DOI"
                        value={form.doi || ''}
                        onChange={(e) => handleChange('doi', e.target.value)}
                        onValidationBlur={() => markFieldTouched('doi')}
                        validationMessages={getFieldState('doi').messages}
                        touched={getFieldState('doi').touched}
                        placeholder="10.xxxx/xxxxx"
                        labelTooltip="Enter DOI in format 10.xxxx/xxxxx or https://doi.org/10.xxxx/xxxxx"
                        className="md:col-span-3"
                    />
                    <InputField
                        id="year"
                        type="number"
                        label="Year"
                        value={form.year || ''}
                        onChange={(e) => handleChange('year', e.target.value)}
                        onValidationBlur={() => handleFieldBlur('year', form.year, yearValidationRules)}
                        validationMessages={getFieldState('year').messages}
                        touched={getFieldState('year').touched}
                        placeholder="2024"
                        className="md:col-span-2"
                        required
                    />
                    <SelectField
                        id="resourceType"
                        label="Resource Type"
                        value={form.resourceType || ''}
                        onValueChange={(val) => handleChange('resourceType', val)}
                        onValidationBlur={() => markFieldTouched('resourceType')}
                        validationMessages={getFieldState('resourceType').messages}
                        touched={getFieldState('resourceType').touched}
                        options={resourceTypes.map((type) => ({
                            value: String(type.id),
                            label: type.name,
                        }))}
                        className="md:col-span-4"
                        required
                        data-testid="resource-type-select"
                    />
                    <InputField
                        id="version"
                        label="Version"
                        value={form.version || ''}
                        onChange={(e) => handleChange('version', e.target.value)}
                        onValidationBlur={() => markFieldTouched('version')}
                        validationMessages={getFieldState('version').messages}
                        touched={getFieldState('version').touched}
                        placeholder="1.0"
                        labelTooltip="Semantic versioning (e.g., 1.2.3)"
                        className="md:col-span-1"
                    />
                    <SelectField
                        id="language"
                        label="Language of Data"
                        value={form.language || ''}
                        onValueChange={(val) => handleChange('language', val)}
                        onValidationBlur={() => markFieldTouched('language')}
                        validationMessages={getFieldState('language').messages}
                        touched={getFieldState('language').touched}
                        options={languages.map((l) => ({
                            value: l.code,
                            label: l.name,
                        }))}
                        className="md:col-span-2"
                        required
                        data-testid="language-select"
                    />
                </div>
                <div className="mt-3 space-y-4">
                    {titles.map((entry, index) => (
                        <TitleField
                            key={entry.id}
                            id={entry.id}
                            title={entry.title}
                            titleType={entry.titleType}
                            options={titleTypes
                                .filter((t) => t.slug !== MAIN_TITLE_SLUG || !mainTitleUsed || entry.titleType === MAIN_TITLE_SLUG)
                                .map((t) => ({ value: t.slug, label: t.name }))}
                            onTitleChange={(val) => handleTitleChange(index, 'title', val)}
                            onTypeChange={(val) => handleTitleChange(index, 'titleType', val)}
                            onAdd={addTitle}
                            onRemove={() => removeTitle(index)}
                            isFirst={index === 0}
                            canAdd={canAddAnotherTitle}
                            validationMessages={getFieldState(`title-${index}`).messages}
                            touched={getFieldState(`title-${index}`).touched}
                            onValidationBlur={() =>
                                handleFieldBlur(
                                    `title-${index}`,
                                    entry.title,
                                    createTitleValidationRules(index, entry.titleType, titles),
                                )
                            }
                        />
                    ))}
                </div>
            </AccordionContent>
        </AccordionItem>
    );
}
