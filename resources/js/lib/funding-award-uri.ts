import type { BaseSuggestionItem, SuggestionAcceptanceInput } from '@/types/assistance';

export interface AwardUriMetadata {
    current: { funder_name: string; funder_identifier: string | null; award_number: string; award_title: string | null; award_uri: string | null };
    live_current?: AwardUriMetadata['current'] | null;
    proposed: { award_uri: string };
    confidence: { level: string; evidence: string[] };
    ambiguity: { status: string; candidate_count: number };
    provenance: {
        provider: string;
        record_id: string;
        award_number: string;
        source_url: string;
        fetched_at: string;
        titles: string[];
        funder_ids: string[];
        programmes: string[];
        attribution: string;
        license_url: string;
    }[];
    review_fingerprint: string;
    review_status: string | null;
    expires_at: string;
}

export function awardUriMetadata(item: BaseSuggestionItem): AwardUriMetadata | null {
    const metadata = item.metadata as Partial<AwardUriMetadata> | undefined;
    if (
        !metadata ||
        typeof metadata !== 'object' ||
        !metadata.current ||
        typeof metadata.current.funder_name !== 'string' ||
        typeof metadata.current.award_number !== 'string' ||
        !metadata.proposed ||
        typeof metadata.proposed.award_uri !== 'string' ||
        !metadata.confidence ||
        metadata.confidence.level !== 'high' ||
        !Array.isArray(metadata.confidence.evidence) ||
        !metadata.confidence.evidence.every((code) => typeof code === 'string') ||
        metadata.ambiguity?.status !== 'none' ||
        metadata.ambiguity.candidate_count !== 1 ||
        typeof metadata.review_fingerprint !== 'string' ||
        !/^[a-f0-9]{64}$/.test(metadata.review_fingerprint) ||
        typeof metadata.expires_at !== 'string' ||
        (metadata.review_status !== null && typeof metadata.review_status !== 'string') ||
        !Array.isArray(metadata.provenance) ||
        metadata.provenance.length === 0 ||
        !metadata.provenance.every(
            (source) =>
                source &&
                ['provider', 'record_id', 'award_number', 'source_url', 'fetched_at', 'attribution', 'license_url'].every(
                    (key) => typeof source[key as keyof typeof source] === 'string',
                ) &&
                [source.titles, source.funder_ids, source.programmes].every(
                    (values) => Array.isArray(values) && values.every((value) => typeof value === 'string'),
                ),
        )
    )
        return null;
    return metadata as AwardUriMetadata;
}

export function awardUriInput(item: BaseSuggestionItem): SuggestionAcceptanceInput {
    if ((item.review?.assistant_id ?? item.assistant_id) !== 'funding-award-uri-enrichment') return {};
    const metadata = awardUriMetadata(item);
    return metadata ? { award_uri_review_fingerprint: metadata.review_fingerprint } : {};
}

export function isAwardUriReady(item: BaseSuggestionItem): boolean {
    return (item.review?.assistant_id ?? item.assistant_id) !== 'funding-award-uri-enrichment' || awardUriMetadata(item) !== null;
}
