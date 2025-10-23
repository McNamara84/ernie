export interface LandingPageConfig {
    id: number;
    resource_id: number;
    template: string;
    ftp_url?: string | null;
    status: 'draft' | 'published';
    preview_token?: string | null;
    published_at?: string | null;
    view_count: number;
    last_viewed_at?: string | null;
    created_at: string;
    updated_at: string;
    public_url: string;
    preview_url: string;
}

export interface LandingPageTemplateOption {
    value: string;
    label: string;
    description: string;
}

export const LANDING_PAGE_TEMPLATES: LandingPageTemplateOption[] = [
    {
        value: 'default_gfz',
        label: 'Default GFZ Data Services',
        description: 'Classic GFZ Data Services landing page design',
    },
    // Future templates can be added here
];
