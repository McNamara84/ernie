import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import AuthorField from '@/components/curation/fields/author';
import type { AuthorEntry } from '@/components/curation/fields/author/types';

vi.mock('@/components/curation/fields/author/author-list', () => ({
    default: ({
        authors,
        onAuthorChange,
        onReorder,
    }: {
        authors: AuthorEntry[];
        onAuthorChange: (index: number, author: AuthorEntry) => void;
        onReorder: (authors: AuthorEntry[]) => void;
    }) => (
        <div data-testid="author-list">
            <button
                data-testid="reorder-authors"
                onClick={() =>
                    onReorder([
                        authors[2],
                        authors[0],
                        authors[1],
                    ])
                }
            >
                Reorder authors
            </button>
            <button
                data-testid="change-first-author"
                onClick={() =>
                    onAuthorChange(0, {
                        ...authors[0],
                        firstName: authors[0].type === 'person' ? 'Updated' : undefined,
                    } as AuthorEntry)
                }
            >
                Change first author
            </button>
        </div>
    ),
}));

describe('AuthorField', () => {
    const authors: AuthorEntry[] = [
        {
            id: 'author-1',
            type: 'person',
            orcid: '0000-0001-1111-1111',
            firstName: 'Ada',
            lastName: 'Lovelace',
            email: 'ada@example.org',
            website: 'https://example.org/ada',
            isContact: true,
            orcidVerified: true,
            affiliations: [{ value: 'University A', rorId: null }],
            affiliationsInput: 'University A',
        },
        {
            id: 'author-2',
            type: 'institution',
            institutionName: 'Institute B',
            affiliations: [{ value: 'Institute B', rorId: 'https://ror.org/02abcde12' }],
            affiliationsInput: 'Institute B',
        },
        {
            id: 'author-3',
            type: 'person',
            orcid: '0000-0002-2222-2222',
            firstName: 'Grace',
            lastName: 'Hopper',
            email: 'grace@example.org',
            website: 'https://example.org/grace',
            isContact: false,
            orcidVerified: false,
            affiliations: [{ value: 'Lab C', rorId: null }],
            affiliationsInput: 'Lab C',
        },
    ];

    it('replaces the full author array when the list reports a reorder', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn<(authors: AuthorEntry[]) => void>();

        render(<AuthorField authors={authors} onChange={onChange} affiliationSuggestions={[]} />);

        await user.click(screen.getByTestId('reorder-authors'));

        expect(onChange).toHaveBeenCalledTimes(1);
        expect(onChange).toHaveBeenCalledWith([
            expect.objectContaining({ id: 'author-3', firstName: 'Grace', lastName: 'Hopper' }),
            expect.objectContaining({ id: 'author-1', firstName: 'Ada', lastName: 'Lovelace' }),
            expect.objectContaining({ id: 'author-2', institutionName: 'Institute B' }),
        ]);
    });

    it('still updates a single author by index for non-reorder changes', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn<(authors: AuthorEntry[]) => void>();

        render(<AuthorField authors={authors} onChange={onChange} affiliationSuggestions={[]} />);

        await user.click(screen.getByTestId('change-first-author'));

        expect(onChange).toHaveBeenCalledTimes(1);
        expect(onChange).toHaveBeenCalledWith([
            expect.objectContaining({ id: 'author-1', firstName: 'Updated', lastName: 'Lovelace' }),
            expect.objectContaining({ id: 'author-2', institutionName: 'Institute B' }),
            expect.objectContaining({ id: 'author-3', firstName: 'Grace', lastName: 'Hopper' }),
        ]);
    });
});