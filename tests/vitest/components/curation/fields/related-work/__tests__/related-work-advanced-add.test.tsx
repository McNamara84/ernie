import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import RelatedWorkAdvancedAdd from '@/components/curation/fields/related-work/related-work-advanced-add';

// Mock the identifier validation hook
vi.mock('@/hooks/use-identifier-validation', () => ({
    useIdentifierValidation: vi.fn(() => ({
        status: 'idle',
        metadata: undefined,
    })),
}));

import { useIdentifierValidation } from '@/hooks/use-identifier-validation';

const mockUseIdentifierValidation = vi.mocked(useIdentifierValidation);

describe('RelatedWorkAdvancedAdd', () => {
    const mockOnAdd = vi.fn();
    const mockOnIdentifierChange = vi.fn();
    const mockOnIdentifierTypeChange = vi.fn();
    const mockOnRelationTypeChange = vi.fn();

    const defaultProps = {
        onAdd: mockOnAdd,
        identifier: '',
        onIdentifierChange: mockOnIdentifierChange,
        identifierType: 'DOI' as const,
        onIdentifierTypeChange: mockOnIdentifierTypeChange,
        relationType: 'References' as const,
        onRelationTypeChange: mockOnRelationTypeChange,
    };

    beforeEach(() => {
        vi.clearAllMocks();
        mockUseIdentifierValidation.mockReturnValue({
            status: 'idle',
            metadata: undefined,
        });
    });

    it('renders the advanced mode header', () => {
        render(<RelatedWorkAdvancedAdd {...defaultProps} />);

        expect(screen.getByText(/Advanced Mode - All Relation Types/)).toBeInTheDocument();
    });

    it('renders the identifier input', () => {
        render(<RelatedWorkAdvancedAdd {...defaultProps} />);

        expect(screen.getByLabelText('Identifier')).toBeInTheDocument();
    });

    it('renders the type select', () => {
        render(<RelatedWorkAdvancedAdd {...defaultProps} />);

        expect(screen.getByLabelText('Type')).toBeInTheDocument();
    });

    it('renders the relation type select', () => {
        render(<RelatedWorkAdvancedAdd {...defaultProps} />);

        expect(screen.getByLabelText('Relation Type')).toBeInTheDocument();
    });

    it('renders the add button', () => {
        render(<RelatedWorkAdvancedAdd {...defaultProps} />);

        expect(screen.getByRole('button', { name: 'Add' })).toBeInTheDocument();
    });

    it('calls onIdentifierChange when typing', async () => {
        const user = userEvent.setup();
        render(<RelatedWorkAdvancedAdd {...defaultProps} />);

        const input = screen.getByLabelText('Identifier');
        await user.type(input, '10.5880/test');

        expect(mockOnIdentifierChange).toHaveBeenCalled();
    });

    it('disables add button when identifier is empty', () => {
        render(<RelatedWorkAdvancedAdd {...defaultProps} identifier="" />);

        const addButton = screen.getByRole('button', { name: 'Add' });
        expect(addButton).toBeDisabled();
    });

    it('enables add button when identifier is valid', () => {
        mockUseIdentifierValidation.mockReturnValue({
            status: 'valid',
            metadata: { title: 'Test Title' },
        });

        render(<RelatedWorkAdvancedAdd {...defaultProps} identifier="10.5880/test.2024.001" />);

        const addButton = screen.getByRole('button', { name: 'Add' });
        expect(addButton).not.toBeDisabled();
    });

    it('calls onAdd when clicking add button with valid identifier', async () => {
        const user = userEvent.setup();
        mockUseIdentifierValidation.mockReturnValue({
            status: 'valid',
            metadata: { title: 'Test Title' },
        });

        render(<RelatedWorkAdvancedAdd {...defaultProps} identifier="10.5880/test.2024.001" />);

        await user.click(screen.getByRole('button', { name: 'Add' }));

        expect(mockOnAdd).toHaveBeenCalledWith({
            identifier: '10.5880/test.2024.001',
            identifierType: 'DOI',
            relationType: 'References',
        });
    });

    it('shows validation error when status is invalid', () => {
        mockUseIdentifierValidation.mockReturnValue({
            status: 'invalid',
            metadata: undefined,
        });

        render(<RelatedWorkAdvancedAdd {...defaultProps} identifier="invalid-doi" />);

        expect(screen.getByText(/Invalid DOI format/)).toBeInTheDocument();
    });

    it('shows warning message when status is warning', () => {
        mockUseIdentifierValidation.mockReturnValue({
            status: 'warning',
            metadata: undefined,
        });

        render(<RelatedWorkAdvancedAdd {...defaultProps} identifier="10.5880/test" />);

        expect(screen.getByText(/Could not verify via API, but format is valid/)).toBeInTheDocument();
    });

    it('shows validated title when status is valid with metadata', () => {
        mockUseIdentifierValidation.mockReturnValue({
            status: 'valid',
            metadata: { title: 'Validated Resource Title' },
        });

        render(<RelatedWorkAdvancedAdd {...defaultProps} identifier="10.5880/test" />);

        expect(screen.getByText(/Validated Resource Title/)).toBeInTheDocument();
    });

    it('shows loading spinner when validating', () => {
        mockUseIdentifierValidation.mockReturnValue({
            status: 'validating',
            metadata: undefined,
        });

        render(<RelatedWorkAdvancedAdd {...defaultProps} identifier="10.5880/test" />);

        // The loading spinner has animate-spin class
        const spinner = document.querySelector('.animate-spin');
        expect(spinner).toBeInTheDocument();
    });

    it('disables add button when validating', () => {
        mockUseIdentifierValidation.mockReturnValue({
            status: 'validating',
            metadata: undefined,
        });

        render(<RelatedWorkAdvancedAdd {...defaultProps} identifier="10.5880/test" />);

        const addButton = screen.getByRole('button', { name: 'Add' });
        expect(addButton).toBeDisabled();
    });

    it('displays relation type description', () => {
        render(<RelatedWorkAdvancedAdd {...defaultProps} relationType="References" />);

        // The description for References should be shown
        expect(screen.getByText(/References:/)).toBeInTheDocument();
    });

    it('handles Enter key press to add', async () => {
        const user = userEvent.setup();
        mockUseIdentifierValidation.mockReturnValue({
            status: 'valid',
            metadata: undefined,
        });

        render(<RelatedWorkAdvancedAdd {...defaultProps} identifier="10.5880/test.2024.001" />);

        const input = screen.getByLabelText('Identifier');
        await user.type(input, '{Enter}');

        expect(mockOnAdd).toHaveBeenCalled();
    });

    it('does not add when identifier is invalid on Enter', async () => {
        const user = userEvent.setup();
        mockUseIdentifierValidation.mockReturnValue({
            status: 'invalid',
            metadata: undefined,
        });

        render(<RelatedWorkAdvancedAdd {...defaultProps} identifier="invalid" />);

        const input = screen.getByLabelText('Identifier');
        await user.type(input, '{Enter}');

        expect(mockOnAdd).not.toHaveBeenCalled();
    });
});
