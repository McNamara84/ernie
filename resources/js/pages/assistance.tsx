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
    type CheckStatusResponse,
    type OrcidAcceptResponse,
    type OrcidCheckStatusResponse,
    type PaginatedData,
    type RorAcceptResponse,
    type RorCheckStatusResponse,
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

function entityTypeLabel(type: string): string {
    return type === 'affiliation' ? 'Affiliation' : type === 'institution' ? 'Institution' : 'Funder';
}

function entityTypeBadgeColor(type: string): string {
    if (type === 'affiliation') return 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200';
    if (type === 'institution') return 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200';
    return 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200';
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

export default function AssistancePage({ suggestions: paginatedSuggestions, orcidSuggestions: paginatedOrcidSuggestions, rorSuggestions: paginatedRorSuggestions }: AssistancePageProps) {
    // Relation suggestions state
    const [suggestions, setSuggestions] = useState<SuggestedRelationItem[]>(paginatedSuggestions.data);
    const [pagination, setPagination] = useState<Omit<PaginatedData<SuggestedRelationItem>, 'data'>>(paginatedSuggestions);
    const [isCheckingRelations, setIsCheckingRelations] = useState(false);
    const [relationCheckProgress, setRelationCheckProgress] = useState<string>('');
    const [processingIds, setProcessingIds] = useState<Set<number>>(new Set());
    const relationPollingRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    // ORCID suggestions state
    const [orcidSuggestions, setOrcidSuggestions] = useState<SuggestedOrcidItem[]>(paginatedOrcidSuggestions.data);
    const [orcidPagination, setOrcidPagination] = useState<Omit<PaginatedData<SuggestedOrcidItem>, 'data'>>(paginatedOrcidSuggestions);
    const [isCheckingOrcids, setIsCheckingOrcids] = useState(false);
    const [orcidCheckProgress, setOrcidCheckProgress] = useState<string>('');
    const [orcidProcessingIds, setOrcidProcessingIds] = useState<Set<number>>(new Set());
    const orcidPollingRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    // ROR suggestions state
    const [rorSuggestions, setRorSuggestions] = useState<SuggestedRorItem[]>(paginatedRorSuggestions.data);
    const [rorPagination, setRorPagination] = useState<Omit<PaginatedData<SuggestedRorItem>, 'data'>>(paginatedRorSuggestions);
    const [isCheckingRors, setIsCheckingRors] = useState(false);
    const [rorCheckProgress, setRorCheckProgress] = useState<string>('');
    const [rorProcessingIds, setRorProcessingIds] = useState<Set<number>>(new Set());
    const rorPollingRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    // Sync state when Inertia updates props
    useEffect(() => {
        setSuggestions(paginatedSuggestions.data);
        setPagination(paginatedSuggestions);
    }, [paginatedSuggestions]);

    useEffect(() => {
        setOrcidSuggestions(paginatedOrcidSuggestions.data);
        setOrcidPagination(paginatedOrcidSuggestions);
    }, [paginatedOrcidSuggestions]);

    useEffect(() => {
        setRorSuggestions(paginatedRorSuggestions.data);
        setRorPagination(paginatedRorSuggestions);
    }, [paginatedRorSuggestions]);

    const stopRelationPolling = useCallback(() => {
        if (relationPollingRef.current !== null) {
            clearTimeout(relationPollingRef.current);
            relationPollingRef.current = null;
        }
    }, []);

    const stopOrcidPolling = useCallback(() => {
        if (orcidPollingRef.current !== null) {
            clearTimeout(orcidPollingRef.current);
            orcidPollingRef.current = null;
        }
    }, []);

    const stopRorPolling = useCallback(() => {
        if (rorPollingRef.current !== null) {
            clearTimeout(rorPollingRef.current);
            rorPollingRef.current = null;
        }
    }, []);

    // Cleanup polling on unmount
    useEffect(() => {
        return () => {
            stopRelationPolling();
            stopOrcidPolling();
            stopRorPolling();
        };
    }, [stopRelationPolling, stopOrcidPolling, stopRorPolling]);

    const isChecking = isCheckingRelations || isCheckingOrcids || isCheckingRors;

    // --- Relation discovery ---
    const startRelationPolling = useCallback((jobId: string) => {
        const pollStatus = async () => {
            try {
                const { data: status } = await axios.get<CheckStatusResponse>(`/assistance/check/${jobId}/status`);
                setRelationCheckProgress(status.progress ?? '');

                if (status.status === 'completed') {
                    relationPollingRef.current = null;
                    setIsCheckingRelations(false);
                    const found = status.newRelationsFound ?? 0;
                    if (found > 0) {
                        toast.success(`Relation discovery completed: ${found} new relation(s) found.`);
                    } else {
                        toast.info('Relation discovery completed: No new relations found.');
                    }
                    router.reload({ only: ['suggestions', 'pendingSuggestedRelationsCount'] });
                } else if (status.status === 'failed') {
                    relationPollingRef.current = null;
                    setIsCheckingRelations(false);
                    toast.error(`Relation discovery failed: ${status.error ?? 'Unknown error'}`);
                } else {
                    relationPollingRef.current = setTimeout(pollStatus, 3000);
                }
            } catch {
                relationPollingRef.current = null;
                setIsCheckingRelations(false);
                toast.error('Failed to check relation discovery status.');
            }
        };

        relationPollingRef.current = setTimeout(pollStatus, 3000);
    }, []);

    const handleCheckRelations = useCallback(async () => {
        setIsCheckingRelations(true);
        setRelationCheckProgress('Starting relation discovery...');
        stopRelationPolling();

        try {
            const { data } = await axios.post<{ jobId: string }>('/assistance/check');
            startRelationPolling(data.jobId);
        } catch (error) {
            setIsCheckingRelations(false);
            if (axios.isAxiosError(error) && error.response?.status === 409) {
                toast.warning(error.response.data?.error ?? 'A relation discovery job is already running.');
            } else {
                toast.error('Failed to start relation discovery.');
            }
        }
    }, [stopRelationPolling, startRelationPolling]);

    // --- ORCID discovery ---
    const startOrcidPolling = useCallback((jobId: string) => {
        const pollStatus = async () => {
            try {
                const { data: status } = await axios.get<OrcidCheckStatusResponse>(`/assistance/check-orcids/${jobId}/status`);
                setOrcidCheckProgress(status.progress ?? '');

                if (status.status === 'completed') {
                    orcidPollingRef.current = null;
                    setIsCheckingOrcids(false);
                    const found = status.newOrcidsFound ?? 0;
                    if (found > 0) {
                        toast.success(`ORCID discovery completed: ${found} new suggestion(s) found.`);
                    } else {
                        toast.info('ORCID discovery completed: No new suggestions found.');
                    }
                    router.reload({ only: ['orcidSuggestions', 'pendingSuggestedOrcidsCount'] });
                } else if (status.status === 'failed') {
                    orcidPollingRef.current = null;
                    setIsCheckingOrcids(false);
                    toast.error(`ORCID discovery failed: ${status.error ?? 'Unknown error'}`);
                } else {
                    orcidPollingRef.current = setTimeout(pollStatus, 3000);
                }
            } catch {
                orcidPollingRef.current = null;
                setIsCheckingOrcids(false);
                toast.error('Failed to check ORCID discovery status.');
            }
        };

        orcidPollingRef.current = setTimeout(pollStatus, 3000);
    }, []);

    const handleCheckOrcids = useCallback(async () => {
        setIsCheckingOrcids(true);
        setOrcidCheckProgress('Starting ORCID discovery...');
        stopOrcidPolling();

        try {
            const { data } = await axios.post<{ jobId: string }>('/assistance/check-orcids');
            startOrcidPolling(data.jobId);
        } catch (error) {
            setIsCheckingOrcids(false);
            if (axios.isAxiosError(error) && error.response?.status === 409) {
                toast.warning(error.response.data?.error ?? 'An ORCID discovery job is already running.');
            } else {
                toast.error('Failed to start ORCID discovery.');
            }
        }
    }, [stopOrcidPolling, startOrcidPolling]);

    // --- ROR discovery ---
    const startRorPolling = useCallback((jobId: string) => {
        const pollStatus = async () => {
            try {
                const { data: status } = await axios.get<RorCheckStatusResponse>(`/assistance/check-rors/${jobId}/status`);
                setRorCheckProgress(status.progress ?? '');

                if (status.status === 'completed') {
                    rorPollingRef.current = null;
                    setIsCheckingRors(false);
                    const found = status.newRorsFound ?? 0;
                    if (found > 0) {
                        toast.success(`ROR discovery completed: ${found} new suggestion(s) found.`);
                    } else {
                        toast.info('ROR discovery completed: No new suggestions found.');
                    }
                    router.reload({ only: ['rorSuggestions', 'pendingSuggestedRorsCount'] });
                } else if (status.status === 'failed') {
                    rorPollingRef.current = null;
                    setIsCheckingRors(false);
                    toast.error(`ROR discovery failed: ${status.error ?? 'Unknown error'}`);
                } else {
                    rorPollingRef.current = setTimeout(pollStatus, 3000);
                }
            } catch {
                rorPollingRef.current = null;
                setIsCheckingRors(false);
                toast.error('Failed to check ROR discovery status.');
            }
        };

        rorPollingRef.current = setTimeout(pollStatus, 3000);
    }, []);

    const handleCheckRors = useCallback(async () => {
        setIsCheckingRors(true);
        setRorCheckProgress('Starting ROR discovery...');
        stopRorPolling();

        try {
            const { data } = await axios.post<{ jobId: string }>('/assistance/check-rors');
            startRorPolling(data.jobId);
        } catch (error) {
            setIsCheckingRors(false);
            if (axios.isAxiosError(error) && error.response?.status === 409) {
                toast.warning(error.response.data?.error ?? 'A ROR discovery job is already running.');
            } else {
                toast.error('Failed to start ROR discovery.');
            }
        }
    }, [stopRorPolling, startRorPolling]);

    // --- Check All ---
    const handleCheckAll = useCallback(async () => {
        setIsCheckingRelations(true);
        setIsCheckingOrcids(true);
        setIsCheckingRors(true);
        setRelationCheckProgress('Starting relation discovery...');
        setOrcidCheckProgress('Starting ORCID discovery...');
        setRorCheckProgress('Starting ROR discovery...');
        stopRelationPolling();
        stopOrcidPolling();
        stopRorPolling();

        try {
            const { data } = await axios.post<{ relationJobId?: string; orcidJobId?: string; rorJobId?: string; relationError?: string; orcidError?: string; rorError?: string }>('/assistance/check-all');

            if (data.relationJobId) {
                startRelationPolling(data.relationJobId);
            } else {
                setIsCheckingRelations(false);
                setRelationCheckProgress('');
                toast.warning(data.relationError ?? 'Relation discovery is already running.');
            }

            if (data.orcidJobId) {
                startOrcidPolling(data.orcidJobId);
            } else {
                setIsCheckingOrcids(false);
                setOrcidCheckProgress('');
                toast.warning(data.orcidError ?? 'ORCID discovery is already running.');
            }

            if (data.rorJobId) {
                startRorPolling(data.rorJobId);
            } else {
                setIsCheckingRors(false);
                setRorCheckProgress('');
                toast.warning(data.rorError ?? 'ROR discovery is already running.');
            }
        } catch (error) {
            setIsCheckingRelations(false);
            setIsCheckingOrcids(false);
            setIsCheckingRors(false);
            if (axios.isAxiosError(error) && error.response?.status === 409) {
                toast.warning(error.response.data?.error ?? 'Discovery jobs are already running.');
            } else {
                toast.error('Failed to start discovery.');
            }
        }
    }, [stopRelationPolling, stopOrcidPolling, stopRorPolling, startRelationPolling, startOrcidPolling, startRorPolling]);

    // --- Relation accept/decline ---
    const handleAcceptRelation = useCallback(async (id: number) => {
        setProcessingIds((prev) => new Set(prev).add(id));

        try {
            const { data } = await axios.post<AcceptResponse>(`/assistance/relations/${id}/accept`);
            setSuggestions((prev) => prev.filter((s) => s.id !== id));

            if (data.datacite_synced) {
                toast.success(data.message);
            } else {
                toast.warning(data.message);
            }

            router.reload({ only: ['suggestions', 'pendingSuggestedRelationsCount'] });
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

    const handleDeclineRelation = useCallback(async (id: number) => {
        setProcessingIds((prev) => new Set(prev).add(id));

        try {
            await axios.post(`/assistance/relations/${id}/decline`);
            setSuggestions((prev) => prev.filter((s) => s.id !== id));
            toast.info('Relation declined.');
            router.reload({ only: ['suggestions', 'pendingSuggestedRelationsCount'] });
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

    // --- ORCID accept/decline ---
    const handleAcceptOrcid = useCallback(async (id: number) => {
        setOrcidProcessingIds((prev) => new Set(prev).add(id));

        try {
            const { data } = await axios.post<OrcidAcceptResponse>(`/assistance/orcids/${id}/accept`);
            setOrcidSuggestions((prev) => prev.filter((s) => s.id !== id));

            if (data.success) {
                toast.success(data.message);
            } else {
                toast.warning(data.message);
            }
            router.reload({ only: ['orcidSuggestions', 'pendingSuggestedOrcidsCount'] });
        } catch {
            toast.error('Failed to accept ORCID.');
        } finally {
            setOrcidProcessingIds((prev) => {
                const next = new Set(prev);
                next.delete(id);
                return next;
            });
        }
    }, []);

    const handleDeclineOrcid = useCallback(async (id: number) => {
        setOrcidProcessingIds((prev) => new Set(prev).add(id));

        try {
            await axios.post(`/assistance/orcids/${id}/decline`);
            setOrcidSuggestions((prev) => prev.filter((s) => s.id !== id));
            toast.info('ORCID suggestion declined.');
            router.reload({ only: ['orcidSuggestions', 'pendingSuggestedOrcidsCount'] });
        } catch {
            toast.error('Failed to decline ORCID.');
        } finally {
            setOrcidProcessingIds((prev) => {
                const next = new Set(prev);
                next.delete(id);
                return next;
            });
        }
    }, []);

    // --- ROR accept/decline ---
    const handleAcceptRor = useCallback(async (id: number) => {
        setRorProcessingIds((prev) => new Set(prev).add(id));

        try {
            const { data } = await axios.post<RorAcceptResponse>(`/assistance/rors/${id}/accept`);
            setRorSuggestions((prev) => prev.filter((s) => s.id !== id));

            if (data.success) {
                toast.success(data.message);
            } else {
                toast.warning(data.message);
            }
            router.reload({ only: ['rorSuggestions', 'pendingSuggestedRorsCount'] });
        } catch {
            toast.error('Failed to accept ROR suggestion.');
        } finally {
            setRorProcessingIds((prev) => {
                const next = new Set(prev);
                next.delete(id);
                return next;
            });
        }
    }, []);

    const handleDeclineRor = useCallback(async (id: number) => {
        setRorProcessingIds((prev) => new Set(prev).add(id));

        try {
            await axios.post(`/assistance/rors/${id}/decline`);
            setRorSuggestions((prev) => prev.filter((s) => s.id !== id));
            toast.info('ROR suggestion declined.');
            router.reload({ only: ['rorSuggestions', 'pendingSuggestedRorsCount'] });
        } catch {
            toast.error('Failed to decline ROR suggestion.');
        } finally {
            setRorProcessingIds((prev) => {
                const next = new Set(prev);
                next.delete(id);
                return next;
            });
        }
    }, []);

    // Group relation suggestions by resource
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

    // Group ORCID suggestions by resource
    const groupedOrcidSuggestions = orcidSuggestions.reduce<Record<number, { doi: string; title: string; items: SuggestedOrcidItem[] }>>(
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

    // Group ROR suggestions by resource
    const groupedRorSuggestions = rorSuggestions.reduce<Record<number, { doi: string; title: string; items: SuggestedRorItem[] }>>(
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
                {/* Page header with Check All button */}
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold tracking-tight">Assistance</h1>

                    <Button onClick={handleCheckAll} disabled={isChecking}>
                        {isChecking ? (
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
                {isCheckingRelations && relationCheckProgress && (
                    <div className="flex items-center gap-2 rounded-lg border bg-muted/50 p-3 text-sm text-muted-foreground">
                        <Spinner size="sm" />
                        <span>{relationCheckProgress}</span>
                    </div>
                )}
                {isCheckingOrcids && orcidCheckProgress && (
                    <div className="flex items-center gap-2 rounded-lg border bg-muted/50 p-3 text-sm text-muted-foreground">
                        <Spinner size="sm" />
                        <span>{orcidCheckProgress}</span>
                    </div>
                )}
                {isCheckingRors && rorCheckProgress && (
                    <div className="flex items-center gap-2 rounded-lg border bg-muted/50 p-3 text-sm text-muted-foreground">
                        <Spinner size="sm" />
                        <span>{rorCheckProgress}</span>
                    </div>
                )}

                {/* Suggested ORCIDs Card */}
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0">
                        <div className="space-y-1.5">
                            <CardTitle>Suggested ORCIDs</CardTitle>
                            <CardDescription>
                                {orcidPagination.total > 0
                                    ? `${orcidPagination.total} pending ORCID suggestion(s) for creators and contributors.`
                                    : 'No pending ORCID suggestions.'}
                            </CardDescription>
                        </div>
                        <Button variant="outline" size="sm" onClick={handleCheckOrcids} disabled={isCheckingOrcids}>
                            {isCheckingOrcids ? (
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
                        {Object.keys(groupedOrcidSuggestions).length > 0 ? (
                            <div className="space-y-6">
                                {Object.entries(groupedOrcidSuggestions).map(([resourceId, group]) => (
                                    <div key={resourceId} className="space-y-3">
                                        <div className="flex items-baseline gap-2">
                                            <span className="font-mono text-sm font-semibold text-primary">{group.doi}</span>
                                            <span className="text-sm text-muted-foreground">— {group.title}</span>
                                            <Badge variant="secondary" className="ml-auto text-xs">
                                                {group.items.length} suggestion(s)
                                            </Badge>
                                        </div>
                                        <div className="space-y-2 pl-4">
                                            {group.items.map((suggestion) => (
                                                <OrcidSuggestionCard
                                                    key={suggestion.id}
                                                    suggestion={suggestion}
                                                    onAccept={handleAcceptOrcid}
                                                    onDecline={handleDeclineOrcid}
                                                    isProcessing={orcidProcessingIds.has(suggestion.id)}
                                                />
                                            ))}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div className="flex flex-col items-center justify-center py-12 text-center">
                                <div className="text-4xl">&#10003;</div>
                                <p className="mt-2 text-lg font-medium">All ORCIDs are up to date!</p>
                                <p className="text-sm text-muted-foreground">
                                    No missing ORCIDs found. Click &quot;Check&quot; to search for new ones.
                                </p>
                            </div>
                        )}

                        {orcidPagination.last_page > 1 && (
                            <div className="mt-6 flex items-center justify-between border-t pt-4">
                                <p className="text-sm text-muted-foreground">
                                    Showing {orcidPagination.from ?? 0}–{orcidPagination.to ?? 0} of {orcidPagination.total}
                                </p>
                                <div className="flex gap-1">
                                    {orcidPagination.links.map((link, index) => (
                                        <Button
                                            key={link.url ?? `orcid-${link.label}-${index}`}
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

                {/* Suggested ROR-IDs Card */}
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0">
                        <div className="space-y-1.5">
                            <CardTitle>Suggested ROR-IDs</CardTitle>
                            <CardDescription>
                                {rorPagination.total > 0
                                    ? `${rorPagination.total} pending ROR suggestion(s) for affiliations, institutions, and funders.`
                                    : 'No pending ROR suggestions.'}
                            </CardDescription>
                        </div>
                        <Button variant="outline" size="sm" onClick={handleCheckRors} disabled={isCheckingRors}>
                            {isCheckingRors ? (
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
                        {Object.keys(groupedRorSuggestions).length > 0 ? (
                            <div className="space-y-6">
                                {Object.entries(groupedRorSuggestions).map(([resourceId, group]) => (
                                    <div key={resourceId} className="space-y-3">
                                        <div className="flex items-baseline gap-2">
                                            <span className="font-mono text-sm font-semibold text-primary">{group.doi}</span>
                                            <span className="text-sm text-muted-foreground">— {group.title}</span>
                                            <Badge variant="secondary" className="ml-auto text-xs">
                                                {group.items.length} suggestion(s)
                                            </Badge>
                                        </div>
                                        <div className="space-y-2 pl-4">
                                            {group.items.map((suggestion) => (
                                                <RorSuggestionCard
                                                    key={suggestion.id}
                                                    suggestion={suggestion}
                                                    onAccept={handleAcceptRor}
                                                    onDecline={handleDeclineRor}
                                                    isProcessing={rorProcessingIds.has(suggestion.id)}
                                                />
                                            ))}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div className="flex flex-col items-center justify-center py-12 text-center">
                                <div className="text-4xl">&#10003;</div>
                                <p className="mt-2 text-lg font-medium">All ROR-IDs are up to date!</p>
                                <p className="text-sm text-muted-foreground">
                                    No missing ROR-IDs found. Click &quot;Check&quot; to search for new ones.
                                </p>
                            </div>
                        )}

                        {rorPagination.last_page > 1 && (
                            <div className="mt-6 flex items-center justify-between border-t pt-4">
                                <p className="text-sm text-muted-foreground">
                                    Showing {rorPagination.from ?? 0}–{rorPagination.to ?? 0} of {rorPagination.total}
                                </p>
                                <div className="flex gap-1">
                                    {rorPagination.links.map((link, index) => (
                                        <Button
                                            key={link.url ?? `ror-${link.label}-${index}`}
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

                {/* Suggested Relations Card */}
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0">
                        <div className="space-y-1.5">
                            <CardTitle>Suggested Relations</CardTitle>
                            <CardDescription>
                                {pagination.total > 0
                                    ? `${pagination.total} pending suggestion(s) from ScholExplorer and DataCite Event Data.`
                                    : 'No pending suggestions. Discover new related works for your registered DOIs.'}
                            </CardDescription>
                        </div>
                        <Button variant="outline" size="sm" onClick={handleCheckRelations} disabled={isCheckingRelations}>
                            {isCheckingRelations ? (
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
                                                    onAccept={handleAcceptRelation}
                                                    onDecline={handleDeclineRelation}
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

                        {pagination.last_page > 1 && (
                            <div className="mt-6 flex items-center justify-between border-t pt-4">
                                <p className="text-sm text-muted-foreground">
                                    Showing {pagination.from ?? 0}–{pagination.to ?? 0} of {pagination.total}
                                </p>
                                <div className="flex gap-1">
                                    {pagination.links.map((link, index) => (
                                        <Button
                                            key={link.url ?? `${link.label}-${index}`}
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
            </div>
        </AppLayout>
    );
}
