import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import RelatedWorkQuickAdd from '@/components/curation/fields/related-work/related-work-quick-add';

// Mock the identifier validation hook
vi.mock('@/hooks/use-identifier-validation', () => ({
    useIdentifierValidation: vi.fn(() => ({
        status: 'idle',
        metadata: null,
    })),
}));

import { useIdentifierValidation } from '@/hooks/use-identifier-validation';

const mockUseIdentifierValidation = vi.mocked(useIdentifierValidation);

describe('RelatedWorkQuickAdd', () => {
    const mockOnAdd = vi.fn();
    const mockOnIdentifierChange = vi.fn();
    const mockOnRelationTypeChange = vi.fn();
    const mockOnToggleAdvanced = vi.fn();

    const defaultProps = {
        onAdd: mockOnAdd,
        identifier: '',
        onIdentifierChange: mockOnIdentifierChange,
        identifierType: 'DOI' as const,
        relationType: 'References' as const,
        onRelationTypeChange: mockOnRelationTypeChange,
    };

    beforeEach(() => {
        vi.clearAllMocks();
        mockUseIdentifierValidation.mockReturnValue({
            status: 'idle',
            metadata: null,
        });
    });

    it('renders the info text', () => {
        render(<RelatedWorkQuickAdd {...defaultProps} />);

        expect(screen.getByText(/Add relationships to other datasets, publications, or resources/)).toBeInTheDocument();
    });

    it('renders the identifier input', () => {
        render(<RelatedWorkQuickAdd {...defaultProps} />);

        expect(screen.getByPlaceholderText(/10\.5194\/nhess/)).toBeInTheDocument();
    });

    it('renders the add button', () => {
        render(<RelatedWorkQuickAdd {...defaultProps} />);

        expect(screen.getByRole('button', { name: /Add/i })).toBeInTheDocument();
    });

    it('calls onIdentifierChange when typing', async () => {
        const user = userEvent.setup();
        render(<RelatedWorkQuickAdd {...defaultProps} />);

        const input = screen.getByPlaceholderText(/10\.5194\/nhess/);
        await user.type(input, '10.5880/test');

        expect(mockOnIdentifierChange).toHaveBeenCalled();
    });

    it('disables add button when identifier is empty', () => {
        render(<RelatedWorkQuickAdd {...defaultProps} identifier="" />);

        const addButton = screen.getByRole('button', { name: /Add/i });
        expect(addButton).toBeDisabled();
    });

    it('enables add button when identifier is valid', () => {
        mockUseIdentifierValidation.mockReturnValue({
            status: 'valid',
            metadata: { title: 'Test Title' },
        });

        render(<RelatedWorkQuickAdd {...defaultProps} identifier="10.5880/test.2024.001" />);

        const addButton = screen.getByRole('button', { name: /Add/i });
        expect(addButton).not.toBeDisabled();
    });

    it('calls onAdd when clicking add button with valid identifier', async () => {
        const user = userEvent.setup();
        mockUseIdentifierValidation.mockReturnValue({
            status: 'valid',
            metadata: { title: 'Test Title' },
        });

        render(<RelatedWorkQuickAdd {...defaultProps} identifier="10.5880/test.2024.001" />);

        await user.click(screen.getByRole('button', { name: /Add/i }));

        expect(mockOnAdd).toHaveBeenCalledWith({
            identifier: '10.5880/test.2024.001',
            identifierType: 'DOI',
            relationType: 'References',
        });
    });

    it('normalizes DOI URL to just the DOI', async () => {
        const user = userEvent.setup();
        mockUseIdentifierValidation.mockReturnValue({
            status: 'valid',
            metadata: null,
        });

        render(<RelatedWorkQuickAdd {...defaultProps} identifier="https://doi.org/10.5880/test.2024.001" />);

        await user.click(screen.getByRole('button', { name: /Add/i }));

        expect(mockOnAdd).toHaveBeenCalledWith({
            identifier: '10.5880/test.2024.001',
            identifierType: 'DOI',
            relationType: 'References',
        });
    });

    it('shows validation error when status is invalid', () => {
        mockUseIdentifierValidation.mockReturnValue({
            status: 'invalid',
            metadata: null,
        });

        render(<RelatedWorkQuickAdd {...defaultProps} identifier="invalid-doi" />);

        expect(screen.getByText(/Invalid DOI format/)).toBeInTheDocument();
    });

    it('shows warning message when status is warning', () => {
        mockUseIdentifierValidation.mockReturnValue({
            status: 'warning',
            metadata: null,
        });

        render(<RelatedWorkQuickAdd {...defaultProps} identifier="10.5880/test" />);

        expect(screen.getByText(/Could not verify via API/)).toBeInTheDocument();
    });

    it('shows validated title when status is valid with metadata', () => {
        mockUseIdentifierValidation.mockReturnValue({
            status: 'valid',
            metadata: { title: 'Validated Resource Title' },
        });

        render(<RelatedWorkQuickAdd {...defaultProps} identifier="10.5880/test" />);

        expect(screen.getByText(/Validated Resource Title/)).toBeInTheDocument();
    });

    it('shows loading spinner when validating', () => {
        mockUseIdentifierValidation.mockReturnValue({
            status: 'validating',
            metadata: null,
        });

        render(<RelatedWorkQuickAdd {...defaultProps} identifier="10.5880/test" />);

        const spinner = document.querySelector('.animate-spin');
        expect(spinner).toBeInTheDocument();
    });

    it('renders simple mode toggle when in advanced mode', () => {
        render(<RelatedWorkQuickAdd {...defaultProps} showAdvancedMode={true} onToggleAdvanced={mockOnToggleAdvanced} />);

        expect(screen.getByText(/Simple mode/)).toBeInTheDocument();
    });

    it('calls onToggleAdvanced when clicking simple mode toggle', async () => {
        const user = userEvent.setup();
        render(<RelatedWorkQuickAdd {...defaultProps} showAdvancedMode={true} onToggleAdvanced={mockOnToggleAdvanced} />);

        await user.click(screen.getByText(/Simple mode/));

        expect(mockOnToggleAdvanced).toHaveBeenCalled();
    });

    it('handles Enter key press to add', async () => {
        const user = userEvent.setup();
        mockUseIdentifierValidation.mockReturnValue({
            status: 'valid',
            metadata: null,
        });

        render(<RelatedWorkQuickAdd {...defaultProps} identifier="10.5880/test.2024.001" />);

        const input = screen.getByPlaceholderText(/10\.5194\/nhess/);
        await user.type(input, '{Enter}');

        expect(mockOnAdd).toHaveBeenCalled();
    });

    it('does not add when identifier is invalid on Enter', async () => {
        const user = userEvent.setup();
        mockUseIdentifierValidation.mockReturnValue({
            status: 'invalid',
            metadata: null,
        });

        render(<RelatedWorkQuickAdd {...defaultProps} identifier="invalid" />);

        const input = screen.getByPlaceholderText(/10\.5194\/nhess/);
        await user.type(input, '{Enter}');

        expect(mockOnAdd).not.toHaveBeenCalled();
    });
});
