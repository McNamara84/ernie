import { Head } from '@inertiajs/react';
import axios, { isAxiosError } from 'axios';
import { ArrowDown, ArrowUp, ArrowUpDown, ArrowUpRight, Trash2 } from 'lucide-react';
import type { ReactNode } from 'react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import { OldDatasetsFilters } from '@/components/old-datasets-filters';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { editor as editorRoute } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import { type FilterOptions, type FilterState, type SortDirection, type SortKey, type SortState } from '@/types/old-datasets';
import { parseOldDatasetFiltersFromUrl } from '@/utils/filter-parser';

interface Author {
    givenName?: string | null;
    familyName?: string | null;
    name?: string;
    affiliations?: Array<{ value: string; rorId?: string | null }>;
    roles?: string[];
    isContact?: boolean;
    email?: string | null;
    website?: string | null;
    orcid?: string | null;
    orcidType?: string | null;
}

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
    first_author?: Author | null;
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

interface SortOption {
    key: SortKey;
    label: string;
    description: string;
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
const ACTIONS_COLUMN_WIDTH_CLASSES = 'w-32 min-w-[8rem]';

const DEFAULT_SORT: SortState = { key: 'updated_at', direction: 'desc' };
const SORT_PREFERENCE_STORAGE_KEY = 'old-datasets.sort-preference';
const DEFAULT_DIRECTION_BY_KEY: Record<SortKey, SortDirection> = {
    id: 'asc',
    identifier: 'asc',
    title: 'asc',
    resourcetypegeneral: 'asc',
    first_author: 'asc',
    publicationyear: 'desc',
    curator: 'asc',
    publicstatus: 'asc',
    created_at: 'desc',
    updated_at: 'desc',
};

const describeDirection = (direction: SortDirection): string =>
    direction === 'asc' ? 'ascending' : 'descending';

const isSortState = (value: unknown): value is SortState => {
    if (!value || typeof value !== 'object') {
        return false;
    }

    const maybeState = value as { key?: unknown; direction?: unknown };
    
    const validKeys: SortKey[] = [
        'id', 'identifier', 'title', 'resourcetypegeneral', 
        'first_author', 'publicationyear', 'curator', 
        'publicstatus', 'created_at', 'updated_at'
    ];

    return (
        validKeys.includes(maybeState.key as SortKey)
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

const getSortLabel = (key: SortKey): string => {
    const labels: Record<SortKey, string> = {
        id: 'ID',
        identifier: 'Identifier',
        title: 'Title',
        resourcetypegeneral: 'Resource Type',
        first_author: 'Author',
        publicationyear: 'Year',
        curator: 'Curator',
        publicstatus: 'Status',
        created_at: 'Created Date',
        updated_at: 'Updated Date',
    };
    return labels[key];
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
            if (!raw || typeof raw !== 'object') return;

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
 * editor form never receives invalid identifiers.
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
    const [isSorting, setIsSorting] = useState(false);
    const [loadingError, setLoadingError] = useState<string>('');
    const [filters, setFilters] = useState<FilterState>(() => {
        // SSR-safe: Only access window.location on the client side
        if (typeof window === 'undefined') {
            return {};
        }
        return parseOldDatasetFiltersFromUrl(window.location.search);
    });
    const [filterOptions, setFilterOptions] = useState<FilterOptions | null>(null);
    const observer = useRef<IntersectionObserver | null>(null);
    const pendingRequestRef = useRef(0);
    const lastRequestRef = useRef<{ page: number; sort: SortState; replace: boolean } | null>(null);
    const [activeSortState, setActiveSortState] = useState<SortState>(initialSort);

    const handleSortChange = useCallback((key: SortKey) => {
        const nextDirection = determineNextDirection(sortState, key);
        
        // Set sorting state immediately for skeleton display
        setIsSorting(true);
        setLoading(true);
        
        // Clear datasets immediately to avoid showing wrongly sorted data
        setDatasets([]);
        
        // Smooth scroll to top
        window.scrollTo({ top: 0, behavior: 'smooth' });
        
        // Update sort state which will trigger the useEffect to reload data
        setSortState({
            key,
            direction: nextDirection,
        });
    }, [sortState]);

    // No client-side sorting - data comes pre-sorted from server
    // We keep the variable name for consistency, but it's just the datasets array
    const sortedDatasets = datasets;

    const handleOpenInCuration = useCallback((dataset: Dataset) => {
        if (!dataset.id) {
            toast.error('Dataset ID is missing');
            return;
        }

        // Navigate to editor with oldDatasetId parameter
        // Backend loads data via OldDatasetEditorLoader service
        const route = editorRoute({ query: { oldDatasetId: dataset.id } });
        window.location.href = route.url;
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

    // Load filter options on component mount
    useEffect(() => {
        const loadFilterOptions = async () => {
            try {
                const response = await axios.get('/old-datasets/filter-options');
                setFilterOptions(response.data);
            } catch (err) {
                console.error('Failed to load filter options:', err);
                
                // Provide empty fallback so filters don't stay disabled forever
                setFilterOptions({
                    resource_types: [],
                    curators: [],
                    year_range: { min: 2000, max: 2025 },
                    statuses: ['published', 'draft', 'review', 'archived'],
                });
                
                // Show a subtle warning toast
                toast.error('Filter options could not be loaded. Some filters may be unavailable.', {
                    duration: 5000,
                });
            }
        };

        loadFilterOptions();
    }, []);
    
    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Old Datasets',
            href: '/old-datasets',
        },
    ];

    const fetchDatasetsPage = useCallback(
        async ({ page, sort, replace, filters: filterParams }: { page: number; sort: SortState; replace: boolean; filters?: FilterState }) => {
            const requestId = pendingRequestRef.current + 1;
            pendingRequestRef.current = requestId;
            lastRequestRef.current = { page, sort, replace };

            setLoading(true);
            setLoadingError('');

            try {
                // Build URLSearchParams for proper array serialization
                const searchParams = new URLSearchParams();
                searchParams.append('page', page.toString());
                searchParams.append('per_page', pagination.per_page.toString());
                searchParams.append('sort_key', sort.key);
                searchParams.append('sort_direction', sort.direction);

                // Add filter parameters - arrays need to be sent as param[]=value
                if (filterParams) {
                    Object.entries(filterParams).forEach(([key, value]) => {
                        if (Array.isArray(value)) {
                            // For arrays, append each value with [] notation
                            value.forEach(item => {
                                searchParams.append(`${key}[]`, String(item));
                            });
                        } else {
                            searchParams.append(key, String(value));
                        }
                    });
                }

                const response = await axios.get('/old-datasets/load-more?' + searchParams.toString());

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

                // Show toast notification after successful sort/reload
                if (replace) {
                    const label = getSortLabel(sort.key);
                    const directionIcon = sort.direction === 'asc' ? '↑' : '↓';
                    toast.success(`Sorted by: ${label} ${directionIcon}`, {
                        duration: 2000,
                    });
                    setIsSorting(false);
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
                
                if (isRefreshing) {
                    setIsSorting(false);
                }
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
            filters,
        });
    }, [loading, pagination.has_more, pagination.current_page, fetchDatasetsPage, activeSortState, filters]);

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
                filters,
            });
        }
    }, [fetchDatasetsPage, pagination.has_more, pagination.current_page, activeSortState, filters]);

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
            filters,
        });
    }, [sortState, activeSortState, fetchDatasetsPage, filters]);

    // Reload datasets when filters change (but not on initial mount)
    const isInitialMount = useRef(true);
    const prevFiltersRef = useRef<FilterState>(filters);
    
    useEffect(() => {
        // Skip on initial mount
        if (isInitialMount.current) {
            isInitialMount.current = false;
            prevFiltersRef.current = filters;
            return;
        }

        // Check if filters actually changed
        const filtersChanged = JSON.stringify(prevFiltersRef.current) !== JSON.stringify(filters);
        
        if (!filtersChanged) {
            return;
        }

        prevFiltersRef.current = filters;

        void fetchDatasetsPage({
            page: 1,
            sort: activeSortState,
            replace: true,
            filters,
        });
    }, [filters, activeSortState, fetchDatasetsPage]);

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
                {
                    key: 'identifier',
                    label: 'Identifier',
                    description: 'Sort by the DOI identifier',
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
            key: 'title_resourcetype',
            label: (
                <span className="flex flex-col leading-tight normal-case">
                    <span>Title</span>
                    <span>Resource Type</span>
                </span>
            ),
            widthClass: TITLE_COLUMN_WIDTH_CLASSES,
            cellClassName: 'whitespace-normal align-top',
            sortOptions: [
                {
                    key: 'title',
                    label: 'Title',
                    description: 'Sort by the dataset title',
                },
                {
                    key: 'resourcetypegeneral',
                    label: 'Type',
                    description: 'Sort by the resource type',
                },
            ],
            sortGroupLabel: 'Sort options for title and resource type',
            render: (dataset: Dataset) => {
                const title = dataset.title ?? '-';
                const resourceType = dataset.resourcetypegeneral ?? '-';

                return (
                    <div className="flex flex-col gap-1 text-left">
                        <span className="text-sm font-normal text-gray-900 dark:text-gray-100 leading-relaxed wrap-break-word">
                            {title}
                        </span>
                        <span className="text-sm text-gray-600 dark:text-gray-300 whitespace-nowrap">
                            {resourceType}
                        </span>
                    </div>
                );
            },
        },
        {
            key: 'author_year',
            label: (
                <span className="flex flex-col leading-tight normal-case">
                    <span>Author</span>
                    <span>Year</span>
                </span>
            ),
            widthClass: 'min-w-[12rem]',
            cellClassName: 'whitespace-normal align-top',
            sortOptions: [
                {
                    key: 'first_author',
                    label: 'Author',
                    description: 'Sort by the first author\'s last name',
                },
                {
                    key: 'publicationyear',
                    label: 'Year',
                    description: 'Sort by the publication year',
                },
            ],
            sortGroupLabel: 'Sort options for author and publication year',
            render: (dataset: Dataset) => {
                // Format first author name
                let authorName = '-';
                if (dataset.first_author) {
                    const author = dataset.first_author;
                    if (author.familyName && author.givenName) {
                        authorName = `${author.familyName}, ${author.givenName}`;
                    } else if (author.familyName) {
                        authorName = author.familyName;
                    } else if (author.name) {
                        authorName = author.name;
                    }
                }

                const year = dataset.publicationyear?.toString() ?? '-';

                return (
                    <div className="flex flex-col gap-1 text-left text-gray-600 dark:text-gray-300">
                        <span className="text-sm">{authorName}</span>
                        <span className="text-sm">{year}</span>
                    </div>
                );
            },
        },
        {
            key: 'curator_status',
            label: (
                <span className="flex flex-col leading-tight normal-case">
                    <span>Curator</span>
                    <span>Status</span>
                </span>
            ),
            widthClass: 'min-w-[10rem]',
            cellClassName: 'whitespace-normal align-top',
            sortOptions: [
                {
                    key: 'curator',
                    label: 'Curator',
                    description: 'Sort by the curator name',
                },
                {
                    key: 'publicstatus',
                    label: 'Status',
                    description: 'Sort by the publication status',
                },
            ],
            sortGroupLabel: 'Sort options for curator and status',
            render: (dataset: Dataset) => {
                const curator = dataset.curator ?? '-';
                const status = dataset.publicstatus ?? '-';

                return (
                    <div className="flex flex-col gap-1 text-left text-gray-600 dark:text-gray-300">
                        <span className="text-sm">{curator}</span>
                        <span className="text-sm">{status}</span>
                    </div>
                );
            },
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
                        <div className="flex items-center gap-1">
                            <div className="size-9 rounded-full bg-gray-200 dark:bg-gray-700" />
                            <div className="size-9 rounded-full bg-gray-200 dark:bg-gray-700" />
                        </div>
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

                        {loadingError && (
                            <Alert className="mb-4" variant="destructive">
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

                        {sortedDatasets.length === 0 && !isSorting && !loading && !loadingError ? (
                            <div className="text-center py-8 text-muted-foreground">
                                {error ?
                                    "No datasets available. Please check the database connection." :
                                    "No old datasets found."
                                }
                            </div>
                        ) : (
                            <>
                                {/* Filter Component */}
                                <OldDatasetsFilters
                                    filters={filters}
                                    onFilterChange={setFilters}
                                    filterOptions={filterOptions}
                                    resultCount={sortedDatasets.length}
                                    totalCount={pagination.total}
                                    isLoading={loading || isSorting}
                                />

                                <div className="mb-4 flex items-center gap-2 flex-wrap">
                                    <Badge variant="outline" className="text-xs">
                                        Sorted by: {getSortLabel(sortState.key)} {sortState.direction === 'asc' ? '↑' : '↓'}
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
                                                            {column.sortOptions ? (
                                                                <div
                                                                    className="flex flex-col gap-1"
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
                                                                                className="h-7 px-2 text-xs font-medium justify-start"
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
                                                            ) : (
                                                                <div className="text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-300">
                                                                    {column.label}
                                                                </div>
                                                            )}
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
                                            {(isSorting || (loading && sortedDatasets.length === 0)) && <LoadingSkeleton />}
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
                                                            <div className="flex items-center gap-1">
                                                                <Button
                                                                    type="button"
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    onClick={() => handleOpenInCuration(dataset)}
                                                                    aria-label={`Open dataset ${datasetLabel} in editor form`}
                                                                    title={`Open dataset ${datasetLabel} in editor form`}
                                                                >
                                                                    <ArrowUpRight aria-hidden="true" className="size-4" />
                                                                </Button>
                                                                <Button
                                                                    type="button"
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    disabled
                                                                    aria-label={`Delete dataset ${datasetLabel} (not yet implemented)`}
                                                                    title="Delete dataset (not yet implemented)"
                                                                    className="opacity-40 cursor-not-allowed"
                                                                >
                                                                    <Trash2 aria-hidden="true" className="size-4" />
                                                                </Button>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                            {loading && sortedDatasets.length > 0 && <LoadingSkeleton />}
                                        </tbody>
                                    </table>
                                </div>

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
