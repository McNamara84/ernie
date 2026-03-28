import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { Check, RefreshCw, X } from 'lucide-react';
import { useCallback, useRef, useState } from 'react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type AcceptResponse, type AssistancePageProps, type CheckStatusResponse, type SuggestedRelationItem } from '@/types/assistance';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Assistance',
        href: '/assistance',
    },
];

function sourceLabel(source: string): string {
    return source === 'scholexplorer' ? 'ScholExplorer' : 'DataCite Event Data';
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

export default function AssistancePage({ suggestions: initialSuggestions }: AssistancePageProps) {
    const [suggestions, setSuggestions] = useState<SuggestedRelationItem[]>(initialSuggestions);
    const [isChecking, setIsChecking] = useState(false);
    const [checkProgress, setCheckProgress] = useState<string>('');
    const [processingIds, setProcessingIds] = useState<Set<number>>(new Set());
    const pollingRef = useRef<ReturnType<typeof setInterval> | null>(null);

    const stopPolling = useCallback(() => {
        if (pollingRef.current !== null) {
            clearInterval(pollingRef.current);
            pollingRef.current = null;
        }
    }, []);

    const handleCheck = useCallback(async () => {
        setIsChecking(true);
        setCheckProgress('Starting discovery...');

        try {
            const { data } = await axios.post<{ jobId: string }>('/assistance/check');
            const jobId = data.jobId;

            pollingRef.current = setInterval(async () => {
                try {
                    const { data: status } = await axios.get<CheckStatusResponse>(`/assistance/check/${jobId}/status`);

                    setCheckProgress(status.progress ?? '');

                    if (status.status === 'completed') {
                        stopPolling();
                        setIsChecking(false);
                        const found = status.newRelationsFound ?? 0;
                        if (found > 0) {
                            toast.success(`Discovery completed: ${found} new relation(s) found.`);
                        } else {
                            toast.info('Discovery completed: No new relations found.');
                        }
                        router.reload({ only: ['suggestions'] });
                    } else if (status.status === 'failed') {
                        stopPolling();
                        setIsChecking(false);
                        toast.error(`Discovery failed: ${status.error ?? 'Unknown error'}`);
                    }
                } catch {
                    stopPolling();
                    setIsChecking(false);
                    toast.error('Failed to check discovery status.');
                }
            }, 3000);
        } catch {
            setIsChecking(false);
            toast.error('Failed to start relation discovery.');
        }
    }, [stopPolling]);

    const handleAccept = useCallback(async (id: number) => {
        setProcessingIds((prev) => new Set(prev).add(id));

        try {
            const { data } = await axios.post<AcceptResponse>(`/assistance/relations/${id}/accept`);

            setSuggestions((prev) => prev.filter((s) => s.id !== id));

            if (data.datacite_synced) {
                toast.success(data.message);
            } else {
                toast.warning(data.message);
            }
        } catch {
            toast.error('Failed to accept relation.');
        } finally {
            setProcessingIds((prev) => {
                const next = new Set(prev);
                next.delete(id);
                return next;
            });
        }
    }, []);

    const handleDecline = useCallback(async (id: number) => {
        setProcessingIds((prev) => new Set(prev).add(id));

        try {
            await axios.post(`/assistance/relations/${id}/decline`);

            setSuggestions((prev) => prev.filter((s) => s.id !== id));
            toast.info('Relation declined.');
        } catch {
            toast.error('Failed to decline relation.');
        } finally {
            setProcessingIds((prev) => {
                const next = new Set(prev);
                next.delete(id);
                return next;
            });
        }
    }, []);

    // Group suggestions by resource
    const groupedSuggestions = suggestions.reduce<Record<number, { doi: string; title: string; items: SuggestedRelationItem[] }>>(
        (groups, suggestion) => {
            if (!groups[suggestion.resource_id]) {
                groups[suggestion.resource_id] = {
                    doi: suggestion.resource_doi,
                    title: suggestion.resource_title,
                    items: [],
                };
            }
            groups[suggestion.resource_id].items.push(suggestion);
            return groups;
        },
        {},
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Assistance" />

            <div className="mx-auto w-full max-w-5xl space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Assistance</h1>
                        <p className="text-sm text-muted-foreground">
                            Discover new related works for your registered DOIs.
                        </p>
                    </div>

                    <Button onClick={handleCheck} disabled={isChecking}>
                        {isChecking ? (
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
                </div>

                {isChecking && checkProgress && (
                    <div className="flex items-center gap-2 rounded-lg border bg-muted/50 p-3 text-sm text-muted-foreground">
                        <Spinner size="sm" />
                        <span>{checkProgress}</span>
                    </div>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>Suggested Relations</CardTitle>
                        <CardDescription>
                            {suggestions.length > 0
                                ? `${suggestions.length} pending suggestion(s) from ScholExplorer and DataCite Event Data.`
                                : 'No pending suggestions.'}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {Object.keys(groupedSuggestions).length > 0 ? (
                            <div className="space-y-6">
                                {Object.entries(groupedSuggestions).map(([resourceId, group]) => (
                                    <div key={resourceId} className="space-y-3">
                                        <div className="flex items-baseline gap-2">
                                            <span className="font-mono text-sm font-semibold text-primary">{group.doi}</span>
                                            <span className="text-sm text-muted-foreground">— {group.title}</span>
                                        </div>
                                        <div className="space-y-2 pl-4">
                                            {group.items.map((suggestion) => (
                                                <SuggestionCard
                                                    key={suggestion.id}
                                                    suggestion={suggestion}
                                                    onAccept={handleAccept}
                                                    onDecline={handleDecline}
                                                    isProcessing={processingIds.has(suggestion.id)}
                                                />
                                            ))}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div className="flex flex-col items-center justify-center py-12 text-center">
                                <div className="text-4xl">&#10003;</div>
                                <p className="mt-2 text-lg font-medium">You&apos;re all caught up!</p>
                                <p className="text-sm text-muted-foreground">
                                    No new suggested relations. Click &quot;Check&quot; to search for new ones.
                                </p>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
