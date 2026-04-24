import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { RelatedItemForm } from '@/components/citations/RelatedItemForm';

import { http, HttpResponse, server } from '../../helpers/msw-server';

vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

const resourceTypes = [
    { value: 'JournalArticle', label: 'Journal Article' },
    { value: 'Book', label: 'Book' },
];
const relationTypes = [
    { id: 1, label: 'Cites' },
    { id: 2, label: 'IsSupplementTo' },
];
const contributorTypes = [{ value: 'Editor', label: 'Editor' }];

describe('RelatedItemForm', () => {
    it('renders required field labels and all accordion sections', () => {
        render(
            <RelatedItemForm
                resourceTypes={resourceTypes}
                relationTypes={relationTypes}
                contributorTypes={contributorTypes}
                onSubmit={vi.fn()}
            />,
        );

        expect(screen.getByText('Type *')).toBeInTheDocument();
        expect(screen.getByText('Relation type *')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Titles \*/ })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Creators/ })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Contributors/ })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Publication details/ })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Numbering/ })).toBeInTheDocument();
    });

    it('shows validation errors when submitting empty form', async () => {
        const user = userEvent.setup();
        const onSubmit = vi.fn();
        render(
            <RelatedItemForm
                resourceTypes={resourceTypes}
                relationTypes={relationTypes}
                contributorTypes={contributorTypes}
                onSubmit={onSubmit}
            />,
        );

        await user.click(screen.getByRole('button', { name: /^Save$/ }));

        await waitFor(() => {
            expect(screen.getByText(/Related item type is required/i)).toBeInTheDocument();
        });
        expect(screen.getByText(/Relation type is required/i)).toBeInTheDocument();
        expect(onSubmit).not.toHaveBeenCalled();
    });

    it('calls onSubmit with valid values', async () => {
        const user = userEvent.setup();
        const onSubmit = vi.fn().mockResolvedValue(undefined);
        render(
            <RelatedItemForm
                initialValue={{
                    related_item_type: 'JournalArticle',
                    relation_type_id: 1,
                    titles: [{ title: 'My title', title_type: 'MainTitle', position: 0 }],
                    position: 0,
                }}
                resourceTypes={resourceTypes}
                relationTypes={relationTypes}
                contributorTypes={contributorTypes}
                onSubmit={onSubmit}
            />,
        );

        await user.click(screen.getByRole('button', { name: /^Save$/ }));

        await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1));
        const payload = onSubmit.mock.calls[0][0];
        expect(payload.related_item_type).toBe('JournalArticle');
        expect(payload.relation_type_id).toBe(1);
        expect(payload.titles[0].title).toBe('My title');
    });

    it('autofills metadata when the Lookup button is clicked', async () => {
        server.use(
            http.get('/api/v1/citation-lookup', () =>
                HttpResponse.json({
                    source: 'crossref',
                    identifier: '10.1234/x',
                    identifier_type: 'DOI',
                    related_item_type: 'JournalArticle',
                    title: 'Autofilled Title',
                    publication_year: 2024,
                    publisher: 'ACME',
                    volume: '5',
                    creators: [
                        {
                            name: 'Doe, Jane',
                            name_type: 'Personal',
                            given_name: 'Jane',
                            family_name: 'Doe',
                        },
                    ],
                }),
            ),
        );

        const user = userEvent.setup();
        render(
            <RelatedItemForm
                resourceTypes={resourceTypes}
                relationTypes={relationTypes}
                contributorTypes={contributorTypes}
                onSubmit={vi.fn()}
            />,
        );

        const identifierInput = screen.getByPlaceholderText(/10\.1234\/abcd/);
        await user.type(identifierInput, '10.1234/x');
        await user.click(screen.getByRole('button', { name: /Look up DOI metadata/i }));

        await waitFor(
            () => {
                expect(screen.getByText(/Metadata autofilled from crossref/i)).toBeInTheDocument();
            },
            { timeout: 2000 },
        );

        // Title field should now contain the autofilled value
        expect(screen.getByDisplayValue('Autofilled Title')).toBeInTheDocument();
        // Open publication details to reveal publisher input
        await user.click(screen.getByRole('button', { name: /Publication details/ }));
        expect(screen.getByDisplayValue('ACME')).toBeInTheDocument();
    });

    it('invokes onCancel when the Cancel button is clicked', async () => {
        const user = userEvent.setup();
        const onCancel = vi.fn();
        render(
            <RelatedItemForm
                resourceTypes={resourceTypes}
                relationTypes={relationTypes}
                contributorTypes={contributorTypes}
                onSubmit={vi.fn()}
                onCancel={onCancel}
            />,
        );

        await user.click(screen.getByRole('button', { name: /^Cancel$/ }));
        expect(onCancel).toHaveBeenCalled();
    });
});
