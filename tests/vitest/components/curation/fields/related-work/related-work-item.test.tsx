import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import RelatedWorkItem from '@/components/curation/fields/related-work/related-work-item';
import type { RelatedIdentifier } from '@/types';

describe('RelatedWorkItem', () => {
    const mockOnRemove = vi.fn();

    const createItem = (overrides: Partial<RelatedIdentifier> = {}): RelatedIdentifier => ({
        identifier: '10.5880/test.2024.001',
        identifier_type: 'DOI',
        relation_type: 'Cites',
        ...overrides,
    });

    const defaultProps = {
        item: createItem(),
        index: 0,
        onRemove: mockOnRemove,
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    describe('rendering', () => {
        it('renders the relation type', () => {
            render(<RelatedWorkItem {...defaultProps} />);

            expect(screen.getByText('Cites')).toBeInTheDocument();
        });

        it('renders the identifier type badge', () => {
            render(<RelatedWorkItem {...defaultProps} />);

            expect(screen.getByText('DOI')).toBeInTheDocument();
        });

        it('renders the identifier', () => {
            render(<RelatedWorkItem {...defaultProps} />);

            expect(screen.getByText('10.5880/test.2024.001')).toBeInTheDocument();
        });

        it('renders related title when provided', () => {
            const item = createItem({ related_title: 'Test Dataset Title' });

            render(<RelatedWorkItem {...defaultProps} item={item} />);

            expect(screen.getByText('Test Dataset Title')).toBeInTheDocument();
        });

        it('does not render related title when not provided', () => {
            render(<RelatedWorkItem {...defaultProps} />);

            expect(screen.queryByText(/test dataset title/i)).not.toBeInTheDocument();
        });
    });

    describe('link handling', () => {
        it('renders DOI as clickable link with doi.org URL', () => {
            render(<RelatedWorkItem {...defaultProps} />);

            const link = screen.getByRole('link');
            expect(link).toHaveAttribute('href', 'https://doi.org/10.5880/test.2024.001');
            expect(link).toHaveAttribute('target', '_blank');
            expect(link).toHaveAttribute('rel', 'noopener noreferrer');
        });

        it('renders URL as clickable link with original URL', () => {
            const item = createItem({
                identifier: 'https://example.com/dataset',
                identifier_type: 'URL',
            });

            render(<RelatedWorkItem {...defaultProps} item={item} />);

            const link = screen.getByRole('link');
            expect(link).toHaveAttribute('href', 'https://example.com/dataset');
        });

        it('renders non-clickable identifier types as plain text', () => {
            const item = createItem({
                identifier: 'ISBN-1234567890',
                identifier_type: 'ISBN',
            });

            render(<RelatedWorkItem {...defaultProps} item={item} />);

            expect(screen.queryByRole('link')).not.toBeInTheDocument();
            expect(screen.getByText('ISBN-1234567890')).toBeInTheDocument();
        });

        it('renders ARK identifier as plain text', () => {
            const item = createItem({
                identifier: 'ark:/12345/example',
                identifier_type: 'ARK',
            });

            render(<RelatedWorkItem {...defaultProps} item={item} />);

            expect(screen.queryByRole('link')).not.toBeInTheDocument();
            expect(screen.getByText('ark:/12345/example')).toBeInTheDocument();
        });
    });

    describe('validation status', () => {
        it('shows valid icon for valid status', () => {
            render(<RelatedWorkItem {...defaultProps} validationStatus="valid" />);

            expect(screen.getByLabelText('Valid')).toBeInTheDocument();
        });

        it('shows warning icon for warning status', () => {
            render(<RelatedWorkItem {...defaultProps} validationStatus="warning" validationMessage="Could not verify" />);

            expect(screen.getByLabelText('Warning')).toBeInTheDocument();
        });

        it('shows invalid icon for invalid status', () => {
            render(<RelatedWorkItem {...defaultProps} validationStatus="invalid" validationMessage="DOI not found" />);

            expect(screen.getByLabelText('Invalid')).toBeInTheDocument();
        });

        it('does not show validation icon when status is validating', () => {
            render(<RelatedWorkItem {...defaultProps} validationStatus="validating" />);

            expect(screen.queryByLabelText('Valid')).not.toBeInTheDocument();
            expect(screen.queryByLabelText('Warning')).not.toBeInTheDocument();
            expect(screen.queryByLabelText('Invalid')).not.toBeInTheDocument();
        });

        it('does not show validation icon when no status provided', () => {
            render(<RelatedWorkItem {...defaultProps} />);

            expect(screen.queryByLabelText('Valid')).not.toBeInTheDocument();
            expect(screen.queryByLabelText('Warning')).not.toBeInTheDocument();
            expect(screen.queryByLabelText('Invalid')).not.toBeInTheDocument();
        });
    });

    describe('remove button', () => {
        it('renders remove button with accessible label', () => {
            render(<RelatedWorkItem {...defaultProps} />);

            expect(screen.getByRole('button', { name: /remove related work/i })).toBeInTheDocument();
        });

        it('calls onRemove with correct index when clicked', async () => {
            const user = userEvent.setup();

            render(<RelatedWorkItem {...defaultProps} index={2} />);

            await user.click(screen.getByRole('button', { name: /remove related work/i }));

            expect(mockOnRemove).toHaveBeenCalledTimes(1);
            expect(mockOnRemove).toHaveBeenCalledWith(2);
        });
    });

    describe('relation types', () => {
        it('displays different relation types correctly', () => {
            const relationTypes = ['References', 'IsDocumentedBy', 'IsDerivedFrom', 'HasPart'] as const;

            relationTypes.forEach((relationType) => {
                const item = createItem({ relation_type: relationType });
                const { unmount } = render(<RelatedWorkItem {...defaultProps} item={item} />);

                expect(screen.getByText(relationType)).toBeInTheDocument();
                unmount();
            });
        });
    });

    describe('identifier types', () => {
        it('displays different identifier type badges', () => {
            const identifierTypes = ['DOI', 'URL', 'ISBN', 'ISSN', 'Handle', 'URN', 'ARK'] as const;

            identifierTypes.forEach((identifierType) => {
                const item = createItem({ identifier_type: identifierType });
                const { unmount } = render(<RelatedWorkItem {...defaultProps} item={item} />);

                expect(screen.getByText(identifierType)).toBeInTheDocument();
                unmount();
            });
        });
    });
});
