import { fireEvent } from '@testing-library/react';
import { render, screen } from '@tests/vitest/utils/render';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import RelatedWorkItem from '@/components/curation/fields/related-work/related-work-item';
import type { RelatedIdentifier } from '@/types';

describe('RelatedWorkItem variants', () => {
    const mockOnChange = vi.fn();
    const mockOnRemove = vi.fn();

    const createItem = (overrides: Partial<RelatedIdentifier> = {}): RelatedIdentifier => ({
        identifier: '10.5880/test.2024.001',
        identifier_type: 'DOI',
        relation_type: 'Cites',
        ...overrides,
    });

    const renderItem = (item: RelatedIdentifier) =>
        render(
            <RelatedWorkItem
                sortableId="related-work-0"
                item={item}
                index={0}
                onChange={mockOnChange}
                onRemove={mockOnRemove}
            />,
        );

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders Handle identifiers as clickable preview links', () => {
        renderItem(
            createItem({
                identifier: '10013/epic.12345',
                identifier_type: 'Handle',
            }),
        );

        const link = screen.getByRole('link', { name: /10013\/epic\.12345/i });
        expect(link).toHaveAttribute('href', 'https://hdl.handle.net/10013/epic.12345');
    });

    it('renders ARK identifiers without a preview link', () => {
        renderItem(
            createItem({
                identifier: 'ark:/12345/example',
                identifier_type: 'ARK',
            }),
        );

        expect(screen.queryByRole('link')).not.toBeInTheDocument();
        expect(screen.getByDisplayValue('ark:/12345/example')).toBeInTheDocument();
    });

    it('formats CamelCase relation types for the visible label', () => {
        renderItem(
            createItem({
                relation_type: 'IsDocumentedBy',
            }),
        );

        expect(screen.getByRole('combobox', { name: /relation type/i })).toHaveTextContent('Is Documented By');
    });

    it('calls onChange when editing relation type information', async () => {
        renderItem(
            createItem({
                relation_type: 'Other',
                relation_type_information: '',
            }),
        );

        const infoInput = screen.getByLabelText('Relation type information');
        fireEvent.change(infoInput, { target: { value: 'Custom relationship' } });

        expect(mockOnChange).toHaveBeenLastCalledWith(
            expect.objectContaining({
                relation_type_information: 'Custom relationship',
            }),
        );
    });

    it('keeps validation icons hidden when status is validating', () => {
        render(
            <RelatedWorkItem
                sortableId="related-work-0"
                item={createItem()}
                index={0}
                onChange={mockOnChange}
                onRemove={mockOnRemove}
                validationStatus="validating"
            />,
        );

        expect(screen.queryByLabelText('Valid')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Warning')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Invalid')).not.toBeInTheDocument();
    });
});