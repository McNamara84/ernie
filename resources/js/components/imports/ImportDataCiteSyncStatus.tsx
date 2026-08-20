import axios, { isAxiosError } from 'axios';
import { AlertTriangle, CheckCircle2, RefreshCw, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { buildCsrfHeaders } from '@/lib/csrf-token';

export interface ImportDataCiteSyncProgress {
    phase?: 'importing' | 'syncing' | 'completed';
    sync_total?: number;
    sync_processed?: number;
    sync_succeeded?: number;
    sync_failed?: number;
    sync_errors?: Array<{ resource_id: number; doi: string | null; error: string }>;
    sync_skipped_test_mode?: boolean;
    sync_retry_available?: boolean;
}

interface ImportDataCiteSyncStatusProps {
    progress: ImportDataCiteSyncProgress;
    retryUrl: string;
    onRetryStarted: () => void;
}

export function ImportDataCiteSyncStatus({ progress, retryUrl, onRetryStarted }: ImportDataCiteSyncStatusProps) {
    const [isRetrying, setIsRetrying] = useState(false);
    const total = progress.sync_total ?? 0;
    const succeeded = progress.sync_succeeded ?? 0;
    const failed = progress.sync_failed ?? 0;

    if (total === 0 && !progress.sync_skipped_test_mode) {
        return null;
    }

    const retry = async () => {
        setIsRetrying(true);

        try {
            await axios.post(retryUrl, {}, { headers: buildCsrfHeaders() });
            toast.info('DataCite retry started');
            onRetryStarted();
        } catch (error) {
            const message = isAxiosError(error)
                ? (error.response?.data?.error ?? error.response?.data?.message ?? error.message)
                : 'The DataCite retry could not be started.';
            toast.error(message);
        } finally {
            setIsRetrying(false);
        }
    };

    if (progress.sync_skipped_test_mode) {
        return (
            <Alert>
                <ShieldCheck className="size-4" />
                <AlertTitle>DataCite update skipped</AlertTitle>
                <AlertDescription>Test mode is active. Landing pages were created locally, and no metadata was written to DataCite.</AlertDescription>
            </Alert>
        );
    }

    if (failed === 0) {
        return (
            <Alert className="border-green-200 bg-green-50 dark:border-green-800 dark:bg-green-950">
                <CheckCircle2 className="size-4 text-green-600 dark:text-green-400" />
                <AlertTitle>DataCite metadata updated</AlertTitle>
                <AlertDescription>
                    {succeeded} {succeeded === 1 ? 'record now points' : 'records now point'} to the new landing page.
                </AlertDescription>
            </Alert>
        );
    }

    return (
        <Alert variant="destructive">
            <AlertTriangle className="size-4" />
            <AlertTitle>DataCite update incomplete</AlertTitle>
            <AlertDescription className="space-y-3">
                <p>
                    {failed} of {total} metadata {total === 1 ? 'update' : 'updates'} failed. The imported records and their published landing pages
                    were kept.
                </p>
                {progress.sync_errors?.[0] && (
                    <p className="text-xs">
                        {progress.sync_errors[0].doi ?? `Resource ${progress.sync_errors[0].resource_id}`}: {progress.sync_errors[0].error}
                    </p>
                )}
                {progress.sync_retry_available && (
                    <Button type="button" variant="outline" size="sm" disabled={isRetrying} onClick={() => void retry()}>
                        <RefreshCw className={isRetrying ? 'animate-spin' : undefined} />
                        Retry failed updates
                    </Button>
                )}
            </AlertDescription>
        </Alert>
    );
}

export function dataCiteSyncProgressLabel(progress: ImportDataCiteSyncProgress): string | null {
    if (progress.phase !== 'syncing') {
        return null;
    }

    return `Updating DataCite metadata… ${progress.sync_processed ?? 0} / ${progress.sync_total ?? 0}`;
}
