import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { AlertTriangle, Building2, Check, RefreshCw, User, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import {
    type AcceptResponse,
    type AssistancePageProps,
    type AssistantManifest,
    type CheckStatusResponse,
    type PaginatedData,
    type SuggestedOrcidItem,
    type SuggestedRelationItem,
    type SuggestedRorItem,
} from '@/types/assistance';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Assistance',
        href: '/assistance',
    },
];

function sourceLabel(source: string): string {
    return source === 'scholexplorer' ? 'ScholExplorer' : 'DataCite Event Data';
}

function similarityColor(score: number): string {
    if (score >= 0.8) return 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200';
    if (score >= 0.5) return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200';
    return 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300';
}

function SuggestionCard({
    suggestion,
    onAccept,
    onDecline,
    isProcessing,
}: {
    suggestion: SuggestedRelationItem;
    onAccept: (id: number) => void;
    onDecline: (id: number) => void;
    isProcessing: boolean;
}) {
    return (
        <div className="rounded-lg border bg-card p-4 shadow-sm transition-all hover:shadow-md">
            <div className="flex items-start justify-between gap-4">
                <div className="min-w-0 flex-1 space-y-2">
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant="outline" className="font-mono text-xs">
                            {suggestion.relation_type_name}
                        </Badge>
                        <Badge variant="secondary" className="text-xs">
                            {suggestion.identifier_type}
                        </Badge>
                        <span className="font-mono text-sm break-all">{suggestion.identifier}</span>
                    </div>

                    {suggestion.source_title && (
                        <p className="text-sm font-medium text-foreground">&quot;{suggestion.source_title}&quot;</p>
                    )}

                    <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                        {suggestion.source_publisher && <span>Publisher: {suggestion.source_publisher}</span>}
                        {suggestion.source_type && <span>Type: {suggestion.source_type}</span>}
                        <span>Source: {sourceLabel(suggestion.source)}</span>
                        <span>Discovered: {new Date(suggestion.discovered_at).toLocaleDateString()}</span>
                    </div>
                </div>

                <div className="flex shrink-0 gap-2">
                    <Button variant="outline" size="sm" disabled={isProcessing} onClick={() => onDecline(suggestion.id)}>
                        <X className="mr-1 h-4 w-4" />
                        Decline
                    </Button>
                    <Button size="sm" disabled={isProcessing} onClick={() => onAccept(suggestion.id)}>
                        <Check className="mr-1 h-4 w-4" />
                        Accept
                    </Button>
                </div>
            </div>
        </div>
    );
}

function OrcidSuggestionCard({
    suggestion,
    onAccept,
    onDecline,
    isProcessing,
}: {
    suggestion: SuggestedOrcidItem;
    onAccept: (id: number) => void;
    onDecline: (id: number) => void;
    isProcessing: boolean;
}) {
    const percent = Math.round(suggestion.similarity_score * 100);
    const candidateName = [suggestion.candidate_first_name, suggestion.candidate_last_name].filter(Boolean).join(' ');

    return (
        <div className="rounded-lg border bg-card p-4 shadow-sm transition-all hover:shadow-md">
            <div className="flex items-start justify-between gap-4">
                <div className="min-w-0 flex-1 space-y-2">
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant="outline" className="text-xs">
                            <User className="mr-1 h-3 w-3" />
                            {suggestion.person_name}
                        </Badge>
                        <Badge variant="secondary" className="text-xs capitalize">
                            {suggestion.source_context}
                        </Badge>
                        <Badge className={`text-xs ${similarityColor(suggestion.similarity_score)}`}>
                            {percent}% match
                        </Badge>
                    </div>

                    <div className="space-y-1">
                        <p className="font-mono text-sm">
                            ORCID: {suggestion.suggested_orcid}
                        </p>
                        {candidateName && (
                            <p className="text-sm text-muted-foreground">
                                Candidate: {candidateName}
                            </p>
                        )}
                        {suggestion.candidate_affiliations.length > 0 && (
                            <p className="text-xs text-muted-foreground">
                                Affiliations: {suggestion.candidate_affiliations.join(', ')}
                            </p>
                        )}
                    </div>

                    <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                        <span>Discovered: {new Date(suggestion.discovered_at).toLocaleDateString()}</span>
                    </div>
                </div>

                <div className="flex shrink-0 gap-2">
                    <Button variant="outline" size="sm" disabled={isProcessing} onClick={() => onDecline(suggestion.id)}>
                        <X className="mr-1 h-4 w-4" />
                        Decline
                    </Button>
                    <Button size="sm" disabled={isProcessing} onClick={() => onAccept(suggestion.id)}>
                        <Check className="mr-1 h-4 w-4" />
                        Accept
                    </Button>
                </div>
            </div>
        </div>
    );
}

function entityTypeLabel(type: SuggestedRorItem['entity_type']): string {
    switch (type) {
        case 'affiliation':
            return 'Affiliation';
        case 'institution':
            return 'Institution';
        case 'funder':
            return 'Funder';
        default: {
            const _exhaustive: never = type;
            return _exhaustive;
        }
    }
}

function entityTypeBadgeColor(type: SuggestedRorItem['entity_type']): string {
    switch (type) {
        case 'affiliation':
            return 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200';
        case 'institution':
            return 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200';
        case 'funder':
            return 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200';
        default: {
            const _exhaustive: never = type;
            return _exhaustive;
        }
    }
}

function RorSuggestionCard({
    suggestion,
    onAccept,
    onDecline,
    isProcessing,
}: {
    suggestion: SuggestedRorItem;
    onAccept: (id: number) => void;
    onDecline: (id: number) => void;
    isProcessing: boolean;
}) {
    const percent = Math.round(suggestion.similarity_score * 100);

    return (
        <div className="rounded-lg border bg-card p-4 shadow-sm transition-all hover:shadow-md">
            <div className="flex items-start justify-between gap-4">
                <div className="min-w-0 flex-1 space-y-2">
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge className={`text-xs ${entityTypeBadgeColor(suggestion.entity_type)}`}>
                            <Building2 className="mr-1 h-3 w-3" />
                            {entityTypeLabel(suggestion.entity_type)}
                        </Badge>
                        <Badge className={`text-xs ${similarityColor(suggestion.similarity_score)}`}>
                            {percent}% match
                        </Badge>
                    </div>

                    <div className="space-y-1">
                        <p className="text-sm font-medium text-foreground">{suggestion.entity_name}</p>
                        <p className="text-sm text-muted-foreground">
                            &rarr; {suggestion.suggested_name}
                        </p>
                        <p className="font-mono text-xs text-muted-foreground">
                            ROR: {suggestion.suggested_ror_id}
                        </p>
                        {suggestion.ror_aliases.length > 0 && (
                            <p className="text-xs text-muted-foreground">
                                Also known as: {suggestion.ror_aliases.slice(0, 3).join(', ')}
                            </p>
                        )}
                    </div>

                    {suggestion.existing_identifier && (
                        <div className="flex items-center gap-2 rounded-md border border-amber-200 bg-amber-50 p-2 text-xs text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
                            <AlertTriangle className="h-3.5 w-3.5 shrink-0" />
                            <span>
                                Accepting will replace the existing {suggestion.existing_identifier_type ?? 'identifier'}:{' '}
                                <span className="font-mono">{suggestion.existing_identifier}</span>
                            </span>
                        </div>
                    )}

                    <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                        <span>Discovered: {new Date(suggestion.discovered_at).toLocaleDateString()}</span>
                    </div>
                </div>

                <div className="flex shrink-0 gap-2">
                    <Button variant="outline" size="sm" disabled={isProcessing} onClick={() => onDecline(suggestion.id)}>
                        <X className="mr-1 h-4 w-4" />
                        Decline
                    </Button>
                    <Button size="sm" disabled={isProcessing} onClick={() => onAccept(suggestion.id)}>
                        <Check className="mr-1 h-4 w-4" />
                        Accept
                    </Button>
                </div>
            </div>
        </div>
    );
}

// ── Per-section state ────────────────────────────────────────────────

interface SectionState {
    isChecking: boolean;
    progress: string;
    processingIds: Set<number>;
}

function useSectionState(manifests: AssistantManifest[]) {
    const [states, setStates] = useState<Record<string, SectionState>>(() => {
        const initial: Record<string, SectionState> = {};
        for (const m of manifests) {
            initial[m.id] = { isChecking: false, progress: '', processingIds: new Set() };
        }
        return initial;
    });

    const pollingRefs = useRef<Record<string, ReturnType<typeof setTimeout> | null>>({});

    const patch = useCallback((id: string, update: Partial<SectionState>) => {
        setStates((prev) => ({ ...prev, [id]: { ...prev[id], ...update } }));
    }, []);

    const addProcessingId = useCallback((sectionId: string, suggestionId: number) => {
        setStates((prev) => {
            const next = new Set(prev[sectionId].processingIds);
            next.add(suggestionId);
            return { ...prev, [sectionId]: { ...prev[sectionId], processingIds: next } };
        });
    }, []);

    const removeProcessingId = useCallback((sectionId: string, suggestionId: number) => {
        setStates((prev) => {
            const next = new Set(prev[sectionId].processingIds);
            next.delete(suggestionId);
            return { ...prev, [sectionId]: { ...prev[sectionId], processingIds: next } };
        });
    }, []);

    // Cleanup all polling on unmount
    useEffect(() => {
        return () => {
            for (const timer of Object.values(pollingRefs.current)) {
                if (timer !== null) clearTimeout(timer);
            }
        };
    }, []);

    return { states, patch, addProcessingId, removeProcessingId, pollingRefs };
}

// ── Main page component ──────────────────────────────────────────────

export default function AssistancePage({ sections, manifests }: AssistancePageProps) {
    const { states, patch, addProcessingId, removeProcessingId, pollingRefs } = useSectionState(manifests);

    const isAnyChecking = Object.values(states).some((s) => s.isChecking);

    // ── Polling logic ────────────────────────────────────────────────

    const stopPolling = useCallback((id: string) => {
        const timer = pollingRefs.current[id];
        if (timer !== null) {
            clearTimeout(timer);
            pollingRefs.current[id] = null;
        }
    }, [pollingRefs]);

    const startPolling = useCallback(
        (manifest: AssistantManifest, jobId: string) => {
            const id = manifest.id;

            const pollStatus = async () => {
                try {
                    const { data: status } = await axios.get<CheckStatusResponse>(
                        `/assistance/check/${id}/${jobId}/status`,
                    );
                    patch(id, { progress: status.progress ?? '' });

                    if (status.status === 'completed') {
                        pollingRefs.current[id] = null;
                        patch(id, { isChecking: false, progress: '' });
                        toast.success(manifest.statusLabels.completed_with_results ?? `${manifest.name} completed.`);
                        router.reload({ only: ['sections', 'pendingAssistanceTotalCount'] });
                    } else if (status.status === 'failed') {
                        pollingRefs.current[id] = null;
                        patch(id, { isChecking: false, progress: '' });
                        toast.error(`${manifest.name} failed: ${status.error ?? 'Unknown error'}`);
                    } else {
                        pollingRefs.current[id] = setTimeout(pollStatus, 3000);
                    }
                } catch {
                    pollingRefs.current[id] = null;
                    patch(id, { isChecking: false, progress: '' });
                    toast.error(`Failed to check ${manifest.name} status.`);
                }
            };

            pollingRefs.current[id] = setTimeout(pollStatus, 3000);
        },
        [patch, pollingRefs],
    );

    // ── Check one assistant ──────────────────────────────────────────

    const handleCheck = useCallback(
        async (manifest: AssistantManifest) => {
            const id = manifest.id;
            patch(id, { isChecking: true, progress: manifest.statusLabels.checking ?? 'Starting...' });
            stopPolling(id);

            try {
                const { data } = await axios.post<{ jobId: string }>(`/assistance/check/${id}`);
                startPolling(manifest, data.jobId);
            } catch (error) {
                patch(id, { isChecking: false, progress: '' });
                if (axios.isAxiosError(error) && error.response?.status === 409) {
                    toast.warning(
                        error.response.data?.error ?? manifest.statusLabels.already_running ?? 'Already running.',
                    );
                } else {
                    toast.error(`Failed to start ${manifest.name}.`);
                }
            }
        },
        [patch, stopPolling, startPolling],
    );

    // ── Check all ────────────────────────────────────────────────────

    const handleCheckAll = useCallback(async () => {
        for (const m of manifests) {
            patch(m.id, { isChecking: true, progress: m.statusLabels.checking ?? 'Starting...' });
            stopPolling(m.id);
        }

        try {
            const { data } = await axios.post<Record<string, string>>('/assistance/check-all');

            for (const m of manifests) {
                const jobIdKey = `${m.id}JobId`;
                const errorKey = `${m.id}Error`;

                if (data[jobIdKey]) {
                    startPolling(m, data[jobIdKey]);
                } else {
                    patch(m.id, { isChecking: false, progress: '' });
                    if (data[errorKey]) {
                        toast.warning(data[errorKey]);
                    }
                }
            }
        } catch (error) {
            for (const m of manifests) {
                patch(m.id, { isChecking: false, progress: '' });
            }
            if (axios.isAxiosError(error) && error.response?.status === 409) {
                toast.warning(error.response.data?.error ?? 'All discovery jobs are already running.');
            } else {
                toast.error('Failed to start discovery.');
            }
        }
    }, [manifests, patch, stopPolling, startPolling]);

    // ── Accept / Decline ─────────────────────────────────────────────

    const handleAccept = useCallback(
        async (manifest: AssistantManifest, suggestionId: number) => {
            addProcessingId(manifest.id, suggestionId);

            try {
                const { data } = await axios.post<AcceptResponse>(
                    `/assistance/${manifest.routePrefix}/${suggestionId}/accept`,
                );

                if (data.success) {
                    toast.success(data.message);
                } else {
                    toast.warning(data.message);
                }
                router.reload({ only: ['sections', 'pendingAssistanceTotalCount'] });
            } catch {
                toast.error('Failed to accept suggestion.');
            } finally {
                removeProcessingId(manifest.id, suggestionId);
            }
        },
        [addProcessingId, removeProcessingId],
    );

    const handleDecline = useCallback(
        async (manifest: AssistantManifest, suggestionId: number) => {
            addProcessingId(manifest.id, suggestionId);

            try {
                await axios.post(`/assistance/${manifest.routePrefix}/${suggestionId}/decline`);
                toast.info('Suggestion declined.');
                router.reload({ only: ['sections', 'pendingAssistanceTotalCount'] });
            } catch {
                toast.error('Failed to decline suggestion.');
            } finally {
                removeProcessingId(manifest.id, suggestionId);
            }
        },
        [addProcessingId, removeProcessingId],
    );

    // ── Render helpers ───────────────────────────────────────────────

    function renderCard(manifest: AssistantManifest, item: Record<string, unknown>, isProcessing: boolean) {
        const onAccept = (id: number) => handleAccept(manifest, id);
        const onDecline = (id: number) => handleDecline(manifest, id);

        switch (manifest.id) {
            case 'relation-suggestion':
                return (
                    <SuggestionCard
                        suggestion={item as unknown as SuggestedRelationItem}
                        onAccept={onAccept}
                        onDecline={onDecline}
                        isProcessing={isProcessing}
                    />
                );
            case 'orcid-suggestion':
                return (
                    <OrcidSuggestionCard
                        suggestion={item as unknown as SuggestedOrcidItem}
                        onAccept={onAccept}
                        onDecline={onDecline}
                        isProcessing={isProcessing}
                    />
                );
            case 'ror-suggestion':
                return (
                    <RorSuggestionCard
                        suggestion={item as unknown as SuggestedRorItem}
                        onAccept={onAccept}
                        onDecline={onDecline}
                        isProcessing={isProcessing}
                    />
                );
            default:
                // Generic card for future student modules
                return (
                    <div className="rounded-lg border bg-card p-4 shadow-sm">
                        <div className="flex items-start justify-between gap-4">
                            <div className="min-w-0 flex-1 space-y-1">
                                <p className="text-sm font-medium">{String(item.suggested_label ?? item.suggested_value ?? 'Suggestion')}</p>
                                <p className="text-xs text-muted-foreground">
                                    Discovered: {item.discovered_at ? new Date(String(item.discovered_at)).toLocaleDateString() : '—'}
                                </p>
                            </div>
                            <div className="flex shrink-0 gap-2">
                                <Button variant="outline" size="sm" disabled={isProcessing} onClick={() => onDecline(item.id as number)}>
                                    <X className="mr-1 h-4 w-4" />
                                    Decline
                                </Button>
                                <Button size="sm" disabled={isProcessing} onClick={() => onAccept(item.id as number)}>
                                    <Check className="mr-1 h-4 w-4" />
                                    Accept
                                </Button>
                            </div>
                        </div>
                    </div>
                );
        }
    }

    // ── Render ────────────────────────────────────────────────────────

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Assistance" />

            <div className="mx-auto w-full max-w-5xl space-y-6 p-6">
                {/* Page header with Check All button */}
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold tracking-tight">Assistance</h1>

                    <Button onClick={handleCheckAll} disabled={isAnyChecking}>
                        {isAnyChecking ? (
                            <>
                                <Spinner size="sm" className="mr-2" />
                                Checking...
                            </>
                        ) : (
                            <>
                                <RefreshCw className="mr-2 h-4 w-4" />
                                Check all
                            </>
                        )}
                    </Button>
                </div>

                {/* Progress indicators */}
                {manifests.map((manifest) => {
                    const state = states[manifest.id];
                    if (!state?.isChecking || !state.progress) return null;
                    return (
                        <div key={`progress-${manifest.id}`} className="flex items-center gap-2 rounded-lg border bg-muted/50 p-3 text-sm text-muted-foreground">
                            <Spinner size="sm" />
                            <span>{state.progress}</span>
                        </div>
                    );
                })}

                {/* Section cards — one per assistant, ordered by sortOrder */}
                {manifests.map((manifest) => {
                    const sectionData = sections[manifest.id] as PaginatedData<Record<string, unknown>> | undefined;
                    const state = states[manifest.id];

                    if (!sectionData) return null;

                    // Group items by resource
                    const grouped = sectionData.data.reduce<Record<number, { doi: string; title: string; items: Record<string, unknown>[] }>>(
                        (groups, item) => {
                            const resourceId = item.resource_id as number;
                            if (!groups[resourceId]) {
                                groups[resourceId] = {
                                    doi: String(item.resource_doi ?? ''),
                                    title: String(item.resource_title ?? 'Untitled'),
                                    items: [],
                                };
                            }
                            groups[resourceId].items.push(item);
                            return groups;
                        },
                        {},
                    );

                    return (
                        <Card key={manifest.id}>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0">
                                <div className="space-y-1.5">
                                    <CardTitle>{manifest.name}</CardTitle>
                                    <CardDescription>
                                        {sectionData.total > 0
                                            ? `${sectionData.total} pending suggestion(s). ${manifest.description}`
                                            : manifest.emptyState.description}
                                    </CardDescription>
                                </div>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => handleCheck(manifest)}
                                    disabled={state?.isChecking ?? false}
                                >
                                    {state?.isChecking ? (
                                        <>
                                            <Spinner size="sm" className="mr-2" />
                                            Checking...
                                        </>
                                    ) : (
                                        <>
                                            <RefreshCw className="mr-2 h-4 w-4" />
                                            Check
                                        </>
                                    )}
                                </Button>
                            </CardHeader>
                            <CardContent>
                                {Object.keys(grouped).length > 0 ? (
                                    <div className="space-y-6">
                                        {Object.entries(grouped).map(([resourceId, group]) => (
                                            <div key={resourceId} className="space-y-3">
                                                <div className="flex items-baseline gap-2">
                                                    <span className="font-mono text-sm font-semibold text-primary">{group.doi}</span>
                                                    <span className="text-sm text-muted-foreground">— {group.title}</span>
                                                    <Badge variant="secondary" className="ml-auto text-xs">
                                                        {group.items.length} suggestion(s)
                                                    </Badge>
                                                </div>
                                                <div className="space-y-2 pl-4">
                                                    {group.items.map((item) => (
                                                        <div key={item.id as number}>
                                                            {renderCard(manifest, item, state?.processingIds.has(item.id as number) ?? false)}
                                                        </div>
                                                    ))}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <div className="flex flex-col items-center justify-center py-12 text-center">
                                        <div className="text-4xl">&#10003;</div>
                                        <p className="mt-2 text-lg font-medium">{manifest.emptyState.title}</p>
                                        <p className="text-sm text-muted-foreground">{manifest.emptyState.description}</p>
                                    </div>
                                )}

                                {sectionData.last_page > 1 && (
                                    <div className="mt-6 flex items-center justify-between border-t pt-4">
                                        <p className="text-sm text-muted-foreground">
                                            Showing {sectionData.from ?? 0}–{sectionData.to ?? 0} of {sectionData.total}
                                        </p>
                                        <div className="flex gap-1">
                                            {sectionData.links.map((link, index) => (
                                                <Button
                                                    key={link.url ?? `${manifest.id}-${link.label}-${index}`}
                                                    variant={link.active ? 'default' : 'outline'}
                                                    size="sm"
                                                    disabled={!link.url}
                                                    onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                                />
                                            ))}
                                        </div>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    );
                })}
            </div>
        </AppLayout>
    );
}
