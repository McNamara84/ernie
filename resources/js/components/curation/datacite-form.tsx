import { useEffect, useMemo, useRef, useState } from 'react';
import InputField from './fields/input-field';
import { SelectField } from './fields/select-field';
import TitleField from './fields/title-field';
import LicenseField from './fields/license-field';
import AuthorField, {
    type AuthorEntry,
    type AuthorType,
    type InstitutionAuthorEntry,
    type PersonAuthorEntry,
} from './fields/author-field';
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
import { buildCsrfHeaders } from '@/lib/csrf-token';
import {
    Accordion,
    AccordionContent,
    AccordionItem,
    AccordionTrigger,
} from '@/components/ui/accordion';
import type { ResourceType, TitleType, License, Language } from '@/types';
import { useRorAffiliations } from '@/hooks/use-ror-affiliations';
import type { AffiliationTag } from '@/types/affiliations';

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

const createEmptyPersonAuthor = (): PersonAuthorEntry => ({
    id: crypto.randomUUID(),
    type: 'person',
    orcid: '',
    firstName: '',
    lastName: '',
    email: '',
    website: '',
    isContact: false,
    affiliations: [] as AffiliationTag[],
    affiliationsInput: '',
});

const createEmptyInstitutionAuthor = (): InstitutionAuthorEntry => ({
    id: crypto.randomUUID(),
    type: 'institution',
    institutionName: '',
    affiliations: [] as AffiliationTag[],
    affiliationsInput: '',
});

const createEmptyAuthor = (type: AuthorType = 'person'): AuthorEntry => {
    return type === 'person' ? createEmptyPersonAuthor() : createEmptyInstitutionAuthor();
};

type InitialAffiliationInput = {
    value?: string | null;
    rorId?: string | null;
};

type BaseInitialAuthor = {
    affiliations?: (InitialAffiliationInput | null | undefined)[] | null;
};

export type InitialAuthor =
    | (BaseInitialAuthor & {
          type?: 'person';
          orcid?: string | null;
          firstName?: string | null;
          lastName?: string | null;
          email?: string | null;
          website?: string | null;
          isContact?: boolean | string | null;
      })
    | (BaseInitialAuthor & {
          type: 'institution';
          institutionName?: string | null;
      });

const DEBUG_AFFILIATIONS = true;

const normaliseInitialAffiliations = (
    affiliations?: (InitialAffiliationInput | null | undefined)[] | null,
): AffiliationTag[] => {
    if (!affiliations || !Array.isArray(affiliations)) {
        if (DEBUG_AFFILIATIONS) {
            console.log('normaliseInitialAffiliations: No affiliations or not an array', affiliations);
        }
        return [];
    }

    const normalized = affiliations
        .map((affiliation, index) => {
            if (!affiliation || typeof affiliation !== 'object') {
                if (DEBUG_AFFILIATIONS) {
                    console.log(`normaliseInitialAffiliations: Affiliation ${index} is not an object`, affiliation);
                }
                return null;
            }

            // Try multiple property names for value
            const rawValue =
                ('value' in affiliation && typeof affiliation.value === 'string'
                    ? affiliation.value
                    : 'name' in affiliation && typeof (affiliation as Record<string, unknown>).name === 'string'
                      ? (affiliation as Record<string, unknown>).name as string
                      : ''
                ).trim();

            // Try multiple property names for rorId
            const rawRorId =
                ('rorId' in affiliation && typeof affiliation.rorId === 'string'
                    ? affiliation.rorId
                    : 'rorid' in affiliation && typeof (affiliation as Record<string, unknown>).rorid === 'string'
                      ? (affiliation as Record<string, unknown>).rorid as string
                      : 'identifier' in affiliation && typeof (affiliation as Record<string, unknown>).identifier === 'string'
                        ? (affiliation as Record<string, unknown>).identifier as string
                        : ''
                ).trim();

            if (!rawValue && !rawRorId) {
                if (DEBUG_AFFILIATIONS) {
                    console.log(`normaliseInitialAffiliations: Affiliation ${index} has no value or rorId`, affiliation);
                }
                return null;
            }

            if (DEBUG_AFFILIATIONS) {
                console.log(`normaliseInitialAffiliations: Affiliation ${index}`, {
                    input: affiliation,
                    value: rawValue || rawRorId,
                    rorId: rawRorId || null,
                });
            }

            return {
                value: rawValue || rawRorId,
                rorId: rawRorId || null,
            } satisfies AffiliationTag;
        })
        .filter((item): item is AffiliationTag => Boolean(item && item.value));

    if (DEBUG_AFFILIATIONS) {
        console.log('normaliseInitialAffiliations: Final result', { input: affiliations, output: normalized });
    }
    return normalized;
};

const mapInitialAuthorToEntry = (author: InitialAuthor): AuthorEntry | null => {
    if (!author || typeof author !== 'object') {
        return null;
    }

    const affiliations = normaliseInitialAffiliations(author.affiliations ?? null);
    const affiliationsInput = affiliations.map((item) => item.value).join(', ');

    if (author.type === 'institution') {
        const base = createEmptyInstitutionAuthor();

        return {
            ...base,
            institutionName:
                typeof author.institutionName === 'string'
                    ? author.institutionName.trim()
                    : '',
            affiliations,
            affiliationsInput,
        } satisfies InstitutionAuthorEntry;
    }

    const base = createEmptyPersonAuthor();

    return {
        ...base,
        orcid: typeof author.orcid === 'string' ? author.orcid.trim() : '',
        firstName: typeof author.firstName === 'string' ? author.firstName.trim() : '',
        lastName: typeof author.lastName === 'string' ? author.lastName.trim() : '',
        email: typeof author.email === 'string' ? author.email.trim() : '',
        website: typeof author.website === 'string' ? author.website.trim() : '',
        isContact: author.isContact === true || author.isContact === 'true',
        affiliations,
        affiliationsInput,
    } satisfies PersonAuthorEntry;
};

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
    initialResourceId?: string;
    initialAuthors?: InitialAuthor[];
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
    initialResourceId,
    initialAuthors = [],
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

    const [authors, setAuthors] = useState<AuthorEntry[]>(() => {
        if (initialAuthors.length > 0) {
            const mapped = initialAuthors
                .map((author) => mapInitialAuthorToEntry(author))
                .filter((author): author is AuthorEntry => Boolean(author));

            if (mapped.length > 0) {
                return mapped;
            }
        }

        return [createEmptyAuthor()];
    });
    const { suggestions: affiliationSuggestions } = useRorAffiliations();

    const [isSaving, setIsSaving] = useState(false);
    const [showSuccessModal, setShowSuccessModal] = useState(false);
    const [successMessage, setSuccessMessage] = useState('Successfully saved resource.');
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const [validationErrors, setValidationErrors] = useState<string[]>([]);

    const areRequiredFieldsFilled = useMemo(() => {
        const mainTitleEntry = titles.find((entry) => entry.titleType === 'main-title');
        const mainTitleFilled = Boolean(mainTitleEntry?.title.trim());
        const yearFilled = Boolean(form.year?.trim());
        const resourceTypeSelected = Boolean(form.resourceType);
        const languageSelected = Boolean(form.language);
        const primaryLicenseFilled = Boolean(licenseEntries[0]?.license?.trim());
        const authorsValid =
            authors.length > 0 &&
            authors.every((author) => {
                if (author.type === 'person') {
                    const hasLastName = Boolean(author.lastName.trim());
                    const contactValid = !author.isContact || Boolean(author.email.trim());
                    return hasLastName && contactValid;
                }

                return Boolean(author.institutionName.trim());
            });

        return (
            mainTitleFilled &&
            yearFilled &&
            resourceTypeSelected &&
            languageSelected &&
            primaryLicenseFilled &&
            authorsValid
        );
    }, [authors, form.language, form.resourceType, form.year, licenseEntries, titles]);

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

    const handleAuthorTypeChange = (authorId: string, type: AuthorType) => {
        setAuthors((previous) =>
            previous.map((author) => {
                if (author.id !== authorId) {
                    return author;
                }

                if (author.type === type) {
                    return author;
                }

                if (type === 'person') {
                    return {
                        ...createEmptyPersonAuthor(),
                        id: author.id,
                        affiliations: author.affiliations,
                        affiliationsInput: author.affiliationsInput,
                    } as PersonAuthorEntry;
                }

                return {
                    ...createEmptyInstitutionAuthor(),
                    id: author.id,
                    affiliations: author.affiliations,
                    affiliationsInput: author.affiliationsInput,
                } as InstitutionAuthorEntry;
            }),
        );
    };

    const handlePersonAuthorChange = (
        authorId: string,
        field: 'orcid' | 'firstName' | 'lastName' | 'email' | 'website',
        value: string,
    ) => {
        setAuthors((previous) =>
            previous.map((author) => {
                if (author.id !== authorId || author.type !== 'person') {
                    return author;
                }

                return { ...author, [field]: value } as PersonAuthorEntry;
            }),
        );
    };

    const handleInstitutionNameChange = (authorId: string, value: string) => {
        setAuthors((previous) =>
            previous.map((author) => {
                if (author.id !== authorId || author.type !== 'institution') {
                    return author;
                }

                return { ...author, institutionName: value } as InstitutionAuthorEntry;
            }),
        );
    };

    const handleAuthorContactChange = (authorId: string, checked: boolean) => {
        setAuthors((previous) =>
            previous.map((author) => {
                if (author.id !== authorId || author.type !== 'person') {
                    return author;
                }

                return {
                    ...author,
                    isContact: checked,
                    email: checked ? author.email : '',
                    website: checked ? author.website : '',
                } as PersonAuthorEntry;
            }),
        );
    };

    const handleAffiliationsChange = (
        authorId: string,
        value: { raw: string; tags: AffiliationTag[] },
    ) => {
        setAuthors((previous) =>
            previous.map((author) =>
                author.id === authorId
                    ? ({
                          ...author,
                          affiliations: value.tags,
                          affiliationsInput: value.raw,
                      } as AuthorEntry)
                    : author,
            ),
        );
    };

    const addAuthor = () => {
        setAuthors((previous) => [...previous, createEmptyAuthor()]);
    };

    const removeAuthor = (authorId: string) => {
        setAuthors((previous) =>
            previous.length > 1
                ? previous.filter((author) => author.id !== authorId)
                : previous,
        );
    };

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

    const resolvedResourceId = useMemo(() => {
        if (!initialResourceId) {
            return null;
        }

        const trimmed = initialResourceId.trim();

        if (!trimmed) {
            return null;
        }

        const parsed = Number(trimmed);

        return Number.isFinite(parsed) ? parsed : null;
    }, [initialResourceId]);

    const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        setIsSaving(true);
        setErrorMessage(null);
        setValidationErrors([]);

        const payload: {
            doi: string | null;
            year: number | null;
            resourceType: number | null;
            version: string | null;
            language: string;
            titles: { title: string; titleType: string }[];
            licenses: string[];
            resourceId?: number;
        } = {
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

        if (resolvedResourceId !== null) {
            payload.resourceId = resolvedResourceId;
        }

        try {
            const csrfHeaders = buildCsrfHeaders();
            const csrfToken = csrfHeaders['X-CSRF-TOKEN'];

            if (!csrfToken) {
                setErrorMessage(
                    'Missing security token. Please refresh the page and try again.',
                );
                console.error('CSRF token unavailable when attempting to save resource.');
                return;
            }

            const response = await fetch(saveUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    ...csrfHeaders,
                    'X-Requested-With': 'XMLHttpRequest',
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
                defaultValue={['resource-info', 'authors', 'licenses-rights']}
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
                <AccordionItem value="authors">
                    <AccordionTrigger>Authors</AccordionTrigger>
                    <AccordionContent>
                        <div className="space-y-6">
                            {authors.map((author, index) => (
                                <AuthorField
                                    key={author.id}
                                    author={author}
                                    index={index}
                                    onTypeChange={(type) =>
                                        handleAuthorTypeChange(author.id, type)
                                    }
                                    onPersonFieldChange={(field, value) =>
                                        handlePersonAuthorChange(author.id, field, value)
                                    }
                                    onInstitutionNameChange={(value) =>
                                        handleInstitutionNameChange(author.id, value)
                                    }
                                    onContactChange={(checked) =>
                                        handleAuthorContactChange(author.id, checked)
                                    }
                                onAffiliationsChange={(value) =>
                                    handleAffiliationsChange(author.id, value)
                                }
                                onRemoveAuthor={() => removeAuthor(author.id)}
                                canRemove={authors.length > 1}
                                onAddAuthor={addAuthor}
                                canAddAuthor={index === authors.length - 1}
                                affiliationSuggestions={affiliationSuggestions}
                            />
                            ))}
                        </div>
                    </AccordionContent>
                </AccordionItem>
            </Accordion>
            <div className="flex justify-end">
                <Button
                    type="submit"
                    disabled={isSaving || !areRequiredFieldsFilled}
                    aria-busy={isSaving}
                    aria-disabled={isSaving || !areRequiredFieldsFilled}
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
