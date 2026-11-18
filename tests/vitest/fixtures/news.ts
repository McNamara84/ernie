import type { NewsManagementProps, PublicNews } from '@/types/news';

export const initialNews: PublicNews = {
    id: 1,
    title: 'Welcome ELMO - our new Metadata Editor!',
    html: '<p>In November 2025, the GFZ Data Services team proudly launched the new, fully revised and modernised version of our <a href="https://dataservices.gfz.de/elmo">new metadata editor ELMO</a>. ELMO is not only a new web interface, but also contains many revised functionalities that increase the quality of metadata and the FAIRness of the data describing it, while at the same time simplifying the entry of information for researchers.</p>',
};

const regular = { kind: 'regular' as const, is_scheduled: false, starts_at: null, ends_at: null, archived_at: null, revision: 1, updated_at: null };
const temporary = { ...regular, kind: 'temporary' as const, starts_at: '2030-07-15T12:00:00+02:00', ends_at: '2030-07-15T13:00:00+02:00' };

export const newsManagementProps: NewsManagementProps = {
    active_news_id: 1,
    state_revision: 7,
    currently_displayed: { id: 1, title: 'Regular announcement', html: '<p>Regular content.</p>' },
    news: [
        { ...regular, id: 1, title: 'Regular announcement', body_markdown: 'Regular content.', revision: 2, status: 'Active regular' },
        { ...regular, id: 2, title: 'Draft announcement', body_markdown: 'Draft **content**.', status: 'Draft' },
        { ...temporary, id: 3, title: 'Maintenance', body_markdown: 'Maintenance text.', revision: 3, status: 'Draft' },
        { ...temporary, id: 4, title: 'Upcoming downtime', body_markdown: 'Downtime text.', is_scheduled: true, revision: 2, status: 'Scheduled' },
        {
            ...regular,
            id: 5,
            title: 'Archived announcement',
            body_markdown: 'Archived content.',
            archived_at: '2030-01-14T10:00:00+00:00',
            revision: 2,
            status: 'Archived',
        },
    ],
};
