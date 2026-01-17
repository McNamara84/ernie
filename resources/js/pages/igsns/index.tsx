import { Head, router, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ArrowUpDown, Eye, MapPin, PencilLine, RefreshCw } from 'lucide-react';
import type { ReactNode } from 'react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import { IgsnStatusBadge } from '@/components/igsns/status-badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
import { editor as editorRoute } from '@/routes';
import { type BreadcrumbItem, type User as AuthUser } from '@/types';

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
    location: string | null;
    latitude: number | null;
    longitude: number | null;
    upload_status: string;
    upload_error_message: string | null;
    parent_resource_id: number | null;
    collector: string | null;
    created_at: string | null;
    updated_at: string | null;
}

interface PaginationInfo {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    has_more: boolean;
}

interface SortState {
    key: string;
    direction: 'asc' | 'desc';
}

interface FilterState {
    search: string;
    upload_status: string;
    sample_type: string;
    material: string;
}

interface FilterOptions {
    upload_statuses: string[];
    sample_types: string[];
    materials: string[];
}

interface IgsnsPageProps {
    igsns: Igsn[];
    pagination: PaginationInfo;
    sort: SortState;
    filters: FilterState;
    filterOptions: FilterOptions;
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

const DEFAULT_SORT: SortState = { key: 'updated_at', direction: 'desc' };

const DEFAULT_DIRECTION_BY_KEY: Record<SortKey, 'asc' | 'desc'> = {
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

const formatDate = (isoDate: string | null): string => {
    if (!isoDate) return '-';

    try {
        const date = new Date(isoDate);
        if (isNaN(date.getTime())) return '-';

        return new Intl.DateTimeFormat(undefined, {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        }).format(date);
    } catch {
        return '-';
    }
};

const determineNextDirection = (currentState: SortState, targetKey: SortKey): 'asc' | 'desc' => {
    if (currentState.key !== targetKey) {
        return DEFAULT_DIRECTION_BY_KEY[targetKey];
    }
    return currentState.direction === 'asc' ? 'desc' : 'asc';
};

// ============================================================================
// Sub-Components
// ============================================================================

function SortDirectionIndicator({ isActive, direction }: { isActive: boolean; direction: 'asc' | 'desc' }) {
    if (!isActive) {
        return <ArrowUpDown aria-hidden="true" className="size-3.5" />;
    }
    return direction === 'asc' ? <ArrowUp aria-hidden="true" className="size-3.5" /> : <ArrowDown aria-hidden="true" className="size-3.5" />;
}

interface SortableHeaderProps {
    label: ReactNode;
    sortKey: SortKey;
    sortState: SortState;
    onSort: (key: SortKey) => void;
    className?: string;
}

function SortableHeader({ label, sortKey, sortState, onSort, className }: SortableHeaderProps) {
    const isActive = sortState.key === sortKey;

    return (
        <TableHead className={className}>
            <Button
                variant="ghost"
                size="sm"
                className="-ml-3 h-8 px-3 font-medium"
                onClick={() => onSort(sortKey)}
                aria-label={`Sort by ${sortKey}`}
            >
                {label}
                <SortDirectionIndicator isActive={isActive} direction={sortState.direction} />
            </Button>
        </TableHead>
    );
}

interface FilterBarProps {
    filters: FilterState;
    filterOptions: FilterOptions;
    onFilterChange: (filters: FilterState) => void;
    onReset: () => void;
}

function FilterBar({ filters, filterOptions, onFilterChange, onReset }: FilterBarProps) {
    const hasActiveFilters = filters.search || filters.upload_status || filters.sample_type || filters.material;

    return (
        <div className="flex flex-wrap items-center gap-4">
            <Input
                placeholder="Search IGSN or title..."
                value={filters.search}
                onChange={(e) => onFilterChange({ ...filters, search: e.target.value })}
                className="w-64"
            />

            <Select value={filters.upload_status || 'all'} onValueChange={(value) => onFilterChange({ ...filters, upload_status: value === 'all' ? '' : value })}>
                <SelectTrigger className="w-40">
                    <SelectValue placeholder="Status" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All Statuses</SelectItem>
                    {filterOptions.upload_statuses.map((status) => (
                        <SelectItem key={status} value={status}>
                            {status.charAt(0).toUpperCase() + status.slice(1)}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>

            {filterOptions.sample_types.length > 0 && (
                <Select value={filters.sample_type || 'all'} onValueChange={(value) => onFilterChange({ ...filters, sample_type: value === 'all' ? '' : value })}>
                    <SelectTrigger className="w-40">
                        <SelectValue placeholder="Sample Type" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All Types</SelectItem>
                        {filterOptions.sample_types.map((type) => (
                            <SelectItem key={type} value={type}>
                                {type}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            )}

            {filterOptions.materials.length > 0 && (
                <Select value={filters.material || 'all'} onValueChange={(value) => onFilterChange({ ...filters, material: value === 'all' ? '' : value })}>
                    <SelectTrigger className="w-40">
                        <SelectValue placeholder="Material" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All Materials</SelectItem>
                        {filterOptions.materials.map((mat) => (
                            <SelectItem key={mat} value={mat}>
                                {mat}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            )}

            {hasActiveFilters && (
                <Button variant="ghost" size="sm" onClick={onReset}>
                    <RefreshCw className="mr-1 size-4" />
                    Reset
                </Button>
            )}
        </div>
    );
}

// ============================================================================
// Main Component
// ============================================================================

function IgsnsPage({ igsns: initialIgsns, pagination: initialPagination, sort: initialSort, filters: initialFilters, filterOptions }: IgsnsPageProps) {
    const { auth } = usePage<{ auth: { user: AuthUser } }>().props;

    const [igsns, setIgsns] = useState<Igsn[]>(initialIgsns);
    const [pagination, setPagination] = useState<PaginationInfo>(initialPagination);
    const [sortState, setSortState] = useState<SortState>(initialSort || DEFAULT_SORT);
    const [filters, setFilters] = useState<FilterState>(initialFilters);
    const [loading, setLoading] = useState(false);

    // Update state when props change (after navigation)
    useEffect(() => {
        setIgsns(initialIgsns);
        setPagination(initialPagination);
        setSortState(initialSort || DEFAULT_SORT);
        setFilters(initialFilters);
    }, [initialIgsns, initialPagination, initialSort, initialFilters]);

    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    const navigateWithParams = useCallback(
        (newSort: SortState, newFilters: FilterState) => {
            const params = new URLSearchParams();

            params.set('sort', newSort.key);
            params.set('direction', newSort.direction);

            if (newFilters.search) params.set('search', newFilters.search);
            if (newFilters.upload_status) params.set('upload_status', newFilters.upload_status);
            if (newFilters.sample_type) params.set('sample_type', newFilters.sample_type);
            if (newFilters.material) params.set('material', newFilters.material);

            router.visit(`/igsns?${params.toString()}`, {
                preserveState: false,
                replace: true,
            });
        },
        [],
    );

    const handleSortChange = useCallback(
        (key: SortKey) => {
            const newDirection = determineNextDirection(sortState, key);
            const newSort = { key, direction: newDirection };
            setSortState(newSort);
            navigateWithParams(newSort, filters);
        },
        [sortState, filters, navigateWithParams],
    );

    const handleFilterChange = useCallback(
        (newFilters: FilterState) => {
            setFilters(newFilters);

            // Debounce search input
            if (debounceRef.current) {
                clearTimeout(debounceRef.current);
            }

            debounceRef.current = setTimeout(() => {
                navigateWithParams(sortState, newFilters);
            }, 300);
        },
        [sortState, navigateWithParams],
    );

    const handleResetFilters = useCallback(() => {
        const emptyFilters: FilterState = {
            search: '',
            upload_status: '',
            sample_type: '',
            material: '',
        };
        setFilters(emptyFilters);
        navigateWithParams(sortState, emptyFilters);
    }, [sortState, navigateWithParams]);

    const handleOpenInEditor = useCallback((igsn: Igsn) => {
        router.get(editorRoute({ query: { resourceId: igsn.id } }).url);
    }, []);

    const handleViewDetails = useCallback((igsn: Igsn) => {
        // For now, just open in editor - can be expanded to a dedicated view page later
        router.get(editorRoute({ query: { resourceId: igsn.id } }).url);
    }, []);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="IGSNs" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <Card>
                    <CardHeader>
                        <CardTitle>Physical Samples (IGSNs)</CardTitle>
                        <CardDescription>
                            Manage physical sample metadata with International Generic Sample Numbers. Total: {pagination.total} samples
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="space-y-4">
                            <FilterBar filters={filters} filterOptions={filterOptions} onFilterChange={handleFilterChange} onReset={handleResetFilters} />

                            {igsns.length === 0 ? (
                                <Alert>
                                    <AlertDescription>
                                        {filters.search || filters.upload_status || filters.sample_type || filters.material
                                            ? 'No IGSNs match your filter criteria.'
                                            : 'No IGSNs found. Upload a CSV file from the Dashboard to add physical samples.'}
                                    </AlertDescription>
                                </Alert>
                            ) : (
                                <div className="rounded-md border">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <SortableHeader label="IGSN" sortKey="igsn" sortState={sortState} onSort={handleSortChange} className="w-48" />
                                                <SortableHeader label="Title" sortKey="title" sortState={sortState} onSort={handleSortChange} className="min-w-[200px]" />
                                                <SortableHeader label="Sample Type" sortKey="sample_type" sortState={sortState} onSort={handleSortChange} className="w-32" />
                                                <SortableHeader label="Material" sortKey="material" sortState={sortState} onSort={handleSortChange} className="w-32" />
                                                <SortableHeader label="Collection Date" sortKey="collection_date" sortState={sortState} onSort={handleSortChange} className="w-36" />
                                                <TableHead className="w-32">Location</TableHead>
                                                <SortableHeader label="Status" sortKey="upload_status" sortState={sortState} onSort={handleSortChange} className="w-28" />
                                                <TableHead className="w-24">Actions</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {igsns.map((igsn) => (
                                                <TableRow key={igsn.id} className={igsn.parent_resource_id ? 'bg-muted/30' : ''}>
                                                    <TableCell className="font-mono text-sm">
                                                        {igsn.parent_resource_id && <span className="mr-2 text-muted-foreground">└</span>}
                                                        {igsn.igsn || '-'}
                                                    </TableCell>
                                                    <TableCell className="max-w-[300px] truncate" title={igsn.title}>
                                                        {igsn.title}
                                                    </TableCell>
                                                    <TableCell>{igsn.sample_type || '-'}</TableCell>
                                                    <TableCell>{igsn.material || '-'}</TableCell>
                                                    <TableCell>{formatDate(igsn.collection_date)}</TableCell>
                                                    <TableCell>
                                                        {igsn.latitude !== null && igsn.longitude !== null ? (
                                                            <TooltipProvider>
                                                                <Tooltip>
                                                                    <TooltipTrigger asChild>
                                                                        <span className="flex items-center gap-1 text-sm">
                                                                            <MapPin className="size-3.5 text-muted-foreground" />
                                                                            {igsn.location || `${igsn.latitude.toFixed(2)}, ${igsn.longitude.toFixed(2)}`}
                                                                        </span>
                                                                    </TooltipTrigger>
                                                                    <TooltipContent>
                                                                        <p>Lat: {igsn.latitude}</p>
                                                                        <p>Lon: {igsn.longitude}</p>
                                                                        {igsn.location && <p>Place: {igsn.location}</p>}
                                                                    </TooltipContent>
                                                                </Tooltip>
                                                            </TooltipProvider>
                                                        ) : (
                                                            <span className="text-muted-foreground">-</span>
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        <TooltipProvider>
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
                                                        </TooltipProvider>
                                                    </TableCell>
                                                    <TableCell>
                                                        <div className="flex items-center gap-1">
                                                            <TooltipProvider>
                                                                <Tooltip>
                                                                    <TooltipTrigger asChild>
                                                                        <Button variant="ghost" size="icon" className="size-8" onClick={() => handleViewDetails(igsn)}>
                                                                            <Eye className="size-4" />
                                                                        </Button>
                                                                    </TooltipTrigger>
                                                                    <TooltipContent>View details</TooltipContent>
                                                                </Tooltip>
                                                            </TooltipProvider>
                                                            <TooltipProvider>
                                                                <Tooltip>
                                                                    <TooltipTrigger asChild>
                                                                        <Button variant="ghost" size="icon" className="size-8" onClick={() => handleOpenInEditor(igsn)}>
                                                                            <PencilLine className="size-4" />
                                                                        </Button>
                                                                    </TooltipTrigger>
                                                                    <TooltipContent>Edit in curation editor</TooltipContent>
                                                                </Tooltip>
                                                            </TooltipProvider>
                                                        </div>
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            )}

                            {/* Pagination Info */}
                            {pagination.total > 0 && (
                                <div className="flex items-center justify-between text-sm text-muted-foreground">
                                    <span>
                                        Showing {pagination.from ?? 0} to {pagination.to ?? 0} of {pagination.total} samples
                                    </span>
                                    {pagination.has_more && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            disabled={loading}
                                            onClick={() => {
                                                const params = new URLSearchParams(window.location.search);
                                                params.set('page', String(pagination.current_page + 1));
                                                router.visit(`/igsns?${params.toString()}`, { preserveState: false });
                                            }}
                                        >
                                            Load More
                                        </Button>
                                    )}
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}

IgsnsPage.layout = null;

export default IgsnsPage;
