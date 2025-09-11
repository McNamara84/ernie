import { useState } from 'react';
import InputField from './fields/input-field';
import { SelectField } from './fields/select-field';
import TitleField from './fields/title-field';
import { LANGUAGE_OPTIONS } from '@/constants/languages';
import {
    Accordion,
    AccordionContent,
    AccordionItem,
    AccordionTrigger,
} from '@/components/ui/accordion';
import type { ResourceType, TitleType } from '@/types';

interface DataCiteFormData {
    doi: string;
    year: string;
    resourceType: string;
    version: string;
    language: string;
}

interface TitleEntry {
    id: string;
    title: string;
    titleType: string;
}

interface DataCiteFormProps {
    resourceTypes: ResourceType[];
    titleTypes: TitleType[];
    maxTitles?: number;
    initialDoi?: string;
    initialYear?: string;
    initialVersion?: string;
    initialLanguage?: string;
    initialResourceType?: string;
    initialTitles?: { title: string; titleType: string }[];
}

export default function DataCiteForm({
    resourceTypes,
    titleTypes,
    maxTitles = 100,
    initialDoi = '',
    initialYear = '',
    initialVersion = '',
    initialLanguage = '',
    initialResourceType = '',
    initialTitles = [],
}: DataCiteFormProps) {
    const MAX_TITLES = maxTitles;
    const [form, setForm] = useState<DataCiteFormData>({
        doi: initialDoi,
        year: initialYear,
        resourceType: initialResourceType,
        version: initialVersion,
        language: initialLanguage,
    });

    const [titles, setTitles] = useState<TitleEntry[]>(
        initialTitles.length
            ? initialTitles.map((t) => ({
                  id: crypto.randomUUID(),
                  title: t.title,
                  titleType: t.titleType,
              }))
            : [{ id: crypto.randomUUID(), title: '', titleType: 'main-title' }],
    );

    const handleChange = (field: keyof DataCiteFormData, value: string) => {
        setForm((prev) => ({ ...prev, [field]: value }));
    };

    const handleTitleChange = (
        index: number,
        field: keyof Omit<TitleEntry, 'id'>,
        value: string,
    ) => {
        setTitles((prev) => {
            const next = [...prev];
            next[index] = { ...next[index], [field]: value };
            return next;
        });
    };

    const addTitle = () => {
        if (titles.length >= MAX_TITLES) return;
        const defaultType = titleTypes.find((t) => t.slug !== 'main-title')?.slug ?? '';
        setTitles((prev) => [
            ...prev,
            { id: crypto.randomUUID(), title: '', titleType: defaultType },
        ]);
    };

    const removeTitle = (index: number) => {
        setTitles((prev) => prev.filter((_, i) => i !== index));
    };

    const mainTitleUsed = titles.some((t) => t.titleType === 'main-title');

    return (
        <form>
            <Accordion
                type="multiple"
                defaultValue={['resource-info', 'licenses-rights']}
                className="w-full"
            >
                <AccordionItem value="resource-info">
                    <AccordionTrigger>Resource Information</AccordionTrigger>
                    <AccordionContent className="space-y-6">
                        <div className="grid gap-4 md:grid-cols-12">
                            <InputField
                                id="doi"
                                label="DOI"
                                value={form.doi}
                                onChange={(e) => handleChange('doi', e.target.value)}
                                placeholder="10.xxxx/xxxxx"
                                className="md:col-span-3"
                            />
                            <InputField
                                id="year"
                                type="number"
                                label="Year"
                                value={form.year}
                                onChange={(e) => handleChange('year', e.target.value)}
                                placeholder="2024"
                                className="md:col-span-2"
                            />
                            <SelectField
                                id="resourceType"
                                label="Resource Type"
                                value={form.resourceType}
                                onValueChange={(val) => handleChange('resourceType', val)}
                                options={resourceTypes.map((type) => ({
                                    value: type.slug,
                                    label: type.name,
                                }))}
                                className="md:col-span-4"
                            />
                            <InputField
                                id="version"
                                label="Version"
                                value={form.version}
                                onChange={(e) => handleChange('version', e.target.value)}
                                placeholder="1.0"
                                className="md:col-span-1"
                            />
                            <SelectField
                                id="language"
                                label="Language of Data"
                                value={form.language}
                                onValueChange={(val) => handleChange('language', val)}
                                options={LANGUAGE_OPTIONS}
                                className="md:col-span-2"
                            />
                        </div>
                        <div className="space-y-4 mt-3">
                            {titles.map((entry, index) => (
                                <TitleField
                                    key={entry.id}
                                    id={entry.id}
                                    title={entry.title}
                                    titleType={entry.titleType}
                                    options={titleTypes
                                        .filter(
                                            (t) =>
                                                t.slug !== 'main-title' ||
                                                !mainTitleUsed ||
                                                entry.titleType === 'main-title',
                                        )
                                        .map((t) => ({ value: t.slug, label: t.name }))}
                                    onTitleChange={(val) =>
                                        handleTitleChange(index, 'title', val)
                                    }
                                    onTypeChange={(val) =>
                                        handleTitleChange(index, 'titleType', val)
                                    }
                                    onAdd={addTitle}
                                    onRemove={() => removeTitle(index)}
                                    isFirst={index === 0}
                                    canAdd={titles.length < MAX_TITLES}
                                />
                            ))}
                        </div>
                    </AccordionContent>
                </AccordionItem>
                <AccordionItem value="licenses-rights">
                    <AccordionTrigger>Licenses and Rights</AccordionTrigger>
                    <AccordionContent />
                </AccordionItem>
            </Accordion>
        </form>
    );
}
