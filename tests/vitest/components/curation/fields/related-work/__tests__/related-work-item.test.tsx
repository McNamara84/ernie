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

    it('suppresses clickable preview links for non-http URL identifiers', () => {
        render(
            <RelatedWorkItem
                {...defaultProps}
                item={{
                    identifier: 'javascript:alert(1)',
                    identifier_type: 'URL',
                    relation_type: 'IsCitedBy',
                }}
            />,
        );

        expect(screen.queryByRole('link')).not.toBeInTheDocument();
        expect(screen.getByText('javascript:alert(1)')).toBeInTheDocument();
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

    it('calls onChange when the identifier type is edited', async () => {
        const user = userEvent.setup();
        render(<RelatedWorkItem {...defaultProps} />);

        await user.click(screen.getByRole('combobox', { name: /^type$/i }));
        await user.click(screen.getByRole('option', { name: 'URL' }));

        expect(mockOnChange).toHaveBeenLastCalledWith(
            expect.objectContaining({
                identifier_type: 'URL',
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

    it('does not render the removed resolved title helper UI', () => {
        render(<RelatedWorkItem {...defaultProps} />);

        expect(screen.queryByText('Resolved title')).not.toBeInTheDocument();
        expect(screen.queryByText('No resolved title available yet.')).not.toBeInTheDocument();
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

    it('renders warning tooltips with the default message when no warning text is provided', async () => {
        const user = userEvent.setup();
        render(<RelatedWorkItem {...defaultProps} validationStatus="warning" />);

        const warningIcon = screen.getByLabelText('Warning');
        await user.hover(warningIcon);

        expect((await screen.findAllByText('Validation warning')).length).toBeGreaterThan(0);
    });

    it('does not surface related_title metadata in the editor card', () => {
        render(
            <RelatedWorkItem
                {...defaultProps}
                item={{
                    ...defaultItem,
                    related_title: 'Related Research Paper Title',
                }}
            />,
        );

        expect(screen.queryByText('Resolved title')).not.toBeInTheDocument();
        expect(screen.queryByText('Related Research Paper Title')).not.toBeInTheDocument();
        expect(screen.getByLabelText('Citation label')).toBeInTheDocument();
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

    it('clears relation type information when switching away from Other', async () => {
        const user = userEvent.setup();
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

        await user.click(screen.getByRole('combobox', { name: /relation type/i }));
        await user.click(screen.getByRole('option', { name: /cites/i }));

        expect(mockOnChange).toHaveBeenLastCalledWith(
            expect.objectContaining({
                relation_type: 'Cites',
                relation_type_information: null,
            }),
        );
    });

    it('filters identifier and relation type options when active sets are provided', async () => {
        const user = userEvent.setup();
        render(
            <RelatedWorkItem
                {...defaultProps}
                activeIdentifierTypes={['DOI', 'URL']}
                activeRelationTypes={['Cites', 'Documents']}
            />,
        );

        await user.click(screen.getByRole('combobox', { name: /^type$/i }));

        expect(screen.getByRole('option', { name: 'DOI' })).toBeInTheDocument();
        expect(screen.getByRole('option', { name: 'URL' })).toBeInTheDocument();
        expect(screen.queryByRole('option', { name: 'Handle' })).not.toBeInTheDocument();

        await user.keyboard('{Escape}');
        await user.click(screen.getByRole('combobox', { name: /relation type/i }));

        expect(screen.getByText('Most Used')).toBeInTheDocument();
        expect(screen.getByText('All relation types')).toBeInTheDocument();
        expect(screen.getByRole('option', { name: /cites/i })).toBeInTheDocument();
        expect(screen.getByRole('option', { name: /documents/i })).toBeInTheDocument();
        expect(screen.queryByRole('option', { name: /references/i })).not.toBeInTheDocument();
    });
});