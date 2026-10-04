import type { BaseSuggestionItem, SuggestionAcceptanceInput } from '@/types/assistance';

export interface RelationCorrectionMetadata {
    current: { identifier: string; relation_type: string; relation_type_name: string; citation_label: string | null };
    proposed: { id: number; slug: string; name: string };
    rule: { rationale: string };
    confidence: { level: string; basis: string };
    evidence: {
        provider: string;
        claimant: string;
        subject: string;
        object: string;
        original_relation: string;
        relation: string;
        source_url: string;
        fetched_at: string;
        primary: boolean;
    }[];
    review_fingerprint: string;
}

export function relationCorrectionMetadata(item: BaseSuggestionItem): RelationCorrectionMetadata | null {
    const value = item.metadata;
    if (!value || typeof value !== 'object') return null;
    const metadata = value as Partial<RelationCorrectionMetadata>;
    if (
        !metadata.current ||
        typeof metadata.current.identifier !== 'string' ||
        typeof metadata.current.relation_type !== 'string' ||
        typeof metadata.current.relation_type_name !== 'string' ||
        (metadata.current.citation_label !== null && typeof metadata.current.citation_label !== 'string') ||
        !metadata.proposed ||
        typeof metadata.proposed.id !== 'number' ||
        typeof metadata.proposed.slug !== 'string' ||
        typeof metadata.proposed.name !== 'string' ||
        !metadata.rule ||
        typeof metadata.rule.rationale !== 'string' ||
        !metadata.confidence ||
        metadata.confidence.level !== 'high' ||
        typeof metadata.confidence.basis !== 'string' ||
        !Array.isArray(metadata.evidence) ||
        !metadata.evidence.every(
            (claim) =>
                claim &&
                typeof claim === 'object' &&
                typeof claim.primary === 'boolean' &&
                ['provider', 'claimant', 'subject', 'object', 'original_relation', 'relation', 'source_url', 'fetched_at'].every(
                    (key) => typeof (claim as unknown as Record<string, unknown>)[key] === 'string',
                ),
        ) ||
        typeof metadata.review_fingerprint !== 'string' ||
        !/^[a-f0-9]{64}$/.test(metadata.review_fingerprint)
    )
        return null;
    return metadata as RelationCorrectionMetadata;
}

export function isRelationCorrectionReady(item: BaseSuggestionItem): boolean {
    return (item.review?.assistant_id ?? item.assistant_id) !== 'relation-type-correction' || relationCorrectionMetadata(item) !== null;
}

export function relationCorrectionInput(item: BaseSuggestionItem): SuggestionAcceptanceInput {
    if ((item.review?.assistant_id ?? item.assistant_id) !== 'relation-type-correction') return {};
    const metadata = relationCorrectionMetadata(item);
    return metadata ? { relation_type_correction_fingerprint: metadata.review_fingerprint } : {};
}
