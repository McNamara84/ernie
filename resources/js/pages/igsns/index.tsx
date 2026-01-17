import { Head, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ArrowUpDown, FileJson, Trash2 } from 'lucide-react';
import type { ReactNode } from 'react';
import { useCallback, useEffect, useState } from 'react';

import { IgsnStatusBadge } from '@/components/igsns/status-badge';
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
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
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

interface IgsnsPageProps {
    igsns: Igsn[];
    pagination: PaginationInfo;
    sort: SortState;
    canDelete: boolean;
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

const formatDateRange = (dateString: string | null): { start: string; end: string | null } => {
    if (!dateString) return { start: '-', end: null };

    // Check if it's a date range (contains " – " separator)
    if (dateString.includes(' – ')) {
        const [start, end] = dateString.split(' – ');
        return { start, end };
    }

    return { start: dateString, end: null };
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

// ============================================================================
// Main Component
// ============================================================================

function IgsnsPage({ igsns: initialIgsns, pagination: initialPagination, sort: initialSort, canDelete }: IgsnsPageProps) {
    const [igsns, setIgsns] = useState<Igsn[]>(initialIgsns);
    const [pagination, setPagination] = useState<PaginationInfo>(initialPagination);
    const [sortState, setSortState] = useState<SortState>(initialSort || DEFAULT_SORT);
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [igsnToDelete, setIgsnToDelete] = useState<Igsn | null>(null);
    const [isDeleting, setIsDeleting] = useState(false);

    // Update state when props change (after navigation)
    useEffect(() => {
        setIgsns(initialIgsns);
        setPagination(initialPagination);
        setSortState(initialSort || DEFAULT_SORT);
    }, [initialIgsns, initialPagination, initialSort]);

    const handleSortChange = useCallback(
        (key: SortKey) => {
            const newDirection = determineNextDirection(sortState, key);
            const newSort = { key, direction: newDirection };
            setSortState(newSort);

            const params = new URLSearchParams();
            params.set('sort', newSort.key);
            params.set('direction', newSort.direction);

            window.location.href = `/igsns?${params.toString()}`;
        },
        [sortState],
    );

    const handleDeleteClick = useCallback((igsn: Igsn) => {
        setIgsnToDelete(igsn);
        setDeleteDialogOpen(true);
    }, []);

    const handleDeleteConfirm = useCallback(() => {
        if (!igsnToDelete) return;

        setIsDeleting(true);
        router.delete(`/igsns/${igsnToDelete.id}`, {
            onSuccess: () => {
                setDeleteDialogOpen(false);
                setIgsnToDelete(null);
                setIsDeleting(false);
            },
            onError: () => {
                setIsDeleting(false);
            },
        });
    }, [igsnToDelete]);

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
                            {igsns.length === 0 ? (
                                <Alert>
                                    <AlertDescription>
                                        No IGSNs found. Upload a CSV file from the Dashboard to add physical samples.
                                    </AlertDescription>
                                </Alert>
                            ) : (
                                <div className="rounded-md border">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead className="w-20">Actions</TableHead>
                                                <SortableHeader label="IGSN" sortKey="igsn" sortState={sortState} onSort={handleSortChange} className="w-48" />
                                                <SortableHeader label="Title" sortKey="title" sortState={sortState} onSort={handleSortChange} className="min-w-[250px]" />
                                                <SortableHeader label="Sample Type" sortKey="sample_type" sortState={sortState} onSort={handleSortChange} className="w-36" />
                                                <SortableHeader label="Material" sortKey="material" sortState={sortState} onSort={handleSortChange} className="w-36" />
                                                <SortableHeader label="Collection Date" sortKey="collection_date" sortState={sortState} onSort={handleSortChange} className="w-40" />
                                                <SortableHeader label="Status" sortKey="upload_status" sortState={sortState} onSort={handleSortChange} className="w-28" />
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {igsns.map((igsn) => (
                                                <TableRow key={igsn.id} className={igsn.parent_resource_id ? 'bg-muted/30' : ''}>
                                                    <TableCell>
                                                        <TooltipProvider>
                                                            <Tooltip>
                                                                <TooltipTrigger asChild>
                                                                    <Button variant="ghost" size="icon" className="size-8" asChild>
                                                                        <a href={`/igsns/${igsn.id}/export/json`} download>
                                                                            <FileJson className="size-4" />
                                                                        </a>
                                                                    </Button>
                                                                </TooltipTrigger>
                                                                <TooltipContent>Export as DataCite JSON</TooltipContent>
                                                            </Tooltip>
                                                        </TooltipProvider>
                                                        {canDelete && (
                                                            <TooltipProvider>
                                                                <Tooltip>
                                                                    <TooltipTrigger asChild>
                                                                        <Button
                                                                            variant="ghost"
                                                                            size="icon"
                                                                            className="size-8 text-destructive hover:bg-destructive/10 hover:text-destructive"
                                                                            onClick={() => handleDeleteClick(igsn)}
                                                                        >
                                                                            <Trash2 className="size-4" />
                                                                        </Button>
                                                                    </TooltipTrigger>
                                                                    <TooltipContent>Delete IGSN</TooltipContent>
                                                                </Tooltip>
                                                            </TooltipProvider>
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="font-mono text-sm">
                                                        {igsn.parent_resource_id && <span className="mr-2 text-muted-foreground">└</span>}
                                                        {igsn.igsn || '-'}
                                                    </TableCell>
                                                    <TableCell className="max-w-[350px] whitespace-normal break-words">
                                                        {igsn.title}
                                                    </TableCell>
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
                                            onClick={() => {
                                                const params = new URLSearchParams(window.location.search);
                                                params.set('page', String(pagination.current_page + 1));
                                                window.location.href = `/igsns?${params.toString()}`;
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

            {/* Delete Confirmation Dialog */}
            <AlertDialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete IGSN</AlertDialogTitle>
                        <AlertDialogDescription>
                            Are you sure you want to delete the IGSN{' '}
                            <span className="font-mono font-semibold">{igsnToDelete?.igsn || igsnToDelete?.title}</span>?
                            This action cannot be undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={isDeleting}>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={handleDeleteConfirm}
                            disabled={isDeleting}
                            className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                        >
                            {isDeleting ? 'Deleting...' : 'Delete'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AppLayout>
    );
}

IgsnsPage.layout = null;

export default IgsnsPage;
