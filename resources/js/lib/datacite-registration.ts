import type { PublishedRecordCounts } from '@/components/curation/types/datacite-form-types';

export interface DoiRegistrationResponse {
    success: boolean;
    message: string;
    doi: string | null;
    mode: 'test' | 'production';
    updated: boolean;
    publication?: DoiPublicationSummary | null;
    publishedRecordCounts?: PublishedRecordCounts;
    error?: string;
    details?: unknown;
}

export type OrcidPreflightReason = 'not_found' | 'checksum' | 'format' | 'network' | 'timeout' | 'api_error' | 'unknown';

export interface OrcidPreflightIssue {
    severity: 'blocking' | 'warning';
    reason: OrcidPreflightReason;
    role: 'creator' | 'contributor';
    position: number;
    orcid: string;
    displayName: string;
}

export interface OrcidPreflightPayload {
    error: 'orcid_validation_failed' | 'orcid_validation_warning';
    message?: string;
    invalid?: OrcidPreflightIssue[];
    warnings?: OrcidPreflightIssue[];
}

export interface DataCitePrefixConfig {
    test: string[];
    production: string[];
    test_mode: boolean;
}

const ORCID_REASON_LABELS: Record<OrcidPreflightReason, string> = {
    not_found: 'not found in ORCID registry',
    checksum: 'invalid ORCID checksum',
    format: 'malformed ORCID identifier',
    network: 'ORCID service unreachable',
    timeout: 'ORCID service timed out',
    api_error: 'ORCID service reported an error',
    unknown: 'ORCID verification failed for an unknown reason',
};

const KNOWN_ORCID_REASONS: ReadonlySet<string> = new Set(Object.keys(ORCID_REASON_LABELS));

export function describeOrcidReason(reason: string | undefined): string {
    if (reason !== undefined && KNOWN_ORCID_REASONS.has(reason)) {
        return ORCID_REASON_LABELS[reason as OrcidPreflightReason];
    }

    return ORCID_REASON_LABELS.unknown;
}

export function isOrcidPreflightPayload(data: unknown): data is OrcidPreflightPayload {
    if (!data || typeof data !== 'object') {
        return false;
    }

    const { error, invalid, warnings } = data as {
        error?: unknown;
        invalid?: unknown;
        warnings?: unknown;
    };

    if (error !== 'orcid_validation_failed' && error !== 'orcid_validation_warning') {
        return false;
    }

    return (invalid === undefined || Array.isArray(invalid)) && (warnings === undefined || Array.isArray(warnings));
}

export interface DoiPublicationSummary {
    uuid: string;
    doi: string | null;
    test_mode: boolean;
    status: string;
    last_error: string | null;
    completed_at: string | null;
}

export interface DataCiteActionStatus {
    state: 'unassigned' | 'missing' | 'draft' | 'registered' | 'findable' | 'unavailable';
    mode: 'test' | 'production';
    can_update: boolean;
    can_publish: boolean;
    publication: DoiPublicationSummary | null;
}

export function publicationNeedsAttention(publication?: DoiPublicationSummary | null): boolean {
    return Boolean(publication && !['succeeded', 'superseded'].includes(publication.status));
}
