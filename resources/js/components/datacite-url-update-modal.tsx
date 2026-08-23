import axios, { isAxiosError } from 'axios';
import { AlertTriangle, CheckCircle2, CircleSlash2, RefreshCw, ShieldAlert, XCircle } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Progress } from '@/components/ui/progress';
import { Spinner } from '@/components/ui/spinner';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { buildCsrfHeaders } from '@/lib/csrf-token';

export type DataCiteUrlUpdateScope = 'resources' | 'igsns';

export interface DataCiteUrlUpdateRun {
    id: string;
    scope: DataCiteUrlUpdateScope;
    scope_label: string;
    status: 'preparing' | 'queued' | 'running' | 'paused' | 'cancel_requested' | 'cancelled' | 'completed' | 'failed';
    test_mode: boolean;
    datacite_endpoint: string;
    target_base_url: string;
    total: number;
    processed: number;
    updated: number;
    already_current: number;
    skipped: number;
    failed: number;
    pause_reason: string | null;
    last_error: string | null;
    started_at: string | null;
    paused_at: string | null;
    cancelled_at: string | null;
    completed_at: string | null;
    created_at: string | null;
    can_cancel: boolean;
    can_resume: boolean;
    can_retry_failed: boolean;
}

interface PreviewItem {
    resource_id: number;
    identifier: string;
    before_url: string | null;
    target_url: string;
    datacite_state: string | null;
    target_reachable: boolean;
    outcome:
        | 'ready'
        | 'target_unreachable'
        | 'already_current'
        | 'remote_missing'
        | 'authentication_failed'
        | 'datacite_unavailable';
    message: string | null;
}

interface PreviewResponse {
    scope: DataCiteUrlUpdateScope;
    scope_label: string;
    total: number;
    sample_count: number;
    target_base_url: string;
    test_mode: boolean;
    datacite_endpoint: string;
    can_start: boolean;
    blocking_message: string | null;
    items: PreviewItem[];
}

interface RunItem {
    id: number;
    resource_id: number | null;
    identifier: string;
    status: string;
    before_url: string | null;
    target_url: string;
    error_message: string | null;
}

interface DataCiteUrlUpdateModalProps {
    scope: DataCiteUrlUpdateScope;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    initialRun?: DataCiteUrlUpdateRun | null;
}

const terminalStatuses = new Set<DataCiteUrlUpdateRun['status']>(['cancelled', 'completed', 'failed']);

const statusLabel: Record<DataCiteUrlUpdateRun['status'], string> = {
    preparing: 'Preparing candidates',
    queued: 'Queued',
    running: 'Updating DataCite',
    paused: 'Paused',
    cancel_requested: 'Cancellation requested',
    cancelled: 'Cancelled',
    completed: 'Completed',
    failed: 'Failed',
};

function responseError(error: unknown, fallback: string): string {
    if (!isAxiosError(error)) return fallback;

    const data = error.response?.data;
    if (typeof data?.message === 'string') return data.message;
    if (data?.errors && typeof data.errors === 'object') {
        const first = Object.values(data.errors)
            .flat()
            .find((value): value is string => typeof value === 'string');
        if (first) return first;
    }

    return error.message || fallback;
}

function outcomeBadge(item: PreviewItem) {
    if (item.outcome === 'ready') return <Badge>Ready</Badge>;
    if (item.outcome === 'already_current') return <Badge variant="secondary">Already current</Badge>;
    if (item.outcome === 'remote_missing') return <Badge variant="outline">Will be skipped</Badge>;
    return <Badge variant="destructive">Blocked</Badge>;
}

export function DataCiteUrlUpdateModal({ scope, open, onOpenChange, initialRun }: DataCiteUrlUpdateModalProps) {
    const [preview, setPreview] = useState<PreviewResponse | null>(null);
    const [run, setRun] = useState<DataCiteUrlUpdateRun | null>(null);
    const [issues, setIssues] = useState<RunItem[]>([]);
    const [isLoading, setIsLoading] = useState(false);
    const [isActing, setIsActing] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const currentRunId = useRef<string | null>(initialRun?.id ?? null);

    const loadPreview = useCallback(async () => {
        setRun(null);
        currentRunId.current = null;
        setPreview(null);
        setIssues([]);
        setError(null);
        setIsLoading(true);

        try {
            const response = await axios.get<PreviewResponse>('/datacite/landing-page-url-updates/preview', { params: { scope } });
            setPreview(response.data);
        } catch (requestError) {
            setError(responseError(requestError, 'The DataCite URL preview could not be loaded.'));
        } finally {
            setIsLoading(false);
        }
    }, [scope]);

    const loadRun = useCallback(async (runId: string) => {
        const response = await axios.get<{ run: DataCiteUrlUpdateRun }>(`/datacite/landing-page-url-updates/${runId}`);
        currentRunId.current = response.data.run.id;
        setRun(response.data.run);

        if (terminalStatuses.has(response.data.run.status) || response.data.run.status === 'paused') {
            const itemResponse = await axios.get<{ items: RunItem[] }>(`/datacite/landing-page-url-updates/${runId}/items`, {
                params: { issues: 1 },
            });
            setIssues(itemResponse.data.items);
        }
    }, []);

    useEffect(() => {
        if (!open) return;

        const runId = currentRunId.current ?? initialRun?.id;

        if (runId) {
            setPreview(null);
            setError(null);
            if (initialRun?.id === runId) setRun(initialRun);
            void loadRun(runId).catch((requestError) => {
                setError(responseError(requestError, 'The previous DataCite URL update could not be loaded.'));
            });
        } else {
            void loadPreview();
        }
    }, [initialRun, loadPreview, loadRun, open]);

    useEffect(() => {
        if (!open || !run || terminalStatuses.has(run.status) || run.status === 'paused') return;

        const interval = window.setInterval(() => {
            void loadRun(run.id).catch((requestError) => {
                setError(responseError(requestError, 'The DataCite URL update status could not be refreshed.'));
            });
        }, 3000);

        return () => window.clearInterval(interval);
    }, [loadRun, open, run]);

    const start = async () => {
        if (!preview?.can_start) return;
        setIsActing(true);
        setError(null);

        try {
            const response = await axios.post<{ run: DataCiteUrlUpdateRun }>(
                '/datacite/landing-page-url-updates',
                { scope },
                { headers: buildCsrfHeaders() },
            );
            setPreview(null);
            currentRunId.current = response.data.run.id;
            setRun(response.data.run);
            toast.success('DataCite landing-page URL update started.');
        } catch (requestError) {
            setError(responseError(requestError, 'The DataCite URL update could not be started.'));
        } finally {
            setIsActing(false);
        }
    };

    const control = async (action: 'cancel' | 'resume' | 'retry-failed') => {
        if (!run) return;
        setIsActing(true);
        setError(null);

        try {
            const response = await axios.post<{ run: DataCiteUrlUpdateRun }>(
                `/datacite/landing-page-url-updates/${run.id}/${action}`,
                {},
                { headers: buildCsrfHeaders() },
            );
            currentRunId.current = response.data.run.id;
            setRun(response.data.run);
            setIssues([]);
            toast.success(action === 'cancel' ? 'Cancellation requested.' : 'DataCite URL update queued.');
        } catch (requestError) {
            setError(responseError(requestError, 'The requested action could not be completed.'));
        } finally {
            setIsActing(false);
        }
    };

    const progress = useMemo(() => (run && run.total > 0 ? Math.round((run.processed / run.total) * 100) : 0), [run]);
    const displayedScope = preview?.scope_label ?? run?.scope_label ?? (scope === 'resources' ? 'Resources' : 'IGSNs');

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[90vh] max-w-5xl flex-col overflow-hidden" data-testid="datacite-url-update-modal">
                <DialogHeader>
                    <div className="flex flex-wrap items-center gap-2">
                        <DialogTitle>Update DataCite landing-page URLs — {displayedScope}</DialogTitle>
                        {(preview || run) && (
                            <Badge variant={(preview?.test_mode ?? run?.test_mode) ? 'secondary' : 'destructive'}>
                                {(preview?.test_mode ?? run?.test_mode) ? 'DataCite Test' : 'DataCite Production'}
                            </Badge>
                        )}
                    </div>
                    <DialogDescription>
                        Only published, internally hosted landing pages are eligible. External landing pages are always excluded.
                    </DialogDescription>
                </DialogHeader>

                <div className="min-h-0 flex-1 space-y-4 overflow-y-auto pr-1">
                    {error && (
                        <Alert variant="destructive">
                            <XCircle />
                            <AlertTitle>Unable to continue</AlertTitle>
                            <AlertDescription>{error}</AlertDescription>
                        </Alert>
                    )}

                    {isLoading && (
                        <div className="flex min-h-48 items-center justify-center gap-3 text-muted-foreground">
                            <Spinner />
                            Checking the first ten identifiers at DataCite…
                        </div>
                    )}

                    {preview && !isLoading && (
                        <>
                            <div className="grid gap-3 rounded-md border p-4 text-sm sm:grid-cols-2">
                                <div>
                                    <span className="font-medium">Eligible in ERNIE:</span> {preview.total}
                                </div>
                                <div>
                                    <span className="font-medium">DataCite endpoint:</span> {preview.datacite_endpoint}
                                </div>
                                <div className="break-all">
                                    <span className="font-medium">Canonical APP_URL:</span> {preview.target_base_url}
                                </div>
                            </div>

                            {!preview.can_start && (
                                <Alert variant="destructive">
                                    <ShieldAlert />
                                    <AlertTitle>Safety check failed</AlertTitle>
                                    <AlertDescription>{preview.blocking_message ?? 'The update cannot be started safely.'}</AlertDescription>
                                </Alert>
                            )}

                            {preview.total === 0 ? (
                                <Alert>
                                    <CircleSlash2 />
                                    <AlertTitle>No eligible records</AlertTitle>
                                    <AlertDescription>There are no published internal landing pages requiring this workflow.</AlertDescription>
                                </Alert>
                            ) : (
                                <div className="space-y-2">
                                    <h3 className="font-medium">First {preview.sample_count} URL comparisons</h3>
                                    <Table containerClassName="max-h-[48vh] rounded-md border">
                                        <TableHeader className="sticky top-0 bg-background">
                                            <TableRow>
                                                <TableHead>Identifier</TableHead>
                                                <TableHead>Before at DataCite</TableHead>
                                                <TableHead>After</TableHead>
                                                <TableHead>Status</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {preview.items.map((item) => (
                                                <TableRow key={item.resource_id}>
                                                    <TableCell className="font-mono text-xs">{item.identifier}</TableCell>
                                                    <TableCell className="max-w-72 text-xs break-all">{item.before_url ?? '—'}</TableCell>
                                                    <TableCell className="max-w-72 text-xs break-all">{item.target_url}</TableCell>
                                                    <TableCell>
                                                        <div className="space-y-1">
                                                            {outcomeBadge(item)}
                                                            {item.message && <p className="max-w-56 text-xs text-muted-foreground">{item.message}</p>}
                                                        </div>
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            )}

                            <Alert>
                                <AlertTriangle />
                                <AlertTitle>Production-safe partial update</AlertTitle>
                                <AlertDescription>
                                    After confirmation, every identifier and its current URL are checked again. ERNIE sends only the URL derived from
                                    the current APP_URL; metadata and DOI state are not changed.
                                </AlertDescription>
                            </Alert>
                        </>
                    )}

                    {run && (
                        <>
                            <div className="flex flex-wrap items-center justify-between gap-2 rounded-md border p-4">
                                <div>
                                    <p className="font-medium">{statusLabel[run.status]}</p>
                                    <p className="text-xs text-muted-foreground">{run.datacite_endpoint}</p>
                                </div>
                                <Badge variant={run.status === 'paused' || run.status === 'failed' ? 'destructive' : 'outline'}>{run.status}</Badge>
                            </div>

                            <div className="space-y-2">
                                <div className="flex justify-between text-sm">
                                    <span>Progress</span>
                                    <span>
                                        {run.processed} / {run.total}
                                    </span>
                                </div>
                                <Progress value={progress} />
                            </div>

                            <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                                {(
                                    [
                                        ['Updated', run.updated],
                                        ['Already current', run.already_current],
                                        ['Skipped', run.skipped],
                                        ['Failed', run.failed],
                                        ['Remaining', Math.max(0, run.total - run.processed)],
                                    ] as Array<[string, number]>
                                ).map(([label, value]) => (
                                    <div key={String(label)} className="rounded-md border p-3 text-center">
                                        <p className="text-2xl font-semibold">{value}</p>
                                        <p className="text-xs text-muted-foreground">{label}</p>
                                    </div>
                                ))}
                            </div>

                            {(run.pause_reason || run.last_error) && (
                                <Alert variant="destructive">
                                    <ShieldAlert />
                                    <AlertTitle>The run needs attention</AlertTitle>
                                    <AlertDescription>{run.pause_reason ?? run.last_error}</AlertDescription>
                                </Alert>
                            )}

                            {run.status === 'completed' && run.failed === 0 && (
                                <Alert className="border-green-200 bg-green-50 dark:border-green-800 dark:bg-green-950">
                                    <CheckCircle2 className="text-green-600" />
                                    <AlertTitle>DataCite URL update completed</AlertTitle>
                                    <AlertDescription>
                                        {run.updated} updated, {run.already_current} already current, and {run.skipped} safely skipped.
                                    </AlertDescription>
                                </Alert>
                            )}

                            {issues.length > 0 && (
                                <div className="space-y-2">
                                    <h3 className="font-medium">Issues and skipped records</h3>
                                    <div className="max-h-64 space-y-2 overflow-y-auto rounded-md border p-2">
                                        {issues.map((item) => (
                                            <div key={item.id} className="rounded border p-2 text-xs">
                                                <div className="flex flex-wrap justify-between gap-2">
                                                    <span className="font-mono">{item.identifier}</span>
                                                    <Badge variant={item.status === 'failed' ? 'destructive' : 'outline'}>{item.status}</Badge>
                                                </div>
                                                {item.error_message && <p className="mt-1 text-muted-foreground">{item.error_message}</p>}
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </>
                    )}
                </div>

                <DialogFooter className="border-t pt-4">
                    {preview && (
                        <>
                            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={isActing}>
                                Cancel
                            </Button>
                            <Button
                                type="button"
                                onClick={() => void start()}
                                disabled={isActing || !preview.can_start || preview.total === 0}
                                data-testid="datacite-url-update-confirm"
                            >
                                {isActing && <Spinner />}
                                Start URL update
                            </Button>
                        </>
                    )}
                    {run && (
                        <>
                            {run.can_cancel && run.status !== 'cancel_requested' && (
                                <Button type="button" variant="destructive" onClick={() => void control('cancel')} disabled={isActing}>
                                    Request cancellation
                                </Button>
                            )}
                            {run.can_resume && (
                                <Button type="button" onClick={() => void control('resume')} disabled={isActing}>
                                    <RefreshCw /> Resume
                                </Button>
                            )}
                            {run.can_retry_failed && (
                                <Button type="button" onClick={() => void control('retry-failed')} disabled={isActing}>
                                    <RefreshCw /> Retry failed
                                </Button>
                            )}
                            {terminalStatuses.has(run.status) && (
                                <Button type="button" variant="outline" onClick={() => void loadPreview()} disabled={isActing || isLoading}>
                                    Prepare another run
                                </Button>
                            )}
                            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={isActing}>
                                Close
                            </Button>
                        </>
                    )}
                    {!preview && !run && !isLoading && (
                        <Button type="button" variant="outline" onClick={() => void loadPreview()}>
                            Retry preview
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
