import { useEffect, useMemo, useRef, useState } from 'react';
import InputField from './fields/input-field';
import { SelectField } from './fields/select-field';
import TitleField from './fields/title-field';
import LicenseField from './fields/license-field';
import { resolveInitialLanguageCode } from './utils/language-resolver';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { withBasePath } from '@/lib/base-path';
import {
    Accordion,
    AccordionContent,
    AccordionItem,
    AccordionTrigger,
} from '@/components/ui/accordion';
import type { ResourceType, TitleType, License, Language } from '@/types';

const getCsrfToken = () => {
    if (typeof document === 'undefined') {
        return '';
    }

    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''
    );
};

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

interface LicenseEntry {
    id: string;
    license: string;
}

interface DataCiteFormProps {
    resourceTypes: ResourceType[];
    titleTypes: TitleType[];
    licenses: License[];
    languages: Language[];
    maxTitles?: number;
    maxLicenses?: number;
    initialDoi?: string;
    initialYear?: string;
    initialVersion?: string;
    initialLanguage?: string;
    initialResourceType?: string;
    initialTitles?: { title: string; titleType: string }[];
    initialLicenses?: string[];
}

export function canAddTitle(titles: TitleEntry[], maxTitles: number) {
    return (
        titles.length < maxTitles &&
        titles.length > 0 &&
        !!titles[titles.length - 1].title
    );
}

export function canAddLicense(
    licenseEntries: LicenseEntry[],
    maxLicenses: number,
) {
    return (
        licenseEntries.length < maxLicenses &&
        licenseEntries.length > 0 &&
        !!licenseEntries[licenseEntries.length - 1].license
    );
}

export default function DataCiteForm({
    resourceTypes,
    titleTypes,
    licenses,
    languages,
    maxTitles = 99,
    maxLicenses = 99,
    initialDoi = '',
    initialYear = '',
    initialVersion = '',
    initialLanguage = '',
    initialResourceType = '',
    initialTitles = [],
    initialLicenses = [],
}: DataCiteFormProps) {
    const MAX_TITLES = maxTitles;
    const MAX_LICENSES = maxLicenses;
    const errorRef = useRef<HTMLDivElement | null>(null);
    const [form, setForm] = useState<DataCiteFormData>({
        doi: initialDoi,
        year: initialYear,
        resourceType: initialResourceType,
        version: initialVersion,
        language: resolveInitialLanguageCode(languages, initialLanguage),
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

    const [licenseEntries, setLicenseEntries] = useState<LicenseEntry[]>(
        initialLicenses.length
            ? initialLicenses.map((l) => ({
                  id: crypto.randomUUID(),
                  license: l,
              }))
            : [{ id: crypto.randomUUID(), license: '' }],
    );

    const [isSaving, setIsSaving] = useState(false);
    const [showSuccessModal, setShowSuccessModal] = useState(false);
    const [successMessage, setSuccessMessage] = useState('Successfully saved resource.');
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const [validationErrors, setValidationErrors] = useState<string[]>([]);

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

    const handleLicenseChange = (index: number, value: string) => {
        setLicenseEntries((prev) => {
            const next = [...prev];
            next[index] = { ...next[index], license: value };
            return next;
        });
    };

    const addLicense = () => {
        if (licenseEntries.length >= MAX_LICENSES) return;
        setLicenseEntries((prev) => [
            ...prev,
            { id: crypto.randomUUID(), license: '' },
        ]);
    };

    const removeLicense = (index: number) => {
        setLicenseEntries((prev) => prev.filter((_, i) => i !== index));
    };

    useEffect(() => {
        if (errorMessage && errorRef.current) {
            errorRef.current.focus();
        }
    }, [errorMessage]);

    const saveUrl = useMemo(() => withBasePath('/curation/resources'), []);

    const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        setIsSaving(true);
        setErrorMessage(null);
        setValidationErrors([]);

        const payload = {
            doi: form.doi?.trim() || null,
            year: form.year ? Number(form.year) : null,
            resourceType: form.resourceType ? Number(form.resourceType) : null,
            version: form.version?.trim() || null,
            language: form.language,
            titles: titles.map((entry) => ({
                title: entry.title,
                titleType: entry.titleType,
            })),
            licenses: licenseEntries
                .map((entry) => entry.license)
                .filter((license): license is string => Boolean(license)),
        };

        try {
            const response = await fetch(saveUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify(payload),
            });

            let data: unknown = null;

            try {
                data = await response.clone().json();
            } catch (parseError) {
                console.error('Failed to parse resource save response JSON', parseError);
                // Ignore JSON parse errors for empty responses.
            }

            if (!response.ok) {
                const defaultError = 'Unable to save resource. Please review the highlighted issues.';
                const parsed = data as { message?: string; errors?: Record<string, string[]> } | null;
                const messages = parsed?.errors
                    ? Object.values(parsed.errors).flat().map((message) => String(message))
                    : [];

                setValidationErrors(messages);
                setErrorMessage(parsed?.message || defaultError);
                return;
            }

            const parsed = data as { message?: string } | null;
            setSuccessMessage(parsed?.message || 'Successfully saved resource.');
            setShowSuccessModal(true);
        } catch (error) {
            console.error('Failed to save resource', error);
            setErrorMessage('A network error prevented saving the resource. Please try again.');
        } finally {
            setIsSaving(false);
        }
    };

    return (
        <form onSubmit={handleSubmit} className="space-y-6">
            {errorMessage && (
                <div
                    ref={errorRef}
                    tabIndex={-1}
                    className="rounded-md border border-destructive bg-destructive/10 p-4 text-destructive"
                    role="alert"
                    aria-live="assertive"
                >
                    <p className="font-semibold">{errorMessage}</p>
                    {validationErrors.length > 0 && (
                        <ul className="mt-2 list-disc space-y-1 pl-5 text-sm">
                            {validationErrors.map((message, index) => (
                                <li key={`${message}-${index}`}>{message}</li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
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
                                required
                            />
                            <SelectField
                                id="resourceType"
                                label="Resource Type"
                                value={form.resourceType}
                                onValueChange={(val) => handleChange('resourceType', val)}
                                options={resourceTypes.map((type) => ({
                                    value: String(type.id),
                                    label: type.name,
                                }))}
                                className="md:col-span-4"
                                required
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
                                options={languages.map((l) => ({
                                    value: l.code,
                                    label: l.name,
                                }))}
                                className="md:col-span-2"
                                required
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
                                    canAdd={canAddTitle(titles, MAX_TITLES)}
                                />
                            ))}
                        </div>
                    </AccordionContent>
                </AccordionItem>
                <AccordionItem value="licenses-rights">
                    <AccordionTrigger>Licenses and Rights</AccordionTrigger>
                    <AccordionContent>
                        <div className="space-y-4">
                            {licenseEntries.map((entry, index) => (
                                <LicenseField
                                    key={entry.id}
                                    id={entry.id}
                                    license={entry.license}
                                    options={licenses.map((l) => ({
                                        value: l.identifier,
                                        label: l.name,
                                    }))}
                                    onLicenseChange={(val) =>
                                        handleLicenseChange(index, val)
                                    }
                                    onAdd={addLicense}
                                    onRemove={() => removeLicense(index)}
                                    isFirst={index === 0}
                                    canAdd={canAddLicense(
                                        licenseEntries,
                                        MAX_LICENSES,
                                    )}
                                    required={index === 0}
                                />
                            ))}
                        </div>
                    </AccordionContent>
                </AccordionItem>
            </Accordion>
            <div className="flex justify-end">
                <Button
                    type="submit"
                    disabled={isSaving}
                    aria-busy={isSaving}
                >
                    {isSaving ? 'Saving…' : 'Save to database'}
                </Button>
            </div>
            <Dialog open={showSuccessModal} onOpenChange={setShowSuccessModal}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Successfully saved resource</DialogTitle>
                        <DialogDescription>
                            {successMessage}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button type="button" onClick={() => setShowSuccessModal(false)}>
                            Close
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </form>
    );
}
