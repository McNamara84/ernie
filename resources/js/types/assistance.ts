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

export interface AssistancePageProps {
    suggestions: PaginatedData<SuggestedRelationItem>;
    orcidSuggestions: PaginatedData<SuggestedOrcidItem>;
    rorSuggestions: PaginatedData<SuggestedRorItem>;
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
    totalDois?: number;
    processedDois?: number;
    newRelationsFound?: number;
    error?: string;
    startedAt?: string;
    completedAt?: string;
}

export interface AcceptResponse {
    success: boolean;
    datacite_synced: boolean;
    message: string;
}

export interface OrcidCheckStatusResponse {
    status: 'queued' | 'running' | 'completed' | 'failed' | 'unknown';
    progress?: string;
    totalPersons?: number;
    processedPersons?: number;
    newOrcidsFound?: number;
    error?: string;
    startedAt?: string;
    completedAt?: string;
}

export interface OrcidAcceptResponse {
    success: boolean;
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
    suggested_ror_id: string;
    suggested_name: string;
    similarity_score: number;
    ror_aliases: string[];
    existing_identifier: string | null;
    existing_identifier_type: string | null;
    discovered_at: string;
}

export interface RorCheckStatusResponse {
    status: 'queued' | 'running' | 'completed' | 'failed' | 'unknown';
    progress?: string;
    totalEntities?: number;
    processedEntities?: number;
    newRorsFound?: number;
    error?: string;
    startedAt?: string;
    completedAt?: string;
}

export interface RorAcceptResponse {
    success: boolean;
    synced_dois: string[];
    message: string;
    replaced_identifier: string | null;
}
