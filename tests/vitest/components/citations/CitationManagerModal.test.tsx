import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { CitationManagerModal } from '@/components/citations/CitationManagerModal';

import { http, HttpResponse, server } from '../../helpers/msw-server';

vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn(), info: vi.fn() } }));

const resourceTypes = [{ value: 'JournalArticle', label: 'Journal Article' }];
const relationTypes = [{ id: 1, label: 'Cites' }];
const contributorTypes = [{ value: 'Editor', label: 'Editor' }];

const resourceId = 99;
const base = `/resources/${resourceId}/related-items`;

const sampleItem = {
    id: 1,
    resource_id: resourceId,
    related_item_type: 'JournalArticle',
    relation_type_id: 1,
    relation_type_slug: 'Cites',
    publication_year: 2023,
    identifier: '10.1234/sample',
    identifier_type: 'DOI',
    position: 0,
    titles: [{ title: 'Sample paper', title_type: 'MainTitle', position: 0 }],
    creators: [],
    contributors: [],
};

function renderModal() {
    return render(
        <CitationManagerModal
            open
            onOpenChange={() => {}}
            resourceId={resourceId}
            resourceTypes={resourceTypes}
            relationTypes={relationTypes}
            contributorTypes={contributorTypes}
        />,
    );
}

describe('CitationManagerModal', () => {
    it('shows an empty state when no items exist', async () => {
        server.use(http.get(base, () => HttpResponse.json({ data: [] })));
        renderModal();

        await waitFor(() => {
            expect(screen.getByText(/No related items yet/i)).toBeInTheDocument();
        });
    });

    it('lists related items fetched from the backend', async () => {
        server.use(http.get(base, () => HttpResponse.json({ data: [sampleItem] })));
        renderModal();

        await waitFor(() => {
            expect(screen.getByText(/Sample paper/)).toBeInTheDocument();
        });
        expect(screen.getByText('Cites')).toBeInTheDocument();
    });

    it('switches to the create form and back', async () => {
        server.use(http.get(base, () => HttpResponse.json({ data: [] })));
        const user = userEvent.setup();
        renderModal();

        await waitFor(() =>
            expect(screen.getByText(/No related items yet/i)).toBeInTheDocument(),
        );

        await user.click(screen.getByRole('button', { name: /Add related item/i }));
        expect(screen.getByText('Type *')).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: /^Cancel$/ }));
        expect(screen.queryByText('Type *')).toBeNull();
    });

    it('creates a new related item and returns to the list', async () => {
        let posted = false;
        server.use(
            http.get(base, () => HttpResponse.json({ data: [] })),
            http.post(base, async () => {
                posted = true;
                return HttpResponse.json({ data: { ...sampleItem, id: 2 } }, { status: 201 });
            }),
        );
        const user = userEvent.setup();
        renderModal();

        await waitFor(() =>
            expect(screen.getByText(/No related items yet/i)).toBeInTheDocument(),
        );
        await user.click(screen.getByRole('button', { name: /Add related item/i }));

        await user.type(
            screen.getByPlaceholderText('Title'),
            'New related',
        );

        // Select type
        await user.click(screen.getAllByRole('combobox')[0]);
        await user.click(screen.getByRole('option', { name: /Journal Article/i }));

        // Select relation
        await user.click(screen.getAllByRole('combobox')[1]);
        await user.click(screen.getByRole('option', { name: /^Cites$/i }));

        await user.click(screen.getByRole('button', { name: /^Save$/ }));

        await waitFor(() => expect(posted).toBe(true));
    });
});
