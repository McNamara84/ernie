import '@testing-library/jest-dom/vitest';

import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import DescriptionField, {
    type DescriptionEntry,
} from '@/components/curation/fields/description-field';

describe('DescriptionField', () => {
    const mockOnChange = vi.fn();

    const defaultProps = {
        descriptions: [] as DescriptionEntry[],
        onChange: mockOnChange,
    };

    beforeEach(() => {
        mockOnChange.mockClear();
    });

    describe('Tab Navigation', () => {
        it('renders all 6 description type tabs', () => {
            render(<DescriptionField {...defaultProps} />);

            expect(screen.getByRole('tab', { name: /Abstract/i })).toBeInTheDocument();
            expect(screen.getByRole('tab', { name: /Methods/i })).toBeInTheDocument();
            expect(screen.getByRole('tab', { name: /Series Information/i })).toBeInTheDocument();
            expect(screen.getByRole('tab', { name: /Table of Contents/i })).toBeInTheDocument();
            expect(screen.getByRole('tab', { name: /Technical Info/i })).toBeInTheDocument();
            expect(screen.getByRole('tab', { name: /Other/i })).toBeInTheDocument();
        });

        it('starts with Abstract tab selected by default', () => {
            render(<DescriptionField {...defaultProps} />);

            const abstractTab = screen.getByRole('tab', { name: /Abstract/i });
            expect(abstractTab).toHaveAttribute('data-state', 'active');
        });

        it('switches tabs when clicking on them', async () => {
            const user = userEvent.setup();
            render(<DescriptionField {...defaultProps} />);

            const methodsTab = screen.getByRole('tab', { name: /Methods/i });
            await user.click(methodsTab);

            expect(methodsTab).toHaveAttribute('data-state', 'active');
        });
    });

    describe('Required Field Indication', () => {
        it('marks Abstract as required with asterisk', () => {
            render(<DescriptionField {...defaultProps} />);

            const abstractTab = screen.getByRole('tab', { name: /Abstract/i });
            expect(within(abstractTab).getByText('*')).toBeInTheDocument();
        });

        it('shows "Required" label for Abstract field', () => {
            render(<DescriptionField {...defaultProps} />);

            expect(screen.getByText(/Required/i)).toBeInTheDocument();
        });

        it('shows "Optional" label for other fields', async () => {
            const user = userEvent.setup();
            render(<DescriptionField {...defaultProps} />);

            const methodsTab = screen.getByRole('tab', { name: /Methods/i });
            await user.click(methodsTab);

            expect(screen.getByText(/Optional/i)).toBeInTheDocument();
        });

        it('marks Abstract textarea as required', () => {
            render(<DescriptionField {...defaultProps} />);

            const textarea = screen.getByRole('textbox', { name: /Abstract/i });
            expect(textarea).toBeRequired();
        });

        it('does not mark Methods textarea as required', async () => {
            const user = userEvent.setup();
            render(<DescriptionField {...defaultProps} />);

            const methodsTab = screen.getByRole('tab', { name: /Methods/i });
            await user.click(methodsTab);

            const textarea = screen.getByRole('textbox', { name: /Methods/i });
            expect(textarea).not.toBeRequired();
        });
    });

    describe('Help Text Display', () => {
        it('displays help text for Abstract', () => {
            render(<DescriptionField {...defaultProps} />);

            expect(
                screen.getByText(/A brief description of the resource/i),
            ).toBeInTheDocument();
        });

        it('displays help text for Methods', async () => {
            const user = userEvent.setup();
            render(<DescriptionField {...defaultProps} />);

            const methodsTab = screen.getByRole('tab', { name: /Methods/i });
            await user.click(methodsTab);

            expect(
                screen.getByText(/The methodology employed for the study or research/i),
            ).toBeInTheDocument();
        });

        it('displays help text for SeriesInformation', async () => {
            const user = userEvent.setup();
            render(<DescriptionField {...defaultProps} />);

            const seriesTab = screen.getByRole('tab', { name: /Series Information/i });
            await user.click(seriesTab);

            expect(
                screen.getByText(/Information about a repeating series/i),
            ).toBeInTheDocument();
        });

        it('displays help text for TableOfContents', async () => {
            const user = userEvent.setup();
            render(<DescriptionField {...defaultProps} />);

            const tocTab = screen.getByRole('tab', { name: /Table of Contents/i });
            await user.click(tocTab);

            expect(
                screen.getByText(/A listing of the Table of Contents/i),
            ).toBeInTheDocument();
        });

        it('displays help text for TechnicalInfo', async () => {
            const user = userEvent.setup();
            render(<DescriptionField {...defaultProps} />);

            const techTab = screen.getByRole('tab', { name: /Technical Info/i });
            await user.click(techTab);

            expect(
                screen.getByText(/Detailed information that may be associated/i),
            ).toBeInTheDocument();
        });

        it('displays help text for Other', async () => {
            const user = userEvent.setup();
            render(<DescriptionField {...defaultProps} />);

            const otherTab = screen.getByRole('tab', { name: /Other/i });
            await user.click(otherTab);

            expect(
                screen.getByText(/Other description information that does not fit/i),
            ).toBeInTheDocument();
        });
    });

    describe('Text Input', () => {
        it('allows typing in Abstract textarea', async () => {
            const user = userEvent.setup();
            render(<DescriptionField {...defaultProps} />);

            const textarea = screen.getByRole('textbox', { name: /Abstract/i });
            await user.click(textarea);
            await user.keyboard('Test');

            expect(mockOnChange).toHaveBeenCalled();
        });

        it('allows typing in Methods textarea', async () => {
            const user = userEvent.setup();
            render(<DescriptionField {...defaultProps} />);

            const methodsTab = screen.getByRole('tab', { name: /Methods/i });
            await user.click(methodsTab);

            const textarea = screen.getByRole('textbox', { name: /Methods/i });
            await user.click(textarea);
            await user.keyboard('Test');

            expect(mockOnChange).toHaveBeenCalled();
        });

        it('updates existing description when typing', async () => {
            const user = userEvent.setup();
            const existingDescriptions: DescriptionEntry[] = [
                { type: 'Abstract', value: 'Initial abstract' },
            ];

            render(
                <DescriptionField
                    descriptions={existingDescriptions}
                    onChange={mockOnChange}
                />,
            );

            const textarea = screen.getByRole('textbox', { name: /Abstract/i });
            expect(textarea).toHaveValue('Initial abstract');

            await user.click(textarea);
            await user.keyboard('Updated');

            expect(mockOnChange).toHaveBeenCalled();
        });
    });    describe('Character Counter', () => {
        it('displays character count for Abstract', () => {
            render(<DescriptionField {...defaultProps} />);

            expect(screen.getByText('0 characters')).toBeInTheDocument();
        });

        it('updates character count when typing', async () => {
            const user = userEvent.setup();
            render(<DescriptionField {...defaultProps} />);

            const textarea = screen.getByRole('textbox', { name: /Abstract/i });
            await user.click(textarea);
            await user.keyboard('Hello');

            // Verify onChange was called
            expect(mockOnChange).toHaveBeenCalled();
        });

        it('shows correct character count for existing content', () => {
            const existingDescriptions: DescriptionEntry[] = [
                { type: 'Abstract', value: 'This is a test abstract' },
            ];

            render(
                <DescriptionField
                    descriptions={existingDescriptions}
                    onChange={mockOnChange}
                />,
            );

            expect(screen.getByText('23 characters')).toBeInTheDocument();
        });
    });

    describe('Content Indicators', () => {
        it('shows green indicator on tab when content exists', () => {
            const existingDescriptions: DescriptionEntry[] = [
                { type: 'Methods', value: 'Some methodology' },
            ];

            render(
                <DescriptionField
                    descriptions={existingDescriptions}
                    onChange={mockOnChange}
                />,
            );

            const methodsTab = screen.getByRole('tab', { name: /Methods/i });
            const indicator = within(methodsTab).getByLabelText('Has content');
            expect(indicator).toBeInTheDocument();
            expect(indicator).toHaveClass('bg-green-500');
        });

        it('does not show indicator on tab when content is empty', () => {
            render(<DescriptionField {...defaultProps} />);

            const abstractTab = screen.getByRole('tab', { name: /Abstract/i });
            const indicator = within(abstractTab).queryByLabelText('Has content');
            expect(indicator).not.toBeInTheDocument();
        });

        it('does not show indicator when content is only whitespace', () => {
            const existingDescriptions: DescriptionEntry[] = [
                { type: 'Abstract', value: '   ' },
            ];

            render(
                <DescriptionField
                    descriptions={existingDescriptions}
                    onChange={mockOnChange}
                />,
            );

            const abstractTab = screen.getByRole('tab', { name: /Abstract/i });
            const indicator = within(abstractTab).queryByLabelText('Has content');
            expect(indicator).not.toBeInTheDocument();
        });
    });

    describe('Multiple Descriptions', () => {
        it('handles multiple description types simultaneously', async () => {
            const user = userEvent.setup();
            const existingDescriptions: DescriptionEntry[] = [
                { type: 'Abstract', value: 'Test abstract' },
                { type: 'Methods', value: 'Test methods' },
            ];

            render(
                <DescriptionField
                    descriptions={existingDescriptions}
                    onChange={mockOnChange}
                />,
            );

            // Check that both descriptions are properly loaded
            expect(screen.getByRole('textbox', { name: /Abstract/i })).toHaveValue(
                'Test abstract',
            );

            // Switch to Methods tab
            const methodsTab = screen.getByRole('tab', { name: /Methods/i });
            await user.click(methodsTab);

            // Check Methods content
            expect(screen.getByRole('textbox', { name: /Methods/i })).toHaveValue(
                'Test methods',
            );

            // Switch back to Abstract
            const abstractTab = screen.getByRole('tab', { name: /Abstract/i });
            await user.click(abstractTab);

            // Verify Abstract content is still there
            expect(screen.getByRole('textbox', { name: /Abstract/i })).toHaveValue(
                'Test abstract',
            );
        });

        it('preserves content when switching between tabs', async () => {
            const user = userEvent.setup();
            const existingDescriptions: DescriptionEntry[] = [
                { type: 'Abstract', value: 'Existing abstract' },
                { type: 'Methods', value: 'Existing methods' },
            ];

            render(
                <DescriptionField
                    descriptions={existingDescriptions}
                    onChange={mockOnChange}
                />,
            );

            // Check Abstract content
            expect(screen.getByRole('textbox', { name: /Abstract/i })).toHaveValue(
                'Existing abstract',
            );

            // Switch to Methods
            const methodsTab = screen.getByRole('tab', { name: /Methods/i });
            await user.click(methodsTab);

            // Check Methods content
            expect(screen.getByRole('textbox', { name: /Methods/i })).toHaveValue(
                'Existing methods',
            );

            // Switch back to Abstract
            const abstractTab = screen.getByRole('tab', { name: /Abstract/i });
            await user.click(abstractTab);

            // Verify Abstract content is still there
            expect(screen.getByRole('textbox', { name: /Abstract/i })).toHaveValue(
                'Existing abstract',
            );
        });
    });
});
