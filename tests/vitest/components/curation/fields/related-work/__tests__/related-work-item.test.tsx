import { fireEvent } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { render, screen } from '@tests/vitest/utils/render';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import RelatedWorkItem from '@/components/curation/fields/related-work/related-work-item';
import type { RelatedIdentifier } from '@/types';

describe('RelatedWorkItem', () => {
    const mockOnChange = vi.fn();
    const mockOnRemove = vi.fn();

    const defaultItem: RelatedIdentifier = {
        identifier: '10.5880/test.2024.001',
        identifier_type: 'DOI',
        relation_type: 'References',
    };

    const defaultProps = {
        sortableId: 'related-work-0',
        item: defaultItem,
        index: 0,
        onChange: mockOnChange,
        onRemove: mockOnRemove,
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders the card heading and current select values', () => {
        render(<RelatedWorkItem {...defaultProps} />);

        expect(screen.getByRole('heading', { name: /related work 1/i })).toBeInTheDocument();
        expect(screen.getByRole('combobox', { name: /relation type/i })).toHaveTextContent('References');
        expect(screen.getByTestId('identifier-type-badge')).toHaveTextContent('DOI');
    });

    it('renders DOI identifiers as clickable preview links', () => {
        render(<RelatedWorkItem {...defaultProps} />);

        const link = screen.getByRole('link', { name: /10\.5880\/test\.2024\.001/i });
        expect(link).toHaveAttribute('href', 'https://doi.org/10.5880/test.2024.001');
        expect(link).toHaveAttribute('target', '_blank');
        expect(link).toHaveAttribute('rel', 'noopener noreferrer');
    });

    it('renders URL identifiers as clickable preview links', () => {
        render(
            <RelatedWorkItem
                {...defaultProps}
                item={{
                    identifier: 'https://example.com/resource',
                    identifier_type: 'URL',
                    relation_type: 'IsCitedBy',
                }}
            />,
        );

        const link = screen.getByRole('link', { name: /https:\/\/example\.com\/resource/i });
        expect(link).toHaveAttribute('href', 'https://example.com/resource');
        expect(screen.getByRole('combobox', { name: /relation type/i })).toHaveTextContent('Is Cited By');
    });

    it('renders non-clickable identifier types as plain preview text', () => {
        render(
            <RelatedWorkItem
                {...defaultProps}
                item={{
                    identifier: '978-3-16-148410-0',
                    identifier_type: 'ISBN',
                    relation_type: 'IsPartOf',
                }}
            />,
        );

        expect(screen.queryByRole('link')).not.toBeInTheDocument();
        expect(screen.getByDisplayValue('978-3-16-148410-0')).toBeInTheDocument();
    });

    it('calls onRemove with the item index', async () => {
        const user = userEvent.setup();
        render(<RelatedWorkItem {...defaultProps} index={2} />);

        await user.click(screen.getByRole('button', { name: /remove related work/i }));

        expect(mockOnRemove).toHaveBeenCalledWith(2);
    });

    it('calls onChange when the identifier is edited', async () => {
        render(<RelatedWorkItem {...defaultProps} />);

        const identifierInput = screen.getByLabelText('Identifier');
        fireEvent.change(identifierInput, { target: { value: '10.5880/updated.2024.001' } });

        expect(mockOnChange).toHaveBeenLastCalledWith(
            expect.objectContaining({
                identifier: '10.5880/updated.2024.001',
            }),
        );
    });

    it('calls onChange when the citation label is edited', async () => {
        render(<RelatedWorkItem {...defaultProps} />);

        const citationLabel = screen.getByLabelText('Citation label');
        fireEvent.change(citationLabel, { target: { value: 'Smith, J. (2024). Test Dataset.' } });

        expect(mockOnChange).toHaveBeenLastCalledWith(
            expect.objectContaining({
                citation_label: 'Smith, J. (2024). Test Dataset.',
            }),
        );
    });

    it('renders validation tooltips for valid items', async () => {
        const user = userEvent.setup();
        render(<RelatedWorkItem {...defaultProps} validationStatus="valid" />);

        const validIcon = screen.getByLabelText('Valid');
        await user.hover(validIcon);

        expect((await screen.findAllByText('Identifier validated successfully')).length).toBeGreaterThan(0);
    });

    it('renders validation tooltips for invalid items', async () => {
        const user = userEvent.setup();
        render(<RelatedWorkItem {...defaultProps} validationStatus="invalid" validationMessage="DOI not found" />);

        const invalidIcon = screen.getByLabelText('Invalid');
        await user.hover(invalidIcon);

        expect((await screen.findAllByText('DOI not found')).length).toBeGreaterThan(0);
    });

    it('shows the resolved title helper when related_title is available', () => {
        render(
            <RelatedWorkItem
                {...defaultProps}
                item={{
                    ...defaultItem,
                    related_title: 'Related Research Paper Title',
                }}
            />,
        );

        expect(screen.getByText('Related Research Paper Title')).toBeInTheDocument();
    });

    it('shows the relation type information field for Other', () => {
        render(
            <RelatedWorkItem
                {...defaultProps}
                item={{
                    ...defaultItem,
                    relation_type: 'Other',
                    relation_type_information: 'Custom relation',
                }}
            />,
        );

        expect(screen.getByLabelText('Relation type information')).toHaveValue('Custom relation');
    });
});