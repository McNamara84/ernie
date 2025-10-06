import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { curation as curationRoute } from '@/routes';
import { Head, router } from '@inertiajs/react';
import { useState, useRef, useCallback, useEffect, useMemo } from 'react';
import type { ReactNode } from 'react';
import { ArrowDown, ArrowUp, ArrowUpDown, ArrowUpRight } from 'lucide-react';
import axios, { isAxiosError } from 'axios';

interface Dataset {
    id?: number;
    identifier?: string;
    resourcetypegeneral?: string;
    curator?: string;
    title?: string;
    titleType?: string;
    title_type?: string;
    titles?: { title?: string | null; titleType?: string | null; title_type?: string | null }[];
    licenses?: (string | { identifier?: string | null; rightsIdentifier?: string | null; license?: string | null })[];
    license?: string;
    version?: string;
    language?: string;
    resourcetype?: string | number;
    resourcetypeid?: string | number;
    resource_type_id?: string | number;
    resourceTypeId?: string | number;
    created_at?: string;
    updated_at?: string;
    publicstatus?: string;
    publisher?: string;
    publicationyear?: number;
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    [key: string]: any;
}

interface PaginationInfo {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number;
    to: number;
    has_more: boolean;
}

interface DatasetsProps {
    datasets: Dataset[];
    pagination: PaginationInfo;
    error?: string;
    debug?: Record<string, unknown>;
    sort: SortState;
}

type SortKey = 'id' | 'created_at' | 'updated_at';
type SortDirection = 'asc' | 'desc';

interface SortOption {
    key: SortKey;
    label: string;
    description: string;
}

interface SortState {
    key: SortKey;
    direction: SortDirection;
}

interface DatasetColumn {
    key: string;
    label: ReactNode;
    widthClass: string;
    cellClassName?: string;
    render?: (dataset: Dataset) => React.ReactNode;
    sortOptions?: SortOption[];
    sortGroupLabel?: string;
}

const TITLE_COLUMN_WIDTH_CLASSES = 'min-w-[24rem] lg:min-w-[36rem] xl:min-w-[44rem]';
const TITLE_COLUMN_CELL_CLASSES = 'whitespace-normal break-words text-gray-900 dark:text-gray-100 leading-relaxed align-top';
const DATE_COLUMN_CONTAINER_CLASSES = 'flex flex-col gap-1 text-left text-gray-600 dark:text-gray-300';
const DATE_COLUMN_HEADER_LABEL = (
    <span className="flex flex-col leading-tight normal-case">
        <span>Created</span>
        <span>Updated</span>
    </span>
);
const IDENTIFIER_COLUMN_HEADER_LABEL = (
    <span className="flex flex-col leading-tight normal-case">
        <span>ID</span>
        <span>Identifier (DOI)</span>
    </span>
);
const ACTIONS_COLUMN_WIDTH_CLASSES = 'w-24 min-w-[6rem]';

const DEFAULT_SORT: SortState = { key: 'updated_at', direction: 'desc' };
const SORT_PREFERENCE_STORAGE_KEY = 'old-datasets.sort-preference';
const DEFAULT_DIRECTION_BY_KEY: Record<SortKey, SortDirection> = {
    id: 'asc',
    created_at: 'desc',
    updated_at: 'desc',
};

const describeDirection = (direction: SortDirection): string =>
    direction === 'asc' ? 'ascending' : 'descending';

const normaliseSortValue = (value: number | null | undefined, direction: SortDirection): number => {
    if (value === null || value === undefined || Number.isNaN(value)) {
        return direction === 'asc' ? Number.POSITIVE_INFINITY : Number.NEGATIVE_INFINITY;
    }

    return value;
};

const getSortValue = (dataset: Dataset, key: SortKey): number | null => {
    if (key === 'id') {
        if (typeof dataset.id === 'number' && Number.isFinite(dataset.id)) {
            return dataset.id;
        }

        if (typeof dataset.id === 'string') {
            const parsed = Number(dataset.id);
            return Number.isFinite(parsed) ? parsed : null;
        }

        return null;
    }

    const rawValue = key === 'created_at' ? dataset.created_at : dataset.updated_at;

    if (typeof rawValue !== 'string') {
        return null;
    }

    const timestamp = Date.parse(rawValue);
    return Number.isNaN(timestamp) ? null : timestamp;
};

const sortDatasets = (datasets: Dataset[], sortState: SortState): Dataset[] => {
    if (!Array.isArray(datasets) || datasets.length === 0) {
        return datasets;
    }

    const sorted = [...datasets].sort((left, right) => {
        const leftRaw = getSortValue(left, sortState.key);
        const rightRaw = getSortValue(right, sortState.key);

        const leftValue = normaliseSortValue(leftRaw, sortState.direction);
        const rightValue = normaliseSortValue(rightRaw, sortState.direction);

        if (leftValue === rightValue) {
            const leftFallback = normaliseSortValue(getSortValue(left, 'id'), 'asc');
            const rightFallback = normaliseSortValue(getSortValue(right, 'id'), 'asc');

            if (leftFallback === rightFallback) {
                return 0;
            }

            return leftFallback < rightFallback ? -1 : 1;
        }

        if (leftValue < rightValue) {
            return sortState.direction === 'asc' ? -1 : 1;
        }

        if (leftValue > rightValue) {
            return sortState.direction === 'asc' ? 1 : -1;
        }

        return 0;
    });

    return sorted;
};

const isSortState = (value: unknown): value is SortState => {
    if (!value || typeof value !== 'object') {
        return false;
    }

    const maybeState = value as { key?: unknown; direction?: unknown };

    return (
        maybeState.key === 'id' ||
        maybeState.key === 'created_at' ||
        maybeState.key === 'updated_at'
    ) && (maybeState.direction === 'asc' || maybeState.direction === 'desc');
};

const resolveDisplayDirection = (option: SortOption, sortState: SortState): SortDirection =>
    sortState.key === option.key ? sortState.direction : DEFAULT_DIRECTION_BY_KEY[option.key];

const determineNextDirection = (currentState: SortState, targetKey: SortKey): SortDirection => {
    if (currentState.key !== targetKey) {
        return DEFAULT_DIRECTION_BY_KEY[targetKey];
    }

    return currentState.direction === 'asc' ? 'desc' : 'asc';
};

const buildSortButtonLabel = (option: SortOption, sortState: SortState): string => {
    const currentDirection = resolveDisplayDirection(option, sortState);
    const nextDirection = determineNextDirection(sortState, option.key);

    if (sortState.key === option.key) {
        return `${option.description}. Currently sorted ${describeDirection(currentDirection)}. Activate to switch to ${describeDirection(nextDirection)} order.`;
    }

    return `${option.description}. Activate to sort ${describeDirection(currentDirection)}.`;
};

const SortDirectionIndicator = ({
    isActive,
    direction,
}: {
    isActive: boolean;
    direction: SortDirection;
}) => {
    if (!isActive) {
        return <ArrowUpDown aria-hidden="true" className="size-3.5" />;
    }

    if (direction === 'asc') {
        return <ArrowUp aria-hidden="true" className="size-3.5" />;
    }

    return <ArrowDown aria-hidden="true" className="size-3.5" />;
};

type DateType = 'Created' | 'Updated';
type DateDetails = { label: string; iso: string | null };

interface NormalisedTitle {
    title: string;
    titleType: string;
}

const NORMALISED_MAIN_TITLE = 'main-title';

const normaliseTitleType = (value: string | null | undefined): string => {
    if (!value) {
        return NORMALISED_MAIN_TITLE;
    }

    const trimmed = value.trim();

    if (!trimmed) {
        return NORMALISED_MAIN_TITLE;
    }

    return trimmed
        .toLowerCase()
        .replace(/[^a-z0-9\s-]/g, '')
        .replace(/\s+/g, '-')
        .replace(/-+/g, '-');
};

/**
 * 64-bit FNV-1a constants sourced from the Fowler–Noll–Vo hash specification.
 * See: http://www.isthe.com/chongo/tech/comp/fnv/
 */
const FNV_OFFSET_BASIS_64 = BigInt('0xcbf29ce484222325');
const FNV_PRIME_64 = BigInt('0x100000001b3');
const FNV_64_MASK = BigInt('0xffffffffffffffff');
const KEY_SUFFIX_MAX_LENGTH = 48;

const createStableHash = (value: string): string => {
    let hash = FNV_OFFSET_BASIS_64;

    for (let index = 0; index < value.length; index += 1) {
        hash ^= BigInt(value.charCodeAt(index));
        hash = (hash * FNV_PRIME_64) & FNV_64_MASK;
    }

    return hash.toString(36);
};

const sanitiseKeySuffix = (value: string): string =>
    value
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/-+/g, '-')
        .replace(/^-|-$/g, '');

const buildDatasetKey = (signature: string): string => {
    const hash = createStableHash(signature);
    const truncatedSignature = signature.slice(0, KEY_SUFFIX_MAX_LENGTH);
    const suffix = sanitiseKeySuffix(truncatedSignature);

    if (suffix) {
        return `dataset-${hash}-${suffix}`;
    }

    return `dataset-${hash}`;
};

const serialiseDeterministically = (value: unknown): string => {
    if (value === null || value === undefined) {
        return '';
    }

    if (typeof value === 'string') {
        const trimmed = value.trim().toLowerCase();
        return trimmed;
    }

    if (typeof value === 'number' || typeof value === 'boolean') {
        return String(value);
    }

    if (Array.isArray(value)) {
        return `[${value.map((entry) => serialiseDeterministically(entry)).join('|')}]`;
    }

    if (typeof value === 'object') {
        const entries = Object.entries(value as Record<string, unknown>).sort(([left], [right]) =>
            left.localeCompare(right),
        );

        return `{${entries
            .map(([key, entryValue]) => `${key.toLowerCase()}:${serialiseDeterministically(entryValue)}`)
            .join('|')}}`;
    }

    return '';
};

const deriveDatasetRowKey = (dataset: Dataset): string => {
    if (dataset.id !== undefined && dataset.id !== null) {
        return `id-${dataset.id}`;
    }

    if (dataset.identifier) {
        return `doi-${dataset.identifier}`;
    }

    const metadataSegments: string[] = [];

    const appendSegment = (value: unknown) => {
        if (value === null || value === undefined) {
            return;
        }

        if (typeof value === 'string') {
            const trimmed = value.trim();
            if (trimmed) {
                metadataSegments.push(trimmed.toLowerCase());
            }
            return;
        }

        if (typeof value === 'number') {
            metadataSegments.push(String(value));
        }
    };

    appendSegment(dataset.title);
    appendSegment(dataset.publicationyear);
    appendSegment(dataset.created_at);
    appendSegment(dataset.updated_at);
    appendSegment(dataset.curator);
    appendSegment(dataset.publisher);
    appendSegment(dataset.language);
    appendSegment(getResourceTypeIdentifier(dataset));

    const normalisedTitles = normaliseTitles(dataset);
    if (normalisedTitles.length > 0) {
        metadataSegments.push(JSON.stringify(normalisedTitles));
    }

    const normalisedLicenses = normaliseLicenses(dataset);
    if (normalisedLicenses.length > 0) {
        metadataSegments.push(JSON.stringify(normalisedLicenses));
    }

    if (metadataSegments.length === 0) {
        return buildDatasetKey(serialiseDeterministically(dataset));
    }

    return buildDatasetKey(metadataSegments.join('|'));
};

const normaliseTitles = (dataset: Dataset): NormalisedTitle[] => {
    const titles: NormalisedTitle[] = [];

    if (Array.isArray(dataset.titles)) {
        dataset.titles.forEach((raw) => {
            if (typeof raw === 'string') {
                const text = raw.trim();
                if (text) {
                    titles.push({ title: text, titleType: NORMALISED_MAIN_TITLE });
                }
                return;
            }

            if (!raw) return;

            const value = raw.title ?? null;
            const titleText = typeof value === 'string' ? value.trim() : '';

            if (!titleText) return;

            const typeValue = normaliseTitleType(raw.titleType ?? raw.title_type ?? null);
            titles.push({ title: titleText, titleType: typeValue });
        });
    }

    const fallbackTitle = typeof dataset.title === 'string' ? dataset.title.trim() : '';

    if (fallbackTitle) {
        const fallbackType = normaliseTitleType(dataset.titleType ?? dataset.title_type ?? null);
        titles.push({ title: fallbackTitle, titleType: fallbackType });
    }

    const mainTitles = titles.filter((entry) => entry.titleType === NORMALISED_MAIN_TITLE);
    const secondaryTitles = titles.filter((entry) => entry.titleType !== NORMALISED_MAIN_TITLE);

    return [...mainTitles, ...secondaryTitles];
};

const normaliseLicenses = (dataset: Dataset): string[] => {
    const licenses: string[] = [];

    const appendLicense = (value: unknown) => {
        if (typeof value === 'string') {
            const trimmed = value.trim();
            if (trimmed) {
                licenses.push(trimmed);
            }
            return;
        }

        if (typeof value === 'object' && value !== null) {
            const candidate =
                'identifier' in value
                    ? value.identifier
                    : 'rightsIdentifier' in value
                        ? value.rightsIdentifier
                        : 'license' in value
                            ? value.license
                            : null;

            if (typeof candidate === 'string') {
                const trimmed = candidate.trim();
                if (trimmed) {
                    licenses.push(trimmed);
                }
            }
        }
    };

    if (Array.isArray(dataset.licenses)) {
        dataset.licenses.forEach(appendLicense);
    }

    appendLicense(dataset.license ?? null);

    return licenses;
};

/**
 * Returns the numeric resource type identifier for a dataset when available.
 *
 * The backend expects this identifier to be numeric. The helper therefore
 * accepts string and number inputs but intentionally filters out values that contain
 * non-digit characters. Only purely numeric strings (for example, "123") are forwarded,
 * while mixed alphanumeric values such as "type123" or "12abc" are rejected so the
 * curation form never receives invalid identifiers.
 */
const getResourceTypeIdentifier = (dataset: Dataset): string | null => {
    const candidates = [
        dataset.resourceTypeId,
        dataset.resource_type_id,
        dataset.resourcetypeid,
        dataset.resourcetype,
    ];

    for (const candidate of candidates) {
        if (candidate === null || candidate === undefined) {
            continue;
        }

        if (typeof candidate === 'number') {
            return String(candidate);
        }

        if (typeof candidate === 'string') {
            const trimmed = candidate.trim();
            if (!trimmed) continue;
            if (/^\d+$/.test(trimmed)) {
                return trimmed;
            }
        }
    }

    return null;
};

// Cache for resource types and licenses to avoid repeated API calls
let resourceTypesCache: { id: number; name: string; slug: string }[] | null = null;
let licensesCache: { id: number; identifier: string; name: string }[] | null = null;

/**
 * Fetch and cache resource types from ERNIE API.
 * 
 * This function retrieves the list of resource types available in ERNIE and caches
 * the result to avoid repeated API calls. The cache persists for the lifetime of
 * the page session.
 * 
 * @returns {Promise<Array<{id: number, name: string, slug: string}>>} Array of resource types with their IDs, names, and slugs
 * @throws Returns empty array if the API call fails
 * 
 * @example
 * const types = await getResourceTypes();
 * // [{id: 1, name: 'Dataset', slug: 'dataset'}, ...]
 */
const getResourceTypes = async (): Promise<{ id: number; name: string; slug: string }[]> => {
    if (resourceTypesCache) {
        return resourceTypesCache;
    }

    try {
        const response = await fetch('/api/v1/resource-types/ernie');
        if (!response.ok) return [];
        resourceTypesCache = await response.json();
        return resourceTypesCache || [];
    } catch (error) {
        console.error('Error fetching resource types:', error);
        return [];
    }
};

/**
 * Fetch and cache licenses from ERNIE API.
 * 
 * This function retrieves the list of licenses available in ERNIE and caches
 * the result to avoid repeated API calls. The cache persists for the lifetime of
 * the page session.
 * 
 * @returns {Promise<Array<{id: number, identifier: string, name: string}>>} Array of licenses with their IDs, identifiers, and names
 * @throws Returns empty array if the API call fails
 * 
 * @example
 * const licenses = await getLicenses();
 * // [{id: 1, identifier: 'CC-BY-4.0', name: 'Creative Commons Attribution 4.0'}, ...]
 */
const getLicenses = async (): Promise<{ id: number; identifier: string; name: string }[]> => {
    if (licensesCache) {
        return licensesCache;
    }

    try {
        const response = await fetch('/api/v1/licenses/ernie');
        if (!response.ok) return [];
        licensesCache = await response.json();
        return licensesCache || [];
    } catch (error) {
        console.error('Error fetching licenses:', error);
        return [];
    }
};

/**
 * Map old database resource type general strings to ERNIE resource type IDs.
 * The mapping is based on the resource type names in ERNIE's resource_types table.
 */
const mapResourceTypeToId = async (resourceTypeGeneral?: string): Promise<string | null> => {
    if (!resourceTypeGeneral) return null;

    // Normalize the input
    const normalized = resourceTypeGeneral.trim();
    if (!normalized) return null;

    try {
        const resourceTypes = await getResourceTypes();

        // Try to find a matching resource type by name (case-insensitive)
        const match = resourceTypes.find(
            (type) => type.name.toLowerCase() === normalized.toLowerCase()
        );

        if (match) {
            return String(match.id);
        }

        // Try to match by slug for some special cases
        const slugMatch = resourceTypes.find(
            (type) => type.slug === normalized.toLowerCase().replace(/\s+/g, '-')
        );

        if (slugMatch) {
            return String(slugMatch.id);
        }

        return null;
    } catch (error) {
        console.error('Error mapping resource type:', error);
        return null;
    }
};

/**
 * Map old database license names to ERNIE license identifiers.
 * This is a best-effort mapping based on common license name patterns.
 */
const mapLicenseToIdentifier = async (licenseName: string): Promise<string | null> => {
    if (!licenseName) return null;

    const normalized = licenseName.trim();
    if (!normalized) return null;

    try {
        const licenses = await getLicenses();

        // First, try exact match on identifier (e.g., "CC-BY-4.0")
        const exactMatch = licenses.find(
            (lic) => lic.identifier.toLowerCase() === normalized.toLowerCase()
        );
        if (exactMatch) {
            return exactMatch.identifier;
        }

        // Try to match by name
        const nameMatch = licenses.find(
            (lic) => lic.name.toLowerCase() === normalized.toLowerCase()
        );
        if (nameMatch) {
            return nameMatch.identifier;
        }

        // Try partial matching for common CC licenses
        const ccMatch = normalized.match(/CC[\s-]*(BY|BY-SA|BY-NC|BY-ND|BY-NC-SA|BY-NC-ND)[\s-]*(\d\.\d)?/i);
        if (ccMatch) {
            const licenseType = ccMatch[1].toUpperCase();
            const version = ccMatch[2] || '4.0';
            const ccIdentifier = `CC-${licenseType}-${version}`;
            
            const found = licenses.find(
                (lic) => lic.identifier.toLowerCase() === ccIdentifier.toLowerCase()
            );
            if (found) {
                return found.identifier;
            }
        }

        return null;
    } catch (error) {
        console.error('Error mapping license:', error);
        return null;
    }
};

const buildCurationQuery = async (dataset: Dataset): Promise<Record<string, string>> => {
    const query: Record<string, string> = {};

    if (dataset.identifier) {
        query.doi = dataset.identifier;
    }

    if (dataset.publicationyear !== undefined && dataset.publicationyear !== null) {
        query.year = String(dataset.publicationyear);
    }

    if (dataset.version) {
        query.version = dataset.version;
    }

    if (dataset.language) {
        query.language = dataset.language;
    }

    // Map resource type from old DB string to ERNIE ID
    if (dataset.resourcetypegeneral) {
        const resourceTypeId = await mapResourceTypeToId(dataset.resourcetypegeneral);
        if (resourceTypeId) {
            query.resourceType = resourceTypeId;
        }
    }

    const titles = normaliseTitles(dataset);
    titles.forEach((title, index) => {
        query[`titles[${index}][title]`] = title.title;
        query[`titles[${index}][titleType]`] = title.titleType;
    });

    // Map licenses from old DB to ERNIE identifiers
    const licenses = normaliseLicenses(dataset);
    const mappedLicenses = await Promise.all(
        licenses.map(async (license) => {
            const identifier = await mapLicenseToIdentifier(license);
            return identifier || license; // Fallback to original if no mapping found
        })
    );
    
    mappedLicenses.forEach((license, index) => {
        query[`licenses[${index}]`] = license;
    });

    // Load authors from old database
    if (dataset.id) {
        try {
            const response = await axios.get(`/old-datasets/${dataset.id}/authors`);
            const authors = response.data.authors || [];
            
            authors.forEach((author: { 
                givenName: string | null; 
                familyName: string | null; 
                name: string; 
                affiliations: Array<{ value: string; rorId: string | null }>;
                isContact: boolean;
                email: string | null;
                website: string | null;
                orcid: string | null;
                orcidType: string | null;
            }, index: number) => {
                // Use firstName/lastName as expected by the form
                if (author.givenName) {
                    query[`authors[${index}][firstName]`] = author.givenName;
                }
                if (author.familyName) {
                    query[`authors[${index}][lastName]`] = author.familyName;
                }
                // If no firstName/lastName, use the full name
                if (!author.givenName && !author.familyName && author.name) {
                    query[`authors[${index}][lastName]`] = author.name;
                }
                
                // Add ORCID if present
                if (author.orcid) {
                    query[`authors[${index}][orcid]`] = author.orcid;
                }
                
                // Pass affiliations as structured array
                if (author.affiliations && Array.isArray(author.affiliations)) {
                    author.affiliations.forEach((affiliation, affIndex) => {
                        query[`authors[${index}][affiliations][${affIndex}][value]`] = affiliation.value;
                        if (affiliation.rorId) {
                            query[`authors[${index}][affiliations][${affIndex}][rorId]`] = affiliation.rorId;
                        }
                    });
                }
                
                // Add contact person information
                if (author.isContact) {
                    query[`authors[${index}][isContact]`] = 'true';
                    
                    if (author.email) {
                        query[`authors[${index}][email]`] = author.email;
                    }
                    
                    if (author.website) {
                        query[`authors[${index}][website]`] = author.website;
                    }
                }
            });
        } catch (error) {
            // Surface structured error information to aid diagnosis
            if (isAxiosError(error) && error.response?.data) {
                const errorData = error.response.data as { error?: string; debug?: unknown };
                console.error('Error loading authors for dataset:', {
                    message: errorData.error || error.message,
                    debug: errorData.debug,
                    status: error.response.status,
                });
            } else {
                console.error('Error loading authors for dataset:', error);
            }
            // Continue without authors if loading fails
        }
    }

    return query;
};

const renderDateContent = (details: DateDetails): ReactNode => {
    if (details.iso) {
        return (
            <time dateTime={details.iso} className="font-medium">
                {details.label}
            </time>
        );
    }

    return <span className="text-gray-600 dark:text-gray-300">{details.label}</span>;
};

const describeDate = (
    label: string,
    iso: string | null,
    rawValue: string | undefined,
    dateType: DateType,
): string | null => {
    if (iso) {
        return `${dateType} on ${label}`;
    }

    if (!rawValue) {
        return `${dateType} date not available`;
    }

    if (label === 'Invalid date') {
        return `${dateType} date is invalid`;
    }

    return null;
};

export default function OldDatasets({
    datasets: initialDatasets,
    pagination: initialPagination,
    error,
    debug,
    sort: initialSortState,
}: DatasetsProps) {
    const [datasets, setDatasets] = useState<Dataset[]>(initialDatasets);
    const initialSort = initialSortState ?? DEFAULT_SORT;
    const [sortState, setSortState] = useState<SortState>(() => {
        if (typeof window !== 'undefined') {
            try {
                const storedValue = window.localStorage.getItem(SORT_PREFERENCE_STORAGE_KEY);
                if (storedValue) {
                    const parsed = JSON.parse(storedValue) as unknown;
                    if (isSortState(parsed)) {
                        return parsed;
                    }
                }
            } catch {
                // Ignore storage parsing errors and fall back to the default sort
            }
        }

        return initialSort;
    });
    const [pagination, setPagination] = useState<PaginationInfo>(initialPagination);
    const [loading, setLoading] = useState(false);
    const [loadingError, setLoadingError] = useState<string>('');
    const observer = useRef<IntersectionObserver | null>(null);
    const pendingRequestRef = useRef(0);
    const lastRequestRef = useRef<{ page: number; sort: SortState; replace: boolean } | null>(null);
    const [activeSortState, setActiveSortState] = useState<SortState>(initialSort);

    const handleSortChange = useCallback((key: SortKey) => {
        setSortState(previousState => ({
            key,
            direction: determineNextDirection(previousState, key),
        }));
    }, []);

    const sortedDatasets = useMemo(() => sortDatasets(datasets, sortState), [datasets, sortState]);

    const handleOpenInCuration = useCallback(async (dataset: Dataset) => {
        const query = await buildCurationQuery(dataset);
        router.get(curationRoute({ query }).url);
    }, []);

    const logDebugInformation = useCallback((source: string, message: string | undefined, payload?: Record<string, unknown>) => {
        if (!payload || Object.keys(payload).length === 0) {
            return;
        }

        const title = `SUMARIOPMD diagnostics – ${source}`;

        if (typeof console.groupCollapsed === 'function') {
            console.groupCollapsed(title);
        } else {
            console.info(title);
        }

        if (message) {
            console.info('Message:', message);
        }

        console.info('Details:', payload);

        if (typeof console.groupEnd === 'function') {
            console.groupEnd();
        }
    }, []);

    useEffect(() => {
        if (error) {
            logDebugInformation('initial page load', error, debug);
        }
    }, [debug, error, logDebugInformation]);

    useEffect(() => {
        if (typeof window === 'undefined') {
            return;
        }

        try {
            window.localStorage.setItem(SORT_PREFERENCE_STORAGE_KEY, JSON.stringify(sortState));
        } catch {
            // Ignore storage write errors so the UI continues to function without persistence
        }
    }, [sortState]);

    // Preload resource types and licenses on component mount
    useEffect(() => {
        getResourceTypes();
        getLicenses();
    }, []);
    
    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Old Datasets',
            href: '/old-datasets',
        },
    ];

    const fetchDatasetsPage = useCallback(
        async ({ page, sort, replace }: { page: number; sort: SortState; replace: boolean }) => {
            const requestId = pendingRequestRef.current + 1;
            pendingRequestRef.current = requestId;
            lastRequestRef.current = { page, sort, replace };

            setLoading(true);
            setLoadingError('');

            try {
                const response = await axios.get('/old-datasets/load-more', {
                    params: {
                        page,
                        per_page: pagination.per_page,
                        sort_key: sort.key,
                        sort_direction: sort.direction,
                    },
                });

                if (pendingRequestRef.current !== requestId) {
                    return;
                }

                if (response.data.datasets) {
                    setDatasets(prev => (replace ? response.data.datasets : [...prev, ...response.data.datasets]));
                }

                if (response.data.pagination) {
                    setPagination(response.data.pagination);
                }

                const responseSort = response.data.sort as SortState | undefined;
                if (responseSort && isSortState(responseSort)) {
                    setActiveSortState(responseSort);
                } else {
                    setActiveSortState(sort);
                }

                lastRequestRef.current = null;
            } catch (err: unknown) {
                if (pendingRequestRef.current !== requestId) {
                    return;
                }

                const isRefreshing = replace;
                const contextDescription = isRefreshing ? 'refreshing datasets' : 'loading more datasets';

                console.error(`Error ${contextDescription}:`, err);

                if (isAxiosError(err)) {
                    const debugPayload = err.response?.data?.debug as Record<string, unknown> | undefined;
                    const errorMessage = err.message || err.response?.data?.error;
                    logDebugInformation(
                        isRefreshing ? 'sort change request' : 'load more request',
                        errorMessage,
                        debugPayload,
                    );
                }

                setLoadingError(
                    isRefreshing
                        ? 'Failed to refresh datasets. Please try again.'
                        : 'Failed to load more datasets. Please try again.',
                );
            } finally {
                if (pendingRequestRef.current === requestId) {
                    setLoading(false);
                }
            }
        },
        [pagination.per_page, logDebugInformation],
    );

    const loadMoreDatasets = useCallback(() => {
        if (loading || !pagination.has_more) {
            return;
        }

        void fetchDatasetsPage({
            page: pagination.current_page + 1,
            sort: activeSortState,
            replace: false,
        });
    }, [loading, pagination.has_more, pagination.current_page, fetchDatasetsPage, activeSortState]);

    const handleRetry = useCallback(() => {
        const lastRequest = lastRequestRef.current;

        if (lastRequest) {
            void fetchDatasetsPage({
                page: lastRequest.page,
                sort: lastRequest.sort,
                replace: lastRequest.replace,
            });
            return;
        }

        if (pagination.has_more) {
            void fetchDatasetsPage({
                page: pagination.current_page + 1,
                sort: activeSortState,
                replace: false,
            });
        }
    }, [fetchDatasetsPage, pagination.has_more, pagination.current_page, activeSortState]);

    useEffect(() => {
        if (
            sortState.key === activeSortState.key &&
            sortState.direction === activeSortState.direction
        ) {
            return;
        }

        void fetchDatasetsPage({
            page: 1,
            sort: sortState,
            replace: true,
        });
    }, [sortState, activeSortState, fetchDatasetsPage]);

    // Reference to the last dataset element for intersection observer
    const lastDatasetElementRef = useCallback((node: HTMLElement | null) => {
        if (loading) return;
        if (observer.current) observer.current.disconnect();
        observer.current = new IntersectionObserver(entries => {
            if (entries[0].isIntersecting && pagination.has_more) {
                loadMoreDatasets();
            }
        });
        if (node) observer.current.observe(node);
    }, [loading, pagination.has_more, loadMoreDatasets]);

    // Loading skeleton component
    const getDateDetails = (dateString: string | null): DateDetails => {
        if (!dateString) {
            return { label: 'Not available', iso: null };
        }

        try {
            const date = new Date(dateString);
            if (Number.isNaN(date.getTime())) {
                return { label: 'Invalid date', iso: null };
            }

            return {
                label: date.toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit',
                }),
                iso: date.toISOString(),
            };
        } catch {
            return { label: 'Invalid date', iso: null };
        }
    };

    const formatValue = (key: string, value: unknown): string => {
        if (value === null || value === undefined) return 'N/A';

        if (key === 'publicstatus') {
            const statusMap: { [key: string]: string } = {
                'published': 'Published',
                'draft': 'Draft',
                'review': 'Under Review',
                'archived': 'Archived',
            };
            return statusMap[value as string] || String(value);
        }
        
        return String(value);
    };
    const datasetColumns: DatasetColumn[] = [
        {
            key: 'id_identifier',
            label: IDENTIFIER_COLUMN_HEADER_LABEL,
            widthClass: 'min-w-[12rem]',
            cellClassName: 'align-top',
            sortOptions: [
                {
                    key: 'id',
                    label: 'ID',
                    description: 'Sort by the dataset ID from the legacy database',
                },
            ],
            sortGroupLabel: 'Sort options for the identifier column',
            render: (dataset: Dataset) => {
                const hasId = dataset.id !== undefined && dataset.id !== null;
                const idValue = hasId ? String(dataset.id) : 'Not available';
                const hasIdentifier = typeof dataset.identifier === 'string' && dataset.identifier.trim().length > 0;
                const identifierValue = hasIdentifier ? dataset.identifier?.trim() ?? '' : 'Not available';
                const identifierClasses = hasIdentifier
                    ? 'text-sm text-gray-600 dark:text-gray-300 break-all'
                    : 'text-sm text-gray-500 dark:text-gray-300';

                const ariaLabelSegments = [
                    hasId ? `ID ${idValue}` : 'ID not available',
                    hasIdentifier ? `DOI ${identifierValue}` : 'DOI not available',
                ];

                return (
                    <div
                        className="flex flex-col gap-1 text-left"
                        aria-label={ariaLabelSegments.join('. ')}
                    >
                        <span
                            className={hasId
                                ? 'text-sm font-semibold text-gray-900 dark:text-gray-100'
                                : 'text-sm text-gray-500 dark:text-gray-300'}
                        >
                            {idValue}
                        </span>
                        <span className={identifierClasses}>
                            {identifierValue}
                        </span>
                    </div>
                );
            },
        },
        {
            key: 'title',
            label: 'Title',
            widthClass: TITLE_COLUMN_WIDTH_CLASSES,
            cellClassName: TITLE_COLUMN_CELL_CLASSES,
        },
        {
            key: 'resourcetypegeneral',
            label: 'Resource Type',
            widthClass: 'min-w-[10rem]',
            cellClassName: 'whitespace-nowrap',
        },
        {
            key: 'curator',
            label: 'Curator',
            widthClass: 'min-w-[7rem]',
            cellClassName: 'whitespace-nowrap',
        },
        {
            key: 'created_updated',
            label: DATE_COLUMN_HEADER_LABEL,
            widthClass: 'min-w-[9rem]',
            cellClassName: 'whitespace-normal align-top',
            sortOptions: [
                {
                    key: 'created_at',
                    label: 'Created',
                    description: 'Sort by the Created date',
                },
                {
                    key: 'updated_at',
                    label: 'Updated',
                    description: 'Sort by the Updated date',
                },
            ],
            sortGroupLabel: 'Sort options for created and updated dates',
            render: (dataset: Dataset) => {
                const createdDetails = getDateDetails(dataset.created_at ?? null);
                const updatedDetails = getDateDetails(dataset.updated_at ?? null);

                const ariaLabelParts = [
                    describeDate(createdDetails.label, createdDetails.iso, dataset.created_at, 'Created'),
                    describeDate(updatedDetails.label, updatedDetails.iso, dataset.updated_at, 'Updated'),
                ].filter((part): part is string => part !== null);

                const dateColumnAriaLabel = ariaLabelParts.length > 0 ? ariaLabelParts.join('. ') : undefined;

                return (
                    <div
                        className={DATE_COLUMN_CONTAINER_CLASSES}
                        aria-label={dateColumnAriaLabel}
                    >
                        {renderDateContent(createdDetails)}
                        {renderDateContent(updatedDetails)}
                    </div>
                );
            },
        },
        {
            key: 'publicstatus',
            label: 'Publication Status',
            widthClass: 'min-w-[10rem]',
            cellClassName: 'whitespace-nowrap',
        },
    ];

    const LoadingSkeleton = () => (
        <>
            {[...Array(5)].map((_, index) => (
                <tr key={`skeleton-${index}`} className="animate-pulse">
                    {datasetColumns.map((column) => (
                        <td key={column.key} className={`px-6 py-4 ${column.widthClass} ${column.cellClassName ?? ''}`}>
                            {column.key === 'id_identifier' ? (
                                <div className="flex flex-col gap-2">
                                    <div className="h-4 w-10 rounded bg-gray-200 dark:bg-gray-700"></div>
                                    <div className="h-4 w-32 rounded bg-gray-200 dark:bg-gray-700"></div>
                                </div>
                            ) : column.key === 'created_updated' ? (
                                <div className="flex flex-col gap-2">
                                    <div className="h-4 w-28 rounded bg-gray-200 dark:bg-gray-700"></div>
                                    <div className="h-4 w-32 rounded bg-gray-200 dark:bg-gray-700"></div>
                                </div>
                            ) : (
                                <div className="h-4 w-3/4 rounded bg-gray-200 dark:bg-gray-700"></div>
                            )}
                        </td>
                    ))}
                    <td className={`px-6 py-4 ${ACTIONS_COLUMN_WIDTH_CLASSES}`}>
                        <div className="size-9 rounded-full bg-gray-200 dark:bg-gray-700" />
                    </td>
                </tr>
            ))}
        </>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Old Datasets" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <Card>
                    <CardHeader>
                        <CardTitle asChild>
                            <h1 className="text-2xl font-semibold tracking-tight">Old Datasets</h1>
                        </CardTitle>
                        <CardDescription>
                            Overview of legacy resources from the SUMARIOPMD database
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {error ? (
                            <Alert className="mb-4" variant="destructive">
                                <AlertDescription>
                                    {error}
                                </AlertDescription>
                            </Alert>
                        ) : null}

                        {sortedDatasets.length === 0 ? (
                            <div className="text-center py-8 text-muted-foreground">
                                {error ?
                                    "No datasets available. Please check the database connection." :
                                    "No old datasets found."
                                }
                            </div>
                        ) : (
                            <>
                                <div className="mb-4 flex items-center gap-2">
                                    <Badge variant="secondary">
                                        1-{sortedDatasets.length} of {pagination.total} datasets
                                    </Badge>
                                </div>

                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                        <thead className="bg-gray-50 dark:bg-gray-800">
                                            <tr>
                                                {datasetColumns.map((column) => {
                                                    const isColumnSorted =
                                                        column.sortOptions?.some(option => option.key === sortState.key) ??
                                                        false;
                                                    const ariaSortValue = isColumnSorted
                                                        ? sortState.direction === 'asc'
                                                            ? 'ascending'
                                                            : 'descending'
                                                        : 'none';

                                                    return (
                                                        <th
                                                            key={column.key}
                                                            className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300 ${column.widthClass}`}
                                                            aria-sort={column.sortOptions ? ariaSortValue : undefined}
                                                            scope="col"
                                                        >
                                                            <div className="flex flex-col gap-1 text-left">
                                                                <div className="text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-300">
                                                                    {column.label}
                                                                </div>
                                                                {column.sortOptions ? (
                                                                    <div
                                                                        className="flex flex-wrap gap-1"
                                                                        role="group"
                                                                        aria-label={column.sortGroupLabel ?? 'Sorting options'}
                                                                    >
                                                                        {column.sortOptions.map(option => {
                                                                            const isActive = sortState.key === option.key;
                                                                            const displayDirection = resolveDisplayDirection(
                                                                                option,
                                                                                sortState,
                                                                            );
                                                                            const buttonLabel = buildSortButtonLabel(
                                                                                option,
                                                                                sortState,
                                                                            );

                                                                            return (
                                                                                <Button
                                                                                    key={option.key}
                                                                                    type="button"
                                                                                    variant={isActive ? 'secondary' : 'ghost'}
                                                                                    size="sm"
                                                                                    className="h-7 px-2 text-xs font-medium"
                                                                                    onClick={() => handleSortChange(option.key)}
                                                                                    aria-pressed={isActive}
                                                                                    aria-label={buttonLabel}
                                                                                    title={buttonLabel}
                                                                                >
                                                                                    <span>{option.label}</span>
                                                                                    <SortDirectionIndicator
                                                                                        isActive={isActive}
                                                                                        direction={displayDirection}
                                                                                    />
                                                                                </Button>
                                                                            );
                                                                        })}
                                                                    </div>
                                                                ) : null}
                                                            </div>
                                                        </th>
                                                    );
                                                })}
                                                <th
                                                    className={`px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-300 ${ACTIONS_COLUMN_WIDTH_CLASSES}`}
                                                >
                                                    Actions
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="bg-white divide-y divide-gray-200 dark:bg-gray-900 dark:divide-gray-700">
                                            {sortedDatasets.map((dataset, index) => {
                                                const isLast = index === sortedDatasets.length - 1;
                                                const datasetLabel =
                                                    dataset.identifier ??
                                                    dataset.title ??
                                                    (dataset.id !== undefined ? `#${dataset.id}` : 'entry');
                                                return (
                                                    <tr
                                                        key={deriveDatasetRowKey(dataset)}
                                                        className="hover:bg-gray-50 dark:hover:bg-gray-800"
                                                        ref={isLast ? lastDatasetElementRef : null}
                                                    >
                                                        {datasetColumns.map((column) => (
                                                            <td
                                                                key={column.key}
                                                                className={`px-6 py-4 text-sm text-gray-500 dark:text-gray-300 ${column.widthClass} ${column.cellClassName ?? ''}`}
                                                            >
                                                                {column.render
                                                                    ? column.render(dataset)
                                                                    : formatValue(column.key, dataset[column.key])}
                                                            </td>
                                                        ))}
                                                        <td className={`px-6 py-4 text-sm text-gray-500 dark:text-gray-300 ${ACTIONS_COLUMN_WIDTH_CLASSES}`}>
                                                            <Button
                                                                type="button"
                                                                variant="ghost"
                                                                size="icon"
                                                                onClick={() => handleOpenInCuration(dataset)}
                                                                aria-label={`Open dataset ${datasetLabel} in curation form`}
                                                                title={`Open dataset ${datasetLabel} in curation form`}
                                                            >
                                                                <ArrowUpRight aria-hidden="true" className="size-4" />
                                                            </Button>
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                            {loading && <LoadingSkeleton />}
                                        </tbody>
                                    </table>
                                </div>

                                {loadingError && (
                                    <Alert className="mt-4" variant="destructive">
                                        <AlertDescription>
                                            {loadingError}
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                className="ml-2"
                                                onClick={handleRetry}
                                                disabled={loading}
                                            >
                                                Retry
                                            </Button>
                                        </AlertDescription>
                                    </Alert>
                                )}

                                {!loading && !pagination.has_more && sortedDatasets.length > 0 && (
                                    <div className="text-center py-4 text-muted-foreground text-sm">
                                        All datasets have been loaded ({pagination.total} total)
                                    </div>
                                )}
                            </>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}

export { deriveDatasetRowKey };
