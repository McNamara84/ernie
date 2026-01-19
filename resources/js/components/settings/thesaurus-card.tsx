import { router, usePage } from '@inertiajs/react';
import { AlertCircle, Calendar, CheckCircle2, Database, Loader2, RefreshCw, XCircle } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { type SharedData } from '@/types';

/**
 * Extract CSRF token from cookies for fetch requests.
 * Centralizes CSRF token extraction to avoid duplication.
 */
function getCsrfToken(): string {
    return decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || '');
}

/** Delay before reloading page after successful thesaurus update (in ms) */
const UPDATE_SUCCESS_RELOAD_DELAY_MS = 1500;

export interface ThesaurusData {
    type: string;
    displayName: string;
    isActive: boolean;
    isElmoActive: boolean;
    exists: boolean;
    conceptCount: number;
    lastUpdated: string | null;
}

interface UpdateCheckResult {
    localCount: number;
    remoteCount: number;
    updateAvailable: boolean;
    lastUpdated: string | null;
}

interface JobStatus {
    status: 'running' | 'completed' | 'failed';
    thesaurusType: string;
    progress: string;
    startedAt?: string;
    completedAt?: string;
    failedAt?: string;
    error?: string;
}

interface ThesaurusRowProps {
    thesaurus: ThesaurusData;
    onActiveChange: (type: string, isActive: boolean) => void;
    onElmoActiveChange: (type: string, isElmoActive: boolean) => void;
    onUpdateComplete?: () => void;
}

function ThesaurusRow({ thesaurus, onActiveChange, onElmoActiveChange, onUpdateComplete }: ThesaurusRowProps) {
    const [checkStatus, setCheckStatus] = useState<'idle' | 'loading' | 'done' | 'error'>('idle');
    const [updateInfo, setUpdateInfo] = useState<UpdateCheckResult | null>(null);
    const [checkError, setCheckError] = useState<string | null>(null);
    const [updateJobId, setUpdateJobId] = useState<string | null>(null);
    const [jobStatus, setJobStatus] = useState<JobStatus | null>(null);
    const pollingRef = useRef<ReturnType<typeof setInterval> | null>(null);

    const { auth } = usePage<SharedData>().props;
    const isAdmin = auth.user?.role === 'admin';

    // Cleanup polling on unmount
    useEffect(() => {
        return () => {
            if (pollingRef.current) {
                clearInterval(pollingRef.current);
            }
        };
    }, []);

    const checkForUpdates = useCallback(async () => {
        setCheckStatus('loading');
        setUpdateInfo(null);
        setCheckError(null);

        try {
            const response = await fetch(`/thesauri/${thesaurus.type}/check`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': getCsrfToken(),
                },
                credentials: 'include',
            });

            if (!response.ok) {
                const errorData = await response.json();
                throw new Error(errorData.error || `HTTP ${response.status}`);
            }

            const data: UpdateCheckResult = await response.json();
            setUpdateInfo(data);
            setCheckStatus('done');
        } catch (error) {
            setCheckError(error instanceof Error ? error.message : 'Unknown error');
            setCheckStatus('error');
        }
    }, [thesaurus.type]);

    const pollJobStatus = useCallback(
        async (jobId: string) => {
            try {
                const response = await fetch(`/thesauri/update-status/${jobId}`, {
                    credentials: 'include',
                });

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const status: JobStatus = await response.json();
                setJobStatus(status);

                if (status.status === 'completed' || status.status === 'failed') {
                    if (pollingRef.current) {
                        clearInterval(pollingRef.current);
                        pollingRef.current = null;
                    }
                    setUpdateJobId(null);

                    if (status.status === 'completed') {
                        // Reset check status so user can check again
                        setCheckStatus('idle');
                        setUpdateInfo(null);
                        onUpdateComplete?.();
                    }
                }
            } catch {
                // Continue polling even on error
            }
        },
        [onUpdateComplete],
    );

    const triggerUpdate = useCallback(async () => {
        try {
            const response = await fetch(`/thesauri/${thesaurus.type}/update`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': getCsrfToken(),
                },
                credentials: 'include',
            });

            if (!response.ok) {
                const errorData = await response.json();
                throw new Error(errorData.error || `HTTP ${response.status}`);
            }

            const data = await response.json();
            setUpdateJobId(data.jobId);
            setJobStatus({
                status: 'running',
                thesaurusType: thesaurus.type,
                progress: 'Starting update...',
            });

            // Start polling
            pollingRef.current = setInterval(() => {
                pollJobStatus(data.jobId);
            }, 2000);

            // Initial poll
            pollJobStatus(data.jobId);
        } catch (error) {
            setJobStatus({
                status: 'failed',
                thesaurusType: thesaurus.type,
                progress: 'Update failed',
                error: error instanceof Error ? error.message : 'Unknown error',
            });
        }
    }, [thesaurus.type, pollJobStatus]);

    const formatDate = (dateString: string | null) => {
        if (!dateString) return 'Never';
        try {
            return new Date(dateString).toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
            });
        } catch {
            return dateString;
        }
    };

    const isUpdating = updateJobId !== null && jobStatus?.status === 'running';

    return (
        <div className="rounded-lg border p-4" data-testid={`thesaurus-row-${thesaurus.type}`}>
            {/* Header with name and toggles */}
            <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div className="flex-1">
                    <h3 className="font-medium">{thesaurus.displayName}</h3>
                    <div className="mt-1 flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                        {thesaurus.exists ? (
                            <>
                                <span className="flex items-center gap-1">
                                    <Database className="h-3.5 w-3.5" />
                                    {thesaurus.conceptCount.toLocaleString()} concepts
                                </span>
                                <span className="text-muted-foreground/50">•</span>
                                <span className="flex items-center gap-1">
                                    <Calendar className="h-3.5 w-3.5" />
                                    {formatDate(thesaurus.lastUpdated)}
                                </span>
                            </>
                        ) : (
                            <Badge variant="outline" className="text-amber-600">
                                <AlertCircle className="mr-1 h-3 w-3" />
                                Not yet downloaded
                            </Badge>
                        )}
                    </div>
                </div>

                {/* Active toggles */}
                <div className="flex gap-4">
                    <div className="flex items-center gap-2">
                        <Checkbox
                            id={`thesaurus-ernie-${thesaurus.type}`}
                            checked={thesaurus.isActive}
                            onCheckedChange={(checked) => onActiveChange(thesaurus.type, checked === true)}
                        />
                        <Label htmlFor={`thesaurus-ernie-${thesaurus.type}`} className="text-sm font-normal">
                            ERNIE
                        </Label>
                    </div>
                    <div className="flex items-center gap-2">
                        <Checkbox
                            id={`thesaurus-elmo-${thesaurus.type}`}
                            checked={thesaurus.isElmoActive}
                            onCheckedChange={(checked) => onElmoActiveChange(thesaurus.type, checked === true)}
                        />
                        <Label htmlFor={`thesaurus-elmo-${thesaurus.type}`} className="text-sm font-normal">
                            ELMO
                        </Label>
                    </div>
                </div>
            </div>

            {/* Update check section */}
            <div className="mt-4 flex flex-wrap items-center gap-2">
                <Button variant="outline" size="sm" onClick={checkForUpdates} disabled={checkStatus === 'loading' || isUpdating}>
                    {checkStatus === 'loading' ? (
                        <>
                            <Loader2 className="mr-1 h-3.5 w-3.5 animate-spin" />
                            Checking...
                        </>
                    ) : (
                        <>
                            <RefreshCw className="mr-1 h-3.5 w-3.5" />
                            Check for Updates
                        </>
                    )}
                </Button>

                {updateInfo?.updateAvailable && isAdmin && !isUpdating && (
                    <Button size="sm" onClick={triggerUpdate}>
                        <RefreshCw className="mr-1 h-3.5 w-3.5" />
                        Update Now
                    </Button>
                )}
            </div>

            {/* Check error message */}
            {checkStatus === 'error' && checkError && (
                <div className="mt-3 flex items-start gap-2 rounded-md bg-red-50 p-3 text-sm text-red-700 dark:bg-red-950/50 dark:text-red-400">
                    <XCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
                    <span>Failed to check for updates: {checkError}</span>
                </div>
            )}

            {/* Update info message */}
            {updateInfo && checkStatus === 'done' && (
                <div
                    className={`mt-3 flex items-start gap-2 rounded-md p-3 text-sm ${
                        updateInfo.updateAvailable
                            ? 'bg-amber-50 text-amber-700 dark:bg-amber-950/50 dark:text-amber-400'
                            : 'bg-green-50 text-green-700 dark:bg-green-950/50 dark:text-green-400'
                    }`}
                >
                    {updateInfo.updateAvailable ? (
                        <>
                            <AlertCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
                            <span>
                                ERNIE contains {updateInfo.localCount.toLocaleString()} concepts, but NASA/GCMD contains{' '}
                                {updateInfo.remoteCount.toLocaleString()} concepts.
                                {isAdmin ? ' Would you like to update the thesaurus?' : ' Contact an administrator to update.'}
                            </span>
                        </>
                    ) : (
                        <>
                            <CheckCircle2 className="mt-0.5 h-4 w-4 flex-shrink-0" />
                            <span>Thesaurus is up to date ({updateInfo.localCount.toLocaleString()} concepts)</span>
                        </>
                    )}
                </div>
            )}

            {/* Update progress */}
            {jobStatus && (
                <div
                    className={`mt-3 flex items-start gap-2 rounded-md p-3 text-sm ${
                        jobStatus.status === 'running'
                            ? 'bg-blue-50 text-blue-700 dark:bg-blue-950/50 dark:text-blue-400'
                            : jobStatus.status === 'completed'
                              ? 'bg-green-50 text-green-700 dark:bg-green-950/50 dark:text-green-400'
                              : 'bg-red-50 text-red-700 dark:bg-red-950/50 dark:text-red-400'
                    }`}
                >
                    {jobStatus.status === 'running' ? (
                        <>
                            <Loader2 className="mt-0.5 h-4 w-4 flex-shrink-0 animate-spin" />
                            <span>{jobStatus.progress}</span>
                        </>
                    ) : jobStatus.status === 'completed' ? (
                        <>
                            <CheckCircle2 className="mt-0.5 h-4 w-4 flex-shrink-0" />
                            <span>{jobStatus.progress}</span>
                        </>
                    ) : (
                        <>
                            <XCircle className="mt-0.5 h-4 w-4 flex-shrink-0" />
                            <span>
                                {jobStatus.progress}
                                {jobStatus.error && `: ${jobStatus.error}`}
                            </span>
                        </>
                    )}
                </div>
            )}
        </div>
    );
}

export interface ThesaurusCardProps {
    thesauri: ThesaurusData[];
    onActiveChange: (type: string, isActive: boolean) => void;
    onElmoActiveChange: (type: string, isElmoActive: boolean) => void;
}

export function ThesaurusCard({ thesauri, onActiveChange, onElmoActiveChange }: ThesaurusCardProps) {
    // Reload page data after update to get fresh data from backend
    // Using Inertia's router.reload() for smoother UX (preserves scroll position)
    const handleUpdateComplete = useCallback(() => {
        // Small delay to show the success message before reload
        setTimeout(() => {
            router.reload({ only: ['thesauri'] });
        }, UPDATE_SUCCESS_RELOAD_DELAY_MS);
    }, []);

    return (
        <div className="space-y-4" data-testid="thesaurus-card">
            {thesauri.map((thesaurus) => (
                <ThesaurusRow
                    key={thesaurus.type}
                    thesaurus={thesaurus}
                    onActiveChange={onActiveChange}
                    onElmoActiveChange={onElmoActiveChange}
                    onUpdateComplete={handleUpdateComplete}
                />
            ))}
        </div>
    );
}
