import axios, { isAxiosError } from 'axios';
import { AlertTriangle, CheckCircle2, RefreshCw, XCircle } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Progress } from '@/components/ui/progress';
import { Spinner } from '@/components/ui/spinner';
import { buildCsrfHeaders } from '@/lib/csrf-token';

export interface IgsnRegistrationRun {
    id: string;
    status: 'preparing' | 'queued' | 'running' | 'paused' | 'cancel_requested' | 'cancelled' | 'completed' | 'failed';
    test_mode: boolean;
    datacite_endpoint: string;
    total: number;
    processed: number;
    registered: number;
    updated: number;
    failed: number;
    cancelled: number;
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

interface IgsnRegistrationItem {
    id: number;
    resource_id: number | null;
    identifier: string;
    status: string;
    operation: string | null;
    attempts: number;
    last_http_status: number | null;
    error_message: string | null;
    processed_at: string | null;
}

interface ItemsResponse {
    items: IgsnRegistrationItem[];
    pagination: {
        current_page: number;
        last_page: number;
        total: number;
    };
}

interface IgsnRegistrationRunModalProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    initialRun: IgsnRegistrationRun | null;
    onRunChange?: (run: IgsnRegistrationRun) => void;
    onTerminal?: () => void;
}

const terminalStatuses = new Set<IgsnRegistrationRun['status']>(['cancelled', 'completed', 'failed']);

const statusLabels: Record<IgsnRegistrationRun['status'], string> = {
    preparing: 'Preparing registration',
    queued: 'Queued',
    running: 'Registering at DataCite',
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

    return fallback;
}

export function IgsnRegistrationRunModal({ open, onOpenChange, initialRun, onRunChange, onTerminal }: IgsnRegistrationRunModalProps) {
    const [run, setRun] = useState<IgsnRegistrationRun | null>(initialRun);
    const [issues, setIssues] = useState<IgsnRegistrationItem[]>([]);
    const [issuePage, setIssuePage] = useState(1);
    const [issueLastPage, setIssueLastPage] = useState(1);
    const [issueTotal, setIssueTotal] = useState(0);
    const [isLoading, setIsLoading] = useState(false);
    const [isActing, setIsActing] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const terminalNotificationRef = useRef<string | null>(null);

    useEffect(() => {
        setRun(initialRun);
        setIssues([]);
        setIssuePage(1);
        setIssueLastPage(1);
        setIssueTotal(0);
        setError(null);
    }, [initialRun]);

    const loadIssues = useCallback(async (runId: string, page = 1) => {
        const response = await axios.get<ItemsResponse>(`/igsns/batch-register/${runId}/items`, {
            params: { issues: 1, page },
        });
        setIssues(response.data.items);
        setIssuePage(response.data.pagination.current_page);
        setIssueLastPage(Math.max(1, response.data.pagination.last_page));
        setIssueTotal(response.data.pagination.total);
    }, []);

    const acceptRun = useCallback(
        (nextRun: IgsnRegistrationRun) => {
            setRun(nextRun);
            onRunChange?.(nextRun);

            if (!terminalStatuses.has(nextRun.status)) {
                terminalNotificationRef.current = null;
            } else if (terminalNotificationRef.current !== nextRun.id) {
                terminalNotificationRef.current = nextRun.id;
                onTerminal?.();
            }
        },
        [onRunChange, onTerminal],
    );

    const loadRun = useCallback(
        async (runId: string) => {
            const response = await axios.get<{ run: IgsnRegistrationRun }>(`/igsns/batch-register/${runId}`);
            acceptRun(response.data.run);

            if (terminalStatuses.has(response.data.run.status) || response.data.run.status === 'paused') {
                await loadIssues(runId, 1);
            }

            setError(null);
        },
        [acceptRun, loadIssues],
    );
    const runId = run?.id;

    useEffect(() => {
        if (!open || !runId) return;

        setIsLoading(true);
        void loadRun(runId)
            .catch((requestError) => setError(responseError(requestError, 'The registration status could not be loaded.')))
            .finally(() => setIsLoading(false));
    }, [loadRun, open, runId]);

    useEffect(() => {
        if (!open || !run || terminalStatuses.has(run.status) || run.status === 'paused') return;

        const interval = window.setInterval(() => {
            void loadRun(run.id).catch((requestError) => {
                setError(responseError(requestError, 'The registration status could not be refreshed.'));
            });
        }, 3000);

        return () => window.clearInterval(interval);
    }, [loadRun, open, run]);

    const control = async (action: 'cancel' | 'resume' | 'retry-failed') => {
        if (!run) return;

        setIsActing(true);
        setError(null);
        try {
            const response = await axios.post<{ run: IgsnRegistrationRun }>(
                `/igsns/batch-register/${run.id}/${action}`,
                {},
                { headers: buildCsrfHeaders() },
            );
            acceptRun(response.data.run);
            setIssues([]);
            setIssueTotal(0);
            toast.success(action === 'cancel' ? 'Cancellation requested.' : 'IGSN registration queued.');
        } catch (requestError) {
            setError(responseError(requestError, 'The requested registration action failed.'));
        } finally {
            setIsActing(false);
        }
    };

    const changeIssuePage = async (page: number) => {
        if (!run || page < 1 || page > issueLastPage || page === issuePage) return;

        setIsLoading(true);
        setError(null);
        try {
            await loadIssues(run.id, page);
        } catch (requestError) {
            setError(responseError(requestError, 'The registration issues could not be loaded.'));
        } finally {
            setIsLoading(false);
        }
    };

    const progress = useMemo(() => (run && run.total > 0 ? Math.round((run.processed / run.total) * 100) : 0), [run]);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[90vh] max-w-3xl flex-col overflow-hidden" data-testid="igsn-registration-run-modal">
                <DialogHeader>
                    <div className="flex flex-wrap items-center gap-2">
                        <DialogTitle>IGSN batch registration</DialogTitle>
                        {run && (
                            <Badge variant={run.test_mode ? 'secondary' : 'destructive'}>
                                {run.test_mode ? 'DataCite Test' : 'DataCite Production'}
                            </Badge>
                        )}
                    </div>
                    <DialogDescription>The registration continues on the DataCite queue when this dialog or browser is closed.</DialogDescription>
                </DialogHeader>

                <div className="min-h-0 space-y-4 overflow-y-auto py-1">
                    {!run && (
                        <Alert>
                            <AlertTriangle />
                            <AlertTitle>No registration run</AlertTitle>
                            <AlertDescription>Select IGSNs and start a registration from the bulk actions toolbar.</AlertDescription>
                        </Alert>
                    )}

                    {run && (
                        <>
                            <div className="flex flex-wrap items-center justify-between gap-2 rounded-md border p-4">
                                <div>
                                    <p className="font-medium">{statusLabels[run.status]}</p>
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
                                        ['Registered', run.registered],
                                        ['Updated', run.updated],
                                        ['Failed', run.failed],
                                        ['Cancelled', run.cancelled],
                                        ['Remaining', Math.max(0, run.total - run.processed)],
                                    ] as Array<[string, number]>
                                ).map(([label, value]) => (
                                    <div key={label} className="rounded-md border p-3 text-center">
                                        <p className="text-2xl font-semibold">{value}</p>
                                        <p className="text-xs text-muted-foreground">{label}</p>
                                    </div>
                                ))}
                            </div>

                            {(run.pause_reason || run.last_error || error) && (
                                <Alert variant="destructive">
                                    <XCircle />
                                    <AlertTitle>The registration needs attention</AlertTitle>
                                    <AlertDescription>{error ?? run.pause_reason ?? run.last_error}</AlertDescription>
                                </Alert>
                            )}

                            {run.status === 'completed' && run.failed === 0 && (
                                <Alert className="border-green-200 bg-green-50 dark:border-green-800 dark:bg-green-950">
                                    <CheckCircle2 className="text-green-600" />
                                    <AlertTitle>IGSN registration completed</AlertTitle>
                                    <AlertDescription>
                                        {run.registered} registered and {run.updated} updated successfully.
                                    </AlertDescription>
                                </Alert>
                            )}

                            {issueTotal > 0 && (
                                <div className="space-y-2">
                                    <div className="flex items-center justify-between">
                                        <h3 className="font-medium">Issues ({issueTotal})</h3>
                                        <span className="text-xs text-muted-foreground">
                                            Page {issuePage} of {issueLastPage}
                                        </span>
                                    </div>
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
                                    {issueLastPage > 1 && (
                                        <div className="flex justify-end gap-2">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                disabled={isLoading || issuePage <= 1}
                                                onClick={() => void changeIssuePage(issuePage - 1)}
                                            >
                                                Previous
                                            </Button>
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                disabled={isLoading || issuePage >= issueLastPage}
                                                onClick={() => void changeIssuePage(issuePage + 1)}
                                            >
                                                Next
                                            </Button>
                                        </div>
                                    )}
                                </div>
                            )}

                            {isLoading && (
                                <div className="flex items-center gap-2 text-sm text-muted-foreground" role="status">
                                    <Spinner size="sm" /> Refreshing registration status…
                                </div>
                            )}
                        </>
                    )}
                </div>

                <DialogFooter className="border-t pt-4">
                    {run?.can_cancel && run.status !== 'cancel_requested' && (
                        <Button variant="destructive" disabled={isActing} onClick={() => void control('cancel')}>
                            Request cancellation
                        </Button>
                    )}
                    {run?.can_resume && (
                        <Button disabled={isActing} onClick={() => void control('resume')}>
                            <RefreshCw /> Resume
                        </Button>
                    )}
                    {run?.can_retry_failed && (
                        <Button disabled={isActing} onClick={() => void control('retry-failed')}>
                            <RefreshCw /> Retry failed
                        </Button>
                    )}
                    <Button variant="outline" disabled={isActing} onClick={() => onOpenChange(false)}>
                        Close
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
