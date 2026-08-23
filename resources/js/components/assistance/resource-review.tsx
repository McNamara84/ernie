import { Link, router } from '@inertiajs/react';
import axios from 'axios';
import { Check, ChevronsDown, ChevronsUp, RefreshCw, X } from 'lucide-react';
import { type ReactNode, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';

import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from '@/components/ui/accordion';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { editor as editorRoute } from '@/routes';
import {
    type AssistanceResourceGroup,
    type AssistantManifest,
    type BaseSuggestionItem,
    type BatchSuggestionResponse,
    type PaginatedData,
    type RorAffiliationBulkMatch,
    type SuggestionAcceptanceInput,
    type SuggestionReviewMetadata,
} from '@/types/assistance';

const VIEW_STORAGE_KEY = 'assistance.review-view';
const ASSISTANCE_ACCORDION_PREFERENCE_URL = '/settings/assistance-accordion';
const ASSISTANCE_ACCORDION_PREFERENCE_DELAY_MS = 400;

function initialReviewView(): 'all' | 'assistant' {
    if (typeof window === 'undefined') return 'all';

    try {
        return window.localStorage.getItem(VIEW_STORAGE_KEY) === 'assistant' ? 'assistant' : 'all';
    } catch {
        return 'all';
    }
}

function normalizeCollapsedAssistantIds(ids: readonly string[] | null | undefined, manifests: AssistantManifest[]): string[] {
    const collapsed = new Set(ids ?? []);

    return manifests.map((manifest) => manifest.id).filter((id) => collapsed.has(id));
}

function equalStringArrays(left: readonly string[], right: readonly string[]): boolean {
    return left.length === right.length && left.every((value, index) => value === right[index]);
}

type SectionData = PaginatedData<AssistanceResourceGroup> | PaginatedData<BaseSuggestionItem>;

interface ResourceReviewProps {
    allAssistantResources?: PaginatedData<AssistanceResourceGroup>;
    sections: Record<string, SectionData>;
    manifests: AssistantManifest[];
    assistanceCollapsedAssistantIds?: string[] | null;
    checking: Record<string, boolean>;
    onCheck: (manifest: AssistantManifest) => void;
    onReload: () => void;
    onRorFollowUps: (matches: RorAffiliationBulkMatch[]) => void;
    renderSuggestion: (manifest: AssistantManifest, item: BaseSuggestionItem, processing: boolean) => ReactNode;
    acceptanceInputs?: Record<string, SuggestionAcceptanceInput>;
    hasActiveFilters?: boolean;
}

function fallbackReview(item: BaseSuggestionItem, manifest: AssistantManifest): SuggestionReviewMetadata {
    const isHint =
        manifest.id === 'date-type-suggestion' &&
        typeof item.metadata === 'object' &&
        item.metadata !== null &&
        (item.metadata as Record<string, unknown>).suggestion_kind === 'hint';

    return {
        assistant_id: manifest.id,
        assistant_name: manifest.name,
        route_prefix: manifest.routePrefix,
        can_accept: !isHint,
        can_decline: true,
        exclusive_target_key: null,
        label: String(item.suggested_label ?? item.suggested_value ?? item.identifier ?? `Suggestion #${item.id}`),
    };
}

function isResourceGroup(item: AssistanceResourceGroup | BaseSuggestionItem): item is AssistanceResourceGroup {
    return Array.isArray(item.suggestions) && typeof item.suggestion_count === 'number';
}

function normalizeSection(section: SectionData, manifest: AssistantManifest): PaginatedData<AssistanceResourceGroup> {
    const first = section.data[0];

    if (first && isResourceGroup(first)) {
        return section as PaginatedData<AssistanceResourceGroup>;
    }

    const groups = new Map<number, AssistanceResourceGroup>();

    for (const rawItem of section.data as BaseSuggestionItem[]) {
        const item = { ...rawItem, assistant_id: rawItem.assistant_id ?? manifest.id, review: rawItem.review ?? fallbackReview(rawItem, manifest) };
        const group = groups.get(item.resource_id) ?? {
            resource_id: item.resource_id,
            resource_doi: item.resource_doi?.trim() ?? '',
            resource_title: item.resource_title?.trim() ?? '',
            suggestion_count: 0,
            suggestions: [],
        };

        group.resource_doi ||= item.resource_doi?.trim() ?? '';
        group.resource_title ||= item.resource_title?.trim() ?? '';
        group.suggestions.push(item);
        group.suggestion_count = group.suggestions.length;
        groups.set(item.resource_id, group);
    }

    return { ...section, data: [...groups.values()] };
}

function identity(item: BaseSuggestionItem): string {
    return `${item.review?.assistant_id ?? item.assistant_id}:${item.id}`;
}

function indirectMatchDescription(match: NonNullable<SuggestionReviewMetadata['filter_match']>): string {
    if (match.matched_doi) return `Affects ${match.matched_doi}`;

    const resourceLabel = match.matched_resource_count === 1 ? 'resource' : 'resources';
    if (match.matched_datacenter_name) {
        return `Affects ${match.matched_resource_count} ${resourceLabel} in ${match.matched_datacenter_name}`;
    }

    return `Affects ${match.matched_resource_count} matching ${resourceLabel}`;
}

function suggestionGroups(items: BaseSuggestionItem[]): Array<{ key: string; exclusive: boolean; items: BaseSuggestionItem[] }> {
    const groups: Array<{ key: string; exclusive: boolean; items: BaseSuggestionItem[] }> = [];

    for (const item of items) {
        const target = item.review?.exclusive_target_key;
        const key = target ? `exclusive:${target}` : `single:${identity(item)}`;
        const current = groups.at(-1);

        if (current?.key === key) current.items.push(item);
        else groups.push({ key, exclusive: Boolean(target), items: [item] });
    }

    return groups;
}

export function ResourceReview({
    allAssistantResources,
    sections,
    manifests,
    assistanceCollapsedAssistantIds,
    checking,
    onCheck,
    onReload,
    onRorFollowUps,
    renderSuggestion,
    acceptanceInputs = {},
    hasActiveFilters = false,
}: ResourceReviewProps) {
    const manifestsById = useMemo(() => new Map(manifests.map((manifest) => [manifest.id, manifest])), [manifests]);
    const [activeView, setActiveView] = useState<'all' | 'assistant'>(initialReviewView);
    const savedCollapsedAssistantIds = useMemo(
        () => normalizeCollapsedAssistantIds(assistanceCollapsedAssistantIds, manifests),
        [assistanceCollapsedAssistantIds, manifests],
    );
    const [collapsedAssistantIds, setCollapsedAssistantIds] = useState<string[]>(savedCollapsedAssistantIds);
    const preferenceTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    const [selected, setSelected] = useState<Set<string>>(() => new Set());
    const [processingResources, setProcessingResources] = useState<Set<number>>(() => new Set());

    const normalizedSections = useMemo(
        () => Object.fromEntries(manifests.map((manifest) => [manifest.id, normalizeSection(sections[manifest.id] ?? emptyPage(), manifest)])),
        [manifests, sections],
    );
    const allResources = allAssistantResources ?? mergeSections(Object.values(normalizedSections));
    const availableIdentities = useMemo(
        () =>
            new Set(
                [...allResources.data, ...Object.values(normalizedSections).flatMap((section) => section.data)]
                    .flatMap((group) => group.suggestions)
                    .map(identity),
            ),
        [allResources.data, normalizedSections],
    );

    useEffect(() => {
        setSelected((current) => {
            const next = new Set([...current].filter((key) => availableIdentities.has(key)));

            return next.size === current.size ? current : next;
        });
    }, [availableIdentities]);

    useEffect(() => {
        setCollapsedAssistantIds((current) => (equalStringArrays(current, savedCollapsedAssistantIds) ? current : savedCollapsedAssistantIds));
    }, [savedCollapsedAssistantIds]);

    const persistCollapsedAssistantIds = useCallback((ids: readonly string[], immediate = false) => {
        const persist = () => {
            preferenceTimeoutRef.current = null;
            router.put(
                ASSISTANCE_ACCORDION_PREFERENCE_URL,
                { collapsed_assistant_ids: [...ids] },
                {
                    preserveScroll: true,
                    preserveState: true,
                    only: ['assistanceCollapsedAssistantIds'],
                    onError: () => toast.error('Failed to save the assistant display preference.'),
                },
            );
        };

        if (preferenceTimeoutRef.current !== null) {
            clearTimeout(preferenceTimeoutRef.current);
            preferenceTimeoutRef.current = null;
        }

        if (immediate) {
            persist();
            return;
        }

        preferenceTimeoutRef.current = setTimeout(persist, ASSISTANCE_ACCORDION_PREFERENCE_DELAY_MS);
    }, []);

    const updateCollapsedAssistantIds = useCallback(
        (ids: readonly string[], immediate = false) => {
            const normalized = normalizeCollapsedAssistantIds(ids, manifests);
            setCollapsedAssistantIds(normalized);
            persistCollapsedAssistantIds(normalized, immediate);
        },
        [manifests, persistCollapsedAssistantIds],
    );

    const openAssistantIds = useMemo(() => {
        const collapsed = new Set(collapsedAssistantIds);

        return manifests.map((manifest) => manifest.id).filter((id) => !collapsed.has(id));
    }, [collapsedAssistantIds, manifests]);
    const allAssistantsCollapsed = manifests.length > 0 && collapsedAssistantIds.length === manifests.length;
    const allAssistantsExpanded = collapsedAssistantIds.length === 0;

    const changeOpenAssistantIds = useCallback(
        (ids: string[]) => {
            const open = new Set(ids);
            updateCollapsedAssistantIds(manifests.map((manifest) => manifest.id).filter((id) => !open.has(id)));
        },
        [manifests, updateCollapsedAssistantIds],
    );

    const collapseAllAssistants = useCallback(() => {
        updateCollapsedAssistantIds(
            manifests.map((manifest) => manifest.id),
            true,
        );
    }, [manifests, updateCollapsedAssistantIds]);

    const expandAllAssistants = useCallback(() => {
        updateCollapsedAssistantIds([], true);
    }, [updateCollapsedAssistantIds]);

    useEffect(() => {
        return () => {
            if (preferenceTimeoutRef.current !== null) {
                clearTimeout(preferenceTimeoutRef.current);
                preferenceTimeoutRef.current = null;
            }
        };
    }, []);

    const changeView = (value: string) => {
        const nextView = value === 'assistant' ? 'assistant' : 'all';
        setActiveView(nextView);

        if (typeof window !== 'undefined') {
            try {
                window.localStorage.setItem(VIEW_STORAGE_KEY, nextView);
            } catch {
                // The selected view remains usable when storage is unavailable.
            }
        }
    };

    const toggleSuggestion = (item: BaseSuggestionItem, checked: boolean) => {
        setSelected((current) => {
            const next = new Set(current);
            const key = identity(item);
            if (checked) next.add(key);
            else next.delete(key);
            return next;
        });
    };

    const selectAllCompatible = (group: AssistanceResourceGroup) => {
        setSelected((current) => {
            const next = new Set(current);
            for (const item of group.suggestions) next.delete(identity(item));
            for (const item of group.suggestions) {
                if (item.review?.can_accept === true && item.review.exclusive_target_key === null) next.add(identity(item));
            }
            return next;
        });
    };

    const runBatch = async (action: 'accept' | 'decline', group: AssistanceResourceGroup) => {
        const items = group.suggestions.filter((item) => selected.has(identity(item)));
        if (items.length === 0) return;

        setProcessingResources((current) => new Set(current).add(group.resource_id));

        try {
            const { data } = await axios.post<BatchSuggestionResponse>(`/assistance/suggestions/batch/${action}`, {
                resource_id: group.resource_id,
                suggestions: items.map((item) => {
                    const acceptanceInput = action === 'accept' ? acceptanceInputs[identity(item)] : undefined;

                    return {
                        assistant_id: item.review?.assistant_id ?? item.assistant_id,
                        suggestion_id: item.id,
                        ...(acceptanceInput ?? {}),
                    };
                }),
            });
            const details = data.results.map((result) => `${result.assistant_name}: ${result.label} — ${result.message}`).join('\n');

            if (data.failure_count === 0) toast.success(data.message, { description: details });
            else toast.warning(data.message, { description: details });

            setSelected((current) => {
                const next = new Set(current);
                for (const result of data.results.filter((result) => result.success)) next.delete(`${result.assistant_id}:${result.suggestion_id}`);
                return next;
            });

            if (data.follow_ups.length > 0) onRorFollowUps(data.follow_ups);
            onReload();
        } catch (error) {
            const message =
                axios.isAxiosError(error) && typeof error.response?.data?.message === 'string'
                    ? error.response.data.message
                    : `Failed to ${action} the selected suggestions.`;
            toast.error(message);
        } finally {
            setProcessingResources((current) => {
                const next = new Set(current);
                next.delete(group.resource_id);
                return next;
            });
        }
    };

    const renderResource = (group: AssistanceResourceGroup, sectionKey: string, sectionManifest?: AssistantManifest) => {
        const selectedItems = group.suggestions.filter((item) => selected.has(identity(item)));
        const processing = processingResources.has(group.resource_id);
        const declineOnlySelected = selectedItems.some((item) => item.review?.can_accept !== true);
        const selectedTargetCounts = new Map<string, number>();

        for (const item of selectedItems) {
            const target = item.review?.exclusive_target_key;
            if (target) selectedTargetCounts.set(target, (selectedTargetCounts.get(target) ?? 0) + 1);
        }

        const conflictingAlternatives = [...selectedTargetCounts.values()].some((count) => count > 1);
        const acceptExplanation = declineOnlySelected
            ? 'The selection contains a hint that can only be declined.'
            : conflictingAlternatives
              ? 'Select at most one ORCID or ROR alternative per target before accepting.'
              : null;
        const resourceLabel = group.resource_doi.trim() || `Resource #${group.resource_id}`;
        const resourceTitle = group.resource_title.trim() || 'Untitled';

        return (
            <Card key={group.resource_id} data-testid={`resource-card-${sectionKey}-${group.resource_id}`}>
                <CardHeader className="gap-4 border-b bg-muted/30 py-4 sm:flex-row sm:items-start sm:justify-between">
                    <div className="min-w-0 space-y-1">
                        <CardTitle className="text-base">
                            <Link
                                href={editorRoute({ query: { resourceId: group.resource_id } }).url}
                                className="font-mono break-all text-primary underline underline-offset-4 hover:text-primary/80 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                                title={`Open ${resourceLabel} in editor`}
                            >
                                {resourceLabel}
                            </Link>
                        </CardTitle>
                        <CardDescription>{resourceTitle}</CardDescription>
                    </div>
                    <div className="flex max-w-full flex-wrap items-center justify-end gap-2">
                        <Badge variant="secondary" className="text-xs">
                            {group.suggestion_count} suggestion(s)
                        </Badge>
                        <Button variant="ghost" size="sm" disabled={processing} onClick={() => selectAllCompatible(group)}>
                            Select all compatible
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={processing || selectedItems.length === 0}
                            onClick={() => runBatch('decline', group)}
                        >
                            <X className="mr-1 h-4 w-4" /> Decline
                        </Button>
                        <Button
                            size="sm"
                            title={acceptExplanation ?? undefined}
                            disabled={processing || selectedItems.length === 0 || acceptExplanation !== null}
                            onClick={() => runBatch('accept', group)}
                        >
                            {processing ? <Spinner size="sm" className="mr-2" /> : <Check className="mr-1 h-4 w-4" />}
                            Accept
                        </Button>
                    </div>
                    {acceptExplanation && <p className="text-sm text-amber-700 sm:basis-full dark:text-amber-300">{acceptExplanation}</p>}
                </CardHeader>
                <CardContent className="p-0">
                    <ul aria-label={`Suggestions for ${resourceLabel}`}>
                        {suggestionGroups(group.suggestions).map((suggestionGroup, groupIndex) => {
                            const firstReview = suggestionGroup.items[0]?.review;
                            const targetLabel = String(
                                suggestionGroup.items[0]?.person_name ?? suggestionGroup.items[0]?.entity_name ?? firstReview?.label ?? 'target',
                            );

                            return (
                                <li
                                    key={suggestionGroup.key}
                                    role="group"
                                    aria-label={suggestionGroup.exclusive ? `Either/or alternatives for ${targetLabel}` : `Suggestion ${targetLabel}`}
                                    className={groupIndex > 0 ? 'border-t' : undefined}
                                >
                                    {suggestionGroup.exclusive && (
                                        <p className="bg-muted/20 px-4 py-2 text-xs font-medium text-muted-foreground">
                                            Either/or — select at most one to accept
                                        </p>
                                    )}
                                    {suggestionGroup.items.map((item, itemIndex) => {
                                        const review = item.review ?? (sectionManifest ? fallbackReview(item, sectionManifest) : undefined);
                                        const manifest = review ? manifestsById.get(review.assistant_id) : sectionManifest;
                                        if (!manifest || !review) return null;

                                        return (
                                            <div
                                                key={identity(item)}
                                                className={`grid grid-cols-[auto_minmax(0,1fr)] items-start gap-3 p-3 ${itemIndex > 0 ? 'border-t border-dashed' : ''}`}
                                            >
                                                <Checkbox
                                                    className="mt-4"
                                                    checked={selected.has(identity(item))}
                                                    disabled={processing || !review.can_decline}
                                                    aria-label={`Select ${review.assistant_name}: ${review.label}`}
                                                    onCheckedChange={(checked) => toggleSuggestion(item, checked === true)}
                                                />
                                                <div className="min-w-0 [&_.suggestion-card-actions]:hidden">
                                                    {sectionKey === 'all' && (
                                                        <Badge variant="outline" className="mt-1 ml-2 text-xs">
                                                            {review.assistant_name}
                                                        </Badge>
                                                    )}
                                                    {review.filter_match?.kind === 'indirect' && (
                                                        <div className="mx-2 mt-2 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                                            <Badge variant="secondary">Indirect match</Badge>
                                                            <span>{indirectMatchDescription(review.filter_match)}</span>
                                                        </div>
                                                    )}
                                                    {renderSuggestion(manifest, item, processing)}
                                                </div>
                                            </div>
                                        );
                                    })}
                                </li>
                            );
                        })}
                    </ul>
                </CardContent>
            </Card>
        );
    };

    const renderPagination = (data: PaginatedData<AssistanceResourceGroup>, sectionKey: string) => {
        if (data.last_page <= 1) return null;

        return (
            <div className="mt-6 flex items-center justify-between border-t pt-4">
                <p className="text-sm text-muted-foreground">
                    Showing {data.from ?? 0}–{data.to ?? 0} of {data.total} resources
                </p>
                <div className="flex gap-1">
                    {data.links.map((link, index) => (
                        <Button
                            key={link.url ?? `${sectionKey}-${link.label}-${index}`}
                            variant={link.active ? 'default' : 'outline'}
                            size="sm"
                            disabled={!link.url}
                            onClick={() => link.url && router.get(link.url, {}, { preserveState: true, preserveScroll: true })}
                            dangerouslySetInnerHTML={{ __html: link.label }}
                        />
                    ))}
                </div>
            </div>
        );
    };

    const renderAllAssistantsSection = (sectionKey: string, data: PaginatedData<AssistanceResourceGroup>) => {
        return (
            <Card key={sectionKey}>
                <CardHeader className="gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div className="space-y-1.5">
                        <CardTitle>All assistants</CardTitle>
                        <CardDescription>Review every pending suggestion for each resource in one place.</CardDescription>
                    </div>
                </CardHeader>
                <CardContent id={`assistance-results-${sectionKey}`} data-testid={`assistance-results-${sectionKey}`}>
                    {data.data.length > 0 ? (
                        <div className="space-y-4">{data.data.map((group) => renderResource(group, sectionKey))}</div>
                    ) : (
                        <div className="flex flex-col items-center justify-center py-12 text-center">
                            <div className="text-4xl">&#10003;</div>
                            <p className="mt-2 text-lg font-medium">{hasActiveFilters ? 'No matching suggestions' : 'No pending suggestions'}</p>
                            <p className="text-sm text-muted-foreground">
                                {hasActiveFilters ? 'No resources match the active DOI and Datacenter filters.' : 'All resources have been reviewed.'}
                            </p>
                        </div>
                    )}
                    {renderPagination(data, sectionKey)}
                </CardContent>
            </Card>
        );
    };

    const renderAssistantSection = (manifest: AssistantManifest) => {
        const data = normalizedSections[manifest.id];
        const isOpen = openAssistantIds.includes(manifest.id);
        const resourceLabel = `${data.total} ${data.total === 1 ? 'resource' : 'resources'} with suggestions`;

        return (
            <AccordionItem
                key={manifest.id}
                value={manifest.id}
                className="overflow-hidden rounded-xl border bg-card text-card-foreground shadow-sm"
                data-testid={`assistant-section-${manifest.id}`}
            >
                <AccordionTrigger
                    className="px-6 py-6 hover:no-underline"
                    aria-label={`${manifest.name}, ${resourceLabel}`}
                    actions={
                        isOpen ? (
                            <Button variant="outline" size="sm" className="mr-6" disabled={checking[manifest.id]} onClick={() => onCheck(manifest)}>
                                {checking[manifest.id] ? <Spinner size="sm" className="mr-2" /> : <RefreshCw className="mr-2 h-4 w-4" />}
                                Check {manifest.name}
                            </Button>
                        ) : undefined
                    }
                >
                    <span className="flex flex-col gap-1.5">
                        <span className="text-base leading-none font-semibold tracking-tight">{manifest.name}</span>
                        <span className="text-sm font-normal text-muted-foreground">{resourceLabel}</span>
                        {isOpen && <span className="text-sm font-normal text-muted-foreground">{manifest.description}</span>}
                    </span>
                </AccordionTrigger>
                <AccordionContent className="px-6 pb-6">
                    <div id={`assistance-results-${manifest.id}`} data-testid={`assistance-results-${manifest.id}`}>
                        {data.data.length > 0 ? (
                            <div className="space-y-4">{data.data.map((group) => renderResource(group, manifest.id, manifest))}</div>
                        ) : (
                            <div className="flex flex-col items-center justify-center py-12 text-center">
                                <div className="text-4xl">&#10003;</div>
                                <p className="mt-2 text-lg font-medium">{hasActiveFilters ? 'No matching suggestions' : manifest.emptyState.title}</p>
                                <p className="text-sm text-muted-foreground">
                                    {hasActiveFilters
                                        ? `No ${manifest.name} resources match the active DOI and Datacenter filters.`
                                        : manifest.emptyState.description}
                                </p>
                            </div>
                        )}
                        {renderPagination(data, manifest.id)}
                    </div>
                </AccordionContent>
            </AccordionItem>
        );
    };

    return (
        <Tabs value={activeView} onValueChange={changeView}>
            <TabsList aria-label="Assistance view">
                <TabsTrigger value="all">All assistants</TabsTrigger>
                <TabsTrigger value="assistant">By assistant</TabsTrigger>
            </TabsList>
            <TabsContent value="all">{renderAllAssistantsSection('all', allResources)}</TabsContent>
            <TabsContent value="assistant" className="space-y-6">
                <div className="flex flex-wrap justify-end gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={manifests.length === 0 || allAssistantsCollapsed}
                        onClick={collapseAllAssistants}
                    >
                        <ChevronsUp className="mr-2 h-4 w-4" aria-hidden="true" />
                        Collapse all
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={manifests.length === 0 || allAssistantsExpanded}
                        onClick={expandAllAssistants}
                    >
                        <ChevronsDown className="mr-2 h-4 w-4" aria-hidden="true" />
                        Expand all
                    </Button>
                </div>
                <Accordion type="multiple" value={openAssistantIds} onValueChange={changeOpenAssistantIds} className="space-y-6">
                    {manifests.map(renderAssistantSection)}
                </Accordion>
            </TabsContent>
        </Tabs>
    );
}

function emptyPage(): PaginatedData<BaseSuggestionItem> {
    return { data: [], current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null, links: [] };
}

function mergeSections(sections: PaginatedData<AssistanceResourceGroup>[]): PaginatedData<AssistanceResourceGroup> {
    const groups = new Map<number, AssistanceResourceGroup>();

    for (const section of sections) {
        for (const group of section.data) {
            const merged = groups.get(group.resource_id) ?? { ...group, suggestions: [], suggestion_count: 0 };
            merged.resource_doi ||= group.resource_doi;
            merged.resource_title ||= group.resource_title;
            merged.suggestions.push(...group.suggestions);
            merged.suggestion_count = merged.suggestions.length;
            groups.set(group.resource_id, merged);
        }
    }

    const data = [...groups.values()];

    return {
        data,
        current_page: 1,
        last_page: 1,
        per_page: data.length || 25,
        total: data.length,
        from: data.length ? 1 : null,
        to: data.length || null,
        links: [],
    };
}
