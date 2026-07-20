// organize-imports-ignore
import { Head, router, usePage } from '@inertiajs/react';
import axios, { isAxiosError } from 'axios';
import { ArrowDown, ArrowUp, ArrowUpDown, ExternalLink, GripVertical, RotateCcw } from 'lucide-react';
import type { KeyboardEvent as ReactKeyboardEvent, MouseEvent as ReactMouseEvent, PointerEvent as ReactPointerEvent, ReactNode } from 'react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';

import { CitationManagerModal } from '@/components/citations/CitationManagerModal';
import { DataCiteIcon } from '@/components/icons/datacite-icon';
import SetupLandingPageModal from '@/components/landing-pages/modals/SetupLandingPageModal';
import { type ResourcesActionKey, type ResourcesActionState, ResourcesBulkActionsToolbar } from '@/components/resources/bulk-actions-toolbar';
import ImportFromDataCiteModal from '@/components/resources/modals/ImportFromDataCiteModal';
import ImportSingleOldResourceModal from '@/components/resources/modals/ImportSingleOldResourceModal';
import RegisterDoiModal from '@/components/resources/modals/RegisterDoiModal';
import { ResourcesFilters } from '@/components/resources-filters';
import { Alert, AlertDescription } from '@/components/ui/alert';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { type ValidationError, ValidationErrorModal } from '@/components/ui/validation-error-modal';
import { useCitationVocabularies } from '@/hooks/use-citation-vocabularies';
import AppLayout from '@/layouts/app-layout';
import { extractErrorMessageFromBlob, parseValidationErrorFromBlob } from '@/lib/blob-utils';
import { openDetachedTab } from '@/lib/detached-tab';
import { cn } from '@/lib/utils';
import {
    areResourceColumnWidthsDefault,
    clampColumnWidth,
    clearStoredResourceColumnWidths,
    COLUMN_RESIZE_LARGE_STEP,
    COLUMN_RESIZE_STEP,
    DEFAULT_RESOURCE_COLUMN_WIDTHS,
    isResizableViewport,
    persistResourceColumnWidths,
    readStoredResourceColumnWidths,
    RESOURCE_COLUMN_RESIZE_LABELS,
    RESOURCE_COLUMN_WIDTH_DEFINITIONS,
    type ResourceColumnKey,
    type ResourceColumnWidths,
    shouldIncludeColumnInLayout,
} from '@/pages/resources-column-widths';
import { editor as editorRoute } from '@/routes';
import { type BreadcrumbItem, type User as AuthUser } from '@/types';
import {
    type ResourceFilterOptions,
    type ResourceFilterState,
    type ResourceListItem as Resource,
    type ResourceSortDirection,
    type ResourceSortKey,
    type ResourceSortState,
} from '@/types/resources';
import { parseResourceFiltersFromUrl } from '@/utils/filter-parser';

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
    canImportFromDataCite?: boolean;
}

interface SortOption {
    key: ResourceSortKey;
    label: string;
    description: string;
}

interface ResourceColumn {
    key: ResourceColumnKey;
    label: ReactNode;
    visibilityClassName?: string;
    colClassName?: string;
    cellClassName?: string;
    render?: (resource: Resource) => React.ReactNode;
    sortOptions?: SortOption[];
    sortGroupLabel?: string;
}

const SELECT_COLUMN_WIDTH = 48;
const DATE_COLUMN_CONTAINER_CLASSES = 'flex min-w-0 flex-col gap-1 text-left text-gray-600 dark:text-gray-300';
const DATE_COLUMN_HEADER_LABEL = (
    <span className="flex flex-col leading-tight normal-case">
        <span>Created</span>
        <span>Updated</span>
    </span>
);
const ID_RESOURCE_TYPE_COLUMN_HEADER_LABEL = (
    <span className="flex flex-col leading-tight normal-case">
        <span>ID</span>
        <span>Resource Type</span>
    </span>
);
const DOI_TITLE_COLUMN_HEADER_LABEL = (
    <span className="flex flex-col leading-tight normal-case">
        <span>DOI</span>
        <span>Title</span>
    </span>
);
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

const getDefaultBatchDeleteErrorMessage = (selectedCount: number): string =>
    selectedCount === 1 ? 'Failed to delete resource.' : 'Failed to delete resources.';

const normalizeValidationMessage = (value: unknown): string | null => {
    if (typeof value === 'string' && value.trim() !== '') {
        return value;
    }

    if (Array.isArray(value)) {
        return value.find((message): message is string => typeof message === 'string' && message.trim() !== '') ?? null;
    }

    return null;
};

const resolveBatchDeleteErrorMessage = (errors: Record<string, unknown> | undefined, fallbackMessage: string): string => {
    if (!errors) {
        return fallbackMessage;
    }

    const idsError = normalizeValidationMessage(errors.ids);
    if (idsError) {
        return idsError;
    }

    const idsItemErrorKey = Object.keys(errors).find((key) => key.startsWith('ids.'));
    const idsItemError = idsItemErrorKey ? normalizeValidationMessage(errors[idsItemErrorKey]) : null;
    if (idsItemError) {
        return idsItemError;
    }

    return normalizeValidationMessage(Object.values(errors)[0]) ?? fallbackMessage;
};
type ResourceExportAction = Extract<ResourcesActionKey, 'export-datacite-json' | 'export-datacite-xml' | 'export-jsonld'>;
type ResourceDeleteStatus = 'draft' | 'curation' | 'review' | 'published';
type DeletableResourceDeleteStatus = Exclude<ResourceDeleteStatus, 'published'>;

interface BlockedEditorTab {
    id: number;
    label: string;
    url: string;
}

const BATCH_EXPORT_FORMAT_BY_ACTION: Record<ResourceExportAction, 'datacite-json' | 'datacite-xml' | 'jsonld'> = {
    'export-datacite-json': 'datacite-json',
    'export-datacite-xml': 'datacite-xml',
    'export-jsonld': 'jsonld',
};

const DELETABLE_DELETE_STATUSES: DeletableResourceDeleteStatus[] = ['draft', 'curation', 'review'];
const DELETE_STATUS_LABELS: Record<ResourceDeleteStatus, string> = {
    draft: 'draft',
    curation: 'curation',
    review: 'preview',
    published: 'published',
};
const DELETE_STATUS_DESCRIPTIONS: Record<DeletableResourceDeleteStatus, string> = {
    draft: 'Draft resources will be removed from ERNIE.',
    curation: 'Curation resources will be removed from ERNIE.',
    review: 'Preview pages for these resources will also be deleted.',
};

const createDefaultDeleteStatusSelection = (): Record<DeletableResourceDeleteStatus, boolean> => ({
    draft: true,
    curation: true,
    review: true,
});

const normalizeDeleteStatus = (resource: Resource): ResourceDeleteStatus => {
    if (resource.publicstatus === 'published') {
        return 'published';
    }

    if (resource.publicstatus === 'review') {
        return 'review';
    }

    if (resource.publicstatus === 'curation') {
        return 'curation';
    }

    return 'draft';
};

const formatDeleteStatusCount = (status: ResourceDeleteStatus, count: number): string =>
    `${count} ${DELETE_STATUS_LABELS[status]} ${count === 1 ? 'resource' : 'resources'}`;

const getResourceActionLabel = (resource: Resource): string => resource.title ?? resource.doi ?? `Resource #${resource.id}`;

const RESOURCE_ROW_INTERACTIVE_SELECTOR = [
    'a',
    'button',
    'input',
    'select',
    'textarea',
    '[role="button"]',
    '[role="checkbox"]',
    '[role="menuitem"]',
    '[data-resource-row-action]',
].join(',');

const shouldIgnoreResourceRowActivation = (event: ReactMouseEvent<HTMLTableRowElement> | ReactKeyboardEvent<HTMLTableRowElement>): boolean => {
    const eventTarget = event.target;
    const target = eventTarget instanceof Element ? eventTarget : eventTarget instanceof Text ? eventTarget.parentElement : null;

    if (target === null) {
        return false;
    }

    const interactiveElement = target.closest(RESOURCE_ROW_INTERACTIVE_SELECTOR);

    return interactiveElement !== null && event.currentTarget.contains(interactiveElement);
};

const getFilenameFromContentDisposition = (contentDisposition: string | undefined, fallback: string): string => {
    const filenameMatch = contentDisposition?.match(/filename="?([^"]+)"?/);

    return filenameMatch?.[1] ?? fallback;
};

const downloadBlob = (blob: Blob, filename: string): void => {
    const url = window.URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    window.URL.revokeObjectURL(url);
};
const describeDirection = (direction: ResourceSortDirection): string => (direction === 'asc' ? 'ascending' : 'descending');

const isSortState = (value: unknown): value is ResourceSortState => {
    if (!value || typeof value !== 'object') {
        return false;
    }

    const maybeState = value as { key?: unknown; direction?: unknown };

    const validKeys: ResourceSortKey[] = [
        'id',
        'doi',
        'title',
        'resourcetypegeneral',
        'first_author',
        'year',
        'curator',
        'publicstatus',
        'created_at',
        'updated_at',
    ];

    return validKeys.includes(maybeState.key as ResourceSortKey) && (maybeState.direction === 'asc' || maybeState.direction === 'desc');
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

const SortDirectionIndicator = ({ isActive, direction }: { isActive: boolean; direction: ResourceSortDirection }) => {
    if (!isActive) {
        return <ArrowUpDown aria-hidden="true" className="size-3.5" />;
    }

    if (direction === 'asc') {
        return <ArrowUp aria-hidden="true" className="size-3.5" />;
    }

    return <ArrowDown aria-hidden="true" className="size-3.5" />;
};

interface OverflowTooltipTextProps {
    value: string;
    className?: string;
    tooltipClassName?: string;
    testId?: string;
}

function OverflowTooltipText({ value, className, tooltipClassName, testId }: OverflowTooltipTextProps) {
    const textRef = useRef<HTMLSpanElement | null>(null);
    const [isOverflowing, setIsOverflowing] = useState(false);

    const measureOverflow = useCallback(() => {
        const element = textRef.current;
        const nextIsOverflowing = Boolean(element && element.scrollWidth > element.clientWidth);
        setIsOverflowing((currentIsOverflowing) => (currentIsOverflowing === nextIsOverflowing ? currentIsOverflowing : nextIsOverflowing));
    }, []);

    useEffect(() => {
        measureOverflow();

        const element = textRef.current;
        if (!element || typeof ResizeObserver === 'undefined') {
            return undefined;
        }

        const resizeObserver = new ResizeObserver(measureOverflow);
        resizeObserver.observe(element);

        return () => resizeObserver.disconnect();
    }, [measureOverflow, value]);

    const text = (
        <span
            ref={textRef}
            className={cn('block min-w-0 truncate', className)}
            data-overflowing={isOverflowing ? 'true' : 'false'}
            data-testid={testId}
        >
            {value}
        </span>
    );

    if (!isOverflowing) {
        return text;
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>{text}</TooltipTrigger>
            <TooltipContent className={cn('max-w-sm text-left break-words normal-case', tooltipClassName)}>{value}</TooltipContent>
        </Tooltip>
    );
}

interface ColumnResizeOptions {
    persist?: boolean;
}

interface ColumnResizeHandleProps {
    columnKey: ResourceColumnKey;
    width: number;
    disabled: boolean;
    onResize: (columnKey: ResourceColumnKey, width: number, options?: ColumnResizeOptions) => void;
}

function ColumnResizeHandle({ columnKey, width, disabled, onResize }: ColumnResizeHandleProps) {
    const definition = RESOURCE_COLUMN_WIDTH_DEFINITIONS[columnKey];
    const label = RESOURCE_COLUMN_RESIZE_LABELS[columnKey];
    const resizeAbortControllerRef = useRef<AbortController | null>(null);

    useEffect(() => {
        return () => {
            resizeAbortControllerRef.current?.abort();
            resizeAbortControllerRef.current = null;
        };
    }, []);

    const resizeTo = useCallback(
        (nextWidth: number, options?: ColumnResizeOptions) => {
            const clampedWidth = clampColumnWidth(columnKey, nextWidth);
            onResize(columnKey, clampedWidth, options);
            return clampedWidth;
        },
        [columnKey, onResize],
    );

    const handlePointerDown = useCallback(
        (event: ReactPointerEvent<HTMLButtonElement>) => {
            if (disabled || event.button !== 0) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            resizeAbortControllerRef.current?.abort();

            const abortController = new AbortController();
            resizeAbortControllerRef.current = abortController;
            const startX = event.clientX;
            const startWidth = width;
            const activePointerId = event.pointerId;
            let latestWidth = startWidth;

            const handlePointerMove = (moveEvent: PointerEvent) => {
                if (moveEvent.pointerId !== activePointerId) {
                    return;
                }

                moveEvent.preventDefault();
                latestWidth = resizeTo(startWidth + moveEvent.clientX - startX);
            };

            function stopResize() {
                abortController.abort();

                if (resizeAbortControllerRef.current === abortController) {
                    resizeAbortControllerRef.current = null;
                }
            }

            function commitResize(upEvent: PointerEvent) {
                if (upEvent.pointerId !== activePointerId) {
                    return;
                }

                resizeTo(latestWidth, { persist: true });
                stopResize();
            }

            function cancelResize(cancelEvent: PointerEvent) {
                if (cancelEvent.pointerId !== activePointerId) {
                    return;
                }

                stopResize();
            }

            window.addEventListener('pointermove', handlePointerMove, { signal: abortController.signal });
            window.addEventListener('pointerup', commitResize, { signal: abortController.signal });
            window.addEventListener('pointercancel', cancelResize, { signal: abortController.signal });
        },
        [disabled, resizeTo, width],
    );

    const handleKeyDown = useCallback(
        (event: ReactKeyboardEvent<HTMLButtonElement>) => {
            let nextWidth: number | null = null;
            const step = event.shiftKey ? COLUMN_RESIZE_LARGE_STEP : COLUMN_RESIZE_STEP;

            if (event.key === 'ArrowLeft') {
                nextWidth = width - step;
            } else if (event.key === 'ArrowRight') {
                nextWidth = width + step;
            } else if (event.key === 'Home') {
                nextWidth = definition.minWidth;
            } else if (event.key === 'End') {
                nextWidth = definition.maxWidth;
            }

            if (nextWidth === null) {
                return;
            }

            event.preventDefault();
            event.stopPropagation();
            resizeTo(nextWidth, { persist: true });
        },
        [definition.maxWidth, definition.minWidth, resizeTo, width],
    );

    return (
        <Button
            type="button"
            variant="ghost"
            size="icon"
            role="separator"
            aria-orientation="vertical"
            aria-label={`Resize ${label} column`}
            aria-valuemin={definition.minWidth}
            aria-valuemax={definition.maxWidth}
            aria-valuenow={width}
            className="absolute inset-y-0 right-0 z-10 hidden h-full w-5 translate-x-1/2 cursor-col-resize touch-none rounded-none border-l border-border/80 bg-background/80 p-0 text-muted-foreground opacity-80 transition hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-0 md:flex"
            disabled={disabled}
            tabIndex={disabled ? -1 : 0}
            title={`Resize ${label} column`}
            data-testid={`resources-column-resize-${columnKey}`}
            data-resource-row-action="true"
            onPointerDown={handlePointerDown}
            onKeyDown={handleKeyDown}
        >
            <GripVertical aria-hidden="true" className="size-3" />
            <span className="sr-only">Resize {label} column</span>
        </Button>
    );
}

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

    return `resource-${metadataSegments
        .join('-')
        .toLowerCase()
        .replace(/[^a-z0-9-]/g, '-')}`;
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

const describeDate = (label: string, iso: string | null, rawValue: string | null | undefined, dateType: string): string | null => {
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

function ResourcesPage({
    resources: initialResources,
    pagination: initialPagination,
    error,
    sort: initialSort,
    canImportFromDataCite,
}: ResourcesProps) {
    const { auth } = usePage<{ auth: { user: AuthUser } }>().props;
    const canManageLandingPages = auth.user?.can_manage_landing_pages ?? false;
    const canRegisterDoi = auth.user?.can_register_doi ?? false;
    const canDeleteResources = auth.user?.role === 'admin' || auth.user?.role === 'group_leader' || auth.user?.role === 'curator';

    const [resources, setResources] = useState<Resource[]>(initialResources);
    const [pagination, setPagination] = useState<PaginationInfo>(initialPagination);
    const [sortState, setSortState] = useState<ResourceSortState>(initialSort || DEFAULT_SORT);
    const [loading, setLoading] = useState(false);
    const [loadingError, setLoadingError] = useState<string | null>(null);
    const [showImportModal, setShowImportModal] = useState(false);
    const [showDatacenterImportModal, setShowDatacenterImportModal] = useState(false);
    const [showSingleImportModal, setShowSingleImportModal] = useState(false);
    const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set());
    const [isBulkRegistering, setIsBulkRegistering] = useState(false);
    const [isBulkExporting, setIsBulkExporting] = useState(false);
    const [filters, setFilters] = useState<ResourceFilterState>(() => {
        // SSR-safe: Only access window.location on the client side
        if (typeof window === 'undefined') {
            return {};
        }
        return parseResourceFiltersFromUrl(window.location.search);
    });
    const [filterOptions, setFilterOptions] = useState<ResourceFilterOptions | null>(null);
    const [columnWidths, setColumnWidths] = useState<ResourceColumnWidths>(DEFAULT_RESOURCE_COLUMN_WIDTHS);
    const [tableCanResize, setTableCanResize] = useState<boolean>(true);

    const lastResourceElementRef = useRef<HTMLTableRowElement | null>(null);
    const observerRef = useRef<IntersectionObserver | null>(null);

    useEffect(() => {
        setResources(initialResources);
    }, [initialResources]);

    useEffect(() => {
        setPagination(initialPagination);
    }, [initialPagination]);

    useEffect(() => {
        setColumnWidths(readStoredResourceColumnWidths());
    }, []);

    useEffect(() => {
        const handleViewportResize = () => {
            setTableCanResize(isResizableViewport());
        };

        handleViewportResize();
        window.addEventListener('resize', handleViewportResize);

        return () => window.removeEventListener('resize', handleViewportResize);
    }, []);

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
                        value.forEach((v) => params.append(`${key}[]`, String(v)));
                    } else {
                        params.append(key, String(value));
                    }
                }
            });

            const response = await axios.get('/resources/load-more', { params });

            setResources((prev) => [...prev, ...(response.data.resources || [])]);
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
                const response = await axios.get('/resources/filter-options');
                setFilterOptions(response.data);
            } catch (err) {
                if (import.meta.env.DEV) {
                    console.error('Failed to load filter options:', err);
                }
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

    const handleSortChange = useCallback(
        (key: ResourceSortKey) => {
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
                        value.forEach((v) => params.append(`${filterKey}[]`, String(v)));
                    } else {
                        params.append(filterKey, String(value));
                    }
                }
            });

            // Navigate to same page with new query params
            router.visit(`/resources?${params.toString()}`, {
                preserveState: false,
                replace: true,
            });
        },
        [sortState, filters],
    );

    const handleFilterChange = useCallback(
        (newFilters: ResourceFilterState) => {
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
                        value.forEach((v) => params.append(`${key}[]`, String(v)));
                    } else {
                        params.append(key, String(value));
                    }
                }
            });

            // Navigate to same page with new query params
            router.visit(`/resources?${params.toString()}`, {
                preserveState: false,
                replace: true,
            });
        },
        [sortState],
    );

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

    const handleImportSuccess = useCallback(() => {
        // Refresh the resources list to show imported resources
        router.reload({ only: ['resources', 'pagination'] });
    }, []);

    // Drop selections that no longer correspond to a loaded resource (e.g. after
    // filter/sort changes that replace the list).
    useEffect(() => {
        setSelectedIds((prev) => {
            if (prev.size === 0) {
                return prev;
            }
            const validIds = new Set(resources.map((r) => r.id).filter((id): id is number => typeof id === 'number'));
            let changed = false;
            const next = new Set<number>();
            prev.forEach((id) => {
                if (validIds.has(id)) {
                    next.add(id);
                } else {
                    changed = true;
                }
            });
            return changed ? next : prev;
        });
    }, [resources]);

    const visibleSelectableIds = useCallback((): number[] => {
        return resources.map((r) => r.id).filter((id): id is number => typeof id === 'number');
    }, [resources]);

    const allVisibleSelected = resources.length > 0 && visibleSelectableIds().every((id) => selectedIds.has(id));
    const someVisibleSelected = !allVisibleSelected && visibleSelectableIds().some((id) => selectedIds.has(id));

    const handleSelectAll = useCallback(
        (checked: boolean) => {
            setSelectedIds(checked ? new Set(visibleSelectableIds()) : new Set());
        },
        [visibleSelectableIds],
    );

    const handleSelectOne = useCallback((id: number, checked: boolean) => {
        setSelectedIds((prev) => {
            const next = new Set(prev);
            if (checked) {
                next.add(id);
            } else {
                next.delete(id);
            }
            return next;
        });
    }, []);

    const selectedResources = resources.filter((resource) => typeof resource.id === 'number' && selectedIds.has(resource.id));
    const selectedResourceIds = selectedResources.map((resource) => resource.id);
    const selectedCount = selectedResources.length;
    const singleSelectedResource = selectedCount === 1 ? selectedResources[0] : null;

    const executeUpdateMetadata = useCallback(async () => {
        if (selectedResourceIds.length === 0) {
            toast.error('Select one or more resources first.');
            return;
        }

        setIsBulkRegistering(true);
        try {
            const response = await axios.post<{
                success: Array<{ id: number; doi: string | null; updated: boolean }>;
                failed: Array<{ id: number; doi: string | null; reason: string }>;
            }>('/resources/batch-register', { ids: selectedResourceIds });

            const { success = [], failed = [] } = response.data ?? {};
            if (success.length > 0) {
                toast.success(`${success.length} ${success.length === 1 ? 'resource' : 'resources'} updated at DataCite`);
            }
            if (failed.length > 0) {
                toast.error(`${failed.length} failed: ${failed[0].reason}`);
            }
            router.reload({ only: ['resources', 'pagination'] });
            setSelectedIds(new Set());
        } catch (error) {
            if (isAxiosError(error) && error.response?.status === 207 && error.response.data) {
                const { success = [], failed = [] } = error.response.data as {
                    success?: Array<{ id: number; doi: string | null; updated: boolean }>;
                    failed?: Array<{ id: number; doi: string | null; reason: string }>;
                };
                if (success.length > 0) {
                    toast.success(`${success.length} ${success.length === 1 ? 'resource' : 'resources'} updated at DataCite`);
                }
                if (failed.length > 0) {
                    toast.error(`${failed.length} failed: ${failed[0].reason}`);
                }
                router.reload({ only: ['resources', 'pagination'] });
                setSelectedIds(new Set());
                return;
            }
            console.error('Metadata update failed:', error);
            toast.error('Metadata update failed');
        } finally {
            setIsBulkRegistering(false);
            setIsUpdateMetadataDialogOpen(false);
        }
    }, [selectedResourceIds]);
    /**
     * Copy text to clipboard with toast notification
     */
    const copyToClipboard = useCallback((text: string, successMessage: string, successDescription?: string) => {
        navigator.clipboard
            .writeText(text)
            .then(() => {
                toast.success(successMessage, {
                    description: successDescription,
                    duration: 3000,
                });
            })
            .catch(() => {
                toast.error('Failed to copy URL to clipboard');
            });
    }, []);

    const handleStatusBadgeClick = useCallback(
        (resource: Resource, status: string) => {
            if (status === 'published' && resource.doi) {
                // Published: Open DOI URL and copy to clipboard
                const doiUrl = `https://doi.org/${resource.doi}`;

                copyToClipboard(doiUrl, 'DOI URL copied to clipboard', doiUrl);

                // Open in new tab
                window.open(doiUrl, '_blank', 'noopener,noreferrer');
            } else if (status === 'review' && resource.landingPage?.public_url) {
                // Review: Open preview landing page and copy URL to clipboard
                const previewUrl = resource.landingPage.public_url;

                copyToClipboard(previewUrl, 'Preview URL copied to clipboard', 'URL with access token copied for sharing with reviewers');

                // Open in new tab
                window.open(previewUrl, '_blank', 'noopener,noreferrer');
            }
        },
        [copyToClipboard],
    );

    const [selectedResourceForLandingPage, setSelectedResourceForLandingPage] = useState<Resource | null>(null);
    const [isLandingPageModalOpen, setIsLandingPageModalOpen] = useState(false);
    const [selectedResourceForDoi, setSelectedResourceForDoi] = useState<Resource | null>(null);
    const [isDoiModalOpen, setIsDoiModalOpen] = useState(false);
    const [validationErrors, setValidationErrors] = useState<ValidationError[]>([]);
    const [isValidationModalOpen, setIsValidationModalOpen] = useState(false);
    const [validationSchemaVersion, setValidationSchemaVersion] = useState<string>('4.6');
    const [citationManagerResourceId, setCitationManagerResourceId] = useState<number | null>(null);
    const [isUpdateMetadataDialogOpen, setIsUpdateMetadataDialogOpen] = useState(false);
    const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);
    const [isDeletingResource, setIsDeletingResource] = useState(false);
    const [selectedDeleteStatuses, setSelectedDeleteStatuses] =
        useState<Record<DeletableResourceDeleteStatus, boolean>>(createDefaultDeleteStatusSelection);
    const [blockedEditorTabs, setBlockedEditorTabs] = useState<BlockedEditorTab[]>([]);
    const isDeletingResourceRef = useRef(false);
    const { vocabularies: citationVocabularies, isLoading: citationVocabulariesLoading } = useCitationVocabularies();

    const hasPersistentIdentifier = useCallback((resource: Resource): boolean => {
        return typeof resource.doi === 'string' && resource.doi.trim().length > 0;
    }, []);

    const selectedDeleteGroups = useMemo((): Record<ResourceDeleteStatus, Resource[]> => {
        const groups: Record<ResourceDeleteStatus, Resource[]> = {
            draft: [],
            curation: [],
            review: [],
            published: [],
        };

        selectedResources.forEach((resource) => {
            groups[normalizeDeleteStatus(resource)].push(resource);
        });

        return groups;
    }, [selectedResources]);

    const selectedDeletableDeleteResourceIds = useMemo(
        () =>
            DELETABLE_DELETE_STATUSES.flatMap((status) =>
                selectedDeleteStatuses[status] ? selectedDeleteGroups[status].map((resource) => resource.id) : [],
            ),
        [selectedDeleteGroups, selectedDeleteStatuses],
    );
    const selectedDeletableDeleteCount = selectedDeletableDeleteResourceIds.length;
    const publishedDeleteCount = selectedDeleteGroups.published.length;
    const hasPreviewDeleteSelection = selectedDeleteStatuses.review && selectedDeleteGroups.review.length > 0;
    const hasAnyDeletableDeleteSelection = selectedDeletableDeleteCount > 0;

    const handleOpenDeleteDialog = useCallback(() => {
        setSelectedDeleteStatuses({
            draft: selectedDeleteGroups.draft.length > 0,
            curation: selectedDeleteGroups.curation.length > 0,
            review: selectedDeleteGroups.review.length > 0,
        });
        setIsDeleteDialogOpen(true);
    }, [selectedDeleteGroups]);

    const handleUpdateMetadataDialogOpenChange = useCallback(
        (open: boolean) => {
            if (!open && !isBulkRegistering) {
                setIsUpdateMetadataDialogOpen(false);
            }
        },
        [isBulkRegistering],
    );

    const handleDeleteDialogOpenChange = useCallback(
        (open: boolean) => {
            if (!open && !isDeletingResourceRef.current && !isDeletingResource) {
                isDeletingResourceRef.current = false;
                setIsDeleteDialogOpen(false);
            }
        },
        [isDeletingResource],
    );

    const handleConfirmUpdateMetadata = useCallback(
        (event: ReactMouseEvent<HTMLButtonElement>) => {
            event.preventDefault();
            void executeUpdateMetadata();
        },
        [executeUpdateMetadata],
    );

    const handleConfirmDelete = useCallback(
        (event: ReactMouseEvent<HTMLButtonElement>) => {
            event.preventDefault();

            if (isDeletingResourceRef.current || selectedDeletableDeleteResourceIds.length === 0) {
                return;
            }

            const deleteCount = selectedDeletableDeleteResourceIds.length;
            isDeletingResourceRef.current = true;
            setIsDeletingResource(true);

            router.delete('/resources/batch', {
                data: { ids: selectedDeletableDeleteResourceIds },
                preserveScroll: true,
                onSuccess: () => {
                    setSelectedIds(new Set());
                    setIsDeleteDialogOpen(false);
                    toast.success(deleteCount === 1 ? 'Resource deleted successfully.' : `${deleteCount} resources deleted successfully.`);
                },
                onError: (errors) => {
                    toast.error(resolveBatchDeleteErrorMessage(errors, getDefaultBatchDeleteErrorMessage(deleteCount)));
                },
                onFinish: () => {
                    isDeletingResourceRef.current = false;
                    setIsDeletingResource(false);
                },
            });
        },
        [selectedDeletableDeleteResourceIds],
    );
    const handleExportDataCiteJson = useCallback(async (resource: Resource) => {
        if (!resource.id) {
            toast.error('Cannot export resource without ID');
            return;
        }

        try {
            const response = await axios.get(`/resources/${resource.id}/export-datacite-json`, {
                responseType: 'blob',
            });

            const blob = new Blob([response.data], { type: 'application/json' });
            const contentDisposition = response.headers['content-disposition'] as string | undefined;
            let filename = `resource-${resource.id}-datacite.json`;

            if (contentDisposition) {
                const filenameMatch = contentDisposition.match(/filename="?([^"]+)"?/);
                if (filenameMatch) {
                    filename = filenameMatch[1];
                }
            }

            const url = window.URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            window.URL.revokeObjectURL(url);

            toast.success('DataCite JSON exported successfully');
        } catch (error) {
            console.error('Failed to export DataCite JSON:', error);

            if (isAxiosError(error) && error.response?.status === 422 && error.response?.data) {
                const validationError = await parseValidationErrorFromBlob(error.response.data);
                if (validationError) {
                    setValidationErrors(validationError.errors);
                    setValidationSchemaVersion(validationError.schema_version || '4.6');
                    setIsValidationModalOpen(true);
                    return;
                }
            }

            const errorMessage =
                isAxiosError(error) && error.response?.data
                    ? await extractErrorMessageFromBlob(error.response.data, 'Failed to export DataCite JSON')
                    : 'Failed to export DataCite JSON';

            toast.error(errorMessage);
        }
    }, []);

    const handleExportJsonLd = useCallback(async (resource: Resource) => {
        if (!resource.id) {
            toast.error('Cannot export resource without ID');
            return;
        }

        try {
            const response = await axios.get(`/resources/${resource.id}/export-jsonld`, {
                responseType: 'blob',
            });

            const blob = new Blob([response.data], { type: 'application/ld+json' });
            const contentDisposition = response.headers['content-disposition'] as string | undefined;
            let filename = `resource-${resource.id}-datacite-ld.jsonld`;

            if (contentDisposition) {
                const filenameMatch = contentDisposition.match(/filename="?([^"]+)"?/);
                if (filenameMatch) {
                    filename = filenameMatch[1];
                }
            }

            const url = window.URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            window.URL.revokeObjectURL(url);

            toast.success('JSON-LD exported successfully');
        } catch (error) {
            console.error('Failed to export JSON-LD:', error);

            const errorMessage =
                isAxiosError(error) && error.response?.data
                    ? await extractErrorMessageFromBlob(error.response.data, 'Failed to export JSON-LD')
                    : 'Failed to export JSON-LD';

            toast.error(errorMessage);
        }
    }, []);

    const handleExportDataCiteXml = useCallback(async (resource: Resource) => {
        if (!resource.id) {
            toast.error('Cannot export resource without ID');
            return;
        }

        try {
            const response = await axios.get(`/resources/${resource.id}/export-datacite-xml`, {
                responseType: 'blob',
            });

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

            const blob = new Blob([response.data], { type: 'application/xml' });
            const contentDisposition = response.headers['content-disposition'] as string | undefined;
            let filename = `resource-${resource.id}-datacite.xml`;

            if (contentDisposition) {
                const filenameMatch = contentDisposition.match(/filename="?([^"]+)"?/);
                if (filenameMatch) {
                    filename = filenameMatch[1];
                }
            }

            const url = window.URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            window.URL.revokeObjectURL(url);

            toast.success(validationWarning ? 'DataCite XML exported with validation warnings' : 'DataCite XML exported successfully');
        } catch (error) {
            console.error('Failed to export DataCite XML:', error);

            const errorMessage =
                isAxiosError(error) && error.response?.data
                    ? await extractErrorMessageFromBlob(error.response.data, 'Failed to export DataCite XML')
                    : 'Failed to export DataCite XML';

            toast.error(errorMessage);
        }
    }, []);

    const openResourceEditorTab = useCallback((resource: Resource): BlockedEditorTab | null => {
        if (typeof resource.id !== 'number') {
            return null;
        }

        const url = editorRoute({ query: { resourceId: resource.id } }).url;
        const opened = openDetachedTab(url);

        if (opened !== null) {
            return null;
        }

        return {
            id: resource.id,
            label: getResourceActionLabel(resource),
            url,
        };
    }, []);

    const warnAboutBlockedEditorTabs = useCallback((blockedTabs: BlockedEditorTab[], attemptedTabCount: number) => {
        if (blockedTabs.length === 0) {
            return;
        }

        if (attemptedTabCount === 1) {
            toast.warning('Your browser blocked the editor tab. Please allow pop-ups for ERNIE and try again.');
            return;
        }

        setBlockedEditorTabs(blockedTabs);
        toast.warning('Your browser blocked one or more editor tabs. Use the fallback links to continue editing.');
    }, []);

    const handleEditSelectedResources = useCallback(() => {
        if (selectedResources.length === 0) {
            toast.error('Select one or more resources first.');
            return;
        }

        const blockedTabs = selectedResources.map(openResourceEditorTab).filter((tab): tab is BlockedEditorTab => tab !== null);
        warnAboutBlockedEditorTabs(blockedTabs, selectedResources.length);
    }, [openResourceEditorTab, selectedResources, warnAboutBlockedEditorTabs]);

    const handleResourceRowActivation = useCallback(
        (resource: Resource) => {
            const blockedTab = openResourceEditorTab(resource);
            warnAboutBlockedEditorTabs(blockedTab ? [blockedTab] : [], 1);
        },
        [openResourceEditorTab, warnAboutBlockedEditorTabs],
    );

    const handleResourceRowClick = useCallback(
        (resource: Resource, event: ReactMouseEvent<HTMLTableRowElement>) => {
            if (shouldIgnoreResourceRowActivation(event)) {
                return;
            }

            handleResourceRowActivation(resource);
        },
        [handleResourceRowActivation],
    );

    const handleResourceRowKeyDown = useCallback(
        (resource: Resource, event: ReactKeyboardEvent<HTMLTableRowElement>) => {
            const isActivationKey = event.key === 'Enter' || event.key === ' ' || event.key === 'Spacebar';

            if (!isActivationKey || shouldIgnoreResourceRowActivation(event)) {
                return;
            }

            event.preventDefault();
            handleResourceRowActivation(resource);
        },
        [handleResourceRowActivation],
    );

    const handleBatchExportSelectedResources = useCallback(
        async (action: ResourceExportAction) => {
            const format = BATCH_EXPORT_FORMAT_BY_ACTION[action];

            try {
                const response = await axios.post('/resources/batch-export', { ids: selectedResourceIds, format }, { responseType: 'blob' });
                const contentDisposition = response.headers['content-disposition'] as string | undefined;
                const filename = getFilenameFromContentDisposition(contentDisposition, `resources-export-${format}.zip`);

                downloadBlob(new Blob([response.data], { type: 'application/zip' }), filename);
                toast.success(`${selectedResourceIds.length} resources exported as ZIP.`);
            } catch (error) {
                console.error('Failed to export resource ZIP:', error);

                const errorMessage =
                    isAxiosError(error) && error.response?.data
                        ? await extractErrorMessageFromBlob(error.response.data, 'Failed to export resources')
                        : 'Failed to export resources';

                toast.error(errorMessage);
            }
        },
        [selectedResourceIds],
    );
    const handleExportSelectedResources = useCallback(
        async (action: ResourceExportAction) => {
            if (selectedResources.length === 0) {
                toast.error('Select one or more resources first.');
                return;
            }

            setIsBulkExporting(true);
            try {
                if (selectedResources.length > 1) {
                    await handleBatchExportSelectedResources(action);
                    return;
                }

                const resource = selectedResources[0];
                if (action === 'export-datacite-json') {
                    await handleExportDataCiteJson(resource);
                } else if (action === 'export-datacite-xml') {
                    await handleExportDataCiteXml(resource);
                } else {
                    await handleExportJsonLd(resource);
                }
            } finally {
                setIsBulkExporting(false);
            }
        },
        [handleBatchExportSelectedResources, handleExportDataCiteJson, handleExportDataCiteXml, handleExportJsonLd, selectedResources],
    );

    const formatSelectionCount = useCallback((count: number, singular: string, plural: string): string => {
        return `${count} ${count === 1 ? singular : plural}`;
    }, []);

    const selectedWithoutLandingPageCount = selectedResources.filter((resource) => !resource.landingPage).length;
    const selectedWithoutDoiCount = selectedResources.filter((resource) => !hasPersistentIdentifier(resource)).length;

    const noSelectionReason = 'Select one or more resources first.';
    const singleRecordReason = 'This action can only be performed on a single record.';

    const resourceActions: Record<ResourcesActionKey, ResourcesActionState> = {
        edit: {
            available: selectedCount > 0,
            reason: noSelectionReason,
        },
        'setup-landing-page': {
            visible: canManageLandingPages,
            available: selectedCount === 1,
            reason: selectedCount === 0 ? noSelectionReason : singleRecordReason,
        },
        'manage-related-items': {
            available: selectedCount === 1 && !citationVocabulariesLoading,
            reason: selectedCount === 0 ? noSelectionReason : selectedCount > 1 ? singleRecordReason : 'Related item vocabularies are still loading.',
        },
        'export-datacite-json': {
            available: selectedCount > 0,
            reason: noSelectionReason,
            loading: isBulkExporting,
        },
        'export-datacite-xml': {
            available: selectedCount > 0,
            reason: noSelectionReason,
            loading: isBulkExporting,
        },
        'export-jsonld': {
            available: selectedCount > 0,
            reason: noSelectionReason,
            loading: isBulkExporting,
        },
        'register-doi': {
            visible: canRegisterDoi,
            available:
                selectedCount === 1 &&
                singleSelectedResource !== null &&
                !hasPersistentIdentifier(singleSelectedResource) &&
                Boolean(singleSelectedResource.landingPage),
            reason:
                selectedCount === 0
                    ? noSelectionReason
                    : selectedCount > 1
                      ? 'Bulk DOI registration is not available because DOI minting requires a prefix selection. Register DOI-less resources one at a time.'
                      : singleSelectedResource && hasPersistentIdentifier(singleSelectedResource)
                        ? 'Register DOI is only available for resources without a DOI. Use Update metadata for registered resources.'
                        : singleSelectedResource && !singleSelectedResource.landingPage
                          ? 'A landing page must be set up before registering a DOI.'
                          : undefined,
            loading: isBulkRegistering,
        },
        'update-metadata': {
            visible: canRegisterDoi,
            available: selectedCount > 0 && selectedWithoutDoiCount === 0 && selectedWithoutLandingPageCount === 0,
            reason:
                selectedCount === 0
                    ? noSelectionReason
                    : selectedWithoutDoiCount > 0
                      ? `Update metadata is only available for resources that already have a DOI. ${formatSelectionCount(selectedWithoutDoiCount, 'selected resource has', 'selected resources have')} no DOI.`
                      : selectedWithoutLandingPageCount > 0
                        ? `${formatSelectionCount(selectedWithoutLandingPageCount, 'selected resource is', 'selected resources are')} missing a landing page.`
                        : undefined,
            loading: isBulkRegistering,
        },
        delete: {
            visible: canDeleteResources,
            available: selectedCount > 0,
            reason: selectedCount === 0 ? noSelectionReason : undefined,
            loading: isDeletingResource,
        },
    };

    const handleUnavailableAction = useCallback((reason: string) => {
        toast.error(reason);
    }, []);

    const handleResourceAction = useCallback(
        (action: ResourcesActionKey) => {
            switch (action) {
                case 'edit':
                    handleEditSelectedResources();
                    break;
                case 'setup-landing-page':
                    if (singleSelectedResource) {
                        handleSetupLandingPage(singleSelectedResource);
                    }
                    break;
                case 'manage-related-items':
                    if (singleSelectedResource) {
                        setCitationManagerResourceId(singleSelectedResource.id);
                    }
                    break;
                case 'export-datacite-json':
                case 'export-datacite-xml':
                case 'export-jsonld':
                    void handleExportSelectedResources(action);
                    break;
                case 'register-doi':
                    if (singleSelectedResource) {
                        handleRegisterDoi(singleSelectedResource);
                    }
                    break;
                case 'update-metadata':
                    setIsUpdateMetadataDialogOpen(true);
                    break;
                case 'delete':
                    handleOpenDeleteDialog();
                    break;
            }
        },
        [
            handleEditSelectedResources,
            handleExportSelectedResources,
            handleOpenDeleteDialog,
            handleRegisterDoi,
            handleSetupLandingPage,
            singleSelectedResource,
        ],
    );

    const handleColumnResize = useCallback((columnKey: ResourceColumnKey, width: number, options: ColumnResizeOptions = {}) => {
        setColumnWidths((currentWidths) => {
            const nextWidth = clampColumnWidth(columnKey, width);
            const nextWidths = currentWidths[columnKey] === nextWidth ? currentWidths : { ...currentWidths, [columnKey]: nextWidth };

            if (options.persist) {
                persistResourceColumnWidths(nextWidths);
            }

            return nextWidths;
        });
    }, []);

    const handleResetColumnWidths = useCallback(() => {
        const defaultWidths = { ...DEFAULT_RESOURCE_COLUMN_WIDTHS };
        clearStoredResourceColumnWidths();
        setColumnWidths(defaultWidths);
    }, []);

    const sortedResources = resources;

    const resourceColumns: ResourceColumn[] = [
        {
            key: 'id_resourcetype',
            label: ID_RESOURCE_TYPE_COLUMN_HEADER_LABEL,
            cellClassName: 'align-middle',
            sortOptions: [
                {
                    key: 'id',
                    label: 'ID',
                    description: 'Sort by the resource ID',
                },
                {
                    key: 'resourcetypegeneral',
                    label: 'Type',
                    description: 'Sort by the resource type',
                },
            ],
            sortGroupLabel: 'Sort options for ID and resource type',
            render: (resource: Resource) => {
                const hasId = resource.id !== undefined && resource.id !== null;
                const idValue = hasId ? `#${resource.id}` : '-';
                const resourceType = resource.resourcetypegeneral ?? '-';

                return (
                    <div className="flex min-w-0 flex-col gap-1 text-left" aria-label={`Resource ID: ${idValue}. Resource Type: ${resourceType}`}>
                        <span
                            className={hasId ? 'text-sm font-semibold text-gray-900 dark:text-gray-100' : 'text-sm text-gray-500 dark:text-gray-300'}
                        >
                            {idValue}
                        </span>
                        <OverflowTooltipText value={resourceType} className="text-sm text-gray-600 dark:text-gray-300" />
                    </div>
                );
            },
        },
        {
            key: 'doi_title',
            label: DOI_TITLE_COLUMN_HEADER_LABEL,
            cellClassName: 'align-middle',
            sortOptions: [
                {
                    key: 'doi',
                    label: 'DOI',
                    description: 'Sort by the DOI',
                },
                {
                    key: 'title',
                    label: 'Title',
                    description: 'Sort by the resource title',
                },
            ],
            sortGroupLabel: 'Sort options for DOI and title',
            render: (resource: Resource) => {
                const title = resource.title ?? '-';
                const identifierValue = resource.doi || 'Not registered';
                // Lighter gray for "Not registered" text to de-emphasize missing DOI
                // Dark mode uses lighter shade (400) for better readability on dark backgrounds
                const identifierClasses = resource.doi
                    ? 'text-sm text-gray-600 dark:text-gray-300'
                    : 'text-sm text-gray-500 dark:text-gray-400 italic';

                return (
                    <div className="flex min-w-0 flex-col gap-1 text-left" aria-label={`DOI: ${identifierValue}. Title: ${title}`}>
                        <OverflowTooltipText value={identifierValue} className={identifierClasses} />
                        <OverflowTooltipText value={title} className="text-sm leading-relaxed font-normal text-gray-900 dark:text-gray-100" />
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
            cellClassName: 'align-middle',
            sortOptions: [
                {
                    key: 'first_author',
                    label: 'Author',
                    description: "Sort by the first author's last name",
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
                    <div className="flex min-w-0 flex-col gap-1 text-left text-gray-600 dark:text-gray-300">
                        <OverflowTooltipText value={authorName} className="text-sm" />
                        <span className="text-sm">{year}</span>
                    </div>
                );
            },
        },
        {
            key: 'curator_status',
            label: (
                <span className="flex flex-col leading-tight normal-case">
                    <span className="hidden lg:inline">Curator</span>
                    <span>Status</span>
                </span>
            ),
            cellClassName: 'align-middle',
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
                const isClickable = (status === 'published' && resource.doi) || (status === 'review' && resource.landingPage?.public_url);

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
                } else if (status === 'draft') {
                    statusClasses += ' bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400';
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
                    <div className="flex min-w-0 flex-col gap-1 text-center text-gray-600 dark:text-gray-300">
                        <OverflowTooltipText value={curator} className="hidden text-sm lg:block" />
                        <span
                            className={cn(statusClasses, 'max-w-full')}
                            onClick={isClickable ? () => handleStatusBadgeClick(resource, status) : undefined}
                            role={isClickable ? 'button' : undefined}
                            tabIndex={isClickable ? 0 : undefined}
                            onKeyDown={
                                isClickable
                                    ? (e) => {
                                          if (e.key === 'Enter' || e.key === ' ') {
                                              e.preventDefault();
                                              handleStatusBadgeClick(resource, status);
                                          }
                                      }
                                    : undefined
                            }
                            aria-label={ariaLabel}
                            title={ariaLabel}
                            data-resource-row-action={isClickable ? 'true' : undefined}
                        >
                            <span className="truncate">{statusLabel}</span>
                        </span>
                    </div>
                );
            },
        },
        {
            key: 'created_updated',
            label: DATE_COLUMN_HEADER_LABEL,
            visibilityClassName: 'hidden md:table-cell',
            colClassName: 'hidden md:table-column',
            cellClassName: 'hidden align-middle md:table-cell',
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
                    <div className={DATE_COLUMN_CONTAINER_CLASSES} aria-label={dateColumnAriaLabel}>
                        {renderDateContent(createdDetails)}
                        {renderDateContent(updatedDetails)}
                    </div>
                );
            },
        },
    ];

    const columnWidthsAreDefault = useMemo(() => areResourceColumnWidthsDefault(columnWidths), [columnWidths]);
    const resourcesTableWidth =
        SELECT_COLUMN_WIDTH +
        resourceColumns.reduce(
            (totalWidth, column) => totalWidth + (shouldIncludeColumnInLayout(column, tableCanResize) ? columnWidths[column.key] : 0),
            0,
        );

    const LoadingSkeleton = () => (
        <>
            {[...Array(5)].map((_, index) => (
                <tr key={`skeleton-${index}`} className="animate-pulse">
                    <td className="w-12 min-w-12 px-6 py-1.5 align-middle">
                        <div className="size-4 rounded bg-gray-200 dark:bg-gray-700" />
                    </td>
                    {resourceColumns.map((column) => (
                        <td key={column.key} className={cn('overflow-hidden px-6 py-1.5', column.visibilityClassName, column.cellClassName)}>
                            {column.key === 'id_resourcetype' ? (
                                <div className="flex flex-col gap-2">
                                    <div className="h-4 w-10 rounded bg-gray-200 dark:bg-gray-700"></div>
                                    <div className="h-4 w-24 rounded bg-gray-200 dark:bg-gray-700"></div>
                                </div>
                            ) : column.key === 'doi_title' ? (
                                <div className="flex flex-col gap-2">
                                    <div className="h-4 w-40 rounded bg-gray-200 dark:bg-gray-700"></div>
                                    <div className="h-4 w-3/4 rounded bg-gray-200 dark:bg-gray-700"></div>
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
                        <CardDescription>Overview of curated resources in ERNIE</CardDescription>
                    </CardHeader>
                    <CardContent>
                        {error ? (
                            <Alert className="mb-4" variant="destructive">
                                <AlertDescription>{error}</AlertDescription>
                            </Alert>
                        ) : null}

                        {loadingError && (
                            <Alert className="mb-4" variant="destructive">
                                <AlertDescription>
                                    {loadingError}
                                    <Button variant="outline" size="sm" className="ml-2" onClick={handleRetry} disabled={loading}>
                                        Retry
                                    </Button>
                                </AlertDescription>
                            </Alert>
                        )}

                        {/* Filter Component - Always visible so filters can be adjusted */}
                        <ResourcesFilters
                            filters={filters}
                            onFilterChange={handleFilterChange}
                            filterOptions={filterOptions}
                            resultCount={sortedResources.length}
                            totalCount={pagination.total}
                            isLoading={loading}
                        />

                        {/* Bulk action toolbar + Import button (mirrors IGSN pattern) */}
                        <div className="mt-4 flex flex-wrap items-center gap-2">
                            <ResourcesBulkActionsToolbar
                                selectedCount={selectedCount}
                                actions={resourceActions}
                                onAction={handleResourceAction}
                                onUnavailableAction={handleUnavailableAction}
                            />
                            {canImportFromDataCite && (
                                <div className="ml-auto flex flex-wrap items-center gap-2">
                                    <Button
                                        variant="outline"
                                        size="default"
                                        onClick={() => setShowImportModal(true)}
                                        className="flex items-center gap-2"
                                    >
                                        <DataCiteIcon className="size-4" aria-hidden="true" />
                                        Import all old Resources
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="default"
                                        onClick={() => setShowDatacenterImportModal(true)}
                                        className="flex items-center gap-2"
                                    >
                                        <DataCiteIcon className="size-4" aria-hidden="true" />
                                        Import all Resources from a Datacenter
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="default"
                                        onClick={() => setShowSingleImportModal(true)}
                                        className="flex items-center gap-2"
                                    >
                                        <DataCiteIcon className="size-4" aria-hidden="true" />
                                        Import old single Resource
                                    </Button>
                                </div>
                            )}
                        </div>

                        {sortedResources.length === 0 && !loading && !loadingError ? (
                            <div className="py-8 text-center text-muted-foreground">
                                {error
                                    ? 'No resources available. Please check the database connection.'
                                    : 'No resources found matching your filters.'}
                            </div>
                        ) : (
                            <>
                                <div className="mb-4 flex flex-wrap items-center gap-2">
                                    <Badge variant="outline" className="text-xs">
                                        Sorted by: {getSortLabel(sortState.key)} {sortState.direction === 'asc' ? 'ascending' : 'descending'}
                                    </Badge>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        className="h-7 gap-1.5 text-xs"
                                        onClick={handleResetColumnWidths}
                                        disabled={columnWidthsAreDefault}
                                        title="Reset resource table column widths"
                                        data-testid="resources-reset-column-widths"
                                    >
                                        <RotateCcw aria-hidden="true" className="size-3.5" />
                                        Reset column widths
                                    </Button>
                                </div>
                                <div className="overflow-x-auto">
                                    <Table className="table-fixed" data-testid="resources-table" style={{ width: `${resourcesTableWidth}px` }}>
                                        <caption className="sr-only">
                                            List of resources with metadata including title, type, DOI, contributors, language, and version
                                        </caption>
                                        <colgroup>
                                            <col data-testid="resources-column-select" style={{ width: SELECT_COLUMN_WIDTH }} />
                                            {resourceColumns.map((column) => (
                                                <col
                                                    key={column.key}
                                                    className={column.colClassName}
                                                    data-testid={`resources-column-${column.key}`}
                                                    style={{
                                                        width: shouldIncludeColumnInLayout(column, tableCanResize) ? columnWidths[column.key] : 0,
                                                    }}
                                                />
                                            ))}
                                        </colgroup>
                                        <TableHeader className="bg-gray-50 dark:bg-gray-800">
                                            <TableRow>
                                                <TableHead className="w-12 min-w-12">
                                                    <Checkbox
                                                        checked={allVisibleSelected ? true : someVisibleSelected ? 'indeterminate' : false}
                                                        onCheckedChange={(value) => handleSelectAll(value === true)}
                                                        aria-label="Select all visible resources"
                                                        data-testid="resources-select-all"
                                                    />
                                                </TableHead>

                                                {resourceColumns.map((column) => {
                                                    const isColumnSorted =
                                                        column.sortOptions?.some((option) => option.key === sortState.key) ?? false;
                                                    const ariaSortValue = isColumnSorted
                                                        ? sortState.direction === 'asc'
                                                            ? 'ascending'
                                                            : 'descending'
                                                        : 'none';

                                                    return (
                                                        <TableHead
                                                            key={column.key}
                                                            className={cn(
                                                                'relative pr-6 text-xs tracking-wider text-gray-500 uppercase dark:text-gray-300',
                                                                column.visibilityClassName,
                                                            )}
                                                            aria-sort={column.sortOptions ? ariaSortValue : undefined}
                                                            scope="col"
                                                        >
                                                            {column.sortOptions ? (
                                                                <div
                                                                    className="flex min-w-0 flex-col gap-1 pr-1"
                                                                    role="group"
                                                                    aria-label={column.sortGroupLabel ?? 'Sorting options'}
                                                                >
                                                                    {column.sortOptions.map((option) => {
                                                                        const isActive = sortState.key === option.key;
                                                                        const displayDirection = resolveDisplayDirection(option, sortState);
                                                                        const buttonLabel = buildSortButtonLabel(option, sortState);

                                                                        return (
                                                                            <Button
                                                                                key={option.key}
                                                                                type="button"
                                                                                variant={isActive ? 'secondary' : 'ghost'}
                                                                                size="sm"
                                                                                className="h-7 min-w-0 justify-start px-2 text-xs font-medium"
                                                                                onClick={() => handleSortChange(option.key)}
                                                                                aria-pressed={isActive}
                                                                                aria-label={buttonLabel}
                                                                                title={buttonLabel}
                                                                            >
                                                                                <span className="truncate">{option.label}</span>
                                                                                <SortDirectionIndicator
                                                                                    isActive={isActive}
                                                                                    direction={displayDirection}
                                                                                />
                                                                            </Button>
                                                                        );
                                                                    })}
                                                                </div>
                                                            ) : (
                                                                <div className="min-w-0 text-left text-xs font-semibold tracking-wider text-gray-500 uppercase dark:text-gray-300">
                                                                    {column.label}
                                                                </div>
                                                            )}
                                                            <ColumnResizeHandle
                                                                columnKey={column.key}
                                                                width={columnWidths[column.key]}
                                                                disabled={!tableCanResize}
                                                                onResize={handleColumnResize}
                                                            />
                                                        </TableHead>
                                                    );
                                                })}
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {loading && sortedResources.length === 0 && <LoadingSkeleton />}
                                            {sortedResources.map((resource, index) => {
                                                const isLast = index === sortedResources.length - 1;
                                                const hasEditableResource = typeof resource.id === 'number';
                                                const resourceLabel =
                                                    resource.doi ?? resource.title ?? (hasEditableResource ? `#${resource.id}` : 'entry');

                                                return (
                                                    <TableRow
                                                        key={deriveResourceRowKey(resource)}
                                                        className={cn(
                                                            'hover:bg-gray-50 dark:hover:bg-gray-800',
                                                            hasEditableResource &&
                                                                'cursor-pointer focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none',
                                                        )}
                                                        ref={isLast ? lastResourceElementRef : null}
                                                        data-state={hasEditableResource && selectedIds.has(resource.id) ? 'selected' : undefined}
                                                        tabIndex={hasEditableResource ? 0 : undefined}
                                                        aria-label={hasEditableResource ? `Open resource ${resourceLabel} in editor` : undefined}
                                                        onClick={hasEditableResource ? (event) => handleResourceRowClick(resource, event) : undefined}
                                                        onKeyDown={
                                                            hasEditableResource ? (event) => handleResourceRowKeyDown(resource, event) : undefined
                                                        }
                                                    >
                                                        <TableCell className="w-12 min-w-12 align-middle">
                                                            {resource.id !== undefined && (
                                                                <Checkbox
                                                                    checked={selectedIds.has(resource.id)}
                                                                    onCheckedChange={(value) => handleSelectOne(resource.id!, value === true)}
                                                                    aria-label={`Select resource ${resourceLabel}`}
                                                                    data-testid={`resources-row-checkbox-${resource.id}`}
                                                                />
                                                            )}
                                                        </TableCell>
                                                        {resourceColumns.map((column) => (
                                                            <TableCell
                                                                key={column.key}
                                                                className={cn(
                                                                    'overflow-hidden text-sm text-gray-500 dark:text-gray-300',
                                                                    column.visibilityClassName,
                                                                    column.cellClassName,
                                                                )}
                                                            >
                                                                {column.render
                                                                    ? column.render(resource)
                                                                    : formatValue(column.key, resource[column.key])}
                                                            </TableCell>
                                                        ))}
                                                    </TableRow>
                                                );
                                            })}
                                            {loading && sortedResources.length > 0 && <LoadingSkeleton />}
                                        </TableBody>
                                    </Table>

                                    {!loading && !pagination.has_more && sortedResources.length > 0 && (
                                        <div className="py-4 text-center text-sm text-muted-foreground">
                                            All resources have been loaded ({pagination.total} total)
                                        </div>
                                    )}
                                </div>
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

            {/* Import from DataCite Modal */}
            <ImportFromDataCiteModal isOpen={showImportModal} onClose={() => setShowImportModal(false)} onSuccess={handleImportSuccess} />

            {/* Import old resources for one portal datacenter */}
            <ImportFromDataCiteModal
                isOpen={showDatacenterImportModal}
                onClose={() => setShowDatacenterImportModal(false)}
                onSuccess={handleImportSuccess}
                mode="datacenter"
            />

            {/* Import Single Old Resource Modal */}
            <ImportSingleOldResourceModal
                isOpen={showSingleImportModal}
                onClose={() => setShowSingleImportModal(false)}
                onSuccess={handleImportSuccess}
            />

            <Dialog
                open={blockedEditorTabs.length > 0}
                onOpenChange={(open) => {
                    if (!open) {
                        setBlockedEditorTabs([]);
                    }
                }}
            >
                <DialogContent data-testid="blocked-editor-tabs-dialog">
                    <DialogHeader>
                        <DialogTitle>Open blocked editor tabs</DialogTitle>
                        <DialogDescription>
                            Your browser blocked one or more editor tabs. Open the resources below, or allow pop-ups for ERNIE and try again.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-2">
                        {blockedEditorTabs.map((tab) => (
                            <Button
                                key={tab.id}
                                asChild
                                variant="outline"
                                className="h-auto min-h-9 w-full items-start justify-between gap-2 py-2 text-left whitespace-normal"
                            >
                                <a href={tab.url} target="_blank" rel="noopener noreferrer">
                                    <span className="min-w-0 flex-1 text-left wrap-break-word whitespace-normal">{tab.label}</span>
                                    <ExternalLink aria-hidden="true" className="size-4 shrink-0" />
                                </a>
                            </Button>
                        ))}
                    </div>
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button">Done</Button>
                        </DialogClose>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            <AlertDialog open={isUpdateMetadataDialogOpen} onOpenChange={handleUpdateMetadataDialogOpenChange}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Update metadata?</AlertDialogTitle>
                        <AlertDialogDescription>
                            This will update metadata at DataCite for {selectedCount} {selectedCount === 1 ? 'resource' : 'resources'}.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={isBulkRegistering}>Cancel</AlertDialogCancel>
                        <AlertDialogAction onClick={handleConfirmUpdateMetadata} disabled={isBulkRegistering}>
                            {isBulkRegistering ? 'Updating...' : 'Update metadata'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <AlertDialog open={isDeleteDialogOpen} onOpenChange={handleDeleteDialogOpenChange}>
                <AlertDialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete selected resources?</AlertDialogTitle>
                        <AlertDialogDescription>Choose which selected resource groups should be permanently removed.</AlertDialogDescription>
                    </AlertDialogHeader>

                    <div className="space-y-4 text-sm" data-testid="resources-delete-confirmation-groups">
                        {publishedDeleteCount > 0 && (
                            <div className="rounded-md border border-destructive/30 bg-destructive/5 p-3 text-destructive">
                                You have selected {selectedCount} {selectedCount === 1 ? 'resource' : 'resources'} for deletion. Of these,{' '}
                                {publishedDeleteCount} {publishedDeleteCount === 1 ? 'resource cannot' : 'resources cannot'} be deleted because{' '}
                                {publishedDeleteCount === 1 ? 'it is' : 'they are'} already registered.
                            </div>
                        )}

                        {hasPreviewDeleteSelection && (
                            <div className="rounded-md border border-amber-300 bg-amber-50 p-3 text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">
                                If you confirm deletion for preview resources, their preview pages will be deleted. Please ensure users are informed
                                that these links will no longer be available.
                            </div>
                        )}

                        <div className="space-y-2">
                            {DELETABLE_DELETE_STATUSES.map((status) => {
                                const count = selectedDeleteGroups[status].length;

                                if (count === 0) {
                                    return null;
                                }

                                const checkboxId = `resources-delete-group-${status}`;

                                return (
                                    <div key={status} className="rounded-md border p-3" data-testid={`resources-delete-group-${status}`}>
                                        <div className="flex items-start gap-3">
                                            <Checkbox
                                                id={checkboxId}
                                                checked={selectedDeleteStatuses[status]}
                                                onCheckedChange={(value) =>
                                                    setSelectedDeleteStatuses((current) => ({ ...current, [status]: value === true }))
                                                }
                                                disabled={isDeletingResource}
                                                data-testid={`resources-delete-group-${status}-checkbox`}
                                            />
                                            <label htmlFor={checkboxId} className="grid gap-1 leading-none">
                                                <span className="font-medium">Delete {formatDeleteStatusCount(status, count)}</span>
                                                <span className="text-muted-foreground">{DELETE_STATUS_DESCRIPTIONS[status]}</span>
                                            </label>
                                        </div>
                                    </div>
                                );
                            })}

                            {publishedDeleteCount > 0 && (
                                <div className="rounded-md border border-muted bg-muted/40 p-3" data-testid="resources-delete-group-published">
                                    <p className="font-medium">{formatDeleteStatusCount('published', publishedDeleteCount)} cannot be deleted.</p>
                                    <p className="mt-1 text-muted-foreground">Published resources are already registered and must remain in ERNIE.</p>
                                </div>
                            )}
                        </div>

                        {!hasAnyDeletableDeleteSelection && <p className="text-muted-foreground">No selected resources can be deleted.</p>}

                        {hasAnyDeletableDeleteSelection && (
                            <p className="text-muted-foreground">
                                This action cannot be undone. If you wish to back up the data, download and save it as an XML file before deleting it.
                            </p>
                        )}
                    </div>

                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={isDeletingResource}>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            variant="destructive"
                            onClick={handleConfirmDelete}
                            disabled={isDeletingResource || selectedDeletableDeleteCount === 0}
                        >
                            {isDeletingResource
                                ? 'Deleting...'
                                : selectedDeletableDeleteCount === 1
                                  ? 'Delete Resource'
                                  : `Delete ${selectedDeletableDeleteCount} Resources`}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
            {/* JSON Validation Error Modal */}
            <ValidationErrorModal
                open={isValidationModalOpen}
                onOpenChange={setIsValidationModalOpen}
                errors={validationErrors}
                resourceType="Resource"
                schemaVersion={validationSchemaVersion}
            />

            {/* Related Item Manager Modal (DataCite 4.7 relatedItem) */}
            {citationManagerResourceId !== null && (
                <CitationManagerModal
                    open={citationManagerResourceId !== null}
                    onOpenChange={(open) => {
                        if (!open) setCitationManagerResourceId(null);
                    }}
                    resourceId={citationManagerResourceId}
                    resourceTypes={citationVocabularies.resourceTypes}
                    relationTypes={citationVocabularies.relationTypes}
                    contributorTypes={citationVocabularies.contributorTypes}
                />
            )}
        </AppLayout>
    );
}

export default ResourcesPage;

export { deriveResourceRowKey, OverflowTooltipText };
