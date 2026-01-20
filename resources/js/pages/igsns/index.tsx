import { Head, router } from '@inertiajs/react';
import axios, { isAxiosError } from 'axios';
import { FileJson, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';

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
import { SortableTableHeader, type SortDirection, type SortState } from '@/components/ui/sortable-table-header';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { type ValidationError, ValidationErrorModal } from '@/components/ui/validation-error-modal';
import AppLayout from '@/layouts/app-layout';
import { extractErrorMessageFromBlob, parseValidationErrorFromBlob } from '@/lib/blob-utils';
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

interface IgsnsPageProps {
    igsns: Igsn[];
    pagination: PaginationInfo;
    sort: SortState<SortKey>;
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

function IgsnsPage({ igsns: initialIgsns, pagination: initialPagination, sort: initialSort, canDelete }: IgsnsPageProps) {
    const [igsns, setIgsns] = useState<Igsn[]>(initialIgsns);
    const [pagination, setPagination] = useState<PaginationInfo>(initialPagination);
    const [sortState, setSortState] = useState<SortState<SortKey>>(initialSort || DEFAULT_SORT);
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [igsnToDelete, setIgsnToDelete] = useState<Igsn | null>(null);
    const [isDeleting, setIsDeleting] = useState(false);
    const [exportingIgsns, setExportingIgsns] = useState<Set<number>>(new Set());
    const [validationErrors, setValidationErrors] = useState<ValidationError[]>([]);
    const [isValidationModalOpen, setIsValidationModalOpen] = useState(false);
    const [validationSchemaVersion, setValidationSchemaVersion] = useState<string>('4.6');

    // Update state when props change (after navigation)
    useEffect(() => {
        setIgsns(initialIgsns);
        setPagination(initialPagination);
        setSortState(initialSort || DEFAULT_SORT);
    }, [initialIgsns, initialPagination, initialSort]);

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
                                    <AlertDescription>No IGSNs found. Upload a CSV file from the Dashboard to add physical samples.</AlertDescription>
                                </Alert>
                            ) : (
                                <div className="rounded-md border">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead className="w-20">Actions</TableHead>
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
                                                <TableRow key={igsn.id} className={igsn.parent_resource_id ? 'bg-muted/30' : ''}>
                                                    <TableCell>
                                                        <TooltipProvider>
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
                                                                            aria-label="Delete IGSN"
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
                            <span className="font-mono font-semibold">{igsnToDelete?.igsn || igsnToDelete?.title}</span>? This action cannot be
                            undone.
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

            {/* JSON Validation Error Modal */}
            <ValidationErrorModal
                open={isValidationModalOpen}
                onOpenChange={setIsValidationModalOpen}
                errors={validationErrors}
                resourceType="IGSN"
                schemaVersion={validationSchemaVersion}
            />
        </AppLayout>
    );
}

IgsnsPage.layout = null;

export default IgsnsPage;
