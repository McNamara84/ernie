export interface ContactSelection {
    key: string;
    name: string | null;
    email: string | null;
    orcid: string | null;
}

export interface ContactVariant {
    key: string;
    name: string;
    email: string | null;
    resource_count: number;
    igsn_count: number;
}

export interface ContactGroup {
    key: string;
    orcid: string | null;
    variants: ContactVariant[];
}

export interface ContactPersonsPageProps {
    groups: ContactGroup[];
    search: string;
    pagination: { current_page: number; last_page: number; per_page: number; total: number };
}

export function contactSelectionLabel(selection?: ContactSelection | null): string {
    if (!selection?.name) return 'Contact selection no longer available';
    return `Contact: ${selection.name} · ${selection.email || 'No email recorded'}${selection.orcid ? ` · ${selection.orcid}` : ''}`;
}
