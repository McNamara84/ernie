import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { CitationsField } from '@/components/curation/fields/citations-field';

import { http, HttpResponse, server } from '../../../helpers/msw-server';

vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() } }));

vi.mock('@/components/citations/CitationManagerModal', () => ({
    CitationManagerModal: ({ open, resourceId }: { open: boolean; resourceId: number }) =>
        open ? <div data-testid="related-item-manager-modal">Related Item Manager for {resourceId}</div> : null,
}));

describe('CitationsField', () => {
    it('renders a hint when no resource id is given', () => {
        render(<CitationsField resourceId={null} />);
        expect(
            screen.getByText(/Save the dataset first to manage its related items/i),
        ).toBeInTheDocument();
    });

    it('shows related item count and a Manage Related Items button once the resource is saved', async () => {
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
            expect(screen.getByText(/2 related items linked to this dataset/i)).toBeInTheDocument(),
        );
        expect(screen.getByRole('button', { name: /Manage Related Items/i })).toBeEnabled();
        expect(screen.getByTestId('open-citation-manager')).toBeEnabled();
    });

    it('opens the Related Item Manager modal from the action button', async () => {
        const user = userEvent.setup();
        server.use(
            http.get('/related-items/vocabularies', () =>
                HttpResponse.json({
                    resourceTypes: [{ value: 'JournalArticle', label: 'Journal Article' }],
                    relationTypes: [{ id: 1, label: 'Cites' }],
                    contributorTypes: [],
                }),
            ),
            http.get('/resources/44/related-items', () => HttpResponse.json({ data: [] })),
        );

        render(<CitationsField resourceId={44} />);

        const manageButton = await screen.findByRole('button', { name: /Manage Related Items/i });

        await waitFor(() => expect(manageButton).toBeEnabled());
        await user.click(manageButton);

        expect(screen.getByTestId('related-item-manager-modal')).toHaveTextContent('Related Item Manager for 44');
    });

    it('uses singular count copy when exactly one related item is linked', async () => {
        server.use(
            http.get('/related-items/vocabularies', () =>
                HttpResponse.json({
                    resourceTypes: [{ value: 'JournalArticle', label: 'Journal Article' }],
                    relationTypes: [{ id: 1, label: 'Cites' }],
                    contributorTypes: [],
                }),
            ),
            http.get('/resources/43/related-items', () =>
                HttpResponse.json({
                    data: [
                        {
                            id: 1,
                            resource_id: 43,
                            related_item_type: 'JournalArticle',
                            relation_type_id: 1,
                            relation_type_slug: 'Cites',
                            position: 0,
                            titles: [{ title: 'X', title_type: 'MainTitle', position: 0 }],
                            creators: [],
                            contributors: [],
                        },
                    ],
                }),
            ),
        );

        render(<CitationsField resourceId={43} />);

        await waitFor(() =>
            expect(screen.getByText(/1 related item linked to this dataset/i)).toBeInTheDocument(),
        );
    });
});
