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

export interface ResourceForCuration {
    doi: string | null;
    year: number;
    version: string | null;
    resource_type: ResourceTypeSummary | null;
    language: ResourceLanguageSummary | null;
    titles: ResourceTitleSummary[];
    licenses: ResourceLicenseSummary[];
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

export const buildCurationQueryFromResource = async (
    resource: ResourceForCuration,
): Promise<Record<string, string>> => {
    const query: Record<string, string> = {};

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

    return query;
};

export const __testing = {
    resetResourceTypeCache: () => {
        resourceTypesCache = null;
    },
};
