/**
 * @vitest-environment jsdom
 */

import userEvent from '@testing-library/user-event';
import { cleanup, render, screen } from '@tests/vitest/utils/render';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import AuthorList from '@/components/curation/fields/author/author-list';
import type { AuthorEntry } from '@/components/curation/fields/author/types';

vi.mock('@dnd-kit/core', () => ({
    DndContext: ({ children }: { children: ReactNode }) => <div>{children}</div>,
    closestCenter: vi.fn(),
    PointerSensor: vi.fn(),
    KeyboardSensor: vi.fn(),
    useSensor: vi.fn(),
    useSensors: vi.fn(() => []),
}));

vi.mock('@dnd-kit/sortable', () => ({
    SortableContext: ({ children }: { children: ReactNode }) => <div>{children}</div>,
    arrayMove: vi.fn(),
    sortableKeyboardCoordinates: vi.fn(),
    verticalListSortingStrategy: vi.fn(),
}));

vi.mock('@/components/curation/fields/author/author-item', () => ({
    default: ({ author, onAuthorChange }: { author: AuthorEntry; onAuthorChange: (author: AuthorEntry) => void }) => (
        <div>
            <button
                type="button"
                onClick={() =>
                    author.type === 'person' &&
                    onAuthorChange({
                        ...author,
                        orcid: '0000-0002-1825-0097',
                        firstName: 'Jane',
                        lastName: 'Doe',
                        orcidVerified: true,
                    })
                }
            >
                Apply ORCID autofill
            </button>
            <button
                type="button"
                onClick={() =>
                    author.type === 'person' &&
                    onAuthorChange({
                        ...author,
                        firstName: 'Corrected',
                    })
                }
            >
                Apply pending name correction
            </button>
        </div>
    ),
}));

vi.mock('@/components/curation/fields/author-csv-import', () => ({
    default: () => null,
}));

const snapshotAuthor: AuthorEntry = {
    id: 'snapshot-author',
    type: 'person',
    resourceCreatorId: 42,
    orcid: '',
    firstName: '',
    lastName: '',
    nameSnapshot: 'The Artist',
    email: '',
    website: '',
    isContact: false,
    affiliations: [],
    affiliationsInput: '',
};

const renderAuthorList = (author: AuthorEntry, onAuthorChange: (index: number, author: AuthorEntry) => void) =>
    render(
        <AuthorList
            authors={[author]}
            onAdd={vi.fn()}
            onRemove={vi.fn()}
            onAuthorChange={onAuthorChange}
            onReorder={vi.fn()}
            affiliationSuggestions={[]}
        />,
    );

afterEach(() => cleanup());

describe('AuthorList ORCID name updates', () => {
    it('clears a snapshot-only author name when ORCID autofill supplies structured names', async () => {
        const onAuthorChange = vi.fn();
        renderAuthorList(snapshotAuthor, onAuthorChange);

        await userEvent.click(screen.getByRole('button', { name: 'Apply ORCID autofill' }));

        expect(onAuthorChange).toHaveBeenCalledWith(0, {
            ...snapshotAuthor,
            orcid: '0000-0002-1825-0097',
            firstName: 'Jane',
            lastName: 'Doe',
            nameSnapshot: undefined,
            orcidVerified: true,
        });
    });

    it('clears a preserved snapshot when a pending ORCID name correction is accepted', async () => {
        const onAuthorChange = vi.fn();
        const author = {
            ...snapshotAuthor,
            firstName: 'Janet',
            lastName: 'Doe',
            nameSnapshot: 'Doe, Janet',
        };
        renderAuthorList(author, onAuthorChange);

        await userEvent.click(screen.getByRole('button', { name: 'Apply pending name correction' }));

        expect(onAuthorChange).toHaveBeenCalledWith(0, {
            ...author,
            firstName: 'Corrected',
            nameSnapshot: undefined,
        });
    });
});
