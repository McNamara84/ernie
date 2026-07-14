import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { AlertTriangle, Building2, Check, Plus,RefreshCw, User, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { LoadingButton } from '@/components/ui/loading-button';
import { Spinner } from '@/components/ui/spinner';
import AppLayout from '@/layouts/app-layout';
import { editor as editorRoute } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import {
    type AcceptResponse,
    type AssistancePageProps,
    type AssistantManifest,
    type BaseSuggestionItem,
    type BulkRorAffiliationAcceptResponse,
    type CheckStatusResponse,
    type PaginatedData,
    type RorAffiliationBulkMatch,
    type SuggestedCrossrefFunderRorItem,
    type SuggestedDescriptionSegmentationItem,
    type SuggestedOrcidItem,
    type SuggestedRelationItem,
    type SuggestedRorItem,
    type SuggestedSpdxRightsItem,
    type SuggestedSubjectMetadataEnrichmentItem,
} from '@/types/assistance';
import { validateORCID } from '@/utils/validation-rules';

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

const ROR_ID_PATTERN = /^\/0[a-z0-9]{6}\d{2}$/;

function isValidOrcidId(id: string): boolean {
    return validateORCID(id).isValid;
}

function isValidRorUrl(url: string): boolean {
    try {
        const parsed = new URL(url);
        return parsed.protocol === 'https:' && parsed.hostname === 'ror.org' && ROR_ID_PATTERN.test(parsed.pathname);
    } catch {
        return false;
    }
}

function resourceEditorUrl(resourceId: number): string {
    return editorRoute({ query: { resourceId } }).url;
}

function rorBulkMatchDialogDescription(count: number): string {
    const isSingular = count === 1;
    const noun = isSingular ? 'creator affiliation' : 'creator affiliations';
    const verb = isSingular ? 'is' : 'are';
    const target = isSingular ? 'this affiliation' : 'these affiliations';

    return `There ${verb} ${count} further ${noun} with the same <creatorName>, <affiliation>, and ROR suggestion you have just confirmed. Would you like to accept the ROR suggestion for ${target} as well?`;
}

function normalizedResourceHeaderValue(value: string | null | undefined): string {
    return typeof value === 'string' ? value.trim() : '';
}

function firstNonEmptyResourceHeaderValue(current: string, candidate: string): string {
    return current === '' ? candidate : current;
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
        <div className="bg-card p-2 sm:p-3">
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

                    {suggestion.source_title && <p className="text-sm font-medium text-foreground">&quot;{suggestion.source_title}&quot;</p>}

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
        <div className="bg-card p-2 sm:p-3">
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
                        <Badge className={`text-xs ${similarityColor(suggestion.similarity_score)}`}>{percent}% match</Badge>
                    </div>

                    <div className="space-y-1">
                        <p className="font-mono text-sm">
                            ORCID:{' '}
                            {isValidOrcidId(suggestion.suggested_orcid) ? (
                                <a
                                    href={`https://orcid.org/${suggestion.suggested_orcid}`}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="text-primary underline hover:text-primary/80"
                                >
                                    {suggestion.suggested_orcid}
                                </a>
                            ) : (
                                suggestion.suggested_orcid
                            )}
                        </p>
                        {candidateName && <p className="text-sm text-muted-foreground">Candidate: {candidateName}</p>}
                        {suggestion.candidate_affiliations.length > 0 && (
                            <p className="text-xs text-muted-foreground">Affiliations: {suggestion.candidate_affiliations.join(', ')}</p>
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

const RIGHTS_FIELD_LABELS: Record<string, string> = {
    rights: 'rights',
    rights_uri: 'rightsURI',
    rights_identifier: 'rightsIdentifier',
    rights_identifier_scheme: 'rightsIdentifierScheme',
    scheme_uri: 'schemeURI',
    language: 'lang',
    source: 'source',
};

function RightsMetadataBlock({ title, values }: { title: string; values: Record<string, string> | undefined }) {
    const entries = Object.entries(RIGHTS_FIELD_LABELS)
        .map(([key, label]) => [label, values?.[key]] as const)
        .filter(([, value]) => typeof value === 'string' && value.trim() !== '');

    return (
        <div className="min-w-0 rounded-md border bg-muted/20 p-3">
            <p className="mb-2 text-xs font-semibold text-muted-foreground uppercase">{title}</p>
            {entries.length > 0 ? (
                <dl className="space-y-1 text-xs">
                    {entries.map(([label, value]) => (
                        <div key={label} className="grid grid-cols-[9.5rem_minmax(0,1fr)] gap-2">
                            <dt className="text-muted-foreground">{label}</dt>
                            <dd className="font-mono break-words text-foreground">{value}</dd>
                        </div>
                    ))}
                </dl>
            ) : (
                <p className="text-xs text-muted-foreground">No metadata captured.</p>
            )}
        </div>
    );
}

function SpdxRightsSuggestionCard({
    suggestion,
    onAccept,
    onDecline,
    isProcessing,
}: {
    suggestion: SuggestedSpdxRightsItem;
    onAccept: (id: number) => void;
    onDecline: (id: number) => void;
    isProcessing: boolean;
}) {
    const metadata = suggestion.metadata ?? null;
    const percent = suggestion.similarity_score !== null ? Math.round(suggestion.similarity_score * 100) : null;

    return (
        <div className="bg-card p-2 sm:p-3">
            <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div className="min-w-0 flex-1 space-y-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant="outline" className="text-xs">
                            SPDX
                        </Badge>
                        {percent !== null && (
                            <Badge className={`text-xs ${similarityColor(suggestion.similarity_score ?? 0)}`}>{percent}% match</Badge>
                        )}
                        <Badge variant="secondary" className="text-xs">
                            resource_right #{suggestion.target_id}
                        </Badge>
                        <span className="font-mono text-sm break-all">{suggestion.suggested_value}</span>
                    </div>

                    <div className="grid gap-3 xl:grid-cols-2">
                        <RightsMetadataBlock title="Current imported rights" values={metadata?.current} />
                        <RightsMetadataBlock title="Proposed SPDX metadata" values={metadata?.proposed} />
                    </div>

                    <div className="rounded-md border border-amber-200 bg-amber-50 p-2 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
                        <div className="flex gap-2">
                            <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                            <p>
                                Clicking Accept links only this rights statement to the shared SPDX catalog. Existing catalog fields are reused; only
                                empty catalog fields may be filled.
                            </p>
                        </div>
                    </div>

                    <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                        {metadata?.evidence?.matched_from && <span>Matched from: {metadata.evidence.matched_from}</span>}
                        {metadata?.evidence?.reason && <span>Reason: {metadata.evidence.reason}</span>}
                        {metadata?.source_url && (
                            <a href={metadata.source_url} target="_blank" rel="noopener noreferrer" className="underline hover:text-foreground">
                                SPDX reference
                            </a>
                        )}
                        <span>Discovered: {new Date(suggestion.discovered_at).toLocaleDateString()}</span>
                    </div>
                </div>

                <div className="flex shrink-0 gap-2 self-start">
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
        <div className="bg-card p-2 sm:p-3">
            <div className="flex items-start justify-between gap-4">
                <div className="min-w-0 flex-1 space-y-2">
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge className={`text-xs ${entityTypeBadgeColor(suggestion.entity_type)}`}>
                            <Building2 className="mr-1 h-3 w-3" />
                            {entityTypeLabel(suggestion.entity_type)}
                        </Badge>
                        <Badge className={`text-xs ${similarityColor(suggestion.similarity_score)}`}>{percent}% match</Badge>
                    </div>

                    <div className="space-y-1">
                        <p className="text-sm font-medium text-foreground">{suggestion.entity_name}</p>
                        {suggestion.person_name && (
                            <p className="text-xs text-muted-foreground">
                                <User className="mr-1 inline h-3 w-3" />
                                Person: {suggestion.person_name}
                            </p>
                        )}
                        <p className="text-sm text-muted-foreground">&rarr; {suggestion.suggested_name}</p>
                        <p className="font-mono text-xs text-muted-foreground">
                            ROR:{' '}
                            {isValidRorUrl(suggestion.suggested_ror_id) ? (
                                <a
                                    href={suggestion.suggested_ror_id}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="text-primary underline hover:text-primary/80"
                                >
                                    {suggestion.suggested_ror_id}
                                </a>
                            ) : (
                                suggestion.suggested_ror_id
                            )}
                        </p>
                        {suggestion.ror_aliases.length > 0 && (
                            <p className="text-xs text-muted-foreground">Also known as: {suggestion.ror_aliases.slice(0, 3).join(', ')}</p>
                        )}
                        {suggestion.locations?.length ? (
                            <p className="text-xs text-muted-foreground">Locations: {suggestion.locations.join(', ')}</p>
                        ) : null}
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

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function confidenceBadgeColor(confidence: string | null): string {
    switch (confidence) {
        case 'high':
            return 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200';
        case 'medium':
            return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200';
        case 'low':
            return 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200';
        default:
            return 'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300';
    }
}

function confidenceLabel(confidence: string | null): string | null {
    switch (confidence) {
        case 'high':
            return 'High confidence';
        case 'medium':
            return 'Medium confidence';
        case 'low':
            return 'Low confidence';
        default:
            return confidence ? `${confidence} confidence` : null;
    }
}

function metadataText(value: unknown): string | null {
    if (value === null || value === undefined || Array.isArray(value) || typeof value === 'object') {
        return null;
    }

    const text = String(value).trim();

    return text === '' ? null : text;
}

function metadataList(value: unknown): string[] {
    if (!Array.isArray(value)) return [];

    return value.map((item) => metadataText(item)).filter((item): item is string => item !== null);
}

const DESCRIPTION_TYPE_LABELS: Record<string, string> = {
    Abstract: 'Abstract',
    Methods: 'Methods',
    SeriesInformation: 'Series Information',
    TableOfContents: 'Table of Contents',
    TechnicalInfo: 'Technical Info',
    Other: 'Other',
};

function descriptionTypeLabel(type: string | null): string | null {
    if (type === null) {
        return null;
    }

    return DESCRIPTION_TYPE_LABELS[type] ?? type;
}

function metadataStringValues(value: unknown): string[] {
    if (Array.isArray(value)) return metadataList(value);

    if (isRecord(value)) {
        return Object.values(value)
            .map((item) => metadataText(item))
            .filter((item): item is string => item !== null);
    }

    const text = metadataText(value);

    return text === null ? [] : [text];
}

const SUBJECT_FIELD_LABELS: Record<string, string> = {
    value: 'subject',
    subject_scheme: 'subjectScheme',
    scheme_uri: 'schemeURI',
    value_uri: 'valueURI',
    classification_code: 'classificationCode',
    breadcrumb_path: 'breadcrumbPath',
    language: 'lang',
};

function SubjectMetadataBlock({ title, fields }: { title: string; fields: Array<[string, unknown]> }) {
    const entries = fields.map(([label, value]) => [label, metadataText(value)] as const).filter(([, value]) => value !== null);

    return (
        <div className="min-w-0 rounded-md border bg-muted/20 p-3">
            <p className="mb-2 text-xs font-semibold text-muted-foreground uppercase">{title}</p>
            {entries.length > 0 ? (
                <dl className="space-y-1 text-xs">
                    {entries.map(([label, value]) => (
                        <div key={label} className="grid grid-cols-[8.5rem_minmax(0,1fr)] gap-2">
                            <dt className="text-muted-foreground">{label}</dt>
                            <dd className="font-mono break-words text-foreground">{value}</dd>
                        </div>
                    ))}
                </dl>
            ) : (
                <p className="text-xs text-muted-foreground">No metadata captured.</p>
            )}
        </div>
    );
}

function subjectUpdateFields(updates: Record<string, string> | undefined): Array<[string, unknown]> {
    if (!updates) return [];

    return Object.entries(SUBJECT_FIELD_LABELS)
        .filter(([field]) => field !== 'value' && Object.prototype.hasOwnProperty.call(updates, field))
        .map(([field, label]) => [label, updates[field]] as [string, unknown]);
}

function SubjectMetadataEnrichmentCard({
    suggestion,
    onAccept,
    onDecline,
    isProcessing,
}: {
    suggestion: SuggestedSubjectMetadataEnrichmentItem;
    onAccept: (id: number) => void;
    onDecline: (id: number) => void;
    isProcessing: boolean;
}) {
    const metadata = suggestion.metadata;
    const current = metadata?.current;
    const proposed = metadata?.proposed;
    const vocabulary = metadata?.vocabulary;
    const match = metadata?.match;
    const provenance = metadata?.provenance;
    const confidence = metadata?.confidence;
    const ambiguity = metadata?.ambiguity;
    const confidenceLevel = metadataText(confidence?.level);
    const confidenceScore = typeof confidence?.score === 'number' ? confidence.score : suggestion.similarity_score;
    const percent = typeof confidenceScore === 'number' ? Math.round(confidenceScore * 100) : null;
    const warningMessages = metadataStringValues(ambiguity?.warning_messages);
    const warnings = metadataList(ambiguity?.warnings);
    const evidence = metadataList(confidence?.evidence);
    const preservedFields = metadataList(proposed?.preserve);
    const matchedFields = metadataList(match?.matched_fields);
    const ambiguityStatus = metadataText(ambiguity?.status);
    const updateFields = subjectUpdateFields(proposed?.updates);

    return (
        <div className="bg-card p-2 sm:p-3">
            <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div className="min-w-0 flex-1 space-y-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant="outline" className="text-xs">
                            subject #{suggestion.target_id}
                        </Badge>
                        {confidenceLevel && (
                            <Badge className={`text-xs ${confidenceBadgeColor(confidenceLevel)}`}>{confidenceLabel(confidenceLevel)}</Badge>
                        )}
                        {percent !== null && (
                            <Badge variant="secondary" className="text-xs">
                                {percent}% vocabulary match
                            </Badge>
                        )}
                        {ambiguityStatus && (
                            <Badge variant={warningMessages.length > 0 || warnings.length > 0 ? 'destructive' : 'secondary'} className="text-xs">
                                {ambiguityStatus === 'none' ? 'No ambiguity' : `Ambiguity: ${ambiguityStatus}`}
                            </Badge>
                        )}
                    </div>

                    <div className="space-y-1">
                        <p className="text-sm font-medium text-foreground">{suggestion.suggested_label}</p>
                        <p className="font-mono text-xs break-words text-muted-foreground">{suggestion.suggested_value}</p>
                    </div>

                    <div className="grid gap-3 xl:grid-cols-2">
                        <SubjectMetadataBlock
                            title="Current Subject metadata"
                            fields={[
                                [SUBJECT_FIELD_LABELS.value, current?.value],
                                [SUBJECT_FIELD_LABELS.subject_scheme, current?.subject_scheme],
                                [SUBJECT_FIELD_LABELS.scheme_uri, current?.scheme_uri],
                                [SUBJECT_FIELD_LABELS.value_uri, current?.value_uri],
                                [SUBJECT_FIELD_LABELS.classification_code, current?.classification_code],
                                [SUBJECT_FIELD_LABELS.breadcrumb_path, current?.breadcrumb_path],
                                [SUBJECT_FIELD_LABELS.language, current?.language],
                            ]}
                        />
                        <SubjectMetadataBlock title="Will update DataCite Subject fields" fields={updateFields} />
                    </div>

                    <div className="rounded-md border border-blue-200 bg-blue-50 p-2 text-xs text-blue-900 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-100">
                        Accept enriches only the listed DataCite Subject fields.
                        {preservedFields.length > 0 && <span> Preserved fields: {preservedFields.join(', ')}.</span>}
                    </div>

                    {(warningMessages.length > 0 || warnings.length > 0) && (
                        <div className="rounded-md border border-amber-200 bg-amber-50 p-2 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
                            <div className="flex gap-2">
                                <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                <div className="space-y-1">
                                    <p>Review warning(s) before accepting this subject enrichment:</p>
                                    <ul className="list-inside list-disc">
                                        {warningMessages.map((warning) => (
                                            <li key={warning}>{warning}</li>
                                        ))}
                                        {warningMessages.length === 0 && warnings.map((warning) => <li key={warning}>{warning}</li>)}
                                    </ul>
                                </div>
                            </div>
                        </div>
                    )}

                    <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                        {vocabulary?.scheme && <span>Vocabulary: {vocabulary.scheme}</span>}
                        {vocabulary?.source && <span>Source: {vocabulary.source}</span>}
                        {(vocabulary?.local_cache_file || provenance?.source_file) && (
                            <span>File: {vocabulary?.local_cache_file ?? provenance?.source_file}</span>
                        )}
                        {match?.strategy && <span>Strategy: {match.strategy}</span>}
                        {matchedFields.length > 0 && <span>Matched fields: {matchedFields.join(', ')}</span>}
                        {typeof match?.candidate_count === 'number' && <span>Candidates: {match.candidate_count}</span>}
                        {match?.path_normalization_applied && <span>Path normalization: {match.path_normalization_applied}</span>}
                        {evidence.length > 0 && <span>Confidence evidence: {evidence.join(', ')}</span>}
                        <span>Discovered: {suggestion.discovered_at ? new Date(suggestion.discovered_at).toLocaleDateString() : '-'}</span>
                    </div>
                </div>

                <div className="flex shrink-0 gap-2 self-start">
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={isProcessing}
                        data-testid={`subject-metadata-enrichment-decline-${suggestion.id}`}
                        onClick={() => onDecline(suggestion.id)}
                    >
                        <X className="mr-1 h-4 w-4" />
                        Decline
                    </Button>
                    <Button
                        size="sm"
                        disabled={isProcessing}
                        data-testid={`subject-metadata-enrichment-accept-${suggestion.id}`}
                        onClick={() => onAccept(suggestion.id)}
                    >
                        <Check className="mr-1 h-4 w-4" />
                        Accept
                    </Button>
                </div>
            </div>
        </div>
    );
}
function CrossrefFunderRorMetadataBlock({ title, fields }: { title: string; fields: Array<[string, unknown]> }) {
    const entries = fields.map(([label, value]) => [label, metadataText(value)] as const).filter(([, value]) => value !== null);

    return (
        <div className="min-w-0 rounded-md border bg-muted/20 p-3">
            <p className="mb-2 text-xs font-semibold text-muted-foreground uppercase">{title}</p>
            {entries.length > 0 ? (
                <dl className="space-y-1 text-xs">
                    {entries.map(([label, value]) => (
                        <div key={label} className="grid grid-cols-[8.5rem_minmax(0,1fr)] gap-2">
                            <dt className="text-muted-foreground">{label}</dt>
                            <dd className="font-mono break-words text-foreground">{value}</dd>
                        </div>
                    ))}
                </dl>
            ) : (
                <p className="text-xs text-muted-foreground">No metadata captured.</p>
            )}
        </div>
    );
}

function CrossrefFunderRorIdentifier({ value }: { value: string }) {
    if (isValidRorUrl(value)) {
        return (
            <a href={value} target="_blank" rel="noopener noreferrer" className="break-all text-primary underline hover:text-primary/80">
                {value}
            </a>
        );
    }

    return <span className="font-mono break-all">{value}</span>;
}

function CrossrefFunderRorSuggestionCard({
    suggestion,
    onAccept,
    onDecline,
    isProcessing,
}: {
    suggestion: SuggestedCrossrefFunderRorItem;
    onAccept: (id: number) => void;
    onDecline: (id: number) => void;
    isProcessing: boolean;
}) {
    const metadata = suggestion.metadata;
    const current = metadata?.current;
    const proposed = metadata?.proposed;
    const provenance = metadata?.provenance;
    const confidence = metadata?.confidence;
    const ambiguity = metadata?.ambiguity;
    const rorIdentifier = proposed?.funder_identifier ?? proposed?.ror_id ?? suggestion.suggested_value;
    const confidenceLevel = metadataText(confidence?.level);
    const confidenceScore = typeof confidence?.score === 'number' ? confidence.score : suggestion.similarity_score;
    const percent = typeof confidenceScore === 'number' ? Math.round(confidenceScore * 100) : null;
    const warnings = metadataList(ambiguity?.warnings);
    const evidence = metadataList(confidence?.evidence);
    const preservedFields = metadataList(metadata?.acceptance?.preserve);
    const rorTypes = metadataList(proposed?.ror_types);
    const ambiguityStatus = metadataText(ambiguity?.status);

    return (
        <div className="bg-card p-2 sm:p-3">
            <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div className="min-w-0 flex-1 space-y-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant="outline" className="text-xs">
                            funding_reference #{suggestion.target_id}
                        </Badge>
                        {confidenceLevel && (
                            <Badge className={`text-xs ${confidenceBadgeColor(confidenceLevel)}`}>{confidenceLabel(confidenceLevel)}</Badge>
                        )}
                        {percent !== null && (
                            <Badge variant="secondary" className="text-xs">
                                {percent}% registry match
                            </Badge>
                        )}
                        {ambiguityStatus && (
                            <Badge variant={warnings.length > 0 ? 'destructive' : 'secondary'} className="text-xs">
                                {ambiguityStatus === 'none' ? 'No ambiguity' : `Ambiguity: ${ambiguityStatus}`}
                            </Badge>
                        )}
                    </div>

                    <div className="space-y-1">
                        <p className="text-sm font-medium text-foreground">{current?.funder_name ?? suggestion.suggested_label}</p>
                        <p className="font-mono text-xs text-muted-foreground">
                            ROR: <CrossrefFunderRorIdentifier value={rorIdentifier} />
                        </p>
                    </div>

                    <div className="grid gap-3 xl:grid-cols-2">
                        <CrossrefFunderRorMetadataBlock
                            title="Current Crossref Funder ID"
                            fields={[
                                ['funderName', current?.funder_name],
                                ['identifier', current?.funder_identifier],
                                ['type', current?.funder_identifier_type],
                                ['schemeURI', current?.scheme_uri],
                                ['normalized', current?.normalized_crossref_funder_id],
                            ]}
                        />
                        <CrossrefFunderRorMetadataBlock
                            title="Proposed ROR identifier"
                            fields={[
                                ['displayName', proposed?.ror_display_name],
                                ['identifier', rorIdentifier],
                                ['type', proposed?.funder_identifier_type],
                                ['schemeURI', proposed?.scheme_uri],
                                ['status', proposed?.ror_status],
                                ['types', rorTypes.join(', ')],
                            ]}
                        />
                    </div>

                    {warnings.length > 0 && (
                        <div className="rounded-md border border-amber-200 bg-amber-50 p-2 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
                            <div className="flex gap-2">
                                <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                <div className="space-y-1">
                                    <p>Review warning(s) from the mapping evidence:</p>
                                    <ul className="list-inside list-disc">
                                        {warnings.map((warning) => (
                                            <li key={warning}>{warning}</li>
                                        ))}
                                    </ul>
                                </div>
                            </div>
                        </div>
                    )}

                    <div className="rounded-md border border-blue-200 bg-blue-50 p-2 text-xs text-blue-900 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-100">
                        Accept updates only the funding reference identifier, identifier type, and scheme URI.
                        {preservedFields.length > 0 && <span> Preserved fields: {preservedFields.join(', ')}.</span>}
                    </div>

                    <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                        {proposed?.matched_external_id?.value && <span>Matched FundRef: {proposed.matched_external_id.value}</span>}
                        {proposed?.matched_external_id?.matched_in && <span>Evidence: {proposed.matched_external_id.matched_in}</span>}
                        {provenance?.source && <span>Source: {provenance.source}</span>}
                        {provenance?.source_file && <span>File: {provenance.source_file}</span>}
                        {provenance?.source_retrieved_at && <span>Retrieved: {provenance.source_retrieved_at}</span>}
                        {provenance?.matching_strategy && <span>Strategy: {provenance.matching_strategy}</span>}
                        {evidence.length > 0 && <span>Confidence evidence: {evidence.join(', ')}</span>}
                        <span>Discovered: {suggestion.discovered_at ? new Date(suggestion.discovered_at).toLocaleDateString() : '-'}</span>
                    </div>
                </div>

                <div className="flex shrink-0 gap-2 self-start">
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={isProcessing}
                        data-testid={`crossref-funder-ror-decline-${suggestion.id}`}
                        onClick={() => onDecline(suggestion.id)}
                    >
                        <X className="mr-1 h-4 w-4" />
                        Decline
                    </Button>
                    <Button
                        size="sm"
                        disabled={isProcessing}
                        data-testid={`crossref-funder-ror-accept-${suggestion.id}`}
                        onClick={() => onAccept(suggestion.id)}
                    >
                        <Check className="mr-1 h-4 w-4" />
                        Accept
                    </Button>
                </div>
            </div>
        </div>
    );
}
function probeMethodLabel(probeMethod: string | null, targetType: unknown): string | null {
    if (!probeMethod) return null;

    const method = probeMethod.toUpperCase();

    if (method === 'DIRECTORY_LISTING') {
        return targetType === 'size' ? 'Calculated from download page' : 'Found on download page';
    }

    const labels: Record<string, string> = {
        CONTENT_LENGTH_HEADER: 'Read from server file size',
        CONTENT_TYPE_HEADER: 'Read from server file type',
        FILENAME_EXTENSION: 'Detected from file name',
        FILENAME_EXTENSION_FALLBACK: 'Detected from file name',
        HTTP_HEAD: 'Checked server file metadata',
        RANGED_GET: 'Checked partial file response',
        RANGED_GET_CONTENT_RANGE: 'Read from partial file size',
        RANGED_GET_CONTENT_TYPE: 'Read from partial file type',
        ZIP_CONTENT_LISTING: 'Read from ZIP contents',
    };

    return labels[method] ?? method.toLowerCase().replaceAll('_', ' ');
}

function sizeValueLabel(value: string): string {
    const trimmed = value.trim();

    return trimmed.replace(/^([0-9]+(?:\.[0-9]+)?)\s*(B|KB|MB|GB|TB|PB)$/i, (_, amount: string, unit: string) => {
        return `${amount} ${unit.toUpperCase()}`;
    });
}

function formatValueLabel(value: string): string {
    const trimmed = value.trim();
    const normalized = trimmed.toLowerCase().replace(/^\./, '');

    const labels: Record<string, string> = {
        'application/json': 'JSON file (application/json)',
        'application/pdf': 'PDF document (application/pdf)',
        'application/x-netcdf': 'NetCDF file (application/x-netcdf)',
        'application/xml': 'XML file (application/xml)',
        'application/zip': 'ZIP archive (application/zip)',
        csv: 'CSV file (.csv)',
        h5: 'HDF5 file (.h5)',
        hdf: 'HDF file (.hdf)',
        hdf5: 'HDF5 file (.hdf5)',
        json: 'JSON file (.json)',
        nc: 'NetCDF file (.nc)',
        netcdf: 'NetCDF file (.netcdf)',
        pdf: 'PDF document (.pdf)',
        tif: 'TIFF image (.tif)',
        tiff: 'TIFF image (.tiff)',
        'text/csv': 'CSV file (text/csv)',
        'text/plain': 'Text file (text/plain)',
        'text/tab-separated-values': 'TSV file (text/tab-separated-values)',
        txt: 'Text file (.txt)',
        xml: 'XML file (.xml)',
        zip: 'ZIP archive (.zip)',
    };

    if (labels[normalized]) {
        return labels[normalized];
    }

    if (normalized.includes('/')) {
        return trimmed;
    }

    if (/^[a-z0-9]{1,8}$/.test(normalized)) {
        return `${normalized.toUpperCase()} file (.${normalized})`;
    }

    return trimmed;
}

function sizeFormatDisplayLabel(targetType: unknown, value: string, fallbackLabel: string): string {
    if (targetType === 'size') {
        const sizeValue = value || fallbackLabel.replace(/^SIZE:\s*/i, '');

        return `Suggested size: ${sizeValueLabel(sizeValue)}`;
    }

    if (targetType === 'format') {
        const formatValue = value || fallbackLabel.replace(/^FORMAT:\s*/i, '');

        return `Suggested format: ${formatValueLabel(formatValue)}`;
    }

    return fallbackLabel;
}

function targetTypeLabel(targetType: unknown, isZip: boolean): string {
    if (isZip) return 'ZIP Archive';
    if (targetType === 'size') return 'File size';
    if (targetType === 'format') return 'File format';

    return String(targetType ?? 'Suggestion');
}

function SizeFormatSuggestionCard({
    suggestion,
    onAccept,
    onDecline,
    isProcessing,
}: {
    suggestion: BaseSuggestionItem;
    onAccept: (id: number) => void;
    onDecline: (id: number) => void;
    isProcessing: boolean;
}) {
    const value = String(suggestion.suggested_value ?? '');
    const label = String(suggestion.suggested_label ?? value);
    const isZip = value.toLowerCase() === 'zip' || value.toLowerCase().includes('application/zip');
    const displayLabel = sizeFormatDisplayLabel(suggestion.target_type, value, label);
    const metadata = isRecord(suggestion.metadata) ? suggestion.metadata : null;
    const evidence = isRecord(metadata?.evidence) ? metadata.evidence : null;
    const sourceUrl = typeof metadata?.source_url === 'string' ? metadata.source_url : null;
    const probeMethod = typeof metadata?.probe_method === 'string' ? metadata.probe_method : null;
    const confidence = typeof metadata?.confidence === 'string' ? metadata.confidence : null;
    const displayConfidence = confidenceLabel(confidence);
    const displayProbeMethod = probeMethodLabel(probeMethod, suggestion.target_type);
    const parsedFileCount = typeof evidence?.parsed_file_count === 'number' ? evidence.parsed_file_count : null;
    const totalFileCount = typeof evidence?.total_file_count === 'number' ? evidence.total_file_count : null;
    const filename = typeof evidence?.filename === 'string' ? evidence.filename : null;

    return (
        <div className={isZip ? 'border-l-4 border-orange-500 bg-orange-50 p-2 sm:p-3 dark:bg-orange-950/20' : 'bg-card p-2 sm:p-3'}>
            <div className="flex items-start justify-between gap-4">
                <div className="min-w-0 flex-1 space-y-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge className={isZip ? 'bg-orange-600 text-white' : ''}>{targetTypeLabel(suggestion.target_type, isZip)}</Badge>
                        {displayConfidence && <Badge className={`text-xs ${confidenceBadgeColor(confidence)}`}>{displayConfidence}</Badge>}
                        {displayProbeMethod && (
                            <Badge variant="secondary" className="text-xs">
                                {displayProbeMethod}
                            </Badge>
                        )}
                    </div>

                    <p className="text-sm font-medium">{displayLabel}</p>

                    {(sourceUrl || filename || parsedFileCount !== null) && (
                        <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                            {sourceUrl && (
                                <a
                                    href={sourceUrl}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="max-w-full break-all underline hover:text-foreground"
                                >
                                    Open source
                                </a>
                            )}
                            {filename && <span className="break-all">Detected from file: {filename}</span>}
                            {parsedFileCount !== null && (
                                <span>
                                    Files counted: {parsedFileCount}
                                    {totalFileCount !== null ? ` of ${totalFileCount}` : ''}
                                </span>
                            )}
                        </div>
                    )}

                    <p className="text-xs text-muted-foreground">
                        Discovered: {suggestion.discovered_at ? new Date(suggestion.discovered_at).toLocaleDateString() : '—'}
                    </p>
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

function DateTypeSuggestionCard({
    suggestion,
    onAccept,
    onDecline,
    isProcessing,
}: {
    suggestion: BaseSuggestionItem;
    onAccept: (id: number) => void;
    onDecline: (id: number) => void;
    isProcessing: boolean;
}) {
    const metadata = isRecord(suggestion.metadata) ? suggestion.metadata : null;

    const suggestionKind = typeof metadata?.suggestion_kind === 'string' ? metadata.suggestion_kind : null;
    const confidence = typeof metadata?.confidence === 'string' ? metadata.confidence : null;
    const targetDateType = typeof metadata?.target_date_type === 'string' ? metadata.target_date_type : null;
    const schemaOrgField = typeof metadata?.schema_org_field === 'string' ? metadata.schema_org_field : null;
    const sourceUrl = typeof metadata?.source_url === 'string' ? metadata.source_url : null;
    const evidence = typeof metadata?.evidence === 'string' ? metadata.evidence : null;

    const collectedDatesCount = typeof metadata?.collected_dates_count === 'number' ? metadata.collected_dates_count : null;
    const geoLocationsCount = typeof metadata?.geo_locations_count === 'number' ? metadata.geo_locations_count : null;

    const isHint = suggestionKind === 'hint';
    const isAmbiguous = metadata?.is_ambiguous === true || isHint || confidence === 'low';
    const evidenceUrl = typeof metadata?.evidence_url === 'string' ? metadata.evidence_url : null;
    const hintLabel = String(suggestion.suggested_label ?? suggestion.suggested_value ?? 'DateType hint').replace(/^Hint:\s*/i, '');
    const displayLabel = isHint ? hintLabel : suggestionKind === 'correction'
        ? String(suggestion.suggested_label ?? 'DateType correction')
        : dateTypeDisplayLabel(
              targetDateType,
              String(suggestion.suggested_value ?? ''),
              String(suggestion.suggested_label ?? 'DateType suggestion'),
          );

    return (
        <div
            className={
                isAmbiguous
                    ? 'rounded-lg border-2 border-orange-500 bg-orange-50 p-4 shadow-sm transition-all hover:shadow-md dark:bg-orange-950/20'
                    : 'rounded-lg border bg-card p-4 shadow-sm transition-all hover:shadow-md'
            }
        >
            <div className="flex items-start justify-between gap-4">
                <div className="min-w-0 flex-1 space-y-3">
                    <div className="flex flex-wrap items-center gap-2">
                            {isHint ? (
                                <Badge className="bg-orange-600 text-white">
                                    <AlertTriangle className="mr-1 h-3 w-3" />
                                    Hint
                                </Badge>
                            ) : suggestionKind === 'correction' ? (
                                <Badge className="bg-black text-white">
                                    <RefreshCw className="mr-1 h-3 w-3" />
                                    Correction
                                </Badge>
                            ) : (
                                <Badge className="bg-black text-white">
                                    <Plus className="mr-1 h-3 w-3" />
                                    Addition
                                </Badge>
                            )}

                        {targetDateType && (
                            <Badge variant="secondary" className="text-xs">
                                {targetDateType}
                            </Badge>
                        )}

                        {confidence && (
                            <Badge className={`text-xs ${confidenceBadgeColor(confidence)}`}>
                                {confidenceLabel(confidence)}
                            </Badge>
                        )}

                        {isAmbiguous && (
                            <Badge className="bg-orange-50 text-orange-600 dark:border-orange-400 dark:text-orange-400">
                                Manual review
                            </Badge>
                        )}

                        {collectedDatesCount !== null && geoLocationsCount !== null && (
                            <Badge variant="secondary" className="text-xs">
                                {collectedDatesCount}:{geoLocationsCount}
                            </Badge>
                        )}
                    </div>

                    <p className="text-sm font-medium">
                        {displayLabel}
                    </p>

                    {evidence && <p className="text-xs text-muted-foreground">{evidence}</p>}
                    {(sourceUrl || evidenceUrl || schemaOrgField) && (
                        <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                            {schemaOrgField && <span>schema.org field: {schemaOrgField}</span>}
                            {sourceUrl && (
                                <a href={sourceUrl} target="_blank" rel="noopener noreferrer" className="break-all underline hover:text-foreground">
                                    Open source
                                </a>
                            )}
                            {evidenceUrl && (
                                <a href={evidenceUrl} target="_blank" rel="noopener noreferrer" className="break-all underline hover:text-foreground">
                                    Open schema.org
                                </a>
                            )}
                        </div>
                    )}
                    <p className="text-xs text-muted-foreground">
                        Discovered: {suggestion.discovered_at ? new Date(suggestion.discovered_at).toLocaleDateString() : '—'}

                    </p>
                </div>

                <div className="flex shrink-0 gap-2">
                    <Button variant="outline" size="sm" disabled={isProcessing} onClick={() => onDecline(suggestion.id)}>
                        <X className="mr-1 h-4 w-4" />
                        {isHint ? 'Dismiss' : 'Decline'}
                    </Button>

                    {!isHint && (
                        <Button size="sm" disabled={isProcessing} onClick={() => onAccept(suggestion.id)}>
                            <Check className="mr-1 h-4 w-4" />
                            Accept
                        </Button>
                    )}
                </div>
            </div>
        </div>
    );
}
function dateTypeDisplayLabel(targetType: unknown, value: string, fallbackLabel: string,): string 
{
    if (typeof targetType !== 'string') {
        return fallbackLabel;
    }
    const dateValue = value || fallbackLabel;
    return `Suggestion for ${targetType}: ${dateValue}`;
}
// ── Per-section state ────────────────────────────────────────────────

function DescriptionPreviewBlock({ title, value }: { title: string; value: string | null | undefined }) {
    const text = typeof value === 'string' && value.trim() !== '' ? value : null;

    return (
        <div className="min-w-0 rounded-md border bg-muted/20 p-3">
            <p className="mb-2 text-xs font-semibold text-muted-foreground uppercase">{title}</p>
            {text ? (
                <div className="max-h-56 overflow-auto text-xs leading-relaxed break-words whitespace-pre-wrap text-foreground">{text}</div>
            ) : (
                <p className="text-xs text-muted-foreground">No text captured.</p>
            )}
        </div>
    );
}

function DescriptionSegmentationSuggestionCard({
    suggestion,
    onAccept,
    onDecline,
    isProcessing,
}: {
    suggestion: SuggestedDescriptionSegmentationItem;
    onAccept: (id: number) => void;
    onDecline: (id: number) => void;
    isProcessing: boolean;
}) {
    const metadata = suggestion.metadata;
    const current = metadata?.current;
    const proposed = metadata?.proposed;
    const confidence = metadata?.confidence;
    const confidenceLevel = metadataText(confidence?.level);
    const confidenceScore = typeof confidence?.score === 'number' ? confidence.score : suggestion.similarity_score;
    const percent = typeof confidenceScore === 'number' ? Math.round(confidenceScore * 100) : null;
    const segments = Array.isArray(proposed?.segments) ? proposed.segments : [];
    const targetTypes = metadataList(proposed?.target_types);
    const targetTypeLabels = targetTypes.map((type) => descriptionTypeLabel(type) ?? type);
    const suggestedLabel = targetTypeLabels.length > 0 ? `Split Abstract into ${targetTypeLabels.join(', ')}` : suggestion.suggested_label;
    const confidenceEvidence = metadataList(confidence?.evidence);
    const preconditions = metadataList(metadata?.acceptance?.preconditions);

    return (
        <div className="bg-card p-2 sm:p-3">
            <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div className="min-w-0 flex-1 space-y-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant="outline" className="text-xs">
                            description #{suggestion.target_id}
                        </Badge>
                        {confidenceLevel && (
                            <Badge className={`text-xs ${confidenceBadgeColor(confidenceLevel)}`}>{confidenceLabel(confidenceLevel)}</Badge>
                        )}
                        {percent !== null && (
                            <Badge variant="secondary" className="text-xs">
                                {percent}% structural confidence
                            </Badge>
                        )}
                        {targetTypes.length > 0 && (
                            <Badge variant="secondary" className="text-xs">
                                {targetTypeLabels.join(', ')}
                            </Badge>
                        )}
                    </div>

                    <div className="space-y-1">
                        <p className="text-sm font-medium text-foreground">{suggestedLabel}</p>
                        <p className="font-mono text-xs break-words text-muted-foreground">{suggestion.suggested_value}</p>
                    </div>

                    <div className="grid gap-3 xl:grid-cols-2">
                        <DescriptionPreviewBlock title="Current Abstract" value={current?.value} />
                        <DescriptionPreviewBlock title="Proposed Abstract" value={proposed?.remaining_abstract} />
                    </div>

                    <div className="space-y-2">
                        <p className="text-xs font-semibold text-muted-foreground uppercase">New Description segments</p>
                        {segments.length > 0 ? (
                            <div className="grid gap-2 xl:grid-cols-2">
                                {segments.map((segment, index) => {
                                    const segmentType = descriptionTypeLabel(metadataText(segment.description_type)) ?? `Segment ${index + 1}`;
                                    const segmentConfidence = metadataText(segment.confidence);
                                    const evidenceTypes = metadataList(segment.evidence_types);
                                    const evidenceLabel = metadataText(segment.evidence_label);

                                    return (
                                        <div key={`${segmentType}-${index}`} className="min-w-0 rounded-md border bg-muted/20 p-3">
                                            <div className="mb-2 flex flex-wrap items-center gap-2">
                                                <Badge variant="outline" className="text-xs">
                                                    {segmentType}
                                                </Badge>
                                                {segmentConfidence && (
                                                    <Badge className={`text-xs ${confidenceBadgeColor(segmentConfidence)}`}>
                                                        {confidenceLabel(segmentConfidence)}
                                                    </Badge>
                                                )}
                                            </div>
                                            <div className="max-h-48 overflow-auto text-xs leading-relaxed break-words whitespace-pre-wrap text-foreground">
                                                {metadataText(segment.value) ?? 'No text captured.'}
                                            </div>
                                            {(evidenceLabel || evidenceTypes.length > 0) && (
                                                <p className="mt-2 text-xs text-muted-foreground">
                                                    {evidenceLabel && <span>Evidence: {evidenceLabel}</span>}
                                                    {evidenceTypes.length > 0 && <span> ({evidenceTypes.join(', ')})</span>}
                                                </p>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        ) : (
                            <div className="rounded-md border bg-muted/20 p-3 text-xs text-muted-foreground">No segment preview captured.</div>
                        )}
                    </div>

                    <div className="rounded-md border border-blue-200 bg-blue-50 p-2 text-xs text-blue-900 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-100">
                        Accept replaces only the source Abstract text and creates the listed Description segments.
                        {preconditions.length > 0 && <span> Preconditions: {preconditions.join(', ')}.</span>}
                    </div>

                    <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                        {metadata?.policy_version && <span>Policy: {metadata.policy_version}</span>}
                        {confidenceEvidence.length > 0 && <span>Evidence: {confidenceEvidence.join(', ')}</span>}
                        {current?.language && <span>Language: {current.language}</span>}
                        <span>Discovered: {suggestion.discovered_at ? new Date(suggestion.discovered_at).toLocaleDateString() : '-'}</span>
                    </div>
                </div>

                <div className="flex shrink-0 gap-2 self-start">
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={isProcessing}
                        data-testid={`description-segmentation-decline-${suggestion.id}`}
                        onClick={() => onDecline(suggestion.id)}
                    >
                        <X className="mr-1 h-4 w-4" />
                        Decline
                    </Button>
                    <Button
                        size="sm"
                        disabled={isProcessing}
                        data-testid={`description-segmentation-accept-${suggestion.id}`}
                        onClick={() => onAccept(suggestion.id)}
                    >
                        <Check className="mr-1 h-4 w-4" />
                        Accept
                    </Button>
                </div>
            </div>
        </div>
    );
}
interface SectionState {
    isChecking: boolean;
    progress: string;
    processingIds: Set<number>;
}

function useSectionState(manifests: AssistantManifest[]) {
    const defaultState = (): SectionState => ({ isChecking: false, progress: '', processingIds: new Set() });

    const [states, setStates] = useState<Record<string, SectionState>>(() => {
        const initial: Record<string, SectionState> = {};
        for (const m of manifests) {
            initial[m.id] = defaultState();
        }
        return initial;
    });

    // Sync states when manifests change (assistant added/removed after Inertia reload)
    useEffect(() => {
        setStates((prev) => {
            const manifestIds = new Set(manifests.map((m) => m.id));
            const next: Record<string, SectionState> = {};
            for (const m of manifests) {
                next[m.id] = prev[m.id] ?? defaultState();
            }
            // Only update if IDs actually changed
            const prevIds = new Set(Object.keys(prev));
            if (prevIds.size === manifestIds.size && [...manifestIds].every((id) => prevIds.has(id))) {
                return prev;
            }
            return next;
        });
    }, [manifests]);

    const pollingRefs = useRef<Record<string, ReturnType<typeof setTimeout> | null>>({});

    const patch = useCallback((id: string, update: Partial<SectionState>) => {
        setStates((prev) => {
            const current = prev[id] ?? defaultState();
            return { ...prev, [id]: { ...current, ...update } };
        });
    }, []);

    const addProcessingId = useCallback((sectionId: string, suggestionId: number) => {
        setStates((prev) => {
            const current = prev[sectionId] ?? defaultState();
            const next = new Set(current.processingIds);
            next.add(suggestionId);
            return { ...prev, [sectionId]: { ...current, processingIds: next } };
        });
    }, []);

    const removeProcessingId = useCallback((sectionId: string, suggestionId: number) => {
        setStates((prev) => {
            const current = prev[sectionId] ?? defaultState();
            const next = new Set(current.processingIds);
            next.delete(suggestionId);
            return { ...prev, [sectionId]: { ...current, processingIds: next } };
        });
    }, []);

    // Cleanup all polling on unmount
    useEffect(() => {
        const refs = pollingRefs.current;
        return () => {
            for (const timer of Object.values(refs)) {
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
    const [pendingRorBulkMatch, setPendingRorBulkMatch] = useState<RorAffiliationBulkMatch | null>(null);
    const [isAcceptingRorBulkMatch, setIsAcceptingRorBulkMatch] = useState(false);

    const reloadAssistanceSections = useCallback(() => {
        router.reload({ only: ['sections', 'pendingAssistanceTotalCount'] });
    }, []);
    // ── Polling logic ────────────────────────────────────────────────

    const stopPolling = useCallback(
        (id: string) => {
            const timer = pollingRefs.current[id];
            if (timer !== null) {
                clearTimeout(timer);
                pollingRefs.current[id] = null;
            }
        },
        [pollingRefs],
    );

    const startPolling = useCallback(
        (manifest: AssistantManifest, jobId: string) => {
            const id = manifest.id;

            const pollStatus = async () => {
                try {
                    const { data: status } = await axios.get<CheckStatusResponse>(`/assistance/check/${id}/${jobId}/status`);
                    patch(id, { progress: status.progress ?? '' });

                    if (status.status === 'completed') {
                        pollingRefs.current[id] = null;
                        patch(id, { isChecking: false, progress: '' });

                        // Pick correct label and interpolate {count}
                        const found =
                            (status as Record<string, unknown>).newSuggestionsFound ??
                            (status as Record<string, unknown>).newRelationsFound ??
                            (status as Record<string, unknown>).newOrcidsFound ??
                            (status as Record<string, unknown>).newRorsFound ??
                            0;
                        const count = Number(found);
                        const label =
                            count > 0
                                ? (manifest.statusLabels.completed_with_results ?? `${manifest.name} completed: {count} new suggestion(s) found.`)
                                : (manifest.statusLabels.completed_empty ?? `${manifest.name} completed: No new suggestions found.`);
                        const message = label.replace('{count}', String(count));

                        if (count > 0) {
                            toast.success(message);
                        } else {
                            toast.info(message);
                        }
                        reloadAssistanceSections();
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
        [patch, pollingRefs, reloadAssistanceSections],
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
                    toast.warning(error.response.data?.error ?? manifest.statusLabels.already_running ?? 'Already running.');
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
                const responseData = error.response.data as Record<string, string> | undefined;
                // Show per-assistant error messages if available
                let shownPerAssistant = false;
                if (responseData) {
                    for (const m of manifests) {
                        const perError = responseData[`${m.id}Error`];
                        if (perError) {
                            toast.warning(`${m.name}: ${perError}`);
                            shownPerAssistant = true;
                        }
                    }
                }
                if (!shownPerAssistant) {
                    toast.warning(responseData?.error ?? 'All discovery jobs are already running.');
                }
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
                const { data } = await axios.post<AcceptResponse>(`/assistance/${manifest.routePrefix}/${suggestionId}/accept`);

                if (data.success) {
                    toast.success(data.message);
                } else {
                    toast.warning(data.message);
                }

                const bulkMatch = data.bulk_affiliation_match;
                if (data.success && manifest.id === 'ror-suggestion' && bulkMatch?.available === true && bulkMatch.count > 0) {
                    setPendingRorBulkMatch(bulkMatch);
                    return;
                }

                reloadAssistanceSections();
            } catch {
                toast.error('Failed to accept suggestion.');
            } finally {
                removeProcessingId(manifest.id, suggestionId);
            }
        },
        [addProcessingId, reloadAssistanceSections, removeProcessingId],
    );

    const handleAcceptRorBulkMatch = useCallback(async () => {
        if (pendingRorBulkMatch === null) return;

        setIsAcceptingRorBulkMatch(true);

        try {
            const { data } = await axios.post<BulkRorAffiliationAcceptResponse>('/assistance/rors/bulk-affiliation-accept', {
                bulk_token: pendingRorBulkMatch.bulk_token,
            });

            if (data.success) {
                toast.success(data.message);
            } else {
                toast.warning(data.message);
            }

            setPendingRorBulkMatch(null);
            reloadAssistanceSections();
        } catch (error) {
            const isAxiosBulkAcceptError = axios.isAxiosError(error);

            if (isAxiosBulkAcceptError && typeof error.response?.data?.message === 'string') {
                toast.warning(error.response.data.message);
            } else {
                toast.error('Failed to accept matching ROR suggestions.');
            }

            if (isAxiosBulkAcceptError && error.response?.status === 422) {
                setPendingRorBulkMatch(null);
                reloadAssistanceSections();
            }
        } finally {
            setIsAcceptingRorBulkMatch(false);
        }
    }, [pendingRorBulkMatch, reloadAssistanceSections]);

    const handleDeclineRorBulkMatch = useCallback(() => {
        if (isAcceptingRorBulkMatch) return;

        setPendingRorBulkMatch(null);
        reloadAssistanceSections();
    }, [isAcceptingRorBulkMatch, reloadAssistanceSections]);

    const handleDecline = useCallback(
        async (manifest: AssistantManifest, suggestionId: number) => {
            addProcessingId(manifest.id, suggestionId);

            try {
                await axios.post(`/assistance/${manifest.routePrefix}/${suggestionId}/decline`);
                toast.info('Suggestion declined.');
                reloadAssistanceSections();
            } catch {
                toast.error('Failed to decline suggestion.');
            } finally {
                removeProcessingId(manifest.id, suggestionId);
            }
        },
        [addProcessingId, reloadAssistanceSections, removeProcessingId],
    );

    // ── Render helpers ───────────────────────────────────────────────

    function renderCard(manifest: AssistantManifest, item: BaseSuggestionItem, isProcessing: boolean) {
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
            case 'size-format-suggestion':
                return <SizeFormatSuggestionCard suggestion={item} onAccept={onAccept} onDecline={onDecline} isProcessing={isProcessing} />;
                case 'date-type-suggestion':
                return (
                    <DateTypeSuggestionCard
                        suggestion={item}
                        onAccept={onAccept}
                        onDecline={onDecline}
                        isProcessing={isProcessing}
                    />
                );
            case 'description-segmentation':
                return (
                    <DescriptionSegmentationSuggestionCard
                        suggestion={item as unknown as SuggestedDescriptionSegmentationItem}
                        onAccept={onAccept}
                        onDecline={onDecline}
                        isProcessing={isProcessing}
                    />
                );
            case 'spdx-license-suggestion':
                return (
                    <SpdxRightsSuggestionCard
                        suggestion={item as unknown as SuggestedSpdxRightsItem}
                        onAccept={onAccept}
                        onDecline={onDecline}
                        isProcessing={isProcessing}
                    />
                );
            case 'crossref-funder-ror-suggestion':
                return (
                    <CrossrefFunderRorSuggestionCard
                        suggestion={item as unknown as SuggestedCrossrefFunderRorItem}
                        onAccept={onAccept}
                        onDecline={onDecline}
                        isProcessing={isProcessing}
                    />
                );
            case 'subject-metadata-enrichment':
                return (
                    <SubjectMetadataEnrichmentCard
                        suggestion={item as unknown as SuggestedSubjectMetadataEnrichmentItem}
                        onAccept={onAccept}
                        onDecline={onDecline}
                        isProcessing={isProcessing}
                    />
                );
            default: 
                // Generic card for future student modules
                return (
                    <div className="bg-card p-2 sm:p-3">
                        <div className="flex items-start justify-between gap-4">
                            <div className="min-w-0 flex-1 space-y-1">
                                <p className="text-sm font-medium">{String(item.suggested_label ?? item.suggested_value ?? 'Suggestion')}</p>
                                <p className="text-xs text-muted-foreground">
                                    Discovered: {item.discovered_at ? new Date(item.discovered_at).toLocaleDateString() : '—'}
                                </p>
                            </div>
                            <div className="flex shrink-0 gap-2">
                                <Button variant="outline" size="sm" disabled={isProcessing} onClick={() => onDecline(item.id)}>
                                    <X className="mr-1 h-4 w-4" />
                                    Decline
                                </Button>
                                <Button size="sm" disabled={isProcessing} onClick={() => onAccept(item.id)}>
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

            <div className="w-full space-y-6 p-6">
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
                        <div
                            key={`progress-${manifest.id}`}
                            className="flex items-center gap-2 rounded-lg border bg-muted/50 p-3 text-sm text-muted-foreground"
                        >
                            <Spinner size="sm" />
                            <span>{state.progress}</span>
                        </div>
                    );
                })}

                {/* Section cards — one per assistant, ordered by sortOrder */}
                {manifests.map((manifest) => {
                    const sectionData = sections[manifest.id] as PaginatedData<BaseSuggestionItem> | undefined;
                    const state = states[manifest.id];

                    if (!sectionData) return null;

                    // Group items by resource
                    const grouped = sectionData.data.reduce<
                        Record<number, { resourceId: number; doi: string; title: string; items: BaseSuggestionItem[] }>
                    >((groups, item) => {
                        const resourceId = item.resource_id;
                        const itemDoi = normalizedResourceHeaderValue(item.resource_doi);
                        const itemTitle = normalizedResourceHeaderValue(item.resource_title);

                        if (!groups[resourceId]) {
                            groups[resourceId] = {
                                resourceId,
                                doi: itemDoi,
                                title: itemTitle,
                                items: [],
                            };
                        } else {
                            groups[resourceId].doi = firstNonEmptyResourceHeaderValue(groups[resourceId].doi, itemDoi);
                            groups[resourceId].title = firstNonEmptyResourceHeaderValue(groups[resourceId].title, itemTitle);
                        }

                        groups[resourceId].items.push(item);
                        return groups;
                    }, {});

                    return (
                        <Card key={manifest.id}>
                            <CardHeader className="flex flex-col gap-4 space-y-0 sm:flex-row sm:items-center sm:justify-between">
                                <div className="min-w-0 space-y-1.5">
                                    <CardTitle className="break-words">{manifest.name}</CardTitle>
                                    <CardDescription>
                                        {sectionData.total > 0
                                            ? `${sectionData.total} pending suggestion(s). ${manifest.description}`
                                            : manifest.emptyState.description}
                                    </CardDescription>
                                </div>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="self-start sm:self-auto"
                                    aria-label={`Check ${manifest.name}`}
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
                                    <div className="space-y-4">
                                        {Object.entries(grouped).map(([resourceKey, group]) => {
                                            const resourceLabel = group.doi === '' ? `Resource #${group.resourceId}` : group.doi;
                                            const resourceTitle = group.title === '' ? 'Untitled' : group.title;

                                            return (
                                                <Card key={resourceKey} data-testid={`resource-card-${manifest.id}-${group.resourceId}`}>
                                                    <CardHeader className="flex flex-row items-start justify-between gap-4 space-y-0 border-b bg-muted/30 py-4">
                                                        <div className="min-w-0 space-y-1">
                                                            <CardTitle className="text-base">
                                                                <Link
                                                                    href={resourceEditorUrl(group.resourceId)}
                                                                    className="font-mono break-all text-primary underline underline-offset-4 hover:text-primary/80 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                                                                    title={`Open ${resourceLabel} in editor`}
                                                                >
                                                                    {resourceLabel}
                                                                </Link>
                                                            </CardTitle>
                                                            <CardDescription>{resourceTitle}</CardDescription>
                                                        </div>
                                                        <Badge variant="secondary" className="shrink-0 text-xs">
                                                            {group.items.length} suggestion(s)
                                                        </Badge>
                                                    </CardHeader>
                                                    <CardContent className="p-0">
                                                        <ul
                                                            aria-label={`Suggestions from ${manifest.name} for ${resourceLabel}`}
                                                            className="divide-y"
                                                        >
                                                            {group.items.map((item) => (
                                                                <li key={item.id as number} className="p-2 sm:p-3">
                                                                    {renderCard(manifest, item, state?.processingIds.has(item.id as number) ?? false)}
                                                                </li>
                                                            ))}
                                                        </ul>
                                                    </CardContent>
                                                </Card>
                                            );
                                        })}
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

            <Dialog
                open={pendingRorBulkMatch !== null}
                onOpenChange={(open) => {
                    if (!open) handleDeclineRorBulkMatch();
                }}
            >
                <DialogContent showCloseButton={!isAcceptingRorBulkMatch}>
                    <DialogHeader>
                        <DialogTitle>Accept matching ROR suggestions?</DialogTitle>
                        <DialogDescription>{rorBulkMatchDialogDescription(pendingRorBulkMatch?.count ?? 0)}</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" disabled={isAcceptingRorBulkMatch} onClick={handleDeclineRorBulkMatch}>
                            Decline
                        </Button>
                        <LoadingButton loading={isAcceptingRorBulkMatch} onClick={handleAcceptRorBulkMatch}>
                            Accept
                        </LoadingButton>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
