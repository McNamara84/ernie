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
