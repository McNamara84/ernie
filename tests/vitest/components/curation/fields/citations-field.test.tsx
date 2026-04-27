import { render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { CitationsField } from '@/components/curation/fields/citations-field';

import { http, HttpResponse, server } from '../../../helpers/msw-server';

vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() } }));

describe('CitationsField', () => {
    it('renders a hint when no resource id is given', () => {
        render(<CitationsField resourceId={null} />);
        expect(
            screen.getByText(/Save the dataset first to manage its citations/i),
        ).toBeInTheDocument();
    });

    it('shows citation count and a Manage Citations button once the resource is saved', async () => {
        server.use(
            http.get('/related-items/vocabularies', () =>
                HttpResponse.json({
                    resourceTypes: [{ value: 'JournalArticle', label: 'Journal Article' }],
                    relationTypes: [{ id: 1, label: 'Cites' }],
                    contributorTypes: [],
                }),
            ),
            http.get('/resources/42/related-items', () =>
                HttpResponse.json({
                    data: [
                        {
                            id: 1,
                            resource_id: 42,
                            related_item_type: 'JournalArticle',
                            relation_type_id: 1,
                            relation_type_slug: 'Cites',
                            position: 0,
                            titles: [{ title: 'X', title_type: 'MainTitle', position: 0 }],
                            creators: [],
                            contributors: [],
                        },
                        {
                            id: 2,
                            resource_id: 42,
                            related_item_type: 'JournalArticle',
                            relation_type_id: 1,
                            relation_type_slug: 'Cites',
                            position: 1,
                            titles: [{ title: 'Y', title_type: 'MainTitle', position: 1 }],
                            creators: [],
                            contributors: [],
                        },
                    ],
                }),
            ),
        );

        render(<CitationsField resourceId={42} />);

        await waitFor(() =>
            expect(screen.getByText(/2 citations linked to this dataset/i)).toBeInTheDocument(),
        );
        expect(screen.getByTestId('open-citation-manager')).toBeEnabled();
    });
});
