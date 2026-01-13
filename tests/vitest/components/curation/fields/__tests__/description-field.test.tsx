import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import DescriptionField, { DescriptionEntry } from '@/components/curation/fields/description-field';

describe('DescriptionField', () => {
    const mockOnChange = vi.fn();
    const mockOnAbstractValidationBlur = vi.fn();

    const defaultDescriptions: DescriptionEntry[] = [];

    const defaultProps = {
        descriptions: defaultDescriptions,
        onChange: mockOnChange,
    };

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders all description type tabs', () => {
        render(<DescriptionField {...defaultProps} />);

        expect(screen.getByRole('tab', { name: /Abstract/i })).toBeInTheDocument();
        expect(screen.getByRole('tab', { name: /Methods/i })).toBeInTheDocument();
        expect(screen.getByRole('tab', { name: /Series Information/i })).toBeInTheDocument();
        expect(screen.getByRole('tab', { name: /Table of Contents/i })).toBeInTheDocument();
        expect(screen.getByRole('tab', { name: /Technical Info/i })).toBeInTheDocument();
        expect(screen.getByRole('tab', { name: /Other/i })).toBeInTheDocument();
    });

    it('shows Abstract tab as active by default', () => {
        render(<DescriptionField {...defaultProps} />);

        const abstractTab = screen.getByRole('tab', { name: /Abstract/i });
        expect(abstractTab).toHaveAttribute('aria-selected', 'true');
    });

    it('renders Abstract textarea with placeholder', () => {
        render(<DescriptionField {...defaultProps} />);

        const textarea = screen.getByPlaceholderText(/Enter a brief summary of the resource/i);
        expect(textarea).toBeInTheDocument();
    });

    it('marks Abstract as required', () => {
        render(<DescriptionField {...defaultProps} />);

        expect(screen.getByText('(Required)')).toBeInTheDocument();
    });

    it('calls onChange when typing in Abstract', async () => {
        const user = userEvent.setup();
        render(<DescriptionField {...defaultProps} />);

        const textarea = screen.getByPlaceholderText(/Enter a brief summary of the resource/i);
        await user.type(textarea, 'Test abstract content');

        expect(mockOnChange).toHaveBeenCalled();
    });

    it('switches tabs when clicking on Methods', async () => {
        const user = userEvent.setup();
        render(<DescriptionField {...defaultProps} />);

        await user.click(screen.getByRole('tab', { name: /Methods/i }));

        const methodsTab = screen.getByRole('tab', { name: /Methods/i });
        expect(methodsTab).toHaveAttribute('aria-selected', 'true');
    });

    it('displays prefilled Abstract value', () => {
        const descriptions: DescriptionEntry[] = [{ type: 'Abstract', value: 'Prefilled abstract text' }];
        render(<DescriptionField {...defaultProps} descriptions={descriptions} />);

        const textarea = screen.getByTestId('abstract-textarea');
        expect(textarea).toHaveValue('Prefilled abstract text');
    });

    it('displays character count for Abstract', () => {
        const descriptions: DescriptionEntry[] = [{ type: 'Abstract', value: 'Test' }];
        render(<DescriptionField {...defaultProps} descriptions={descriptions} />);

        expect(screen.getByText(/4 characters/)).toBeInTheDocument();
    });

    it('shows "more needed" message when Abstract is too short', () => {
        const descriptions: DescriptionEntry[] = [{ type: 'Abstract', value: 'Short' }];
        render(<DescriptionField {...defaultProps} descriptions={descriptions} />);

        expect(screen.getByText(/more needed/)).toBeInTheDocument();
    });

    it('shows green indicator when tab has content', () => {
        const descriptions: DescriptionEntry[] = [{ type: 'Methods', value: 'Method description content' }];
        render(<DescriptionField {...defaultProps} descriptions={descriptions} />);

        const methodsTab = screen.getByRole('tab', { name: /Methods/i });
        const indicator = methodsTab.querySelector('[aria-label="Has content"]');
        expect(indicator).toBeInTheDocument();
    });

    it('does not show indicator when tab has no content', () => {
        render(<DescriptionField {...defaultProps} />);

        const methodsTab = screen.getByRole('tab', { name: /Methods/i });
        const indicator = methodsTab.querySelector('[aria-label="Has content"]');
        expect(indicator).not.toBeInTheDocument();
    });

    it('displays help text for Abstract', () => {
        render(<DescriptionField {...defaultProps} />);

        expect(screen.getByText(/A brief description of the resource/)).toBeInTheDocument();
    });

    it('calls onAbstractValidationBlur when Abstract loses focus', async () => {
        const user = userEvent.setup();
        render(<DescriptionField {...defaultProps} onAbstractValidationBlur={mockOnAbstractValidationBlur} />);

        const textarea = screen.getByTestId('abstract-textarea');
        await user.click(textarea);
        await user.tab(); // Move focus away

        expect(mockOnAbstractValidationBlur).toHaveBeenCalled();
    });

    it('shows validation messages when Abstract is touched and has errors', () => {
        const validationMessages = [{ type: 'error' as const, message: 'Abstract is required' }];
        render(
            <DescriptionField
                {...defaultProps}
                abstractValidationMessages={validationMessages}
                abstractTouched={true}
            />,
        );

        expect(screen.getByText('Abstract is required')).toBeInTheDocument();
    });

    it('does not show validation messages when Abstract is not touched', () => {
        const validationMessages = [{ type: 'error' as const, message: 'Abstract is required' }];
        render(
            <DescriptionField
                {...defaultProps}
                abstractValidationMessages={validationMessages}
                abstractTouched={false}
            />,
        );

        expect(screen.queryByText('Abstract is required')).not.toBeInTheDocument();
    });

    it('renders Methods tab content when selected', async () => {
        const user = userEvent.setup();
        render(<DescriptionField {...defaultProps} />);

        await user.click(screen.getByRole('tab', { name: /Methods/i }));

        expect(screen.getByPlaceholderText(/Describe the methods used/i)).toBeInTheDocument();
        expect(screen.getByText('(Optional)')).toBeInTheDocument();
    });

    it('updates existing description when typing', async () => {
        const user = userEvent.setup();
        const descriptions: DescriptionEntry[] = [{ type: 'Abstract', value: 'Initial' }];
        render(<DescriptionField {...defaultProps} descriptions={descriptions} />);

        const textarea = screen.getByTestId('abstract-textarea');
        await user.type(textarea, ' more');

        expect(mockOnChange).toHaveBeenCalled();
        // The onChange should be called with updated description
        const lastCall = mockOnChange.mock.calls[mockOnChange.mock.calls.length - 1][0];
        expect(lastCall[0].type).toBe('Abstract');
    });

    it('sets aria-invalid on Abstract when validation fails', () => {
        const validationMessages = [{ type: 'error' as const, message: 'Required' }];
        render(
            <DescriptionField
                {...defaultProps}
                abstractValidationMessages={validationMessages}
                abstractTouched={true}
            />,
        );

        const textarea = screen.getByTestId('abstract-textarea');
        expect(textarea).toHaveAttribute('aria-invalid', 'true');
    });

    it('renders all help texts for different description types', async () => {
        const user = userEvent.setup();
        render(<DescriptionField {...defaultProps} />);

        // Check Series Information help text
        await user.click(screen.getByRole('tab', { name: /Series Information/i }));
        expect(screen.getByText(/Information about a repeating series/)).toBeInTheDocument();

        // Check Technical Info help text
        await user.click(screen.getByRole('tab', { name: /Technical Info/i }));
        expect(screen.getByText(/Detailed information that may be associated/)).toBeInTheDocument();
    });
});
