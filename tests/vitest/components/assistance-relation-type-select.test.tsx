import userEvent from '@testing-library/user-event';
import { render, screen } from '@tests/vitest/utils/render';
import { describe, expect, it, vi } from 'vitest';

import { RelationTypeSelect } from '@/components/assistance/relation-type-select';
import type { AssistanceRelationTypeOption, SuggestedRelationItem } from '@/types/assistance';

const options: AssistanceRelationTypeOption[] = [
    { id: 1, name: 'Cites', slug: 'Cites', usage_count: 50, is_most_used: true },
    { id: 2, name: 'References', slug: 'References', usage_count: 40, is_most_used: true },
    { id: 3, name: 'Documents', slug: 'Documents', usage_count: 30, is_most_used: true },
    { id: 4, name: 'Has Part', slug: 'HasPart', usage_count: 20, is_most_used: true },
    { id: 5, name: 'Compiles', slug: 'Compiles', usage_count: 10, is_most_used: true },
    { id: 6, name: 'Is Derived From', slug: 'IsDerivedFrom', usage_count: 2, is_most_used: false },
    { id: 7, name: 'Reviews', slug: 'Reviews', usage_count: 1, is_most_used: false },
];

function suggestion(overrides: Partial<SuggestedRelationItem> = {}): SuggestedRelationItem {
    return {
        id: 11,
        resource_id: 10,
        resource_doi: '10.5880/test.2026.011',
        resource_title: 'Test resource',
        identifier: '10.1234/related',
        identifier_type: 'DOI',
        identifier_type_name: 'DOI',
        relation_type_id: 1,
        relation_type: 'Cites',
        relation_type_name: 'Cites',
        source: 'scholexplorer',
        source_title: 'Related resource',
        source_type: 'Dataset',
        source_publisher: 'GFZ',
        source_publication_date: '2026',
        discovered_at: '2026-07-26T10:00:00+00:00',
        ...overrides,
    };
}

describe('RelationTypeSelect', () => {
    it('groups the dynamic top five before the remaining active relation types', async () => {
        const user = userEvent.setup();
        const onValueChange = vi.fn();

        render(<RelationTypeSelect suggestion={suggestion()} options={options} value={1} disabled={false} onValueChange={onValueChange} />);

        const select = screen.getByRole('combobox', { name: 'Relation type for 10.1234/related' });
        expect(select).toHaveTextContent('Cites');

        await user.click(select);

        expect(screen.getByText('Most Used')).toBeInTheDocument();
        expect(screen.getByText('All Relation Types')).toBeInTheDocument();
        expect(screen.queryByText('Suggested Type')).not.toBeInTheDocument();
        expect(screen.getAllByRole('option')).toHaveLength(7);

        await user.click(screen.getByRole('option', { name: /^References/ }));
        expect(onValueChange).toHaveBeenCalledWith(2);
    });

    it('keeps only the inactive original type available as a marked suggestion option', async () => {
        const user = userEvent.setup();
        const inactiveSuggestion = suggestion({
            relation_type_id: 99,
            relation_type: 'LegacyRelation',
            relation_type_name: 'Legacy Relation',
        });

        render(<RelationTypeSelect suggestion={inactiveSuggestion} options={options} value={99} disabled={false} onValueChange={vi.fn()} />);

        await user.click(screen.getByRole('combobox', { name: 'Relation type for 10.1234/related' }));

        expect(screen.getByText('Suggested Type')).toBeInTheDocument();
        expect(screen.getByRole('option', { name: 'Legacy Relation (inactive)' })).toBeInTheDocument();
        expect(screen.getAllByRole('option')).toHaveLength(8);
    });

    it('disables changes while the suggestion is processing', () => {
        render(<RelationTypeSelect suggestion={suggestion()} options={options} value={1} disabled onValueChange={vi.fn()} />);

        expect(screen.getByRole('combobox', { name: 'Relation type for 10.1234/related' })).toBeDisabled();
    });
});
