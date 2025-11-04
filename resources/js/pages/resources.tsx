import { Head, router } from '@inertiajs/react';
import axios, { isAxiosError } from 'axios';
import { ArrowDown, ArrowUp, ArrowUpDown, Eye, PencilLine, Trash2 } from 'lucide-react';
import type { ReactNode } from 'react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import { DataCiteIcon } from '@/components/icons/datacite-icon';
import { FileJsonIcon, FileXmlIcon } from '@/components/icons/file-icons';
import SetupLandingPageModal from '@/components/landing-pages/modals/SetupLandingPageModal';
import RegisterDoiModal from '@/components/resources/modals/RegisterDoiModal';
import { ResourcesFilters } from '@/components/resources-filters';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { withBasePath } from '@/lib/base-path';
import { editor as editorRoute } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import { 
    type ResourceFilterOptions, 
    type ResourceFilterState, 
    type ResourceSortDirection, 
    type ResourceSortKey, 
    type ResourceSortState 
} from '@/types/resources';

interface Author {
    givenName?: string | null;
    familyName?: string | null;
    name?: string;
}

interface LandingPage {
    id: number;
    status: string;
    public_url: string;
}

interface Resource {
    id: number;
    doi?: string | null;
    year: number;
    version?: string | null;
    created_at?: string;
    updated_at?: string;
    curator?: string;
    publicstatus?: string;
    resourcetypegeneral?: string;
    title?: string;
    first_author?: Author | null;
    landingPage?: LandingPage | null;
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

interface ResourcesProps {
    resources: Resource[];
    pagination: PaginationInfo;
    error?: string;
    sort: ResourceSortState;
}

interface SortOption {
    key: ResourceSortKey;
    label: string;
    description: string;
}

interface ResourceColumn {
    key: string;
    label: ReactNode;
    widthClass: string;
    cellClassName?: string;
    render?: (resource: Resource) => React.ReactNode;
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
        <span>DOI</span>
    </span>
);
const ACTIONS_COLUMN_WIDTH_CLASSES = 'w-48 min-w-[12rem]';

const DEFAULT_SORT: ResourceSortState = { key: 'updated_at', direction: 'desc' };
const SORT_PREFERENCE_STORAGE_KEY = 'resources.sort-preference';
const DEFAULT_DIRECTION_BY_KEY: Record<ResourceSortKey, ResourceSortDirection> = {
    id: 'asc',
    doi: 'asc',
    title: 'asc',
    resourcetypegeneral: 'asc',
    first_author: 'asc',
    year: 'desc',
    curator: 'asc',
    publicstatus: 'asc',
    created_at: 'desc',
    updated_at: 'desc',
};

const describeDirection = (direction: ResourceSortDirection): string =>
    direction === 'asc' ? 'ascending' : 'descending';

const isSortState = (value: unknown): value is ResourceSortState => {
    if (!value || typeof value !== 'object') {
        return false;
    }

    const maybeState = value as { key?: unknown; direction?: unknown };
    
    const validKeys: ResourceSortKey[] = [
        'id', 'doi', 'title', 'resourcetypegeneral', 
        'first_author', 'year', 'curator', 
        'publicstatus', 'created_at', 'updated_at'
    ];

    return (
        validKeys.includes(maybeState.key as ResourceSortKey)
    ) && (maybeState.direction === 'asc' || maybeState.direction === 'desc');
};

const resolveDisplayDirection = (option: SortOption, sortState: ResourceSortState): ResourceSortDirection =>
    sortState.key === option.key ? sortState.direction : DEFAULT_DIRECTION_BY_KEY[option.key];

const determineNextDirection = (currentState: ResourceSortState, targetKey: ResourceSortKey): ResourceSortDirection => {
    if (currentState.key !== targetKey) {
        return DEFAULT_DIRECTION_BY_KEY[targetKey];
    }

    return currentState.direction === 'asc' ? 'desc' : 'asc';
};

const buildSortButtonLabel = (option: SortOption, sortState: ResourceSortState): string => {
    const currentDirection = resolveDisplayDirection(option, sortState);
    const nextDirection = determineNextDirection(sortState, option.key);

    if (sortState.key === option.key) {
        return `${option.description}. Currently sorted ${describeDirection(currentDirection)}. Activate to switch to ${describeDirection(nextDirection)} order.`;
    }

    return `${option.description}. Activate to sort ${describeDirection(currentDirection)}.`;
};

const getSortLabel = (key: ResourceSortKey): string => {
    const labels: Record<ResourceSortKey, string> = {
        id: 'ID',
        doi: 'DOI',
        title: 'Title',
        resourcetypegeneral: 'Resource Type',
        first_author: 'Author',
        year: 'Year',
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
    direction: ResourceSortDirection;
}) => {
    if (!isActive) {
        return <ArrowUpDown aria-hidden="true" className="size-3.5" />;
    }

    if (direction === 'asc') {
        return <ArrowUp aria-hidden="true" className="size-3.5" />;
    }

    return <ArrowDown aria-hidden="true" className="size-3.5" />;
};

type DateDetails = { label: string; iso: string | null };

const deriveResourceRowKey = (resource: Resource): string => {
    if (resource.id !== undefined && resource.id !== null) {
        return `resource-id-${resource.id}`;
    }

    if (resource.doi) {
        return `resource-doi-${resource.doi}`;
    }

    const metadataSegments: string[] = [];
    
    if (resource.title) metadataSegments.push(resource.title);
    if (resource.year) metadataSegments.push(String(resource.year));
    if (resource.created_at) metadataSegments.push(resource.created_at);
    
    return `resource-${metadataSegments.join('-').toLowerCase().replace(/[^a-z0-9-]/g, '-')}`;
};

const getDateDetails = (isoDate: string | null): DateDetails => {
    if (!isoDate) {
        return { label: '-', iso: null };
    }

    try {
        const date = new Date(isoDate);
        if (isNaN(date.getTime())) {
            return { label: '-', iso: null };
        }

        const formatter = new Intl.DateTimeFormat(undefined, {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });

        return {
            label: formatter.format(date),
            iso: date.toISOString(),
        };
    } catch {
        return { label: '-', iso: null };
    }
};

const describeDate = (
    label: string,
    iso: string | null,
    rawValue: string | null | undefined,
    dateType: string,
): string | null => {
    if (!rawValue || iso === null) {
        return null;
    }

    return `${dateType} date: ${label}`;
};

const renderDateContent = (details: DateDetails) => {
    if (details.iso) {
        return (
            <time dateTime={details.iso} className="text-sm">
                {details.label}
            </time>
        );
    }

    return <span className="text-sm">{details.label}</span>;
};

const formatValue = (key: string, value: unknown): string => {
    if (value === null || value === undefined) {
        return '-';
    }

    if (typeof value === 'string') {
        return value || '-';
    }

    if (typeof value === 'number') {
        return String(value);
    }

    return '-';
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Resources',
        href: '/resources',
    },
];

function ResourcesPage({ resources: initialResources, pagination: initialPagination, error, sort: initialSort }: ResourcesProps) {
    const [resources, setResources] = useState<Resource[]>(initialResources);
    const [pagination, setPagination] = useState<PaginationInfo>(initialPagination);
    const [sortState, setSortState] = useState<ResourceSortState>(initialSort || DEFAULT_SORT);
    const [loading, setLoading] = useState(false);
    const [loadingError, setLoadingError] = useState<string | null>(null);
    const [filters, setFilters] = useState<ResourceFilterState>({});
    const [filterOptions, setFilterOptions] = useState<ResourceFilterOptions | null>(null);

    const lastResourceElementRef = useRef<HTMLTableRowElement | null>(null);
    const observerRef = useRef<IntersectionObserver | null>(null);

    // Load more resources for infinite scrolling
    const loadMore = useCallback(async () => {
        if (loading || !pagination.has_more) {
            return;
        }

        setLoading(true);
        setLoadingError(null);

        try {
            const params = new URLSearchParams({
                page: String(pagination.current_page + 1),
                per_page: String(pagination.per_page),
                sort_key: sortState.key,
                sort_direction: sortState.direction,
            });

            // Add filters
            Object.entries(filters).forEach(([key, value]) => {
                if (value !== undefined && value !== null && value !== '') {
                    if (Array.isArray(value)) {
                        value.forEach(v => params.append(`${key}[]`, String(v)));
                    } else {
                        params.append(key, String(value));
                    }
                }
            });

            const response = await axios.get(withBasePath('/resources/load-more'), { params });

            setResources(prev => [...prev, ...(response.data.resources || [])]);
            setPagination(response.data.pagination);
        } catch (err) {
            console.error('Error loading more resources:', err);
            
            if (isAxiosError(err)) {
                const errorMessage = err.response?.data?.error || err.message;
                setLoadingError(`Failed to load more resources: ${errorMessage}`);
                toast.error('Failed to load more resources');
            } else {
                setLoadingError('An unexpected error occurred while loading resources.');
                toast.error('Failed to load more resources');
            }
        } finally {
            setLoading(false);
        }
    }, [loading, pagination, sortState, filters]);

    // Load filter options on mount
    useEffect(() => {
        const loadFilterOptions = async () => {
            try {
                const response = await axios.get(withBasePath('/resources/filter-options'));
                setFilterOptions(response.data);
            } catch (err) {
                console.error('Failed to load filter options:', err);
            }
        };

        void loadFilterOptions();
    }, []);

    // Load sort preference from localStorage
    useEffect(() => {
        try {
            const stored = localStorage.getItem(SORT_PREFERENCE_STORAGE_KEY);
            if (stored) {
                const parsed = JSON.parse(stored);
                if (isSortState(parsed)) {
                    setSortState(parsed);
                }
            }
        } catch {
            // Ignore parse errors
        }
    }, []);

    // Save sort preference to localStorage
    useEffect(() => {
        try {
            localStorage.setItem(SORT_PREFERENCE_STORAGE_KEY, JSON.stringify(sortState));
        } catch {
            // Ignore storage errors
        }
    }, [sortState]);

    const handleSortChange = useCallback((key: ResourceSortKey) => {
        const newDirection = determineNextDirection(sortState, key);
        const newState = { key, direction: newDirection };
        
        setSortState(newState);
        
        // Build query string
        const params = new URLSearchParams({
            sort_key: newState.key,
            sort_direction: newState.direction,
        });
        
        // Add current filters
        Object.entries(filters).forEach(([filterKey, value]) => {
            if (value !== undefined && value !== null && value !== '') {
                if (Array.isArray(value)) {
                    value.forEach(v => params.append(`${filterKey}[]`, String(v)));
                } else {
                    params.append(filterKey, String(value));
                }
            }
        });
        
        // Navigate to same page with new query params
        router.visit(withBasePath(`/resources?${params.toString()}`), {
            preserveState: false,
            replace: true,
        });
    }, [sortState, filters]);

    const handleFilterChange = useCallback((newFilters: ResourceFilterState) => {
        setFilters(newFilters);
        
        // Build query string with current sort state
        const params = new URLSearchParams({
            sort_key: sortState.key,
            sort_direction: sortState.direction,
        });

        // Add filters to params
        Object.entries(newFilters).forEach(([key, value]) => {
            if (value !== undefined && value !== null && value !== '') {
                if (Array.isArray(value)) {
                    value.forEach(v => params.append(`${key}[]`, String(v)));
                } else {
                    params.append(key, String(value));
                }
            }
        });

        // Navigate to same page with new query params
        router.visit(withBasePath(`/resources?${params.toString()}`), {
            preserveState: false,
            replace: true,
        });
    }, [sortState]);

    // Infinite scrolling
    useEffect(() => {
        if (!lastResourceElementRef.current || loading || !pagination.has_more) {
            return;
        }

        const callback: IntersectionObserverCallback = (entries) => {
            if (entries[0].isIntersecting && pagination.has_more && !loading) {
                void loadMore();
            }
        };

        observerRef.current = new IntersectionObserver(callback, {
            root: null,
            rootMargin: '100px',
            threshold: 0.1,
        });

        observerRef.current.observe(lastResourceElementRef.current);

        return () => {
            if (observerRef.current) {
                observerRef.current.disconnect();
            }
        };
    }, [pagination.has_more, loading, loadMore, resources.length]);

    const handleRetry = useCallback(() => {
        void loadMore();
    }, [loadMore]);

    const handleOpenInEditor = useCallback(async (resource: Resource) => {
        try {
            // Navigate to editor with resource data
            router.get(editorRoute({ query: { resourceId: resource.id } }).url);
        } catch (error) {
            console.error('Unable to open resource in editor:', error);
            toast.error('Failed to open resource in editor');
        }
    }, []);

    const handleSetupLandingPage = useCallback((resource: Resource) => {
        setSelectedResourceForLandingPage(resource);
        setIsLandingPageModalOpen(true);
    }, []);

    const handleCloseLandingPageModal = useCallback(() => {
        setIsLandingPageModalOpen(false);
        setSelectedResourceForLandingPage(null);
    }, []);

    const handleLandingPageSuccess = useCallback(() => {
        // Refresh the resources list to show updated landing page status
        router.reload({ only: ['resources'] });
    }, []);

    const handleRegisterDoi = useCallback((resource: Resource) => {
        setSelectedResourceForDoi(resource);
        setIsDoiModalOpen(true);
    }, []);

    const handleCloseDoiModal = useCallback(() => {
        setIsDoiModalOpen(false);
        setSelectedResourceForDoi(null);
    }, []);

    const handleDoiSuccess = useCallback((doi: string) => {
        // Refresh the resources list to show the new DOI
        router.reload({ only: ['resources'] });
        
        toast.success(`DOI ${doi} successfully registered!`);
    }, []);

    /**
     * Copy text to clipboard with toast notification
     */
    const copyToClipboard = useCallback((text: string, successMessage: string, successDescription?: string) => {
        navigator.clipboard.writeText(text).then(() => {
            toast.success(successMessage, {
                description: successDescription,
                duration: 3000,
            });
        }).catch(() => {
            toast.error('Failed to copy URL to clipboard');
        });
    }, []);

    const handleStatusBadgeClick = useCallback((resource: Resource, status: string) => {
        if (status === 'published' && resource.doi) {
            // Published: Open DOI URL and copy to clipboard
            const doiUrl = `https://doi.org/${resource.doi}`;
            
            copyToClipboard(doiUrl, 'DOI URL copied to clipboard', doiUrl);
            
            // Open in new tab
            window.open(doiUrl, '_blank', 'noopener,noreferrer');
            
        } else if (status === 'review' && resource.landingPage?.public_url) {
            // Review: Open preview landing page and copy URL to clipboard
            const previewUrl = resource.landingPage.public_url;
            
            copyToClipboard(
                previewUrl, 
                'Preview URL copied to clipboard',
                'URL with access token copied for sharing with reviewers'
            );
            
            // Open in new tab
            window.open(previewUrl, '_blank', 'noopener,noreferrer');
        }
    }, [copyToClipboard]);

    const [exportingResources, setExportingResources] = useState<Set<number>>(new Set());
    const [exportingXmlResources, setExportingXmlResources] = useState<Set<number>>(new Set());
    const [selectedResourceForLandingPage, setSelectedResourceForLandingPage] = useState<Resource | null>(null);
    const [isLandingPageModalOpen, setIsLandingPageModalOpen] = useState(false);
    const [selectedResourceForDoi, setSelectedResourceForDoi] = useState<Resource | null>(null);
    const [isDoiModalOpen, setIsDoiModalOpen] = useState(false);

    const handleExportDataCiteJson = useCallback(async (resource: Resource) => {
        if (!resource.id) {
            toast.error('Cannot export resource without ID');
            return;
        }

        // Mark resource as exporting
        setExportingResources(prev => new Set(prev).add(resource.id!));

        try {
            const response = await axios.get(
                withBasePath(`/resources/${resource.id}/export-datacite-json`),
                {
                    responseType: 'blob', // Important for file download
                }
            );

            // Create blob from response
            const blob = new Blob([response.data], { type: 'application/json' });
            
            // Get filename from Content-Disposition header or generate it
            const contentDisposition = response.headers['content-disposition'] as string | undefined;
            let filename = `resource-${resource.id}-datacite.json`;
            
            if (contentDisposition) {
                const filenameMatch = contentDisposition.match(/filename="?([^"]+)"?/);
                if (filenameMatch) {
                    filename = filenameMatch[1];
                }
            }

            // Create download link and trigger download
            const url = window.URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            
            // Cleanup
            document.body.removeChild(link);
            window.URL.revokeObjectURL(url);

            toast.success('DataCite JSON exported successfully');
        } catch (error) {
            console.error('Failed to export DataCite JSON:', error);
            
            let errorMessage = 'Failed to export DataCite JSON';
            if (isAxiosError(error) && error.response?.data) {
                try {
                    const errorBlob = error.response.data as Blob;
                    const errorText = await errorBlob.text();
                    const errorData = JSON.parse(errorText);
                    errorMessage = errorData.message || errorMessage;
                } catch {
                    // Ignore parsing errors
                }
            }
            
            toast.error(errorMessage);
        } finally {
            // Remove resource from exporting set
            setExportingResources(prev => {
                const next = new Set(prev);
                next.delete(resource.id!);
                return next;
            });
        }
    }, []);

    const handleExportDataCiteXml = useCallback(async (resource: Resource) => {
        if (!resource.id) {
            toast.error('Cannot export resource without ID');
            return;
        }

        // Mark resource as exporting
        setExportingXmlResources(prev => new Set(prev).add(resource.id!));

        try {
            const response = await axios.get(
                withBasePath(`/resources/${resource.id}/export-datacite-xml`),
                {
                    responseType: 'blob', // Important for file download
                }
            );

            // Check for validation warning in headers
            const validationWarning = response.headers['x-validation-warning'];
            if (validationWarning) {
                try {
                    const warningMessage = atob(validationWarning);
                    toast.warning('XML Validation Warning', {
                        description: warningMessage,
                        duration: 10000,
                    });
                } catch (e) {
                    console.debug('Failed to decode validation warning:', e);
                    toast.error('Backend Communication Error', {
                        description: 'Failed to decode validation warning from server. The response format may be incorrect.',
                        duration: 8000,
                    });
                }
            }

            // Create blob from response
            const blob = new Blob([response.data], { type: 'application/xml' });
            
            // Get filename from Content-Disposition header or generate it
            const contentDisposition = response.headers['content-disposition'] as string | undefined;
            let filename = `resource-${resource.id}-datacite.xml`;
            
            if (contentDisposition) {
                const filenameMatch = contentDisposition.match(/filename="?([^"]+)"?/);
                if (filenameMatch) {
                    filename = filenameMatch[1];
                }
            }

            // Create download link and trigger download
            const url = window.URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            
            // Cleanup
            document.body.removeChild(link);
            window.URL.revokeObjectURL(url);

            if (!validationWarning) {
                toast.success('DataCite XML exported successfully');
            } else {
                toast.success('DataCite XML exported with validation warnings');
            }
        } catch (error) {
            console.error('Failed to export DataCite XML:', error);
            
            let errorMessage = 'Failed to export DataCite XML';
            if (isAxiosError(error) && error.response?.data) {
                try {
                    const errorBlob = error.response.data as Blob;
                    const errorText = await errorBlob.text();
                    const errorData = JSON.parse(errorText);
                    errorMessage = errorData.message || errorMessage;
                } catch (e) {
                    console.debug('Failed to parse error response:', e);
                }
            }
            
            toast.error(errorMessage);
        } finally {
            // Remove resource from exporting set
            setExportingXmlResources(prev => {
                const next = new Set(prev);
                next.delete(resource.id!);
                return next;
            });
        }
    }, []);

    const sortedResources = resources;

    const resourceColumns: ResourceColumn[] = [
        {
            key: 'id_doi',
            label: IDENTIFIER_COLUMN_HEADER_LABEL,
            widthClass: 'min-w-[12rem]',
            cellClassName: 'whitespace-normal align-middle',
            sortOptions: [
                {
                    key: 'id',
                    label: 'ID',
                    description: 'Sort by the resource ID',
                },
                {
                    key: 'doi',
                    label: 'DOI',
                    description: 'Sort by the DOI',
                },
            ],
            sortGroupLabel: 'Sort options for ID and DOI',
            render: (resource: Resource) => {
                const hasId = resource.id !== undefined && resource.id !== null;
                const idValue = hasId ? `#${resource.id}` : '-';
                const identifierValue = resource.doi || 'Not registered';
                const identifierClasses = resource.doi
                    ? 'text-sm text-gray-600 dark:text-gray-300'
                    : 'text-sm text-gray-500 dark:text-gray-400 italic';

                return (
                    <div
                        className="flex flex-col gap-1 text-left"
                        aria-label={`Resource ID: ${idValue}. DOI: ${identifierValue}`}
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
            cellClassName: 'whitespace-normal align-middle',
            sortOptions: [
                {
                    key: 'title',
                    label: 'Title',
                    description: 'Sort by the resource title',
                },
                {
                    key: 'resourcetypegeneral',
                    label: 'Type',
                    description: 'Sort by the resource type',
                },
            ],
            sortGroupLabel: 'Sort options for title and resource type',
            render: (resource: Resource) => {
                const title = resource.title ?? '-';
                const resourceType = resource.resourcetypegeneral ?? '-';

                return (
                    <div className="flex flex-col gap-1 text-left">
                        <span className="text-sm font-normal text-gray-900 dark:text-gray-100 leading-relaxed break-words">
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
            cellClassName: 'whitespace-normal align-middle',
            sortOptions: [
                {
                    key: 'first_author',
                    label: 'Author',
                    description: 'Sort by the first author\'s last name',
                },
                {
                    key: 'year',
                    label: 'Year',
                    description: 'Sort by the publication year',
                },
            ],
            sortGroupLabel: 'Sort options for author and publication year',
            render: (resource: Resource) => {
                // Format first author name
                let authorName = '-';
                if (resource.first_author) {
                    const author = resource.first_author;
                    if (author.familyName && author.givenName) {
                        authorName = `${author.familyName}, ${author.givenName}`;
                    } else if (author.familyName) {
                        authorName = author.familyName;
                    } else if (author.name) {
                        authorName = author.name;
                    }
                }

                const year = resource.year?.toString() ?? '-';

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
            cellClassName: 'whitespace-normal align-middle',
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
            render: (resource: Resource) => {
                const curator = resource.curator ?? '-';
                const status = resource.publicstatus ?? 'curation';

                // Determine if badge is clickable
                const isClickable = (status === 'published' && resource.doi) || 
                                   (status === 'review' && resource.landingPage?.public_url);

                // Determine badge style based on status
                let statusClasses = 'text-sm px-2 py-0.5 rounded-md font-medium inline-flex items-center justify-center';
                if (status === 'published') {
                    statusClasses += ' bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400';
                    if (isClickable) {
                        statusClasses += ' cursor-pointer hover:bg-green-200 dark:hover:bg-green-900/50 transition-colors';
                    }
                } else if (status === 'review') {
                    statusClasses += ' bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400';
                    if (isClickable) {
                        statusClasses += ' cursor-pointer hover:bg-blue-200 dark:hover:bg-blue-900/50 transition-colors';
                    }
                } else {
                    statusClasses += ' bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300';
                }

                const statusLabel = status.charAt(0).toUpperCase() + status.slice(1);
                const ariaLabel = isClickable
                    ? status === 'published'
                        ? `${statusLabel} - Click to open DOI and copy URL to clipboard`
                        : `${statusLabel} - Click to open preview page and copy URL to clipboard`
                    : statusLabel;

                return (
                    <div className="flex flex-col gap-1 text-center text-gray-600 dark:text-gray-300">
                        <span className="text-sm">{curator}</span>
                        <span
                            className={statusClasses}
                            onClick={isClickable ? () => handleStatusBadgeClick(resource, status) : undefined}
                            role={isClickable ? 'button' : undefined}
                            tabIndex={isClickable ? 0 : undefined}
                            onKeyDown={isClickable ? (e) => {
                                if (e.key === 'Enter' || e.key === ' ') {
                                    e.preventDefault();
                                    handleStatusBadgeClick(resource, status);
                                }
                            } : undefined}
                            aria-label={ariaLabel}
                            title={ariaLabel}
                        >
                            {statusLabel}
                        </span>
                    </div>
                );
            },
        },
        {
            key: 'created_updated',
            label: DATE_COLUMN_HEADER_LABEL,
            widthClass: 'min-w-[9rem]',
            cellClassName: 'whitespace-normal align-middle',
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
            render: (resource: Resource) => {
                const createdDetails = getDateDetails(resource.created_at ?? null);
                const updatedDetails = getDateDetails(resource.updated_at ?? null);

                const ariaLabelParts = [
                    describeDate(createdDetails.label, createdDetails.iso, resource.created_at, 'Created'),
                    describeDate(updatedDetails.label, updatedDetails.iso, resource.updated_at, 'Updated'),
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
                    {resourceColumns.map((column) => (
                        <td key={column.key} className={`px-6 py-1.5 ${column.widthClass} ${column.cellClassName ?? ''}`}>
                            {column.key === 'id_doi' ? (
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
                    <td className={`px-6 py-1.5 align-middle ${ACTIONS_COLUMN_WIDTH_CLASSES}`}>
                        <div className="flex flex-col gap-0.5">
                            <div className="flex items-center gap-1">
                                <div className="size-9 rounded-full bg-gray-200 dark:bg-gray-700" />
                                <div className="size-9 rounded-full bg-gray-200 dark:bg-gray-700" />
                            </div>
                            <div className="flex items-center gap-1">
                                <div className="size-9 rounded-full bg-gray-200 dark:bg-gray-700" />
                                <div className="size-9 rounded-full bg-gray-200 dark:bg-gray-700" />
                            </div>
                        </div>
                    </td>
                </tr>
            ))}
        </>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Resources" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <Card>
                    <CardHeader>
                        <CardTitle asChild>
                            <h1 className="text-2xl font-semibold tracking-tight">Resources</h1>
                        </CardTitle>
                        <CardDescription>
                            Overview of curated resources in ERNIE
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

                        {sortedResources.length === 0 && !loading && !loadingError ? (
                            <div className="text-center py-8 text-muted-foreground">
                                {error ?
                                    "No resources available. Please check the database connection." :
                                    "No resources found."
                                }
                            </div>
                        ) : (
                            <>
                                {/* Filter Component */}
                                <ResourcesFilters
                                    filters={filters}
                                    onFilterChange={handleFilterChange}
                                    filterOptions={filterOptions}
                                    resultCount={sortedResources.length}
                                    totalCount={pagination.total}
                                    isLoading={loading}
                                />

                                <div className="mb-4 flex items-center gap-2 flex-wrap">
                                    <Badge variant="outline" className="text-xs">
                                        Sorted by: {getSortLabel(sortState.key)} {sortState.direction === 'asc' ? '↑' : '↓'}
                                    </Badge>
                                </div>

                                <div className="overflow-x-auto">
                                    <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                        <caption className="sr-only">
                                            List of resources with metadata including title, type, DOI, contributors, language, and version
                                        </caption>
                                        <thead className="bg-gray-50 dark:bg-gray-800">
                                            <tr>
                                                {resourceColumns.map((column) => {
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
                                            {loading && sortedResources.length === 0 && <LoadingSkeleton />}
                                            {sortedResources.map((resource, index) => {
                                                const isLast = index === sortedResources.length - 1;
                                                const resourceLabel =
                                                    resource.doi ??
                                                    resource.title ??
                                                    (resource.id !== undefined ? `#${resource.id}` : 'entry');
                                                return (
                                                    <tr
                                                        key={deriveResourceRowKey(resource)}
                                                        className="hover:bg-gray-50 dark:hover:bg-gray-800"
                                                        ref={isLast ? lastResourceElementRef : null}
                                                    >
                                                        {resourceColumns.map((column) => (
                                                            <td
                                                                key={column.key}
                                                                className={`px-6 py-1.5 text-sm text-gray-500 dark:text-gray-300 ${column.widthClass} ${column.cellClassName ?? ''}`}
                                                            >
                                                                {column.render
                                                                    ? column.render(resource)
                                                                    : formatValue(column.key, resource[column.key])}
                                                            </td>
                                                        ))}
                                                        <td className={`px-6 py-1.5 text-sm text-gray-500 dark:text-gray-300 align-middle ${ACTIONS_COLUMN_WIDTH_CLASSES}`}>
                                                            <div className="flex flex-col gap-0.5">
                                                                <div className="flex items-center gap-1">
                                                                    <Button
                                                                        type="button"
                                                                        variant="ghost"
                                                                        size="icon"
                                                                        onClick={() => handleOpenInEditor(resource)}
                                                                        aria-label={`Open resource ${resourceLabel} in editor form`}
                                                                        title={`Open resource ${resourceLabel} in editor form`}
                                                                    >
                                                                        <PencilLine aria-hidden="true" className="size-4" />
                                                                    </Button>
                                                                    <Button
                                                                        type="button"
                                                                        variant="ghost"
                                                                        size="icon"
                                                                        onClick={() => handleSetupLandingPage(resource)}
                                                                        aria-label={`Setup landing page for resource ${resourceLabel}`}
                                                                        title={`Setup landing page for resource ${resourceLabel}`}
                                                                    >
                                                                        <Eye aria-hidden="true" className="size-4" />
                                                                    </Button>
                                                                    {resource.landingPage && (
                                                                        <Button
                                                                            type="button"
                                                                            variant="ghost"
                                                                            size="icon"
                                                                            onClick={() => handleRegisterDoi(resource)}
                                                                            aria-label={`Register DOI for resource ${resourceLabel}`}
                                                                            title={resource.doi ? 'Update DOI metadata' : 'Register DOI with DataCite'}
                                                                        >
                                                                            <DataCiteIcon aria-hidden="true" className="size-4" />
                                                                        </Button>
                                                                    )}
                                                                </div>
                                                                <div className="flex items-center gap-1">
                                                                    <Button
                                                                        type="button"
                                                                        variant="ghost"
                                                                        size="icon"
                                                                        onClick={() => handleExportDataCiteJson(resource)}
                                                                        disabled={exportingResources.has(resource.id ?? 0)}
                                                                        aria-label={`Export resource ${resourceLabel} as DataCite JSON`}
                                                                        title={`Export as DataCite JSON`}
                                                                    >
                                                                        <FileJsonIcon aria-hidden="true" className="size-4" />
                                                                    </Button>
                                                                    <Button
                                                                        type="button"
                                                                        variant="ghost"
                                                                        size="icon"
                                                                        onClick={() => handleExportDataCiteXml(resource)}
                                                                        disabled={exportingXmlResources.has(resource.id ?? 0)}
                                                                        aria-label={`Export resource ${resourceLabel} as DataCite XML`}
                                                                        title={`Export as DataCite XML`}
                                                                    >
                                                                        <FileXmlIcon aria-hidden="true" className="size-4" />
                                                                    </Button>
                                                                    <Button
                                                                        type="button"
                                                                        variant="ghost"
                                                                        size="icon"
                                                                        disabled
                                                                        aria-label={`Delete resource ${resourceLabel} (not yet implemented)`}
                                                                        title="Delete resource (not yet implemented)"
                                                                        className="opacity-40 cursor-not-allowed"
                                                                    >
                                                                        <Trash2 aria-hidden="true" className="size-4" />
                                                                    </Button>
                                                                </div>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                            {loading && sortedResources.length > 0 && <LoadingSkeleton />}
                                        </tbody>
                                    </table>
                                </div>

                                {!loading && !pagination.has_more && sortedResources.length > 0 && (
                                    <div className="text-center py-4 text-muted-foreground text-sm">
                                        All resources have been loaded ({pagination.total} total)
                                    </div>
                                )}
                            </>
                        )}
                    </CardContent>
                </Card>
            </div>

            {/* Landing Page Setup Modal */}
            {selectedResourceForLandingPage && (
                <SetupLandingPageModal
                    isOpen={isLandingPageModalOpen}
                    resource={selectedResourceForLandingPage}
                    onClose={handleCloseLandingPageModal}
                    onSuccess={handleLandingPageSuccess}
                />
            )}

            {/* DOI Registration Modal */}
            {selectedResourceForDoi && (
                <RegisterDoiModal
                    isOpen={isDoiModalOpen}
                    resource={selectedResourceForDoi}
                    onClose={handleCloseDoiModal}
                    onSuccess={handleDoiSuccess}
                />
            )}
        </AppLayout>
    );
}

export default ResourcesPage;

export { deriveResourceRowKey };
