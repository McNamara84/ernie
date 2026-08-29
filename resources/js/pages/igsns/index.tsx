import { Head, router } from '@inertiajs/react';
import axios, { isAxiosError } from 'axios';
import { Braces, ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight, CloudUpload, Download, FileJson, Globe, RefreshCw } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import { DataCiteUrlUpdateModal, type DataCiteUrlUpdateRun } from '@/components/datacite-url-update-modal';
import { DataCiteIcon } from '@/components/icons/datacite-icon';
import { BulkActionsToolbar } from '@/components/igsns/bulk-actions-toolbar';
import { type IgsnFilterOptions, IgsnFilters, type IgsnFilterState } from '@/components/igsns/igsn-filters';
import ImportIgsnsModal from '@/components/igsns/modals/ImportIgsnsModal';
import ImportSingleIgsnModal from '@/components/igsns/modals/ImportSingleIgsnModal';
import { IgsnStatusBadge } from '@/components/igsns/status-badge';
import SetupIgsnLandingPageModal from '@/components/landing-pages/modals/SetupIgsnLandingPageModal';
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
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { SortableTableHeader, type SortDirection, type SortState } from '@/components/ui/sortable-table-header';
import { Spinner } from '@/components/ui/spinner';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { type ValidationError, ValidationErrorModal } from '@/components/ui/validation-error-modal';
import AppLayout from '@/layouts/app-layout';
import { extractErrorMessageFromBlob, parseValidationErrorFromBlob } from '@/lib/blob-utils';
import {
    clearStoredIgsnDatacenterFilter,
    persistIgsnDatacenterFilter,
    readStoredIgsnDatacenterFilter,
    storedIgsnDatacenterFilterToState,
} from '@/lib/igsns-datacenter-filter-storage';
import { IGSNS_PAGE_SIZE_OPTIONS, isIgsnsPageSize, persistIgsnsPageSize, readStoredIgsnsPageSize } from '@/lib/igsns-page-size-storage';
import { type BreadcrumbItem } from '@/types';

// ============================================================================
// Types
// ============================================================================

interface Igsn {
    id: number;
    igsn: string | null;
    title: string;
    sample_type: string | null;
    material: string | null;
    collection_date: string | null;
    latitude: number | null;
    longitude: number | null;
    upload_status: string;
    upload_error_message: string | null;
    parent_resource_id: number | null;
    collector: string | null;
    has_landing_page: boolean;
    created_at: string | null;
    updated_at: string | null;
}

interface PaginationInfo {
    current_page: number;
    last_page: number | null;
    per_page: number;
    total: number | null;
    from: number | null;
    to: number | null;
    has_more: boolean;
    count_status: 'pending' | 'ready' | 'failed';
    filter_fingerprint: string;
}

interface IgsnCountResponse {
    filter_fingerprint: string;
    filtered_total: number;
    inventory_total: number;
    last_page: number;
    count_status: 'ready';
}

interface IgsnsPageProps {
    igsns: Igsn[];
    pagination: PaginationInfo;
    sort: SortState<SortKey>;
    canDelete: boolean;
    canImport: boolean;
    canRegister: boolean;
    canUpdateDataCiteLandingPageUrls?: boolean;
    dataCiteUrlUpdateRun?: DataCiteUrlUpdateRun | null;
    igsnPrefix: string;
    search: string;
    totalCount: number | null;
    filters: {
        prefix: string;
        status: string;
        datacenter_id?: number;
        without_datacenter?: boolean;
    };
    filterOptions: IgsnFilterOptions;
}

// ============================================================================
// Constants
// ============================================================================

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'IGSNs',
        href: '/igsns',
    },
];

type SortKey = 'id' | 'igsn' | 'title' | 'sample_type' | 'material' | 'collection_date' | 'upload_status' | 'created_at' | 'updated_at';

const DEFAULT_SORT: SortState<SortKey> = { key: 'updated_at', direction: 'desc' };

const DEFAULT_DIRECTION_BY_KEY: Record<SortKey, SortDirection> = {
    id: 'asc',
    igsn: 'asc',
    title: 'asc',
    sample_type: 'asc',
    material: 'asc',
    collection_date: 'desc',
    upload_status: 'asc',
    created_at: 'desc',
    updated_at: 'desc',
};

// ============================================================================
// Helper Functions
// ============================================================================
// ============================================================================

const formatDateRange = (dateString: string | null): { start: string; end: string | null } => {
    if (!dateString) return { start: '-', end: null };

    // Check if it's a date range (contains " – " separator)
    if (dateString.includes(' – ')) {
        const [start, end] = dateString.split(' – ');
        return { start, end };
    }

    return { start: dateString, end: null };
};

const determineNextDirection = (currentState: SortState<SortKey>, targetKey: SortKey): SortDirection => {
    if (currentState.key !== targetKey) {
        return DEFAULT_DIRECTION_BY_KEY[targetKey];
    }
    return currentState.direction === 'asc' ? 'desc' : 'asc';
};

// ============================================================================
// Main Component
// ============================================================================

function IgsnsPage({
    igsns: initialIgsns,
    pagination: initialPagination,
    sort: initialSort,
    canDelete,
    canImport,
    canRegister,
    canUpdateDataCiteLandingPageUrls,
    dataCiteUrlUpdateRun,
    igsnPrefix,
    search: initialSearch,
    totalCount: initialTotalCount,
    filters: initialFilters,
    filterOptions: initialFilterOptions,
}: IgsnsPageProps) {
    const [igsns, setIgsns] = useState<Igsn[]>(initialIgsns);
    const [pagination, setPagination] = useState<PaginationInfo>(initialPagination);
    const [totalCount, setTotalCount] = useState<number | null>(initialTotalCount);
    const [sortState, setSortState] = useState<SortState<SortKey>>(initialSort || DEFAULT_SORT);
    const [searchQuery, setSearchQuery] = useState(initialSearch || '');
    const [isNavigating, setIsNavigating] = useState(false);
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [isDeleting, setIsDeleting] = useState(false);
    const [exportingIgsns, setExportingIgsns] = useState<Set<number>>(new Set());
    const [exportingJsonLdIgsns, setExportingJsonLdIgsns] = useState<Set<number>>(new Set());
    const [validationErrors, setValidationErrors] = useState<ValidationError[]>([]);
    const [isValidationModalOpen, setIsValidationModalOpen] = useState(false);
    const [validationSchemaVersion, setValidationSchemaVersion] = useState<string>('4.6');
    const [isImportModalOpen, setIsImportModalOpen] = useState(false);
    const [isDatacenterImportModalOpen, setIsDatacenterImportModalOpen] = useState(false);
    const [isSingleImportModalOpen, setIsSingleImportModalOpen] = useState(false);
    const [isLandingPageModalOpen, setIsLandingPageModalOpen] = useState(false);
    const [isDataCiteUrlUpdateModalOpen, setIsDataCiteUrlUpdateModalOpen] = useState(false);
    const [selectedIgsnForLandingPage, setSelectedIgsnForLandingPage] = useState<Igsn | null>(null);
    const [registeringIgsns, setRegisteringIgsns] = useState<Set<number>>(new Set());
    const [isBulkRegistering, setIsBulkRegistering] = useState(false);

    // Filter state
    const [activeFilters, setActiveFilters] = useState<IgsnFilterState>({
        search: initialSearch || undefined,
        prefix: initialFilters?.prefix || undefined,
        status: initialFilters?.status || undefined,
        datacenter_id: initialFilters?.datacenter_id,
        without_datacenter: initialFilters?.without_datacenter || undefined,
    });
    // Filter options are delivered as Inertia props to avoid extra network requests on remount
    const [filterOptions, setFilterOptions] = useState<IgsnFilterOptions>(initialFilterOptions);
    const attemptedPreferenceRestoreRef = useRef(false);

    // Selection state for bulk actions
    const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set());

    // Update state when props change (after navigation)
    useEffect(() => {
        setIgsns(initialIgsns);
        setPagination(initialPagination);
        setTotalCount(initialTotalCount);
        setSortState(initialSort || DEFAULT_SORT);
        setSearchQuery(initialSearch || '');
        setActiveFilters({
            search: initialSearch || undefined,
            prefix: initialFilters?.prefix || undefined,
            status: initialFilters?.status || undefined,
            datacenter_id: initialFilters?.datacenter_id,
            without_datacenter: initialFilters?.without_datacenter || undefined,
        });
        setFilterOptions(initialFilterOptions);
        // Clear selection when data changes (e.g., after pagination or sorting)
        setSelectedIds(new Set());
    }, [initialIgsns, initialPagination, initialSort, initialSearch, initialFilters, initialFilterOptions, initialTotalCount]);

    const handleExportJson = useCallback(async (igsn: Igsn) => {
        // Mark IGSN as exporting
        setExportingIgsns((prev) => new Set(prev).add(igsn.id));

        try {
            const response = await axios.get(`/igsns/${igsn.id}/export/json`, {
                responseType: 'blob',
            });

            // Create blob from response
            const blob = new Blob([response.data], { type: 'application/json' });

            // Get filename from Content-Disposition header or generate it
            const contentDisposition = response.headers['content-disposition'] as string | undefined;
            let filename = `igsn-${igsn.igsn ?? `resource-${igsn.id}`}.json`;

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

            if (isAxiosError(error) && error.response?.status === 422 && error.response?.data) {
                // Validation error - show modal with details
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
        } finally {
            // Remove IGSN from exporting set
            setExportingIgsns((prev) => {
                const next = new Set(prev);
                next.delete(igsn.id);
                return next;
            });
        }
    }, []);

    const handleExportJsonLd = useCallback(async (igsn: Igsn) => {
        setExportingJsonLdIgsns((prev) => new Set(prev).add(igsn.id));

        try {
            const response = await axios.get(`/igsns/${igsn.id}/export/jsonld`, {
                responseType: 'blob',
            });

            const blob = new Blob([response.data], { type: 'application/ld+json' });

            const contentDisposition = response.headers['content-disposition'] as string | undefined;
            let filename = `igsn-${igsn.igsn ?? `resource-${igsn.id}`}.jsonld`;

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
        } finally {
            setExportingJsonLdIgsns((prev) => {
                const next = new Set(prev);
                next.delete(igsn.id);
                return next;
            });
        }
    }, []);

    const buildParams = useCallback(
        (overrides: { sort?: SortState<SortKey>; search?: string; page?: number; perPage?: number; filters?: IgsnFilterState } = {}) => {
            const sort = overrides.sort ?? sortState;
            const currentFilters = overrides.filters ?? activeFilters;
            const search = overrides.search ?? currentFilters.search ?? searchQuery;
            const params = new URLSearchParams();
            params.set('sort', sort.key);
            params.set('direction', sort.direction);
            if (search) {
                params.set('search', search);
            }
            if (currentFilters.prefix) {
                params.set('prefix', currentFilters.prefix);
            }
            if (currentFilters.status) {
                params.set('status', currentFilters.status);
            }
            if (currentFilters.without_datacenter) {
                params.set('without_datacenter', '1');
            } else if (currentFilters.datacenter_id) {
                params.set('datacenter_id', String(currentFilters.datacenter_id));
            }
            if (overrides.page && overrides.page > 1) {
                params.set('page', String(overrides.page));
            }
            // Always carry the current page size so navigation never silently resets it.
            params.set('per_page', String(overrides.perPage ?? pagination.per_page));
            return params;
        },
        [sortState, searchQuery, activeFilters, pagination.per_page],
    );

    useEffect(() => {
        if (!initialPagination.filter_fingerprint || initialPagination.count_status === 'ready') {
            return;
        }

        const controller = new AbortController();
        const expectedFingerprint = initialPagination.filter_fingerprint;
        const params = new URLSearchParams({ per_page: String(initialPagination.per_page) });

        if (initialSearch) params.set('search', initialSearch);
        if (initialFilters.prefix) params.set('prefix', initialFilters.prefix);
        if (initialFilters.status) params.set('status', initialFilters.status);
        if (initialFilters.without_datacenter) {
            params.set('without_datacenter', '1');
        } else if (initialFilters.datacenter_id) {
            params.set('datacenter_id', String(initialFilters.datacenter_id));
        }

        void axios
            .get<IgsnCountResponse>('/igsns/count', { params, signal: controller.signal })
            .then(({ data }) => {
                if (data.filter_fingerprint !== expectedFingerprint) {
                    return;
                }

                setPagination((current) =>
                    current.filter_fingerprint === data.filter_fingerprint
                        ? {
                              ...current,
                              total: data.filtered_total,
                              last_page: data.last_page,
                              count_status: 'ready',
                          }
                        : current,
                );
                setTotalCount(data.inventory_total);
            })
            .catch((error: unknown) => {
                if (controller.signal.aborted || (isAxiosError(error) && error.code === 'ERR_CANCELED')) {
                    return;
                }

                setPagination((current) => (current.filter_fingerprint === expectedFingerprint ? { ...current, count_status: 'failed' } : current));
            });

        return () => controller.abort();
    }, [
        initialFilters.datacenter_id,
        initialFilters.prefix,
        initialFilters.status,
        initialFilters.without_datacenter,
        initialPagination.count_status,
        initialPagination.current_page,
        initialPagination.filter_fingerprint,
        initialPagination.per_page,
        initialSearch,
    ]);

    useEffect(() => {
        if (attemptedPreferenceRestoreRef.current || typeof window === 'undefined') {
            return;
        }

        attemptedPreferenceRestoreRef.current = true;

        const searchParams = new URLSearchParams(window.location.search);
        const storedPageSize = readStoredIgsnsPageSize();
        const shouldRestorePageSize = !searchParams.has('per_page') && storedPageSize !== null && storedPageSize !== pagination.per_page;
        let restoredFilters: IgsnFilterState | null = null;

        if (window.location.pathname === '/igsns' && window.location.search === '') {
            const storedFilter = readStoredIgsnDatacenterFilter();

            if (
                storedFilter?.type === 'datacenter' &&
                !(filterOptions.datacenters ?? []).some((datacenter) => datacenter.id === storedFilter.datacenterId)
            ) {
                clearStoredIgsnDatacenterFilter();
            } else if (storedFilter) {
                restoredFilters = storedIgsnDatacenterFilterToState(storedFilter);
                setActiveFilters(restoredFilters);
            }
        }

        if (!shouldRestorePageSize && restoredFilters === null) {
            return;
        }

        const params = buildParams({
            ...(restoredFilters === null ? {} : { filters: restoredFilters, search: '' }),
            ...(shouldRestorePageSize ? { perPage: storedPageSize } : {}),
        });
        setIsNavigating(true);
        router.visit(`/igsns?${params.toString()}`, {
            preserveState: false,
            replace: true,
            onFinish: () => setIsNavigating(false),
        });
    }, [buildParams, filterOptions.datacenters, pagination.per_page]);

    const handlePageChange = useCallback(
        (page: number) => {
            if (page < 1 || (pagination.last_page !== null && page > pagination.last_page) || page === pagination.current_page) {
                return;
            }

            const params = buildParams({ page });
            setIsNavigating(true);
            router.visit(`/igsns?${params.toString()}`, {
                preserveState: false,
                replace: true,
                onFinish: () => setIsNavigating(false),
            });
        },
        [buildParams, pagination.current_page, pagination.last_page],
    );

    const handlePageSizeChange = useCallback(
        (value: string) => {
            const pageSize = Number(value);
            if (!isIgsnsPageSize(pageSize) || pageSize === pagination.per_page) {
                return;
            }

            persistIgsnsPageSize(pageSize);
            const params = buildParams({ page: 1, perPage: pageSize });
            setIsNavigating(true);
            router.visit(`/igsns?${params.toString()}`, {
                preserveState: false,
                replace: true,
                onFinish: () => setIsNavigating(false),
            });
        },
        [buildParams, pagination.per_page],
    );

    const handleSortChange = useCallback(
        (key: SortKey) => {
            const newDirection = determineNextDirection(sortState, key);
            const newSort = { key, direction: newDirection };
            setSortState(newSort);

            const params = buildParams({ sort: newSort });
            setIsNavigating(true);
            router.visit(`/igsns?${params.toString()}`, {
                preserveState: false,
                replace: true,
                onFinish: () => setIsNavigating(false),
            });
        },
        [sortState, buildParams],
    );

    const handleFilterChange = useCallback(
        (newFilters: IgsnFilterState) => {
            setActiveFilters(newFilters);
            setSearchQuery(newFilters.search || '');
            persistIgsnDatacenterFilter(newFilters);

            const params = buildParams({ filters: newFilters, search: newFilters.search || '' });
            setIsNavigating(true);
            router.visit(`/igsns?${params.toString()}`, {
                preserveState: false,
                replace: true,
                onFinish: () => setIsNavigating(false),
            });
        },
        [buildParams],
    );

    // Selection handlers for bulk actions
    const handleSelectAll = useCallback(
        (checked: boolean | 'indeterminate') => {
            // Only select all when explicitly checked (true), not for indeterminate state
            if (checked === true) {
                setSelectedIds(new Set(igsns.map((igsn) => igsn.id)));
            } else {
                setSelectedIds(new Set());
            }
        },
        [igsns],
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

    const handleBulkDeleteClick = useCallback(() => {
        if (selectedIds.size === 0) return;
        setDeleteDialogOpen(true);
    }, [selectedIds]);

    const handleSetupLandingPage = useCallback((igsn: Igsn) => {
        setSelectedIgsnForLandingPage(igsn);
        setIsLandingPageModalOpen(true);
    }, []);

    const handleCloseLandingPageModal = useCallback(() => {
        setIsLandingPageModalOpen(false);
        setSelectedIgsnForLandingPage(null);
    }, []);

    const handleRegister = useCallback(async (igsn: Igsn) => {
        setRegisteringIgsns((prev) => new Set(prev).add(igsn.id));
        try {
            const response = await axios.post<{ success: boolean; doi: string; mode: string; updated: boolean; message: string }>(
                `/igsns/${igsn.id}/register`,
            );
            const { doi, mode, updated } = response.data;

            toast.success(updated ? `Metadata updated at DataCite (${mode})` : `IGSN registered at DataCite (${mode}): ${doi}`);

            // Refresh page data to reflect new status
            router.reload();
        } catch (error) {
            const message =
                isAxiosError(error) && error.response?.data?.message
                    ? (error.response.data.message as string)
                    : 'Failed to register IGSN at DataCite';
            toast.error(message);
        } finally {
            setRegisteringIgsns((prev) => {
                const next = new Set(prev);
                next.delete(igsn.id);
                return next;
            });
        }
    }, []);

    const handleBulkRegister = useCallback(async () => {
        if (selectedIds.size === 0) return;

        // Check if any selected IGSNs lack a landing page
        const withoutLandingPage = igsns.filter((i) => selectedIds.has(i.id) && !i.has_landing_page);

        if (withoutLandingPage.length > 0) {
            toast.error(`${withoutLandingPage.length} IGSN(s) have no landing page and cannot be registered.`);
            return;
        }

        setIsBulkRegistering(true);
        try {
            const response = await axios.post<{ success: Array<{ id: number }>; failed: Array<{ id: number; reason: string }> }>(
                '/igsns/batch-register',
                { ids: Array.from(selectedIds) },
            );

            const { success, failed } = response.data;

            if (success.length > 0) {
                toast.success(`${success.length} IGSN(s) registered successfully.`);
            }
            if (failed.length > 0) {
                toast.error(`${failed.length} IGSN(s) failed to register.`);
            }

            setSelectedIds(new Set());
            router.reload();
        } catch (error) {
            const message =
                isAxiosError(error) && error.response?.data?.message ? (error.response.data.message as string) : 'Batch registration failed.';
            toast.error(message);
        } finally {
            setIsBulkRegistering(false);
        }
    }, [selectedIds, igsns]);

    const handleBulkDeleteConfirm = useCallback(() => {
        if (selectedIds.size === 0) return;

        setIsDeleting(true);
        router.delete('/igsns/batch', {
            data: { ids: Array.from(selectedIds) },
            onSuccess: () => {
                setDeleteDialogOpen(false);
                setSelectedIds(new Set());
                setIsDeleting(false);
            },
            onError: (errors) => {
                setIsDeleting(false);
                setDeleteDialogOpen(false);

                // Extract error message from Inertia errors object
                // Laravel may return 'ids' for array-level errors or 'ids.0', 'ids.1' for item-level errors
                let errorMessage = 'Failed to delete IGSNs. Please try again.';
                if (errors && typeof errors === 'object') {
                    if ('ids' in errors) {
                        errorMessage = errors.ids as string;
                    } else {
                        // Check for ids.* keys (e.g., ids.0, ids.1) from ids.* validation rule
                        const idsErrorKey = Object.keys(errors).find((key) => key.startsWith('ids.'));
                        if (idsErrorKey) {
                            errorMessage = errors[idsErrorKey] as string;
                        }
                    }
                }
                toast.error(errorMessage);
            },
        });
    }, [selectedIds]);

    // Computed values for selection state
    const allSelected = igsns.length > 0 && selectedIds.size === igsns.length;
    const someSelected = selectedIds.size > 0 && selectedIds.size < igsns.length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="IGSNs" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <div>
                                <CardTitle>Physical Samples (IGSNs)</CardTitle>
                                <CardDescription>Manage physical sample metadata with International Generic Sample Numbers.</CardDescription>
                            </div>
                            {(canImport || canUpdateDataCiteLandingPageUrls) && (
                                <div className="flex flex-wrap items-center gap-2">
                                    {canUpdateDataCiteLandingPageUrls && (
                                        <Button
                                            variant="outline"
                                            onClick={() => setIsDataCiteUrlUpdateModalOpen(true)}
                                            data-testid="igsns-datacite-url-update"
                                        >
                                            <DataCiteIcon className="mr-2 size-4" aria-hidden="true" />
                                            Update DataCite landing-page URLs
                                        </Button>
                                    )}
                                    {canImport && (
                                        <>
                                            <Button variant="outline" onClick={() => setIsImportModalOpen(true)}>
                                                <Download className="mr-2 h-4 w-4" />
                                                Import all IGSNs
                                            </Button>
                                            <Button variant="outline" onClick={() => setIsSingleImportModalOpen(true)}>
                                                <Download className="mr-2 h-4 w-4" />
                                                Import single IGSN
                                            </Button>
                                            <Button variant="outline" onClick={() => setIsDatacenterImportModalOpen(true)}>
                                                <Download className="mr-2 h-4 w-4" />
                                                Import by datacenter
                                            </Button>
                                        </>
                                    )}
                                </div>
                            )}
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div className="space-y-4">
                            {/* Filters (Search + Prefix + Status) */}
                            <IgsnFilters
                                filters={activeFilters}
                                onFilterChange={handleFilterChange}
                                filterOptions={filterOptions}
                                resultCount={pagination.total}
                                totalCount={totalCount}
                                countStatus={pagination.count_status}
                                isLoading={isNavigating}
                            />

                            {/* Bulk Actions Toolbar */}
                            <BulkActionsToolbar
                                selectedCount={selectedIds.size}
                                onDelete={handleBulkDeleteClick}
                                onRegister={canRegister ? handleBulkRegister : undefined}
                                canDelete={canDelete}
                                isDeleting={isDeleting}
                                isRegistering={isBulkRegistering}
                            />

                            {igsns.length === 0 ? (
                                <Alert>
                                    <AlertDescription>No IGSNs found. Upload a CSV file from the Dashboard to add physical samples.</AlertDescription>
                                </Alert>
                            ) : (
                                <Table containerClassName="max-h-[calc(100vh-350px)] rounded-md border">
                                    <TableHeader className="sticky top-0 z-10 bg-background">
                                        <TableRow>
                                            <TableHead className="w-12">
                                                <Checkbox
                                                    checked={allSelected}
                                                    indeterminate={someSelected}
                                                    onCheckedChange={handleSelectAll}
                                                    aria-label="Select all"
                                                />
                                            </TableHead>
                                            <TableHead className="w-32">Actions</TableHead>
                                            <SortableTableHeader<SortKey>
                                                label="IGSN"
                                                sortKey="igsn"
                                                sortState={sortState}
                                                onSort={handleSortChange}
                                                className="w-48"
                                            />
                                            <SortableTableHeader<SortKey>
                                                label="Title"
                                                sortKey="title"
                                                sortState={sortState}
                                                onSort={handleSortChange}
                                                className="min-w-[250px]"
                                            />
                                            <SortableTableHeader<SortKey>
                                                label="Sample Type"
                                                sortKey="sample_type"
                                                sortState={sortState}
                                                onSort={handleSortChange}
                                                className="w-36"
                                            />
                                            <SortableTableHeader<SortKey>
                                                label="Material"
                                                sortKey="material"
                                                sortState={sortState}
                                                onSort={handleSortChange}
                                                className="w-36"
                                            />
                                            <SortableTableHeader<SortKey>
                                                label="Collection Date"
                                                sortKey="collection_date"
                                                sortState={sortState}
                                                onSort={handleSortChange}
                                                className="w-40"
                                                defaultDirection="desc"
                                            />
                                            <SortableTableHeader<SortKey>
                                                label="Status"
                                                sortKey="upload_status"
                                                sortState={sortState}
                                                onSort={handleSortChange}
                                                className="w-28"
                                            />
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {igsns.map((igsn) => (
                                            <TableRow
                                                key={igsn.id}
                                                className={igsn.parent_resource_id ? 'bg-muted/30' : ''}
                                                data-state={selectedIds.has(igsn.id) ? 'selected' : undefined}
                                            >
                                                <TableCell>
                                                    <Checkbox
                                                        checked={selectedIds.has(igsn.id)}
                                                        onCheckedChange={(checked) => handleSelectOne(igsn.id, checked === true)}
                                                        aria-label={`Select ${igsn.igsn || igsn.title}`}
                                                    />
                                                </TableCell>
                                                <TableCell>
                                                    <div className="flex items-center gap-0.5">
                                                        <Tooltip>
                                                            <TooltipTrigger asChild>
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    className="size-8"
                                                                    onClick={() => handleExportJson(igsn)}
                                                                    disabled={exportingIgsns.has(igsn.id)}
                                                                    aria-label="Export as DataCite JSON"
                                                                >
                                                                    <FileJson className="size-4" />
                                                                </Button>
                                                            </TooltipTrigger>
                                                            <TooltipContent>Export as DataCite JSON</TooltipContent>
                                                        </Tooltip>
                                                        <Tooltip>
                                                            <TooltipTrigger asChild>
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    className="size-8"
                                                                    onClick={() => handleExportJsonLd(igsn)}
                                                                    disabled={exportingJsonLdIgsns.has(igsn.id)}
                                                                    aria-label="Export as JSON-LD"
                                                                >
                                                                    <Braces className="size-4" />
                                                                </Button>
                                                            </TooltipTrigger>
                                                            <TooltipContent>Export as JSON-LD (Linked Data)</TooltipContent>
                                                        </Tooltip>
                                                        <Tooltip>
                                                            <TooltipTrigger asChild>
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    className="size-8"
                                                                    onClick={() => handleSetupLandingPage(igsn)}
                                                                    aria-label="Setup Landing Page"
                                                                >
                                                                    <Globe className="size-4" />
                                                                </Button>
                                                            </TooltipTrigger>
                                                            <TooltipContent>Setup Landing Page</TooltipContent>
                                                        </Tooltip>
                                                        {canRegister && (
                                                            <Tooltip>
                                                                <TooltipTrigger asChild>
                                                                    <span tabIndex={0} className="inline-flex">
                                                                        <Button
                                                                            variant="ghost"
                                                                            size="icon"
                                                                            className="size-8"
                                                                            onClick={() => handleRegister(igsn)}
                                                                            disabled={!igsn.has_landing_page || registeringIgsns.has(igsn.id)}
                                                                            aria-label={
                                                                                igsn.upload_status === 'registered'
                                                                                    ? 'Update Metadata at DataCite'
                                                                                    : 'Register at DataCite'
                                                                            }
                                                                        >
                                                                            {registeringIgsns.has(igsn.id) ? (
                                                                                <Spinner size="sm" />
                                                                            ) : igsn.upload_status === 'registered' ? (
                                                                                <RefreshCw className="size-4" />
                                                                            ) : (
                                                                                <CloudUpload className="size-4" />
                                                                            )}
                                                                        </Button>
                                                                    </span>
                                                                </TooltipTrigger>
                                                                <TooltipContent>
                                                                    {!igsn.has_landing_page
                                                                        ? 'Set up a landing page first'
                                                                        : igsn.upload_status === 'registered'
                                                                          ? 'Update Metadata at DataCite'
                                                                          : 'Register at DataCite'}
                                                                </TooltipContent>
                                                            </Tooltip>
                                                        )}
                                                    </div>
                                                </TableCell>
                                                <TableCell className="font-mono text-sm">
                                                    {igsn.parent_resource_id && <span className="mr-2 text-muted-foreground">└</span>}
                                                    {igsn.igsn || '-'}
                                                </TableCell>
                                                <TableCell className="max-w-[350px] break-words whitespace-normal">{igsn.title}</TableCell>
                                                <TableCell>{igsn.sample_type || '-'}</TableCell>
                                                <TableCell>{igsn.material || '-'}</TableCell>
                                                <TableCell>
                                                    {(() => {
                                                        const { start, end } = formatDateRange(igsn.collection_date);
                                                        return (
                                                            <div className="text-sm">
                                                                <div>{start}</div>
                                                                {end && <div>{end}</div>}
                                                            </div>
                                                        );
                                                    })()}
                                                </TableCell>
                                                <TableCell>
                                                    <Tooltip>
                                                        <TooltipTrigger asChild>
                                                            <span>
                                                                <IgsnStatusBadge status={igsn.upload_status} />
                                                            </span>
                                                        </TooltipTrigger>
                                                        {igsn.upload_error_message && (
                                                            <TooltipContent className="max-w-xs">
                                                                <p className="text-destructive">{igsn.upload_error_message}</p>
                                                            </TooltipContent>
                                                        )}
                                                    </Tooltip>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )}

                            {/* Pagination */}
                            {(igsns.length > 0 || pagination.current_page > 1) && (
                                <div className="flex flex-col gap-4 px-2 text-sm text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
                                    <span className="flex-1">
                                        Showing {pagination.from ?? 0} to {pagination.to ?? 0}{' '}
                                        {pagination.total === null
                                            ? pagination.count_status === 'failed'
                                                ? 'samples (count unavailable)'
                                                : 'samples (counting total...)'
                                            : `of ${pagination.total} samples`}
                                    </span>
                                    <div className="flex flex-wrap items-center gap-4 sm:justify-end">
                                        <div className="flex items-center gap-2">
                                            <span className="font-medium text-foreground">Rows per page</span>
                                            <Select value={String(pagination.per_page)} onValueChange={handlePageSizeChange} disabled={isNavigating}>
                                                <SelectTrigger className="h-8 w-[80px]" aria-label="Rows per page">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent side="top">
                                                    {IGSNS_PAGE_SIZE_OPTIONS.map((pageSize) => (
                                                        <SelectItem key={pageSize} value={String(pageSize)}>
                                                            {pageSize}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <span className="min-w-24 text-center font-medium text-foreground">
                                            Page {pagination.current_page}
                                            {pagination.last_page === null ? '' : ` of ${pagination.last_page}`}
                                        </span>
                                        <div className="flex items-center gap-2">
                                            <Button
                                                variant="outline"
                                                size="icon"
                                                className="hidden size-8 sm:inline-flex"
                                                onClick={() => handlePageChange(1)}
                                                disabled={isNavigating || pagination.current_page === 1}
                                                aria-label="Go to first page"
                                            >
                                                <ChevronsLeft className="size-4" />
                                            </Button>
                                            <Button
                                                variant="outline"
                                                size="icon"
                                                className="size-8"
                                                onClick={() => handlePageChange(pagination.current_page - 1)}
                                                disabled={isNavigating || pagination.current_page === 1}
                                                aria-label="Go to previous page"
                                            >
                                                <ChevronLeft className="size-4" />
                                            </Button>
                                            <Button
                                                variant="outline"
                                                size="icon"
                                                className="size-8"
                                                onClick={() => handlePageChange(pagination.current_page + 1)}
                                                disabled={isNavigating || !pagination.has_more}
                                                aria-label="Go to next page"
                                            >
                                                <ChevronRight className="size-4" />
                                            </Button>
                                            <Button
                                                variant="outline"
                                                size="icon"
                                                className="hidden size-8 sm:inline-flex"
                                                onClick={() => pagination.last_page !== null && handlePageChange(pagination.last_page)}
                                                disabled={
                                                    isNavigating || pagination.last_page === null || pagination.current_page === pagination.last_page
                                                }
                                                aria-label="Go to last page"
                                            >
                                                <ChevronsRight className="size-4" />
                                            </Button>
                                        </div>
                                    </div>
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>
            </div>

            {/* Delete Confirmation Dialog */}
            <AlertDialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete {selectedIds.size === 1 ? 'IGSN' : 'IGSNs'}</AlertDialogTitle>
                        <AlertDialogDescription>
                            Are you sure you want to delete {selectedIds.size} {selectedIds.size === 1 ? 'IGSN' : 'IGSNs'}? This action cannot be
                            undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={isDeleting}>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={handleBulkDeleteConfirm}
                            disabled={isDeleting}
                            className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                        >
                            {isDeleting ? 'Deleting...' : 'Delete'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            {/* JSON Validation Error Modal */}
            <ValidationErrorModal
                open={isValidationModalOpen}
                onOpenChange={setIsValidationModalOpen}
                errors={validationErrors}
                resourceType="IGSN"
                schemaVersion={validationSchemaVersion}
            />

            {/* IGSN Landing Page Setup Modal */}
            {selectedIgsnForLandingPage && (
                <SetupIgsnLandingPageModal
                    resource={{
                        id: selectedIgsnForLandingPage.id,
                        doi: selectedIgsnForLandingPage.igsn,
                        title: selectedIgsnForLandingPage.title,
                    }}
                    isOpen={isLandingPageModalOpen}
                    onClose={handleCloseLandingPageModal}
                />
            )}

            <ImportIgsnsModal isOpen={isImportModalOpen} onClose={() => setIsImportModalOpen(false)} onSuccess={() => router.reload()} />
            <ImportSingleIgsnModal
                isOpen={isSingleImportModalOpen}
                igsnPrefix={igsnPrefix}
                onClose={() => setIsSingleImportModalOpen(false)}
                onSuccess={() => router.reload()}
            />
            <ImportIgsnsModal
                mode="datacenter"
                isOpen={isDatacenterImportModalOpen}
                onClose={() => setIsDatacenterImportModalOpen(false)}
                onSuccess={() => router.reload()}
            />
            {canUpdateDataCiteLandingPageUrls && (
                <DataCiteUrlUpdateModal
                    scope="igsns"
                    open={isDataCiteUrlUpdateModalOpen}
                    onOpenChange={setIsDataCiteUrlUpdateModalOpen}
                    initialRun={dataCiteUrlUpdateRun}
                />
            )}
        </AppLayout>
    );
}

IgsnsPage.layout = null;

export default IgsnsPage;
