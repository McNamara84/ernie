import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

import {
    Accordion,
    AccordionContent,
    AccordionItem,
    AccordionTrigger,
} from '@/components/ui/accordion';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useRorAffiliations } from '@/hooks/use-ror-affiliations';
import { withBasePath } from '@/lib/base-path';
import { inferContributorTypeFromRoles, normaliseContributorRoleLabel } from '@/lib/contributors';
import { buildCsrfHeaders } from '@/lib/csrf-token';
import type { Language, License, ResourceType, Role, TitleType } from '@/types';
import type { AffiliationTag } from '@/types/affiliations';

import AuthorField, {
    type AuthorEntry,
    type AuthorType,
    type InstitutionAuthorEntry,
    type PersonAuthorEntry,
} from './fields/author-field';
import ContributorField, {
    type ContributorEntry,
    type ContributorRoleTag,
    type ContributorType,
    type InstitutionContributorEntry,
    type PersonContributorEntry,
} from './fields/contributor-field';
import InputField from './fields/input-field';
import LicenseField from './fields/license-field';
import { SelectField } from './fields/select-field';
import TitleField from './fields/title-field';
import { resolveInitialLanguageCode } from './utils/language-resolver';

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

interface SerializedAffiliation {
    value: string;
    rorId: string | null;
}

type SerializedAuthor =
    | {
          type: 'person';
          orcid: string | null;
          firstName: string | null;
          lastName: string;
          email: string | null;
          website: string | null;
          isContact: boolean;
          affiliations: SerializedAffiliation[];
          position: number;
      }
    | {
          type: 'institution';
          institutionName: string;
          rorId: string | null;
          affiliations: SerializedAffiliation[];
          position: number;
      };

type SerializedContributor =
    | {
          type: 'person';
          orcid: string | null;
          firstName: string | null;
          lastName: string;
          roles: string[];
          affiliations: SerializedAffiliation[];
          position: number;
      }
    | {
          type: 'institution';
          institutionName: string;
          roles: string[];
          affiliations: SerializedAffiliation[];
          position: number;
      };

const createEmptyPersonAuthor = (): PersonAuthorEntry => ({
    id: crypto.randomUUID(),
    type: 'person',
    orcid: '',
    firstName: '',
    lastName: '',
    email: '',
    website: '',
    isContact: false,
    affiliations: [],
    affiliationsInput: '',
});

const createEmptyInstitutionAuthor = (): InstitutionAuthorEntry => ({
    id: crypto.randomUUID(),
    type: 'institution',
    institutionName: '',
    affiliations: [],
    affiliationsInput: '',
});

const createEmptyAuthor = (type: AuthorType = 'person'): AuthorEntry => {
    return type === 'person' ? createEmptyPersonAuthor() : createEmptyInstitutionAuthor();
};

const createEmptyPersonContributor = (): PersonContributorEntry => ({
    id: crypto.randomUUID(),
    type: 'person',
    roles: [],
    rolesInput: '',
    orcid: '',
    firstName: '',
    lastName: '',
    affiliations: [],
    affiliationsInput: '',
});

const createEmptyInstitutionContributor = (): InstitutionContributorEntry => ({
    id: crypto.randomUUID(),
    type: 'institution',
    roles: [],
    rolesInput: '',
    institutionName: '',
    affiliations: [],
    affiliationsInput: '',
});

const createEmptyContributor = (type: ContributorType = 'person'): ContributorEntry => {
    return type === 'person'
        ? createEmptyPersonContributor()
        : createEmptyInstitutionContributor();
};

/**
 * Serializes affiliations from an author or contributor entry.
 * Deduplicates affiliations based on value and ROR ID combination.
 */
const serializeAffiliations = (
    entry: AuthorEntry | ContributorEntry
): SerializedAffiliation[] => {
    const seen = new Set<string>();

    return entry.affiliations
        .map((affiliation) => {
            const rawValue = affiliation.value.trim();
            const rawRorId = typeof affiliation.rorId === 'string' ? affiliation.rorId.trim() : '';

            if (!rawValue && !rawRorId) {
                return null;
            }

            const value = rawValue || rawRorId;
            const rorId = rawRorId || null;
            const key = `${value}|${rorId ?? ''}`;

            if (seen.has(key)) {
                return null;
            }

            seen.add(key);

            return { value, rorId } satisfies SerializedAffiliation;
        })
        .filter((item): item is SerializedAffiliation => item !== null);
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
          rorId?: string | null;
      });

type BaseInitialContributor = {
    affiliations?: (InitialAffiliationInput | null | undefined)[] | null;
    roles?: (string | null | undefined)[] | Record<string, unknown> | string | null;
};

export type InitialContributor =
    | (BaseInitialContributor & {
          type?: 'person';
          orcid?: string | null;
          firstName?: string | null;
          lastName?: string | null;
      })
    | (BaseInitialContributor & {
          type: 'institution';
          institutionName?: string | null;
      });

const normaliseInitialAffiliations = (
    affiliations?: (InitialAffiliationInput | null | undefined)[] | null,
): AffiliationTag[] => {
    if (!affiliations || !Array.isArray(affiliations)) {
        return [];
    }

    return affiliations
        .map((affiliation) => {
            if (!affiliation || typeof affiliation !== 'object') {
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
                return null;
            }

            return {
                value: rawValue || rawRorId,
                rorId: rawRorId || null,
            } satisfies AffiliationTag;
        })
        .filter((item): item is AffiliationTag => Boolean(item && item.value));
};

const normaliseInitialContributorRoles = (
    roles: BaseInitialContributor['roles'],
): ContributorRoleTag[] => {
    if (!roles) {
        return [];
    }

    const rawRoles = Array.isArray(roles)
        ? roles
        : typeof roles === 'string'
          ? [roles]
          : typeof roles === 'object'
            ? Object.values(roles)
            : [];

    const unique = new Set<string>();

    return rawRoles
        .map((role) =>
            typeof role === 'string' ? normaliseContributorRoleLabel(role) : '',
        )
        .filter((role) => role.length > 0)
        .filter((role) => {
            if (unique.has(role)) {
                return false;
            }
            unique.add(role);
            return true;
        })
        .map((role) => ({ value: role }));
};

const mapInitialContributorToEntry = (
    contributor: InitialContributor,
): ContributorEntry | null => {
    if (!contributor || typeof contributor !== 'object') {
        return null;
    }

    const affiliations = normaliseInitialAffiliations(contributor.affiliations ?? null);
    const affiliationsInput = affiliations.map((item) => item.value).join(', ');
    const roles = normaliseInitialContributorRoles(contributor.roles ?? null);
    const roleLabels = roles.map((role) => role.value);
    const rolesInput = roleLabels.join(', ');
    const resolvedType = inferContributorTypeFromRoles(contributor.type, roleLabels);

    if (resolvedType === 'institution') {
        const base = createEmptyInstitutionContributor();
        const institutionContributor = contributor as BaseInitialContributor & {
            type: 'institution';
            institutionName?: string | null;
        };

        return {
            ...base,
            institutionName:
                typeof institutionContributor.institutionName === 'string'
                    ? institutionContributor.institutionName.trim()
                    : '',
            affiliations,
            affiliationsInput,
            roles,
            rolesInput,
        } satisfies InstitutionContributorEntry;
    }

    const base = createEmptyPersonContributor();
    const personContributor = contributor as BaseInitialContributor & {
        type?: 'person';
        orcid?: string | null;
        firstName?: string | null;
        lastName?: string | null;
    };

    return {
        ...base,
        orcid: typeof personContributor.orcid === 'string' ? personContributor.orcid.trim() : '',
        firstName: typeof personContributor.firstName === 'string' ? personContributor.firstName.trim() : '',
        lastName: typeof personContributor.lastName === 'string' ? personContributor.lastName.trim() : '',
        affiliations,
        affiliationsInput,
        roles,
        rolesInput,
    } satisfies PersonContributorEntry;
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
    contributorPersonRoles?: Role[];
    contributorInstitutionRoles?: Role[];
    authorRoles?: Role[];
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
    initialContributors?: InitialContributor[];
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
    contributorPersonRoles = [],
    contributorInstitutionRoles = [],
    authorRoles = [],
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
    initialContributors = [],
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
    const [contributors, setContributors] = useState<ContributorEntry[]>(() => {
        if (initialContributors.length > 0) {
            const mapped = initialContributors
                .map((contributor) => mapInitialContributorToEntry(contributor))
                .filter((contributor): contributor is ContributorEntry => Boolean(contributor));

            if (mapped.length > 0) {
                return mapped;
            }
        }

        return [createEmptyContributor()];
    });
    const contributorPersonRoleNames = useMemo(
        () => contributorPersonRoles.map((role) => role.name),
        [contributorPersonRoles],
    );
    const contributorInstitutionRoleNames = useMemo(
        () => contributorInstitutionRoles.map((role) => role.name),
        [contributorInstitutionRoles],
    );
    const contributorPersonRoleSet = useMemo(
        () => new Set(contributorPersonRoleNames),
        [contributorPersonRoleNames],
    );
    const contributorInstitutionRoleSet = useMemo(
        () => new Set(contributorInstitutionRoleNames),
        [contributorInstitutionRoleNames],
    );
    const authorRoleNames = useMemo(
        () =>
            authorRoles
                .map((role) => role.name.trim())
                .filter((name): name is string => name.length > 0),
        [authorRoles],
    );
    const authorRoleSummary = useMemo(() => {
        if (authorRoleNames.length === 0) {
            return '';
        }

        if (authorRoleNames.length === 1) {
            return authorRoleNames[0];
        }

        if (authorRoleNames.length === 2) {
            return `${authorRoleNames[0]} and ${authorRoleNames[1]}`;
        }

        const allButLast = authorRoleNames.slice(0, -1).join(', ');
        const last = authorRoleNames[authorRoleNames.length - 1];
        return `${allButLast}, and ${last}`;
    }, [authorRoleNames]);
    const authorRolesDescriptionId =
        authorRoleNames.length > 0 ? 'author-roles-description' : undefined;
    const filterRolesForType = useCallback(
        (roles: ContributorRoleTag[], type: ContributorType): ContributorRoleTag[] => {
            const allowedRoles =
                type === 'institution' ? contributorInstitutionRoleSet : contributorPersonRoleSet;

            return roles.filter((role) => allowedRoles.has(role.value));
        },
        [contributorInstitutionRoleSet, contributorPersonRoleSet],
    );
    const serialiseRoleInput = useCallback((roles: ContributorRoleTag[]): string => {
        return roles.map((role) => role.value).join(', ');
    }, []);
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

    const handleContributorTypeChange = (
        contributorId: string,
        type: ContributorType,
    ) => {
        setContributors((previous) =>
            previous.map((contributor) => {
                if (contributor.id !== contributorId) {
                    return contributor;
                }

                if (contributor.type === type) {
                    return contributor;
                }

                const filteredRoles = filterRolesForType(contributor.roles, type);
                const rolesInput = serialiseRoleInput(filteredRoles);

                if (type === 'person') {
                    return {
                        ...createEmptyPersonContributor(),
                        id: contributor.id,
                        roles: filteredRoles,
                        rolesInput,
                        affiliations: contributor.affiliations,
                        affiliationsInput: contributor.affiliationsInput,
                    } satisfies PersonContributorEntry;
                }

                return {
                    ...createEmptyInstitutionContributor(),
                    id: contributor.id,
                    roles: filteredRoles,
                    rolesInput,
                    affiliations: contributor.affiliations,
                    affiliationsInput: contributor.affiliationsInput,
                } satisfies InstitutionContributorEntry;
            }),
        );
    };

    const handleContributorRolesChange = (
        contributorId: string,
        value: { raw: string; tags: ContributorRoleTag[] },
    ) => {
        setContributors((previous) =>
            previous.map((contributor) => {
                if (contributor.id !== contributorId) {
                    return contributor;
                }

                const allowedRoles =
                    contributor.type === 'institution'
                        ? contributorInstitutionRoleSet
                        : contributorPersonRoleSet;

                const uniqueRoles = Array.from(
                    new Set(
                        value.tags
                            .map((tag) => (typeof tag.value === 'string' ? tag.value.trim() : ''))
                            .filter((role) => role.length > 0 && allowedRoles.has(role)),
                    ),
                );

                const normalisedRoles = uniqueRoles.map((role) => ({ value: role }));

                return {
                    ...contributor,
                    roles: normalisedRoles,
                    rolesInput: serialiseRoleInput(normalisedRoles),
                } satisfies ContributorEntry;
            }),
        );
    };

    const handleContributorPersonChange = (
        contributorId: string,
        field: 'orcid' | 'firstName' | 'lastName',
        value: string,
    ) => {
        setContributors((previous) =>
            previous.map((contributor) => {
                if (contributor.id !== contributorId || contributor.type !== 'person') {
                    return contributor;
                }

                return { ...contributor, [field]: value } satisfies PersonContributorEntry;
            }),
        );
    };

    const handleContributorInstitutionChange = (contributorId: string, value: string) => {
        setContributors((previous) =>
            previous.map((contributor) => {
                if (contributor.id !== contributorId || contributor.type !== 'institution') {
                    return contributor;
                }

                return { ...contributor, institutionName: value } satisfies InstitutionContributorEntry;
            }),
        );
    };

    const handleContributorAffiliationsChange = (
        contributorId: string,
        value: { raw: string; tags: AffiliationTag[] },
    ) => {
        setContributors((previous) =>
            previous.map((contributor) =>
                contributor.id === contributorId
                    ? ({
                          ...contributor,
                          affiliations: value.tags,
                          affiliationsInput: value.raw,
                      } satisfies ContributorEntry)
                    : contributor,
            ),
        );
    };

    const addContributor = () => {
        setContributors((previous) => [...previous, createEmptyContributor()]);
    };

    const removeContributor = (contributorId: string) => {
        setContributors((previous) =>
            previous.length > 1
                ? previous.filter((contributor) => contributor.id !== contributorId)
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

        const serializedAuthors: SerializedAuthor[] = authors.map((author, index) => {
            const affiliations = serializeAffiliations(author);

            if (author.type === 'person') {
                const orcid = author.orcid.trim();
                const firstName = author.firstName.trim();
                const lastName = author.lastName.trim();
                const email = author.email.trim();
                const website = author.website.trim();

                return {
                    type: 'person',
                    orcid: orcid || null,
                    firstName: firstName || null,
                    lastName,
                    email: author.isContact && email ? email : null,
                    website: author.isContact && website ? website : null,
                    isContact: author.isContact,
                    affiliations,
                    position: index,
                } satisfies SerializedAuthor;
            }

            const institutionName = author.institutionName.trim();
            // Get ROR ID from first affiliation that has one
            const rorId = author.affiliations.find((aff) => aff.rorId)?.rorId?.trim() || null;

            return {
                type: 'institution',
                institutionName,
                rorId,
                affiliations,
                position: index,
            } satisfies SerializedAuthor;
        });

        const serializedContributors: SerializedContributor[] = contributors
            .filter((contributor) => {
                // Filter out empty contributors
                if (contributor.type === 'person') {
                    const hasName = contributor.lastName.trim() !== '';
                    const hasRoles = contributor.roles.length > 0;
                    return hasName || hasRoles;
                }
                const hasInstitution = contributor.institutionName.trim() !== '';
                const hasRoles = contributor.roles.length > 0;
                return hasInstitution || hasRoles;
            })
            .map((contributor, index) => {
                const affiliations = serializeAffiliations(contributor);
                const roles = contributor.roles.map((role) => role.value);

                if (contributor.type === 'person') {
                    const orcid = contributor.orcid.trim();
                    const firstName = contributor.firstName.trim();
                    const lastName = contributor.lastName.trim();

                    return {
                        type: 'person',
                        orcid: orcid || null,
                        firstName: firstName || null,
                        lastName,
                        roles,
                        affiliations,
                        position: index,
                    } satisfies SerializedContributor;
                }

                const institutionName = contributor.institutionName.trim();

                return {
                    type: 'institution',
                    institutionName,
                    roles,
                    affiliations,
                    position: index,
                } satisfies SerializedContributor;
            });

        const payload: {
            doi: string | null;
            year: number | null;
            resourceType: number | null;
            version: string | null;
            language: string;
            titles: { title: string; titleType: string }[];
            licenses: string[];
            authors: SerializedAuthor[];
            contributors: SerializedContributor[];
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
            authors: serializedAuthors,
            contributors: serializedContributors,
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
                defaultValue={['resource-info', 'authors', 'licenses-rights', 'contributors']}
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
                        {authorRoleNames.length > 0 && (
                            <p
                                id={authorRolesDescriptionId}
                                className="mb-4 text-sm text-muted-foreground"
                                data-testid="author-roles-availability"
                            >
                                {`The available author ${
                                    authorRoleNames.length === 1 ? 'role is' : 'roles are'
                                } ${authorRoleSummary}.`}
                            </p>
                        )}
                        <div
                            className="space-y-6"
                            role="group"
                            aria-describedby={authorRolesDescriptionId}
                            data-testid="author-entries-group"
                        >
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
                <AccordionItem value="contributors">
                    <AccordionTrigger>Contributors</AccordionTrigger>
                    <AccordionContent>
                        <div className="space-y-6">
                            {contributors.map((contributor, index) => (
                                <ContributorField
                                    key={contributor.id}
                                    contributor={contributor}
                                    index={index}
                                    onTypeChange={(type) =>
                                        handleContributorTypeChange(contributor.id, type)
                                    }
                                    onRolesChange={(value) =>
                                        handleContributorRolesChange(contributor.id, value)
                                    }
                                    onPersonFieldChange={(field, value) =>
                                        handleContributorPersonChange(
                                            contributor.id,
                                            field,
                                            value,
                                        )
                                    }
                                    onInstitutionNameChange={(value) =>
                                        handleContributorInstitutionChange(
                                            contributor.id,
                                            value,
                                        )
                                    }
                                    onAffiliationsChange={(value) =>
                                        handleContributorAffiliationsChange(
                                            contributor.id,
                                            value,
                                        )
                                    }
                                    onRemoveContributor={() => removeContributor(contributor.id)}
                                    canRemove={contributors.length > 1}
                                    onAddContributor={addContributor}
                                    canAddContributor={index === contributors.length - 1}
                                    affiliationSuggestions={affiliationSuggestions}
                                    personRoleOptions={contributorPersonRoleNames}
                                    institutionRoleOptions={contributorInstitutionRoleNames}
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
