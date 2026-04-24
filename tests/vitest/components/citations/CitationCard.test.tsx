import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { CitationCard } from '@/components/citations/CitationCard';
import type { RelatedItem } from '@/types/related-item';

// sonner toast mock
vi.mock('sonner', () => ({
    toast: { success: vi.fn(), error: vi.fn() },
}));

function makeItem(overrides: Partial<RelatedItem> = {}): RelatedItem {
    return {
        related_item_type: 'JournalArticle',
        relation_type_id: 1,
        relation_type_slug: 'Cites',
        publication_year: 2023,
        publisher: 'Nature',
        volume: '12',
        issue: '3',
        first_page: '45',
        last_page: '60',
        identifier: '10.1234/abcd',
        identifier_type: 'DOI',
        position: 0,
        titles: [{ title: 'Seismic patterns', title_type: 'MainTitle', position: 0 }],
        creators: [
            {
                name: 'Doe, Jane',
                name_type: 'Personal',
                given_name: 'Jane',
                family_name: 'Doe',
                position: 0,
                affiliations: [],
            },
        ],
        contributors: [],
        ...overrides,
    };
}

describe('CitationCard', () => {
    it('renders the formatted APA citation by default', () => {
        render(<CitationCard item={makeItem()} />);

        const text = screen.getByText(/Doe, J\./);
        expect(text.textContent).toMatch(/Seismic patterns/);
        expect(text.textContent).toMatch(/2023/);
    });

    it('switches to IEEE formatting when the toggle is clicked', async () => {
        const user = userEvent.setup();
        render(<CitationCard item={makeItem()} />);

        await user.click(screen.getByRole('radio', { name: /IEEE style/i }));

        const text = screen.getByTestId
            ? screen.getByText(/J\. Doe/)
            : screen.getByText(/J\. Doe/);
        expect(text).toBeInTheDocument();
    });

    it('shows the DOI link when an identifier is present', () => {
        render(<CitationCard item={makeItem()} />);

        const link = screen.getByRole('link', { name: /doi\.org/i });
        expect(link).toHaveAttribute('href', 'https://doi.org/10.1234/abcd');
    });

    it('renders the relation badge when a label is provided', () => {
        render(<CitationCard item={makeItem()} relationLabel="Cites" />);
        expect(screen.getByText('Cites')).toBeInTheDocument();
    });

    it('renders edit/delete only when editable is true', () => {
        const onEdit = vi.fn();
        const onDelete = vi.fn();

        const { rerender } = render(
            <CitationCard item={makeItem()} onEdit={onEdit} onDelete={onDelete} />,
        );
        expect(screen.queryByRole('button', { name: /edit related item/i })).toBeNull();

        rerender(
            <CitationCard
                item={makeItem()}
                editable
                onEdit={onEdit}
                onDelete={onDelete}
            />,
        );
        expect(screen.getByRole('button', { name: /edit related item/i })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /delete related item/i })).toBeInTheDocument();
    });

    it('invokes onEdit and onDelete callbacks', async () => {
        const user = userEvent.setup();
        const onEdit = vi.fn();
        const onDelete = vi.fn();

        render(
            <CitationCard
                item={makeItem({ id: 42 })}
                editable
                onEdit={onEdit}
                onDelete={onDelete}
            />,
        );

        await user.click(screen.getByRole('button', { name: /edit related item/i }));
        await user.click(screen.getByRole('button', { name: /delete related item/i }));

        expect(onEdit).toHaveBeenCalledWith(expect.objectContaining({ id: 42 }));
        expect(onDelete).toHaveBeenCalledWith(expect.objectContaining({ id: 42 }));
    });

    it('copies the citation text to the clipboard', async () => {
        const user = userEvent.setup();
        render(<CitationCard item={makeItem()} />);

        const writeSpy = vi
            .spyOn(navigator.clipboard, 'writeText')
            .mockResolvedValue(undefined);

        await user.click(screen.getByRole('button', { name: /copy citation/i }));

        expect(writeSpy).toHaveBeenCalledTimes(1);
        expect(writeSpy.mock.calls[0][0]).toMatch(/Seismic patterns/);
        writeSpy.mockRestore();
    });
});
