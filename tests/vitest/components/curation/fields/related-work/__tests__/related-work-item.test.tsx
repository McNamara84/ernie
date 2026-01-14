import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import RelatedWorkItem from '@/components/curation/fields/related-work/related-work-item';
import type { RelatedIdentifier } from '@/types';

describe('RelatedWorkItem', () => {
    const mockOnRemove = vi.fn();

    const defaultItem: RelatedIdentifier = {
        identifier: '10.5880/test.2024.001',
        identifier_type: 'DOI',
        relation_type: 'References',
    };

    const defaultProps = {
        item: defaultItem,
        index: 0,
        onRemove: mockOnRemove,
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders the relation type', () => {
        render(<RelatedWorkItem {...defaultProps} />);

        expect(screen.getByText('References')).toBeInTheDocument();
    });

    it('renders the identifier', () => {
        render(<RelatedWorkItem {...defaultProps} />);

        expect(screen.getByText('10.5880/test.2024.001')).toBeInTheDocument();
    });

    it('renders the identifier type badge', () => {
        render(<RelatedWorkItem {...defaultProps} />);

        expect(screen.getByText('DOI')).toBeInTheDocument();
    });

    it('renders DOI as clickable link with correct href', () => {
        render(<RelatedWorkItem {...defaultProps} />);

        const link = screen.getByRole('link', { name: /10\.5880\/test\.2024\.001/i });
        expect(link).toHaveAttribute('href', 'https://doi.org/10.5880/test.2024.001');
        expect(link).toHaveAttribute('target', '_blank');
    });

    it('renders URL as clickable link with correct href', () => {
        const urlItem: RelatedIdentifier = {
            identifier: 'https://example.com/resource',
            identifier_type: 'URL',
            relation_type: 'IsCitedBy',
        };
        render(<RelatedWorkItem {...defaultProps} item={urlItem} />);

        const link = screen.getByRole('link', { name: /https:\/\/example\.com\/resource/i });
        expect(link).toHaveAttribute('href', 'https://example.com/resource');
    });

    it('renders non-URL/DOI identifiers as plain text', () => {
        const isbnItem: RelatedIdentifier = {
            identifier: '978-3-16-148410-0',
            identifier_type: 'ISBN',
            relation_type: 'IsPartOf',
        };
        render(<RelatedWorkItem {...defaultProps} item={isbnItem} />);

        expect(screen.getByText('978-3-16-148410-0')).toBeInTheDocument();
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it('calls onRemove with index when remove button is clicked', async () => {
        const user = userEvent.setup();
        render(<RelatedWorkItem {...defaultProps} index={2} />);

        await user.click(screen.getByRole('button', { name: /remove related work/i }));

        expect(mockOnRemove).toHaveBeenCalledWith(2);
    });

    it('displays valid icon when validationStatus is valid', async () => {
        const user = userEvent.setup();
        render(<RelatedWorkItem {...defaultProps} validationStatus="valid" />);

        const validIcon = screen.getByLabelText('Valid');
        expect(validIcon).toBeInTheDocument();

        // Hover to show tooltip
        await user.hover(validIcon);
        const tooltipTexts = await screen.findAllByText('Identifier validated successfully');
        expect(tooltipTexts.length).toBeGreaterThan(0);
    });

    it('displays invalid icon with message when validationStatus is invalid', async () => {
        const user = userEvent.setup();
        render(
            <RelatedWorkItem
                {...defaultProps}
                validationStatus="invalid"
                validationMessage="DOI not found"
            />,
        );

        const invalidIcon = screen.getByLabelText('Invalid');
        expect(invalidIcon).toBeInTheDocument();

        await user.hover(invalidIcon);
        const tooltipTexts = await screen.findAllByText('DOI not found');
        expect(tooltipTexts.length).toBeGreaterThan(0);
    });

    it('displays warning icon with message when validationStatus is warning', async () => {
        const user = userEvent.setup();
        render(
            <RelatedWorkItem
                {...defaultProps}
                validationStatus="warning"
                validationMessage="DOI may be incorrect"
            />,
        );

        const warningIcon = screen.getByLabelText('Warning');
        expect(warningIcon).toBeInTheDocument();

        await user.hover(warningIcon);
        const tooltipTexts = await screen.findAllByText('DOI may be incorrect');
        expect(tooltipTexts.length).toBeGreaterThan(0);
    });

    it('does not display validation icon when status is validating', () => {
        render(<RelatedWorkItem {...defaultProps} validationStatus="validating" />);

        expect(screen.queryByLabelText('Valid')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Invalid')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Warning')).not.toBeInTheDocument();
    });

    it('does not display validation icon when no status provided', () => {
        render(<RelatedWorkItem {...defaultProps} />);

        expect(screen.queryByLabelText('Valid')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Invalid')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Warning')).not.toBeInTheDocument();
    });

    it('displays related_title when provided', () => {
        const itemWithTitle: RelatedIdentifier = {
            ...defaultItem,
            related_title: 'Related Research Paper Title',
        };
        render(<RelatedWorkItem {...defaultProps} item={itemWithTitle} />);

        expect(screen.getByText('Related Research Paper Title')).toBeInTheDocument();
    });

    it('renders different relation types correctly', () => {
        const citedByItem: RelatedIdentifier = {
            identifier: '10.5880/test.2024.002',
            identifier_type: 'DOI',
            relation_type: 'IsCitedBy',
        };
        render(<RelatedWorkItem {...defaultProps} item={citedByItem} />);

        expect(screen.getByText('IsCitedBy')).toBeInTheDocument();
    });

    it('handles ARK identifier type', () => {
        const arkItem: RelatedIdentifier = {
            identifier: 'ark:/12345/abc123',
            identifier_type: 'ARK',
            relation_type: 'References',
        };
        render(<RelatedWorkItem {...defaultProps} item={arkItem} />);

        expect(screen.getByText('ARK')).toBeInTheDocument();
        expect(screen.getByText('ark:/12345/abc123')).toBeInTheDocument();
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });

    it('displays default message when validation fails without custom message', async () => {
        const user = userEvent.setup();
        render(<RelatedWorkItem {...defaultProps} validationStatus="invalid" />);

        const invalidIcon = screen.getByLabelText('Invalid');
        await user.hover(invalidIcon);
        const tooltipTexts = await screen.findAllByText('Validation failed');
        expect(tooltipTexts.length).toBeGreaterThan(0);
    });

    it('displays default message when warning without custom message', async () => {
        const user = userEvent.setup();
        render(<RelatedWorkItem {...defaultProps} validationStatus="warning" />);

        const warningIcon = screen.getByLabelText('Warning');
        await user.hover(warningIcon);
        const tooltipTexts = await screen.findAllByText('Validation warning');
        expect(tooltipTexts.length).toBeGreaterThan(0);
    });
});
