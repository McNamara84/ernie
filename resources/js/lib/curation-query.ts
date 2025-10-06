import { withBasePath } from '@/lib/base-path';

interface ResourceTypeReference {
    id: number;
    name: string;
}

interface ResourceTitleTypeSummary {
    slug: string | null;
}

interface ResourceTitleSummary {
    title: string;
    title_type: ResourceTitleTypeSummary | null;
}

interface ResourceLicenseSummary {
    identifier: string | null;
}

interface ResourceLanguageSummary {
    code: string | null;
}

interface ResourceTypeSummary {
    name: string | null;
}

type AuthorTypeSummary = 'person' | 'institution';

interface ResourceAuthorAffiliationSummary {
    value?: string | null;
    name?: string | null;
    identifier?: string | null;
    rorId?: string | null;
    ror_id?: string | null;
}

interface BaseResourceAuthorSummary {
    type?: AuthorTypeSummary;
    position?: number | string | null;
    affiliations?: (ResourceAuthorAffiliationSummary | null | undefined)[] | null;
}

interface ResourcePersonAuthorSummary extends BaseResourceAuthorSummary {
    type?: 'person';
    orcid?: string | null;
    firstName?: string | null;
    lastName?: string | null;
    email?: string | null;
    website?: string | null;
    isContact?: boolean | string | number | null;
}

interface ResourceInstitutionAuthorSummary extends BaseResourceAuthorSummary {
    type: 'institution';
    institutionName?: string | null;
    rorId?: string | null;
}

type ResourceAuthorSummary = ResourcePersonAuthorSummary | ResourceInstitutionAuthorSummary;

interface NormalisedAuthorAffiliation {
    value: string;
    rorId: string | null;
}

type NormalisedAuthor =
    | ({
          type: 'person';
          orcid: string | null;
          firstName: string | null;
          lastName: string | null;
          email: string | null;
          website: string | null;
          isContact: boolean;
      } & { position: number; affiliations: NormalisedAuthorAffiliation[] })
    | ({
          type: 'institution';
          institutionName: string | null;
          rorId: string | null;
      } & { position: number; affiliations: NormalisedAuthorAffiliation[] });

export interface ResourceForCuration {
    id?: number;
    doi: string | null;
    year: number;
    version: string | null;
    resource_type: ResourceTypeSummary | null;
    language: ResourceLanguageSummary | null;
    titles: ResourceTitleSummary[];
    licenses: ResourceLicenseSummary[];
    authors?: (ResourceAuthorSummary | null | undefined)[] | null;
}

let resourceTypesCache: ResourceTypeReference[] | null = null;

const fetchResourceTypes = async (): Promise<ResourceTypeReference[]> => {
    if (resourceTypesCache) {
        return resourceTypesCache;
    }

    try {
        const response = await fetch(withBasePath('/api/v1/resource-types/ernie'));

        if (!response.ok) {
            return [];
        }

        const data = (await response.json()) as ResourceTypeReference[];
        resourceTypesCache = Array.isArray(data) ? data : [];

        return resourceTypesCache;
    } catch (error) {
        console.error('Failed to fetch resource types for curation.', error);
        return [];
    }
};

const mapResourceTypeNameToId = async (name: string | null): Promise<string | null> => {
    if (!name) {
        return null;
    }

    const trimmed = name.trim();

    if (!trimmed) {
        return null;
    }

    const resourceTypes = await fetchResourceTypes();
    const normalised = trimmed.toLowerCase();

    const match = resourceTypes.find((type) => type.name.trim().toLowerCase() === normalised);

    if (!match) {
        return null;
    }

    return String(match.id);
};

const normaliseTitles = (titles: ResourceTitleSummary[]): { title: string; titleType: string }[] => {
    const cleaned = titles
        .map((entry) => {
            const text = entry.title?.trim();

            if (!text) {
                return null;
            }

            const slug = entry.title_type?.slug?.trim() ?? '';

            return {
                title: text,
                titleType: slug,
            };
        })
        .filter(Boolean) as { title: string; titleType: string }[];

    if (cleaned.length === 0) {
        return [];
    }

    let hasMainTitle = cleaned.some((entry) => entry.titleType === 'main-title');

    const resolved = cleaned.map((entry) => {
        if (entry.titleType === 'main-title') {
            hasMainTitle = true;
            return entry;
        }

        if (!entry.titleType) {
            if (!hasMainTitle) {
                hasMainTitle = true;
                return { ...entry, titleType: 'main-title' };
            }

            return { ...entry, titleType: 'alternative-title' };
        }

        return entry;
    });

    if (!hasMainTitle && resolved.length > 0) {
        const [first, ...rest] = resolved;
        return [{ ...first, titleType: 'main-title' }, ...rest];
    }

    const mainTitles = resolved.filter((entry) => entry.titleType === 'main-title');
    const secondaryTitles = resolved.filter((entry) => entry.titleType !== 'main-title');

    return [...mainTitles, ...secondaryTitles];
};

const normaliseLicenses = (licenses: ResourceLicenseSummary[]): string[] =>
    licenses
        .map((license) => license.identifier?.trim())
        .filter((identifier): identifier is string => Boolean(identifier));

const toTrimmedStringOrNull = (value: unknown): string | null => {
    if (typeof value !== 'string') {
        return null;
    }

    const trimmed = value.trim();

    return trimmed ? trimmed : null;
};

const toNonNegativeInteger = (value: unknown): number => {
    if (typeof value === 'number' && Number.isFinite(value)) {
        return Math.max(0, Math.trunc(value));
    }

    if (typeof value === 'string') {
        const trimmed = value.trim();

        if (!trimmed) {
            return 0;
        }

        const parsed = Number.parseInt(trimmed, 10);

        if (Number.isFinite(parsed)) {
            return Math.max(0, parsed);
        }
    }

    return 0;
};

const normaliseAuthorAffiliations = (
    affiliations?: (ResourceAuthorAffiliationSummary | null | undefined)[] | null,
): NormalisedAuthorAffiliation[] => {
    if (!Array.isArray(affiliations) || affiliations.length === 0) {
        return [];
    }

    const seen = new Set<string>();
    const results: NormalisedAuthorAffiliation[] = [];

    affiliations.forEach((affiliation) => {
        if (!affiliation || typeof affiliation !== 'object') {
            return;
        }

        const valueCandidate =
            typeof affiliation.value === 'string'
                ? affiliation.value
                : typeof affiliation.name === 'string'
                  ? affiliation.name
                  : '';
        const rorCandidate =
            typeof affiliation.rorId === 'string'
                ? affiliation.rorId
                : typeof affiliation.ror_id === 'string'
                  ? affiliation.ror_id
                  : typeof affiliation.identifier === 'string'
                    ? affiliation.identifier
                    : '';

        const value = valueCandidate.trim();
        const rorId = rorCandidate.trim();

        if (!value && !rorId) {
            return;
        }

        const finalValue = value || rorId;
        const finalRorId = rorId || null;
        const key = `${finalValue}|${finalRorId ?? ''}`;

        if (seen.has(key)) {
            return;
        }

        seen.add(key);
        results.push({ value: finalValue, rorId: finalRorId });
    });

    return results;
};

const normaliseAuthors = (
    authors?: (ResourceAuthorSummary | null | undefined)[] | null,
): NormalisedAuthor[] => {
    if (!Array.isArray(authors) || authors.length === 0) {
        return [];
    }

    return authors
        .map((author) => {
            if (!author || typeof author !== 'object') {
                return null;
            }

            const position = toNonNegativeInteger(author.position);
            const affiliations = normaliseAuthorAffiliations(author.affiliations ?? null);
            const type: AuthorTypeSummary = author.type === 'institution' ? 'institution' : 'person';

            if (type === 'institution') {
                return {
                    type,
                    position,
                    institutionName: toTrimmedStringOrNull(
                        (author as ResourceInstitutionAuthorSummary).institutionName,
                    ),
                    rorId: toTrimmedStringOrNull((author as ResourceInstitutionAuthorSummary).rorId),
                    affiliations,
                } satisfies NormalisedAuthor;
            }

            const { isContact } = author as ResourcePersonAuthorSummary;
            const contactFlag =
                isContact === true ||
                isContact === 'true' ||
                isContact === 1 ||
                isContact === '1';

            return {
                type,
                position,
                orcid: toTrimmedStringOrNull((author as ResourcePersonAuthorSummary).orcid),
                firstName: toTrimmedStringOrNull((author as ResourcePersonAuthorSummary).firstName),
                lastName: toTrimmedStringOrNull((author as ResourcePersonAuthorSummary).lastName),
                email: toTrimmedStringOrNull((author as ResourcePersonAuthorSummary).email),
                website: toTrimmedStringOrNull((author as ResourcePersonAuthorSummary).website),
                isContact: contactFlag,
                affiliations,
            } satisfies NormalisedAuthor;
        })
        .filter((author): author is NormalisedAuthor => Boolean(author))
        .sort((left, right) => left.position - right.position);
};

export const buildCurationQueryFromResource = async (
    resource: ResourceForCuration,
): Promise<Record<string, string>> => {
    const query: Record<string, string> = {};

    if (Number.isInteger(resource.id)) {
        query.resourceId = String(resource.id);
    }

    const doi = resource.doi?.trim();

    if (doi) {
        query.doi = doi;
    }

    if (Number.isFinite(resource.year)) {
        query.year = String(resource.year);
    }

    const version = resource.version?.trim();

    if (version) {
        query.version = version;
    }

    const languageCode = resource.language?.code?.trim();

    if (languageCode) {
        query.language = languageCode;
    }

    const resourceTypeName = resource.resource_type?.name;
    const resourceTypeId = await mapResourceTypeNameToId(resourceTypeName ?? null);

    if (resourceTypeId) {
        query.resourceType = resourceTypeId;
    }

    const titles = normaliseTitles(resource.titles);
    titles.forEach((title, index) => {
        query[`titles[${index}][title]`] = title.title;
        query[`titles[${index}][titleType]`] = title.titleType;
    });

    const licenses = normaliseLicenses(resource.licenses);
    licenses.forEach((identifier, index) => {
        query[`licenses[${index}]`] = identifier;
    });

    const authors = normaliseAuthors(resource.authors ?? null);
    authors.forEach((author, index) => {
        const prefix = `authors[${index}]`;

        query[`${prefix}[type]`] = author.type;
        query[`${prefix}[position]`] = String(author.position);

        if (author.type === 'person') {
            if (author.orcid) {
                query[`${prefix}[orcid]`] = author.orcid;
            }

            if (author.firstName) {
                query[`${prefix}[firstName]`] = author.firstName;
            }

            if (author.lastName) {
                query[`${prefix}[lastName]`] = author.lastName;
            }

            if (author.email) {
                query[`${prefix}[email]`] = author.email;
            }

            if (author.website) {
                query[`${prefix}[website]`] = author.website;
            }

            if (author.isContact) {
                query[`${prefix}[isContact]`] = 'true';
            }
        }

        if (author.type === 'institution') {
            if (author.institutionName) {
                query[`${prefix}[institutionName]`] = author.institutionName;
            }

            if (author.rorId) {
                query[`${prefix}[rorId]`] = author.rorId;
            }
        }

        author.affiliations.forEach((affiliation, affiliationIndex) => {
            const affiliationPrefix = `${prefix}[affiliations][${affiliationIndex}]`;

            query[`${affiliationPrefix}[value]`] = affiliation.value;

            if (affiliation.rorId) {
                query[`${affiliationPrefix}[rorId]`] = affiliation.rorId;
            }
        });
    });

    return query;
};

export const __testing = {
    resetResourceTypeCache: () => {
        resourceTypesCache = null;
    },
};
