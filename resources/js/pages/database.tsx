import { Head } from '@inertiajs/react';
import axios, { isAxiosError } from 'axios';
import { AlertTriangle, Database, Download, Play, RefreshCw } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';

type DumpStatus = 'pending' | 'running' | 'completed' | 'failed' | 'expired';

type DatabaseDumpExport = {
    id: string;
    targetKey: string;
    targetLabel: string;
    connectionName: string;
    databaseName: string;
    status: DumpStatus;
    filename: string | null;
    sizeBytes: number | null;
    sha256: string | null;
    serverVersion: string | null;
    dumpClient: string | null;
    errorMessage: string | null;
    requestedAt: string | null;
    startedAt: string | null;
    finishedAt: string | null;
    expiresAt: string | null;
    downloadCount: number;
    lastDownloadedAt: string | null;
    downloadUrl: string | null;
};

type DatabaseDumpTarget = {
    key: string;
    label: string;
    description: string;
    connection: string;
    database: string | null;
    legacy: boolean;
    requiresLegacySslProbe: boolean;
    serverVersionHint: string | null;
    latestExport: DatabaseDumpExport | null;
};

type DatabasePageProps = {
    targets: DatabaseDumpTarget[];
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Database',
        href: '/database',
    },
];

const activeStatuses: DumpStatus[] = ['pending', 'running'];

function statusBadgeVariant(status: DumpStatus): 'default' | 'secondary' | 'destructive' | 'outline' {
    if (status === 'completed') return 'default';
    if (status === 'failed') return 'destructive';
    if (status === 'expired') return 'outline';
    return 'secondary';
}

function formatDateTime(value: string | null): string {
    if (!value) return '-';

    return new Date(value).toLocaleString('en-US', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}

function formatBytes(value: number | null): string {
    if (value === null) return '-';
    if (value === 0) return '0 B';

    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const index = Math.min(Math.floor(Math.log(value) / Math.log(1024)), units.length - 1);
    const amount = value / Math.pow(1024, index);

    return `${amount.toFixed(index === 0 ? 0 : 1)} ${units[index]}`;
}

function axiosMessage(error: unknown): string {
    if (isAxiosError(error)) {
        const data = error.response?.data as { message?: unknown } | undefined;

        if (typeof data?.message === 'string') {
            return data.message;
        }
    }

    return 'The database dump could not be started.';
}

export default function DatabasePage({ targets: initialTargets }: DatabasePageProps) {
    const [targets, setTargets] = useState(initialTargets);
    const [busyTarget, setBusyTarget] = useState<string | null>(null);
    const activeExports = useMemo(
        () => targets.map((target) => target.latestExport).filter((exportItem): exportItem is DatabaseDumpExport => exportItem !== null && activeStatuses.includes(exportItem.status)),
        [targets],
    );

    const updateExport = useCallback((exportItem: DatabaseDumpExport) => {
        setTargets((currentTargets) =>
            currentTargets.map((target) =>
                target.key === exportItem.targetKey
                    ? {
                          ...target,
                          latestExport: exportItem,
                      }
                    : target,
            ),
        );
    }, []);

    const startDump = useCallback(
        async (targetKey: string) => {
            setBusyTarget(targetKey);

            try {
                const response = await axios.post<{ export: DatabaseDumpExport }>(`/database/${targetKey}/dumps`);
                updateExport(response.data.export);
                toast.success('Database dump queued');
            } catch (error) {
                toast.error(axiosMessage(error));
            } finally {
                setBusyTarget(null);
            }
        },
        [updateExport],
    );

    useEffect(() => {
        if (activeExports.length === 0) {
            return;
        }

        const interval = window.setInterval(() => {
            activeExports.forEach((exportItem) => {
                void axios
                    .get<{ export: DatabaseDumpExport }>(`/database/dumps/${exportItem.id}/status`)
                    .then((response) => updateExport(response.data.export))
                    .catch(() => {
                        // Keep the last known state visible; the next polling tick can recover.
                    });
            });
        }, 3000);

        return () => window.clearInterval(interval);
    }, [activeExports, updateExport]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Database" />

            <div className="flex flex-1 flex-col gap-4 p-4 md:p-6">
                <Card>
                    <CardHeader className="gap-3">
                        <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                            <div className="flex items-start gap-3">
                                <div className="rounded-md bg-muted p-2 text-gfz-primary">
                                    <Database aria-hidden="true" className="size-5" />
                                </div>
                                <div>
                                    <CardTitle asChild>
                                        <h1>Database Dumps</h1>
                                    </CardTitle>
                                    <CardDescription>Admin exports for ERNIE and legacy metadata databases.</CardDescription>
                                </div>
                            </div>
                            {activeExports.length > 0 && (
                                <Badge variant="secondary" className="gap-1">
                                    <RefreshCw aria-hidden="true" className="size-3 animate-spin" />
                                    Updating
                                </Badge>
                            )}
                        </div>
                        <Alert className="border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/40">
                            <AlertTriangle aria-hidden="true" className="size-4 text-amber-600 dark:text-amber-300" />
                            <AlertDescription className="text-amber-900 dark:text-amber-100">
                                Database dumps contain complete private data. Files expire automatically after the configured retention window.
                            </AlertDescription>
                        </Alert>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Database</TableHead>
                                    <TableHead>Server</TableHead>
                                    <TableHead>Latest dump</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Action</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {targets.map((target) => {
                                    const latestExport = target.latestExport;
                                    const isActive = latestExport !== null && activeStatuses.includes(latestExport.status);
                                    const isBusy = busyTarget === target.key;
                                    const canDownload = latestExport?.downloadUrl !== null && latestExport?.downloadUrl !== undefined;

                                    return (
                                        <TableRow key={target.key}>
                                            <TableCell>
                                                <div className="space-y-1">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <span className="font-medium">{target.label}</span>
                                                        {target.legacy && <Badge variant="outline">Legacy</Badge>}
                                                    </div>
                                                    <p className="text-sm text-muted-foreground">{target.description}</p>
                                                    <p className="font-mono text-xs text-muted-foreground">
                                                        {target.connection} / {target.database ?? 'not configured'}
                                                    </p>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <div className="max-w-72 text-sm">
                                                    <p className="text-foreground">{latestExport?.serverVersion ?? target.serverVersionHint ?? '-'}</p>
                                                    {target.requiresLegacySslProbe && (
                                                        <p className="mt-1 text-xs text-amber-700 dark:text-amber-300">Legacy SSL check required before production use.</p>
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                {latestExport ? (
                                                    <div className="space-y-1 text-sm">
                                                        <p>{latestExport.filename ?? '-'}</p>
                                                        <p className="text-muted-foreground">
                                                            {formatBytes(latestExport.sizeBytes)} - requested {formatDateTime(latestExport.requestedAt)}
                                                        </p>
                                                        {latestExport.expiresAt && (
                                                            <p className="text-xs text-muted-foreground">Expires {formatDateTime(latestExport.expiresAt)}</p>
                                                        )}
                                                        {latestExport.errorMessage && (
                                                            <p className="max-w-md text-xs text-destructive">{latestExport.errorMessage}</p>
                                                        )}
                                                    </div>
                                                ) : (
                                                    <span className="text-sm text-muted-foreground">No dump yet</span>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {latestExport ? (
                                                    <Badge variant={statusBadgeVariant(latestExport.status)}>{latestExport.status}</Badge>
                                                ) : (
                                                    <Badge variant="outline">idle</Badge>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex justify-end gap-2">
                                                    {canDownload && (
                                                        <Button asChild variant="outline" size="sm">
                                                            <a href={latestExport.downloadUrl ?? '#'} aria-label={`Download ${target.label} database dump`}>
                                                                <Download aria-hidden="true" className="size-4" />
                                                                Download
                                                            </a>
                                                        </Button>
                                                    )}
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        onClick={() => void startDump(target.key)}
                                                        disabled={isBusy || isActive || target.database === null}
                                                    >
                                                        {isBusy || isActive ? <Spinner size="sm" /> : <Play aria-hidden="true" className="size-4" />}
                                                        Create dump
                                                    </Button>
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
