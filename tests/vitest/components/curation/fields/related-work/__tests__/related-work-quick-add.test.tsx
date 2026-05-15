import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import RelatedWorkQuickAdd from '@/components/curation/fields/related-work/related-work-quick-add';

vi.mock('@/hooks/use-identifier-validation', () => ({
    useIdentifierValidation: vi.fn(() => ({
        status: 'idle',
        metadata: undefined,
    })),
}));

import { useIdentifierValidation } from '@/hooks/use-identifier-validation';

const mockUseIdentifierValidation = vi.mocked(useIdentifierValidation);

describe('RelatedWorkQuickAdd', () => {
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

    it('renders the info text and core controls', () => {
        render(<RelatedWorkQuickAdd {...defaultProps} />);

        expect(screen.getByText(/add relationships to other datasets, publications, or resources/i)).toBeInTheDocument();
        expect(screen.getByPlaceholderText(/10\.5194\/nhess/i)).toBeInTheDocument();
        expect(screen.getByRole('combobox', { name: /identifier type/i })).toBeInTheDocument();
        expect(screen.getByRole('combobox', { name: /relation type/i })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /add related work/i })).toBeInTheDocument();
    });

    it('does not render the removed simple mode toggle', () => {
        render(<RelatedWorkQuickAdd {...defaultProps} />);

        expect(screen.queryByText(/simple mode/i)).not.toBeInTheDocument();
    });

    it('calls onIdentifierChange when typing', async () => {
        const user = userEvent.setup();
        render(<RelatedWorkQuickAdd {...defaultProps} />);

        await user.type(screen.getByPlaceholderText(/10\.5194\/nhess/i), '10.5880/test');

        expect(mockOnIdentifierChange).toHaveBeenCalled();
    });

    it('disables the add button when identifier is empty', () => {
        render(<RelatedWorkQuickAdd {...defaultProps} identifier="" />);

        expect(screen.getByRole('button', { name: /add related work/i })).toBeDisabled();
    });

    it('enables the add button when the identifier validates', () => {
        mockUseIdentifierValidation.mockReturnValue({
            status: 'valid',
            metadata: { title: 'Test Title' },
        });

        render(<RelatedWorkQuickAdd {...defaultProps} identifier="10.5880/test.2024.001" />);

        expect(screen.getByRole('button', { name: /add related work/i })).not.toBeDisabled();
    });

    it('calls onAdd when clicking add with a valid identifier', async () => {
        const user = userEvent.setup();
        mockUseIdentifierValidation.mockReturnValue({
            status: 'valid',
            metadata: { title: 'Test Title' },
        });

        render(<RelatedWorkQuickAdd {...defaultProps} identifier="10.5880/test.2024.001" />);

        await user.click(screen.getByRole('button', { name: /add related work/i }));

        expect(mockOnAdd).toHaveBeenCalledWith({
            identifier: '10.5880/test.2024.001',
            identifierType: 'DOI',
            relationType: 'References',
        });
    });

    it('normalizes DOI URLs before calling onAdd', async () => {
        const user = userEvent.setup();
        mockUseIdentifierValidation.mockReturnValue({
            status: 'valid',
            metadata: undefined,
        });

        render(<RelatedWorkQuickAdd {...defaultProps} identifier="https://doi.org/10.5880/test.2024.001" />);

        await user.click(screen.getByRole('button', { name: /add related work/i }));

        expect(mockOnAdd).toHaveBeenCalledWith({
            identifier: '10.5880/test.2024.001',
            identifierType: 'DOI',
            relationType: 'References',
        });
    });

    it('normalizes dx.doi.org URLs before calling onAdd', async () => {
        const user = userEvent.setup();
        mockUseIdentifierValidation.mockReturnValue({
            status: 'valid',
            metadata: undefined,
        });

        render(<RelatedWorkQuickAdd {...defaultProps} identifier="https://dx.doi.org/10.5880/test.2024.002" />);

        await user.click(screen.getByRole('button', { name: /add related work/i }));

        expect(mockOnAdd).toHaveBeenCalledWith({
            identifier: '10.5880/test.2024.002',
            identifierType: 'DOI',
            relationType: 'References',
        });
    });

    it('does not submit invalid identifiers when Enter is pressed', async () => {
        const user = userEvent.setup();
        mockUseIdentifierValidation.mockReturnValue({
            status: 'invalid',
            metadata: undefined,
        });

        render(<RelatedWorkQuickAdd {...defaultProps} identifier="invalid-doi" />);

        const input = screen.getByPlaceholderText(/10\.5194\/nhess/i);
        input.focus();
        await user.keyboard('{Enter}');

        expect(mockOnAdd).not.toHaveBeenCalled();
    });

    it('shows validation messages for invalid, warning, and valid states', () => {
        mockUseIdentifierValidation.mockReturnValueOnce({
            status: 'invalid',
            metadata: undefined,
        });
        const { rerender } = render(<RelatedWorkQuickAdd {...defaultProps} identifier="invalid-doi" />);
        expect(screen.getByText(/invalid doi format/i)).toBeInTheDocument();

        mockUseIdentifierValidation.mockReturnValueOnce({
            status: 'warning',
            metadata: undefined,
        });
        rerender(<RelatedWorkQuickAdd {...defaultProps} identifier="10.5880/test" />);
        expect(screen.getByText(/could not verify via api/i)).toBeInTheDocument();

        mockUseIdentifierValidation.mockReturnValueOnce({
            status: 'valid',
            metadata: { title: 'Validated Resource Title' },
        });
        rerender(<RelatedWorkQuickAdd {...defaultProps} identifier="10.5880/test" />);
        expect(screen.getByText(/validated resource title/i)).toBeInTheDocument();
    });

    it('shows a loading spinner while validating', () => {
        mockUseIdentifierValidation.mockReturnValue({
            status: 'validating',
            metadata: undefined,
        });

        const { container } = render(<RelatedWorkQuickAdd {...defaultProps} identifier="10.5880/test" />);
        expect(container.querySelector('.animate-spin')).toBeInTheDocument();
    });

    it('renders Most Used and All relation types in the relation select', async () => {
        const user = userEvent.setup();
        render(<RelatedWorkQuickAdd {...defaultProps} />);

        await user.click(screen.getByRole('combobox', { name: /relation type/i }));

        expect(screen.getByText('Most Used')).toBeInTheDocument();
        expect(screen.getByText('All relation types')).toBeInTheDocument();
        expect(screen.getByText('Is Derived From')).toBeInTheDocument();
    });

    it('calls onIdentifierTypeChange when selecting a different identifier type', async () => {
        const user = userEvent.setup();
        render(<RelatedWorkQuickAdd {...defaultProps} />);

        await user.click(screen.getByRole('combobox', { name: /identifier type/i }));
        await user.click(screen.getByRole('option', { name: 'URL' }));

        expect(mockOnIdentifierTypeChange).toHaveBeenCalledWith('URL');
    });

    it('shows and applies the opposite relation suggestion when available', async () => {
        const user = userEvent.setup();
        const { rerender } = render(<RelatedWorkQuickAdd {...defaultProps} relationType="References" />);

        await user.click(screen.getByRole('combobox', { name: /relation type/i }));
        await user.click(screen.getByRole('option', { name: /cites/i }));

        expect(mockOnRelationTypeChange).toHaveBeenCalledWith('Cites');

        rerender(<RelatedWorkQuickAdd {...defaultProps} relationType="Cites" />);

        expect(screen.getByText(/did you mean/i)).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: /use iscitedby/i }));

        expect(mockOnRelationTypeChange).toHaveBeenLastCalledWith('IsCitedBy');
    });

    it('suppresses opposite relation suggestions when the opposite type is filtered out', async () => {
        const user = userEvent.setup();
        const { rerender } = render(
            <RelatedWorkQuickAdd
                {...defaultProps}
                relationType="References"
                activeRelationTypes={['Cites']}
            />,
        );

        await user.click(screen.getByRole('combobox', { name: /relation type/i }));
        await user.click(screen.getByRole('option', { name: /cites/i }));

        rerender(
            <RelatedWorkQuickAdd
                {...defaultProps}
                relationType="Cites"
                activeRelationTypes={['Cites']}
            />,
        );

        expect(screen.queryByText(/did you mean/i)).not.toBeInTheDocument();
    });

    it('filters identifier types and relation groups when active options are constrained', async () => {
        const user = userEvent.setup();
        render(
            <RelatedWorkQuickAdd
                {...defaultProps}
                activeIdentifierTypes={['DOI', 'URL']}
                activeRelationTypes={['Documents']}
            />,
        );

        await user.click(screen.getByRole('combobox', { name: /identifier type/i }));

        expect(screen.getByRole('option', { name: 'DOI' })).toBeInTheDocument();
        expect(screen.getByRole('option', { name: 'URL' })).toBeInTheDocument();
        expect(screen.queryByRole('option', { name: 'Handle' })).not.toBeInTheDocument();

        await user.keyboard('{Escape}');
        await user.click(screen.getByRole('combobox', { name: /relation type/i }));

        expect(screen.queryByText('Most Used')).not.toBeInTheDocument();
        expect(screen.getByText('All relation types')).toBeInTheDocument();
        expect(screen.getByRole('option', { name: /documents/i })).toBeInTheDocument();
    });

    it('handles Enter to add a valid identifier', async () => {
        const user = userEvent.setup();
        mockUseIdentifierValidation.mockReturnValue({
            status: 'valid',
            metadata: undefined,
        });

        render(<RelatedWorkQuickAdd {...defaultProps} identifier="10.5880/test.2024.001" />);

        await user.type(screen.getByPlaceholderText(/10\.5194\/nhess/i), '{Enter}');

        expect(mockOnAdd).toHaveBeenCalled();
    });
});