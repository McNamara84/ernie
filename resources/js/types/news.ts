export interface PublicNews {
    id: number;
    title: string;
    html: string;
}

export interface ManagedNews {
    id: number;
    title: string;
    body_markdown: string;
    kind: 'regular' | 'temporary';
    is_scheduled: boolean;
    starts_at: string | null;
    ends_at: string | null;
    archived_at: string | null;
    revision: number;
    status: string;
    updated_at: string | null;
}

export interface NewsManagementProps {
    news: ManagedNews[];
    active_news_id: number;
    state_revision: number;
    currently_displayed: PublicNews;
}
