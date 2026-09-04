import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { RefreshCw } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import { FairImprovementIndicator } from '@/components/assessment/fair-improvement-indicator';
import { ResourceImpactFilters } from '@/components/resource-impact-filters';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { LoadingButton } from '@/components/ui/loading-button';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import {
    type AssessmentEntry,
    type AssessmentJobStatus,
    type AssessmentPageProps,
    type AssessmentScope,
    type AssessmentSummary,
} from '@/types/assessment';
import type { ResourceImpactFilterState } from '@/types/resource-impact-filters';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Assessment',
        href: '/assessment',
    },
];

type ScopeState = {
    isChecking: boolean;
    progress: string;
};

const RELOAD_KEYS = [
    'resourcesNeedingAttention',
    'igsnsNeedingAttention',
    'resourceAssessmentSummary',
    'igsnAssessmentSummary',
    'datacenterOptions',
] as const;

const ENDPOINTS: Record<AssessmentScope, string> = {
    resource: '/assessment/check-resources',
    igsn: '/assessment/check-igsns',
};

const ASSESSMENT_SERVICE_UNAVAILABLE_MESSAGE = 'The FAIR assessment service is currently unavailable. Please try again shortly.';

type AssessmentErrorPayload = {
    error?: string;
    progress?: string;
};

function userFacingAssessmentMessage(message: string): string {
    return message.replace(/F-?UJI/gi, 'FAIR assessment service');
}

function scopeLabel(scope: AssessmentScope): string {
    return scope === 'igsn' ? 'IGSNs' : 'Resources';
}

function scopeNoun(scope: AssessmentScope): string {
    return scope === 'igsn' ? 'IGSNs' : 'resources';
}

function getAssessmentErrorMessage(error: unknown, fallback: string): string {
    if (!axios.isAxiosError(error)) {
        return fallback;
    }

    const payload = error.response?.data as AssessmentErrorPayload | undefined;

    if (typeof payload?.progress === 'string' && payload.progress.trim() !== '') {
        return userFacingAssessmentMessage(payload.progress);
    }

    if (typeof payload?.error === 'string' && payload.error.trim() !== '') {
        return userFacingAssessmentMessage(payload.error);
    }

    return fallback;
}

function summaryText(summary: AssessmentSummary): string {
    return `${summary.assessed} assessed, ${summary.failed} failed, ${summary.skipped} skipped, ${summary.unassessed} remaining.`;
}

function assessmentLabel(scope: AssessmentScope): string {
    return scope === 'resource' ? 'resource assessments' : 'IGSN assessments';
}

function hasCuratorAction(entry: AssessmentEntry): boolean {
    return (
        entry.improvementOpportunity.status === 'available' &&
        entry.improvementOpportunity.suggestions.some((suggestion) => suggestion.actor === 'curator')
    );
}

function assistanceUrl(doi: string): string {
    return '/assistance?' + new URLSearchParams({ doi }).toString();
}

function doiResolverUrl(doi: string): string {
    const encodedDoi = encodeURI(doi).replaceAll('?', '%3F').replaceAll('#', '%23');

    return `https://doi.org/${encodedDoi}`;
}

function AssessmentDoi({ doi }: { doi: string | null }) {
    if (doi === null) {
        return <span className="block truncate">N/A</span>;
    }

    return (
        <a
            href={doiResolverUrl(doi)}
            target="_blank"
            rel="noopener noreferrer"
            className="block truncate text-primary underline decoration-dotted underline-offset-2 hover:text-primary/80"
            title={doi}
        >
            {doi}
        </a>
    );
}

function emptyStateMessage(summary: AssessmentSummary, scope: AssessmentScope, canRunAssessments: boolean, hasActiveFilters = false): string {
    if (hasActiveFilters && summary.total === 0) {
        return `No ${scopeNoun(scope)} match the active DOI and Datacenter filters.`;
    }

    if (summary.total === 0) {
        return `No ${scopeNoun(scope)} are available.`;
    }

    if (summary.assessed === 0 && summary.failed === 0 && summary.skipped === 0) {
        return canRunAssessments
            ? `No assessment results available yet. Run Check ${scopeLabel(scope)} to populate this list.`
            : `No assessment results are available yet. Ask an Admin or Group Leader to run Check ${scopeLabel(scope)}.`;
    }

    if (summary.assessed === 0) {
        return `No completed ${assessmentLabel(scope)} are available yet.`;
    }

    return `No ${scopeNoun(scope)} currently require attention.`;
}

export function AssessmentTable({
    entries,
    summary,
    scope,
    canRunAssessments,
    canAccessAssistance,
    showImprovementActorLabels,
    hasActiveFilters = false,
}: {
    entries: AssessmentEntry[];
    summary: AssessmentSummary;
    scope: AssessmentScope;
    canRunAssessments: boolean;
    canAccessAssistance: boolean;
    showImprovementActorLabels: boolean;
    hasActiveFilters?: boolean;
}) {
    if (entries.length === 0) {
        return <p className="text-sm text-muted-foreground">{emptyStateMessage(summary, scope, canRunAssessments, hasActiveFilters)}</p>;
    }

    return (
        <Table className="table-fixed">
            <TableHeader>
                <TableRow>
                    <TableHead className="hidden w-40 sm:table-cell">DOI</TableHead>
                    <TableHead>Main Title</TableHead>
                    <TableHead className="w-28 text-center">FAIR opportunity</TableHead>
                    <TableHead className="w-20 text-right">Score</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {entries.map((entry) => (
                    <TableRow key={entry.id}>
                        <TableCell className="hidden overflow-hidden font-mono text-xs text-muted-foreground sm:table-cell">
                            <AssessmentDoi doi={entry.doi} />
                        </TableCell>
                        <TableCell className="min-w-0 overflow-hidden font-medium">
                            <span className="block truncate" title={entry.mainTitle}>
                                {entry.mainTitle}
                            </span>
                            <span className="block truncate font-mono text-[11px] font-normal text-muted-foreground sm:hidden">
                                <AssessmentDoi doi={entry.doi} />
                            </span>
                            {scope === 'resource' && hasCuratorAction(entry) && (
                                <span className="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs font-normal">
                                    <Link
                                        href={'/editor?resourceId=' + entry.id}
                                        className="text-primary underline underline-offset-4 hover:text-primary/80"
                                    >
                                        Open in Data Editor
                                    </Link>
                                    {canAccessAssistance && entry.doi !== null && entry.hasPendingSuggestions && (
                                        <Link
                                            href={assistanceUrl(entry.doi)}
                                            className="text-primary underline underline-offset-4 hover:text-primary/80"
                                        >
                                            Check Assistant
                                        </Link>
                                    )}
                                </span>
                            )}
                        </TableCell>
                        <TableCell className="text-center">
                            <FairImprovementIndicator opportunity={entry.improvementOpportunity} showActorLabels={showImprovementActorLabels} />
                        </TableCell>
                        <TableCell className="text-right font-semibold">{entry.score.toFixed(2)}%</TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}

export default function Assessment({
    fujiConfigured,
    fujiHealthy,
    fujiStatusMessage,
    fujiStatusCode,
    canRunAssessments,
    canAccessAssistance,
    showImprovementActorLabels,
    includeExternalResources,
    includeDraftReviewResources,
    filters = { doi: null, datacenter_id: null },
    datacenterOptions = [],
    resourcesNeedingAttention,
    igsnsNeedingAttention,
    resourceAssessmentSummary,
    igsnAssessmentSummary,
}: AssessmentPageProps) {
    const [states, setStates] = useState<Record<AssessmentScope, ScopeState>>({
        resource: { isChecking: false, progress: '' },
        igsn: { isChecking: false, progress: '' },
    });
    const fujiConfiguredForActions = fujiConfigured;
    const pollingRefs = useRef<Record<AssessmentScope, ReturnType<typeof setTimeout> | null>>({
        resource: null,
        igsn: null,
    });

    useEffect(() => {
        const timers = pollingRefs.current;

        return () => {
            for (const scope of Object.keys(timers) as AssessmentScope[]) {
                if (timers[scope] !== null) {
                    clearTimeout(timers[scope]);
                }
            }
        };
    }, []);

    function patchState(scope: AssessmentScope, patch: Partial<ScopeState>) {
        setStates((current) => ({
            ...current,
            [scope]: {
                ...current[scope],
                ...patch,
            },
        }));
    }

    function stopPolling(scope: AssessmentScope) {
        if (pollingRefs.current[scope] !== null) {
            clearTimeout(pollingRefs.current[scope]);
            pollingRefs.current[scope] = null;
        }
    }

    function startPolling(scope: AssessmentScope, jobId: string) {
        stopPolling(scope);

        const pollStatus = async () => {
            try {
                const { data } = await axios.get<AssessmentJobStatus>(`/assessment/check/${scope}/${jobId}/status`);

                if (data.status === 'completed') {
                    stopPolling(scope);
                    patchState(scope, { isChecking: false, progress: '' });
                    toast.success(`${scopeLabel(scope)} assessment completed.`);
                    router.reload({ only: [...RELOAD_KEYS] });

                    return;
                }

                if (data.status === 'failed') {
                    stopPolling(scope);
                    patchState(scope, { isChecking: false, progress: '' });
                    toast.error(userFacingAssessmentMessage(data.error ?? `${scopeLabel(scope)} assessment failed.`));

                    return;
                }

                patchState(scope, {
                    isChecking: true,
                    progress: userFacingAssessmentMessage(data.progress),
                });

                pollingRefs.current[scope] = setTimeout(pollStatus, 3000);
            } catch (error) {
                stopPolling(scope);
                patchState(scope, { isChecking: false, progress: '' });

                if (axios.isAxiosError(error) && error.response?.status === 404) {
                    toast.warning(getAssessmentErrorMessage(error, 'Job not found.'));

                    return;
                }

                toast.error(getAssessmentErrorMessage(error, `Failed to check ${scopeLabel(scope)} assessment status.`));
            }
        };

        pollingRefs.current[scope] = setTimeout(pollStatus, 3000);
    }

    async function handleCheck(scope: AssessmentScope) {
        patchState(scope, { isChecking: true, progress: `${scopeLabel(scope)} assessment is waiting to start.` });
        stopPolling(scope);

        try {
            const { data } = await axios.post<{ jobId: string }>(ENDPOINTS[scope]);
            startPolling(scope, data.jobId);
        } catch (error) {
            patchState(scope, { isChecking: false, progress: '' });

            if (axios.isAxiosError(error) && error.response?.status === 409) {
                toast.warning(error.response.data?.error ?? `${scopeLabel(scope)} assessment is already running.`);

                return;
            }

            if (axios.isAxiosError(error) && error.response?.status === 503) {
                toast.error(getAssessmentErrorMessage(error, ASSESSMENT_SERVICE_UNAVAILABLE_MESSAGE));

                return;
            }

            toast.error(`Failed to start ${scopeLabel(scope)} assessment.`);
        }
    }

    async function handleCheckAll() {
        for (const scope of ['resource', 'igsn'] as AssessmentScope[]) {
            patchState(scope, { isChecking: true, progress: `${scopeLabel(scope)} assessment is waiting to start.` });
            stopPolling(scope);
        }

        try {
            const { data } = await axios.post<Record<string, string>>('/assessment/check-all');

            for (const scope of ['resource', 'igsn'] as AssessmentScope[]) {
                const jobId = data[`${scope}JobId`];
                const error = data[`${scope}Error`];

                if (jobId) {
                    startPolling(scope, jobId);

                    continue;
                }

                patchState(scope, { isChecking: false, progress: '' });

                if (error) {
                    toast.warning(userFacingAssessmentMessage(error));
                }
            }
        } catch (error) {
            for (const scope of ['resource', 'igsn'] as AssessmentScope[]) {
                patchState(scope, { isChecking: false, progress: '' });
            }

            if (axios.isAxiosError(error) && error.response?.status === 409) {
                toast.warning(error.response.data?.error ?? 'All assessment jobs are already running.');

                return;
            }

            if (axios.isAxiosError(error) && error.response?.status === 503) {
                toast.error(getAssessmentErrorMessage(error, ASSESSMENT_SERVICE_UNAVAILABLE_MESSAGE));

                return;
            }

            toast.error('Failed to start the assessment jobs.');
        }
    }

    function assessmentQuery(nextFilters: ResourceImpactFilterState, includeExternal: boolean, includeDraftReview: boolean) {
        return {
            ...(nextFilters.doi !== null ? { doi: nextFilters.doi } : {}),
            ...(nextFilters.datacenter_id !== null ? { datacenter_id: nextFilters.datacenter_id } : {}),
            ...(includeExternal ? { include_external_resources: true } : {}),
            ...(includeDraftReview ? { include_draft_review_resources: true } : {}),
        };
    }

    function handleFiltersChange(nextFilters: ResourceImpactFilterState) {
        router.get('/assessment', assessmentQuery(nextFilters, includeExternalResources, includeDraftReviewResources), {
            only: ['filters', ...RELOAD_KEYS],
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    }

    function handleExternalResourcesChange(checked: boolean) {
        router.get('/assessment', assessmentQuery(filters, checked, includeDraftReviewResources), {
            only: ['includeExternalResources', 'resourcesNeedingAttention'],
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    }

    function handleDraftReviewResourcesChange(checked: boolean) {
        router.get('/assessment', assessmentQuery(filters, includeExternalResources, checked), {
            only: ['includeDraftReviewResources', 'resourcesNeedingAttention'],
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    }

    const isAnyChecking = states.resource.isChecking || states.igsn.isChecking;
    const hasActiveFilters = filters.doi !== null || filters.datacenter_id !== null;
    const fujiAvailabilityMessage = !fujiConfigured
        ? 'The FAIR assessment service is not configured for this environment.'
        : !fujiHealthy
          ? userFacingAssessmentMessage(fujiStatusMessage ?? ASSESSMENT_SERVICE_UNAVAILABLE_MESSAGE)
          : null;

    const fujiStatusDetail =
        !fujiConfigured || fujiHealthy
            ? null
            : fujiStatusCode === 0
              ? 'Connection refused or DNS failure — check the assessment service configuration and network connectivity.'
              : fujiStatusCode !== null
                ? `HTTP ${fujiStatusCode} — check the assessment service configuration and service logs.`
                : null;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Assessment" />

            <div className="w-full space-y-6 p-6">
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Assessment</h1>
                        <p className="text-sm text-muted-foreground">FAIR assessment dashboard for resources and IGSNs.</p>
                    </div>

                    {canRunAssessments && (
                        <LoadingButton onClick={handleCheckAll} disabled={!fujiConfiguredForActions} loading={isAnyChecking}>
                            {isAnyChecking ? (
                                'Checking...'
                            ) : (
                                <>
                                    <RefreshCw className="mr-2 h-4 w-4" />
                                    Check all
                                </>
                            )}
                        </LoadingButton>
                    )}
                </div>

                <ResourceImpactFilters filters={filters} datacenterOptions={datacenterOptions} onChange={handleFiltersChange} />

                {fujiAvailabilityMessage !== null && (
                    <div className="rounded-lg border border-dashed bg-muted/30 p-4 text-sm text-muted-foreground">
                        <p>{fujiAvailabilityMessage}</p>
                        {fujiStatusDetail !== null && <p className="mt-1 text-xs opacity-75">{fujiStatusDetail}</p>}
                    </div>
                )}

                {(['resource', 'igsn'] as AssessmentScope[]).map((scope) => {
                    const state = states[scope];

                    if (!state.isChecking || state.progress === '') {
                        return null;
                    }

                    return (
                        <div key={scope} className="flex items-center gap-2 rounded-lg border bg-muted/50 p-3 text-sm text-muted-foreground">
                            <Spinner size="sm" />
                            <span>{state.progress}</span>
                        </div>
                    );
                })}

                <div className="grid gap-6" data-testid="assessment-card-stack">
                    <Card>
                        <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-4 space-y-0">
                            <div className="space-y-1.5">
                                <CardTitle>Resources needing your attention</CardTitle>
                                <CardDescription>{summaryText(resourceAssessmentSummary)}</CardDescription>
                                <div className="flex items-center gap-2 pt-1">
                                    <Switch
                                        id="include-external-resources"
                                        size="sm"
                                        checked={includeExternalResources}
                                        onCheckedChange={handleExternalResourcesChange}
                                    />
                                    <Label htmlFor="include-external-resources" className="text-xs text-muted-foreground">
                                        Include resources with external landing pages
                                    </Label>
                                </div>
                                <div className="flex items-center gap-2 pt-1">
                                    <Switch
                                        id="include-draft-review-resources"
                                        size="sm"
                                        checked={includeDraftReviewResources}
                                        onCheckedChange={handleDraftReviewResourcesChange}
                                    />
                                    <Label htmlFor="include-draft-review-resources" className="text-xs text-muted-foreground">
                                        Include resources with Draft or Review status
                                    </Label>
                                </div>
                            </div>
                            {canRunAssessments && (
                                <LoadingButton
                                    variant="outline"
                                    size="sm"
                                    onClick={() => handleCheck('resource')}
                                    disabled={!fujiConfiguredForActions}
                                    loading={states.resource.isChecking}
                                >
                                    {states.resource.isChecking ? 'Checking...' : 'Check Resources'}
                                </LoadingButton>
                            )}
                        </CardHeader>
                        <CardContent>
                            <AssessmentTable
                                entries={resourcesNeedingAttention}
                                summary={resourceAssessmentSummary}
                                scope="resource"
                                canRunAssessments={canRunAssessments}
                                canAccessAssistance={canAccessAssistance}
                                showImprovementActorLabels={showImprovementActorLabels}
                                hasActiveFilters={hasActiveFilters}
                            />
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between gap-4 space-y-0">
                            <div className="space-y-1.5">
                                <CardTitle>IGSNs needing your attention</CardTitle>
                                <CardDescription>{summaryText(igsnAssessmentSummary)}</CardDescription>
                            </div>
                            {canRunAssessments && (
                                <LoadingButton
                                    variant="outline"
                                    size="sm"
                                    onClick={() => handleCheck('igsn')}
                                    disabled={!fujiConfiguredForActions}
                                    loading={states.igsn.isChecking}
                                >
                                    {states.igsn.isChecking ? 'Checking...' : 'Check IGSNs'}
                                </LoadingButton>
                            )}
                        </CardHeader>
                        <CardContent>
                            <AssessmentTable
                                entries={igsnsNeedingAttention}
                                summary={igsnAssessmentSummary}
                                scope="igsn"
                                canRunAssessments={canRunAssessments}
                                canAccessAssistance={canAccessAssistance}
                                showImprovementActorLabels={showImprovementActorLabels}
                                hasActiveFilters={hasActiveFilters}
                            />
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
