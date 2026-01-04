import { Head, router } from '@inertiajs/react';
import { AlertTriangle, FileX2, Info, RefreshCw, ScrollText, Search, Trash2, XCircle } from 'lucide-react';
import { useCallback, useState } from 'react';
import { toast } from 'sonner';

import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';

interface LogEntry {
    timestamp: string;
    level: string;
    message: string;
    context: string;
    line_number: number;
}

interface Pagination {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface Filters {
    level: string | null;
    search: string | null;
    per_page: number;
}

interface LogsIndexProps {
    logs: LogEntry[];
    pagination: Pagination;
    filters: Filters;
    available_levels: string[];
    can_delete: boolean;
}

const levelColors: Record<string, string> = {
    emergency: 'bg-red-600 text-white',
    alert: 'bg-red-500 text-white',
    critical: 'bg-red-400 text-white',
    error: 'bg-red-300 text-red-900',
    warning: 'bg-yellow-300 text-yellow-900',
    notice: 'bg-blue-300 text-blue-900',
    info: 'bg-blue-200 text-blue-800',
    debug: 'bg-gray-200 text-gray-800',
};

const levelIcons: Record<string, React.ReactNode> = {
    emergency: <XCircle className="size-4" />,
    alert: <XCircle className="size-4" />,
    critical: <XCircle className="size-4" />,
    error: <XCircle className="size-4" />,
    warning: <AlertTriangle className="size-4" />,
    notice: <Info className="size-4" />,
    info: <Info className="size-4" />,
    debug: <Info className="size-4" />,
};

export default function Index({ logs, pagination, filters, available_levels, can_delete }: LogsIndexProps) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [level, setLevel] = useState(filters.level ?? '');
    const [expandedRows, setExpandedRows] = useState<Set<number>>(new Set());
    const [isLoading, setIsLoading] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Logs',
            href: '/logs',
        },
    ];

    const applyFilters = useCallback(
        (newFilters: { level?: string; search?: string; page?: number }) => {
            setIsLoading(true);
            router.get(
                '/logs',
                {
                    level: newFilters.level ?? level,
                    search: newFilters.search ?? search,
                    page: newFilters.page ?? 1,
                    per_page: filters.per_page,
                },
                {
                    preserveState: true,
                    preserveScroll: true,
                    onFinish: () => setIsLoading(false),
                },
            );
        },
        [level, search, filters.per_page],
    );

    const handleSearch = () => {
        applyFilters({ search, page: 1 });
    };

    const handleLevelChange = (newLevel: string) => {
        const levelValue = newLevel === 'all' ? '' : newLevel;
        setLevel(levelValue);
        applyFilters({ level: levelValue, page: 1 });
    };

    const handlePageChange = (page: number) => {
        applyFilters({ page });
    };

    const toggleRowExpansion = (lineNumber: number) => {
        const newExpanded = new Set(expandedRows);
        if (newExpanded.has(lineNumber)) {
            newExpanded.delete(lineNumber);
        } else {
            newExpanded.add(lineNumber);
        }
        setExpandedRows(newExpanded);
    };

    const handleDeleteEntry = (entry: LogEntry) => {
        router.delete('/logs/entry', {
            data: {
                line_number: entry.line_number,
                timestamp: entry.timestamp,
            },
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Log entry deleted');
            },
            onError: () => {
                toast.error('Failed to delete log entry');
            },
        });
    };

    const handleClearAll = () => {
        router.delete('/logs/clear', {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('All logs cleared');
            },
            onError: () => {
                toast.error('Failed to clear logs');
            },
        });
    };

    const handleRefresh = () => {
        setIsLoading(true);
        router.reload({
            onFinish: () => setIsLoading(false),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Logs" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-4">
                        <div className="flex items-center gap-2">
                            <ScrollText className="size-6" />
                            <div>
                                <CardTitle>Application Logs</CardTitle>
                                <CardDescription>
                                    {pagination.total} log {pagination.total === 1 ? 'entry' : 'entries'} found
                                </CardDescription>
                            </div>
                        </div>
                        <div className="flex items-center gap-2">
                            <Button variant="outline" size="sm" onClick={handleRefresh} disabled={isLoading}>
                                <RefreshCw className={cn('mr-2 size-4', isLoading && 'animate-spin')} />
                                Refresh
                            </Button>
                            {can_delete && (
                                <AlertDialog>
                                    <AlertDialogTrigger asChild>
                                        <Button variant="destructive" size="sm" disabled={pagination.total === 0}>
                                            <Trash2 className="mr-2 size-4" />
                                            Clear All
                                        </Button>
                                    </AlertDialogTrigger>
                                    <AlertDialogContent>
                                        <AlertDialogHeader>
                                            <AlertDialogTitle>Clear all logs?</AlertDialogTitle>
                                            <AlertDialogDescription>
                                                This will permanently delete all {pagination.total} log entries. This action cannot be undone.
                                            </AlertDialogDescription>
                                        </AlertDialogHeader>
                                        <AlertDialogFooter>
                                            <AlertDialogCancel>Cancel</AlertDialogCancel>
                                            <AlertDialogAction onClick={handleClearAll} className="bg-destructive text-destructive-foreground hover:bg-destructive/90">
                                                Clear All
                                            </AlertDialogAction>
                                        </AlertDialogFooter>
                                    </AlertDialogContent>
                                </AlertDialog>
                            )}
                        </div>
                    </CardHeader>

                    <CardContent>
                        {/* Filters */}
                        <div className="mb-4 flex flex-wrap items-center gap-4">
                            <div className="flex items-center gap-2">
                                <Select value={level || 'all'} onValueChange={handleLevelChange}>
                                    <SelectTrigger className="w-40">
                                        <SelectValue placeholder="Filter by level" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Levels</SelectItem>
                                        {available_levels.map((lvl) => (
                                            <SelectItem key={lvl} value={lvl}>
                                                <span className="flex items-center gap-2">
                                                    {levelIcons[lvl]}
                                                    {lvl.charAt(0).toUpperCase() + lvl.slice(1)}
                                                </span>
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="flex flex-1 items-center gap-2">
                                <div className="relative flex-1">
                                    <Search className="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                                    <Input
                                        placeholder="Search logs..."
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                        onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
                                        className="pl-10"
                                    />
                                </div>
                                <Button onClick={handleSearch} disabled={isLoading}>
                                    Search
                                </Button>
                            </div>
                        </div>

                        {/* Log Table */}
                        {logs.length === 0 ? (
                            <div className="text-muted-foreground flex flex-col items-center justify-center py-12">
                                <FileX2 className="mb-4 size-12" />
                                <p className="text-lg font-medium">No log entries found</p>
                                <p className="text-sm">Try adjusting your filters or check back later</p>
                            </div>
                        ) : (
                            <div className="rounded-md border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="w-48">Timestamp</TableHead>
                                            <TableHead className="w-28">Level</TableHead>
                                            <TableHead>Message</TableHead>
                                            {can_delete && <TableHead className="w-20">Actions</TableHead>}
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {logs.map((log) => (
                                            <TableRow
                                                key={log.line_number}
                                                className="cursor-pointer"
                                                onClick={() => log.context && toggleRowExpansion(log.line_number)}
                                            >
                                                <TableCell className="font-mono text-sm">{log.timestamp}</TableCell>
                                                <TableCell>
                                                    <Badge className={cn('gap-1', levelColors[log.level] || 'bg-gray-200')}>
                                                        {levelIcons[log.level]}
                                                        {log.level.toUpperCase()}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>
                                                    <div className="max-w-2xl">
                                                        <p className="truncate font-medium">{log.message}</p>
                                                        {expandedRows.has(log.line_number) && log.context && (
                                                            <pre className="bg-muted mt-2 max-h-64 overflow-auto whitespace-pre-wrap rounded p-2 font-mono text-xs">
                                                                {log.context}
                                                            </pre>
                                                        )}
                                                        {log.context && !expandedRows.has(log.line_number) && (
                                                            <p className="text-muted-foreground text-xs">Click to expand stack trace</p>
                                                        )}
                                                    </div>
                                                </TableCell>
                                                {can_delete && (
                                                    <TableCell>
                                                        <AlertDialog>
                                                            <AlertDialogTrigger asChild>
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    className="text-destructive hover:text-destructive"
                                                                    onClick={(e) => e.stopPropagation()}
                                                                >
                                                                    <Trash2 className="size-4" />
                                                                </Button>
                                                            </AlertDialogTrigger>
                                                            <AlertDialogContent onClick={(e) => e.stopPropagation()}>
                                                                <AlertDialogHeader>
                                                                    <AlertDialogTitle>Delete log entry?</AlertDialogTitle>
                                                                    <AlertDialogDescription>
                                                                        This will permanently delete this log entry. This action cannot be undone.
                                                                    </AlertDialogDescription>
                                                                </AlertDialogHeader>
                                                                <AlertDialogFooter>
                                                                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                                                                    <AlertDialogAction
                                                                        onClick={() => handleDeleteEntry(log)}
                                                                        className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                                                                    >
                                                                        Delete
                                                                    </AlertDialogAction>
                                                                </AlertDialogFooter>
                                                            </AlertDialogContent>
                                                        </AlertDialog>
                                                    </TableCell>
                                                )}
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}

                        {/* Pagination */}
                        {pagination.last_page > 1 && (
                            <div className="mt-4 flex items-center justify-between">
                                <p className="text-muted-foreground text-sm">
                                    Page {pagination.current_page} of {pagination.last_page}
                                </p>
                                <div className="flex gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => handlePageChange(pagination.current_page - 1)}
                                        disabled={pagination.current_page === 1 || isLoading}
                                    >
                                        Previous
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => handlePageChange(pagination.current_page + 1)}
                                        disabled={pagination.current_page === pagination.last_page || isLoading}
                                    >
                                        Next
                                    </Button>
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
