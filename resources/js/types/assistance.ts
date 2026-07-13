export interface SuggestedRelationItem {
    id: number;
    resource_id: number;
    resource_doi: string;
    resource_title: string;
    identifier: string;
    identifier_type: string;
    identifier_type_name: string;
    relation_type: string;
    relation_type_name: string;
    source: 'scholexplorer' | 'datacite_event_data';
    source_title: string | null;
    source_type: string | null;
    source_publisher: string | null;
    source_publication_date: string | null;
    discovered_at: string;
}

export interface PaginatedData<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: { url: string | null; label: string; active: boolean }[];
}

export interface AssistantManifest {
    id: string;
    name: string;
    description: string;
    icon: string;
    version: string;
    routePrefix: string;
    sortOrder: number;
    statusLabels: Record<string, string>;
    emptyState: { title: string; description: string };
    cardComponent: string | null;
}

/** Base fields shared by all suggestion types (including generic assistant_suggestions). */
export interface BaseSuggestionItem {
    id: number;
    resource_id: number;
    resource_doi: string;
    resource_title: string;
    discovered_at: string;
    [key: string]: unknown;
}

export interface SuggestedLanguageItem extends BaseSuggestionItem {
    suggested_value: string;
    suggested_label: string;
    similarity_score: number | null;
}

export interface SpdxRightsSuggestionMetadata {
    current?: Record<string, string>;
    proposed?: Record<string, string>;
    source?: string;
    source_url?: string;
    evidence?: {
        matched_from?: string;
        reason?: string;
    };
}

export interface SuggestedSpdxRightsItem extends BaseSuggestionItem {
    target_type: 'resource_right';
    target_id: number;
    suggested_value: string;
    suggested_label: string;
    similarity_score: number | null;
    metadata: SpdxRightsSuggestionMetadata | null;
}

export interface CrossrefFunderRorCurrentMetadata {
    funding_reference_id?: number;
    resource_id?: number;
    funder_name?: string;
    funder_identifier?: string;
    funder_identifier_type?: string;
    scheme_uri?: string;
    normalized_crossref_funder_id?: string;
    canonical_crossref_funder_identifier?: string;
    award_number?: string | null;
    award_uri?: string | null;
    award_title?: string | null;
}

export interface CrossrefFunderRorProposedMetadata {
    funder_identifier?: string;
    funder_identifier_type?: string;
    scheme_uri?: string;
    ror_id?: string;
    ror_display_name?: string;
    ror_status?: string;
    ror_types?: string[];
    ror_record_last_modified?: string | null;
    matched_external_id?: {
        type?: string;
        value?: string;
        matched_in?: string;
        preferred?: string | null;
    };
}

export interface CrossrefFunderRorSuggestionMetadata {
    current?: CrossrefFunderRorCurrentMetadata;
    proposed?: CrossrefFunderRorProposedMetadata;
    provenance?: {
        source?: string;
        source_file?: string;
        source_retrieved_at?: string;
        matching_strategy?: string;
        source_generated_by?: string;
        source_generated_from?: string;
    };
    confidence?: {
        level?: string;
        score?: number;
        evidence?: string[];
    };
    ambiguity?: {
        status?: string;
        candidate_count?: number;
        notes?: string[];
        warnings?: string[];
    };
    acceptance?: {
        updates?: Record<string, string>;
        preserve?: string[];
        preconditions?: string[];
    };
}

export interface SuggestedCrossrefFunderRorItem extends BaseSuggestionItem {
    target_type: 'funding_reference';
    target_id: number;
    suggested_value: string;
    suggested_label: string;
    similarity_score: number | null;
    metadata: CrossrefFunderRorSuggestionMetadata | null;
}
export interface SubjectEnrichmentCurrentMetadata {
    subject_id?: number;
    resource_id?: number;
    value?: string | null;
    subject_scheme?: string | null;
    normalized_subject_scheme?: string | null;
    scheme_uri?: string | null;
    value_uri?: string | null;
    classification_code?: string | null;
    breadcrumb_path?: string | null;
    language?: string | null;
    is_controlled?: boolean;
}

export interface SubjectEnrichmentProposedMetadata {
    subject_scheme?: string | null;
    scheme_uri?: string | null;
    value_uri?: string | null;
    classification_code?: string | null;
    breadcrumb_path?: string | null;
    label?: string | null;
    language?: string | null;
    updates?: Record<string, string>;
    preserve?: string[];
    concept?: Record<string, unknown>;
}

export interface SubjectMetadataEnrichmentSuggestionMetadata {
    contract_version?: string;
    issue?: number;
    current?: SubjectEnrichmentCurrentMetadata;
    proposed?: SubjectEnrichmentProposedMetadata;
    vocabulary?: {
        scheme?: string;
        scheme_uri?: string;
        source?: string;
        source_registry_url?: string;
        local_cache_file?: string;
        local_cache_updated_at?: string | null;
        version?: string | null;
    };
    match?: {
        strategy?: string;
        input?: string | null;
        normalized_input?: string | null;
        matched_fields?: string[];
        candidate_count?: number;
        suppression_reason?: string | null;
        path_normalization_applied?: string | null;
    };
    provenance?: {
        source?: string;
        source_file?: string;
        source_retrieved_at?: string;
        source_generated_by?: string;
        matching_strategy?: string;
        path_normalization_applied?: string | null;
    };
    confidence?: {
        level?: string;
        score?: number;
        evidence?: string[];
    };
    ambiguity?: {
        status?: string;
        candidate_count?: number;
        candidate_ids?: string[];
        notes?: string[];
        warnings?: string[];
        warning_messages?: string[] | Record<string, string>;
    };
    acceptance?: {
        updates?: string[];
        preconditions?: string[];
        stale_if?: string[];
        implementation_issue?: number;
    };
}

export interface SuggestedSubjectMetadataEnrichmentItem extends BaseSuggestionItem {
    target_type: 'subject';
    target_id: number;
    suggested_value: string;
    suggested_label: string;
    similarity_score: number | null;
    metadata: SubjectMetadataEnrichmentSuggestionMetadata | null;
}
export interface DescriptionSegmentationCurrentMetadata {
    description_id?: number;
    resource_id?: number;
    description_type?: string;
    value?: string;
    value_hash?: string;
    language?: string | null;
}

export interface DescriptionSegmentationSegmentMetadata {
    description_type?: string;
    value?: string;
    language?: string | null;
    confidence?: string | null;
    confidence_score?: number | null;
    evidence_label?: string | null;
    evidence_types?: string[];
    source_ranges?: Array<{ start?: number; end?: number }>;
}

export interface DescriptionSegmentationSuggestionMetadata {
    contract_version?: string;
    issue?: number;
    policy_version?: string;
    current?: DescriptionSegmentationCurrentMetadata;
    proposed?: {
        remaining_abstract?: string;
        segments?: DescriptionSegmentationSegmentMetadata[];
        target_types?: string[];
    };
    confidence?: {
        level?: string;
        score?: number;
        evidence?: string[];
    };
    acceptance?: {
        updates?: {
            source_description?: string;
            new_descriptions?: string[];
        };
        preconditions?: string[];
        stale_if?: string[];
    };
}

export interface SuggestedDescriptionSegmentationItem extends BaseSuggestionItem {
    target_type: 'description';
    target_id: number;
    suggested_value: string;
    suggested_label: string;
    similarity_score: number | null;
    metadata: DescriptionSegmentationSuggestionMetadata | null;
}
export interface AssistancePageProps {
    sections: Record<string, PaginatedData<BaseSuggestionItem>>;
    manifests: AssistantManifest[];
}

export interface SuggestedOrcidItem {
    id: number;
    resource_id: number;
    resource_doi: string;
    resource_title: string;
    person_id: number;
    person_name: string;
    person_affiliations: string[];
    source_context: 'creator' | 'contributor';
    suggested_orcid: string;
    similarity_score: number;
    candidate_first_name: string;
    candidate_last_name: string;
    candidate_affiliations: string[];
    discovered_at: string;
}

export interface CheckStatusResponse {
    status: 'queued' | 'running' | 'completed' | 'failed' | 'unknown';
    progress?: string;
    error?: string;
    startedAt?: string;
    completedAt?: string;
    [key: string]: unknown;
}

export interface AcceptResponse {
    success: boolean;
    datacite_synced?: boolean;
    synced_dois?: string[];
    replaced_identifier?: string | null;
    bulk_affiliation_match?: RorAffiliationBulkMatch | null;
    message: string;
}

export interface RorAffiliationBulkMatch {
    available: boolean;
    count: number;
    bulk_token: string;
    creator_name: string;
    affiliation: string;
    suggested_ror_id: string;
}

export interface BulkRorAffiliationAcceptResponse {
    success: boolean;
    accepted_count: number;
    skipped_count: number;
    synced_dois: string[];
    message: string;
}

export interface SuggestedRorItem {
    id: number;
    resource_id: number;
    resource_doi: string;
    resource_title: string;
    entity_type: 'affiliation' | 'institution' | 'funder';
    entity_id: number;
    entity_name: string;
    person_name: string | null;
    suggested_ror_id: string;
    suggested_name: string;
    similarity_score: number;
    ror_aliases: string[];
    locations?: string[];
    existing_identifier: string | null;
    existing_identifier_type: string | null;
    discovered_at: string;
}
