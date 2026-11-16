import type { ReactNode } from 'react';

import AuthorList from '@/components/curation/fields/author/author-list';
import type { AuthorEntry } from '@/components/curation/fields/author/types';
import ContributorList from '@/components/curation/fields/contributor/contributor-list';
import type { ContributorEntry } from '@/components/curation/fields/contributor/types';
import { FundingReferenceField } from '@/components/curation/fields/funding-reference/funding-reference-field';
import type { FundingReferenceEntry } from '@/components/curation/fields/funding-reference/types';
import RelatedWorkList from '@/components/curation/fields/related-work/related-work-list';
import { RorCatalogContext } from '@/components/curation/fields/ror-input-feedback';
import { indexRorSuggestions } from '@/lib/ror-input';
import type { RelatedIdentifier } from '@/types';

import { expect, page, render, test, vi } from '../helpers/test';

const authors: AuthorEntry[] = ['GFZ', 'MIT'].map((institutionName, index) => ({
    id: `author-${index}`,
    type: 'institution',
    institutionName,
    affiliations: [],
    affiliationsInput: '',
}));
const contributors: ContributorEntry[] = ['GFZ', 'MIT'].map((institutionName, index) => ({
    id: `contributor-${index}`,
    type: 'institution',
    institutionName,
    affiliations: [],
    affiliationsInput: '',
    roles: [],
    rolesInput: '',
}));
const related: RelatedIdentifier[] = ['10.5880/first', '10.5880/second'].map((identifier, position) => ({
    identifier,
    identifier_type: 'DOI',
    relation_type: 'Cites',
    position,
}));
const fundings: FundingReferenceEntry[] = ['GFZ', 'MIT'].map((funderName, index) => ({
    id: `funding-${index}`,
    funderName,
    funderIdentifier: '',
    funderIdentifierType: null,
    awardNumber: '',
    awardUri: '',
    awardTitle: '',
    isExpanded: false,
}));
const suggestions = [{ value: 'GFZ', rorId: 'https://ror.org/04z8jg394', searchTerms: [] }];

interface SortableCase {
    name: string;
    label: string;
    ids: (items: unknown[]) => unknown[];
    expected: unknown[];
    component: (onReorder: (items: unknown[]) => void) => ReactNode;
}

const cases: SortableCase[] = [
    {
        name: 'authors',
        label: 'Reorder author 1',
        ids: (items) => (items as AuthorEntry[]).map((item) => item.id),
        expected: ['author-1', 'author-0'],
        component: (onReorder) => (
            <AuthorList
                authors={authors}
                onAdd={vi.fn()}
                onRemove={vi.fn()}
                onAuthorChange={vi.fn()}
                onReorder={onReorder}
                affiliationSuggestions={[]}
            />
        ),
    },
    {
        name: 'contributors',
        label: 'Reorder contributor 1',
        ids: (items) => (items as ContributorEntry[]).map((item) => item.id),
        expected: ['contributor-1', 'contributor-0'],
        component: (onReorder) => (
            <ContributorList
                contributors={contributors}
                onAdd={vi.fn()}
                onRemove={vi.fn()}
                onContributorChange={vi.fn()}
                onReorder={onReorder}
                affiliationSuggestions={[]}
                personRoleOptions={[]}
                institutionRoleOptions={[]}
            />
        ),
    },
    {
        name: 'related works',
        label: 'Reorder related work 1',
        ids: (items) => (items as RelatedIdentifier[]).map((item) => item.identifier),
        expected: ['10.5880/second', '10.5880/first'],
        component: (onReorder) => <RelatedWorkList items={related} onItemChange={vi.fn()} onRemove={vi.fn()} onReorder={onReorder} />,
    },
    {
        name: 'funding references',
        label: 'Drag to reorder funding 1',
        ids: (items) => (items as FundingReferenceEntry[]).map((item) => item.id),
        expected: ['funding-1', 'funding-0'],
        component: (onReorder) => (
            <RorCatalogContext.Provider value={{ suggestions, index: indexRorSuggestions(suggestions), isLoading: false, error: null }}>
                <FundingReferenceField value={fundings} onChange={onReorder} />
            </RorCatalogContext.Provider>
        ),
    },
];

test.for(cases)('reorders $name with the real keyboard sensor', async (item, { user }) => {
    const onReorder = vi.fn();
    await render(item.component(onReorder));
    page.getByRole('button', { name: item.label }).element().focus();
    await user.keyboard('[Space]');
    await user.keyboard('{ArrowDown}');
    await user.keyboard('[Space]');
    await expect.poll(() => onReorder.mock.calls.length, { message: `${item.name} reorder callback` }).toBe(1);
    expect(item.ids(onReorder.mock.calls[0][0])).toEqual(item.expected);
});

test.for(cases)('cancels keyboard dragging of $name with Escape', async (item, { user }) => {
    const onReorder = vi.fn();
    await render(item.component(onReorder));
    const handle = page.getByRole('button', { name: item.label });
    handle.element().focus();
    await user.keyboard('[Space]');
    await user.keyboard('{ArrowDown}{Escape}');
    expect(onReorder).not.toHaveBeenCalled();
    await expect.element(handle).toHaveFocus();
});
