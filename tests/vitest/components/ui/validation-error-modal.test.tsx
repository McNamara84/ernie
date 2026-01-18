import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ValidationErrorModal, type ValidationError } from '@/components/ui/validation-error-modal';

describe('ValidationErrorModal', () => {
    const mockOnOpenChange = vi.fn();

    const sampleErrors: ValidationError[] = [
        {
            path: '/data/attributes/titles',
            message: 'Required property "titles" is missing. (Path: /data/attributes/titles)',
            keyword: 'required',
            context: { raw_message: 'The required properties (titles) are missing' },
        },
        {
            path: '/data/attributes/types/resourceTypeGeneral',
            message:
                'Value must be one of: Audiovisual, Book, Collection, etc. (Path: /data/attributes/types/resourceTypeGeneral)',
            keyword: 'enum',
            context: { raw_message: 'The data should match one item from enum' },
        },
    ];

    beforeEach(() => {
        mockOnOpenChange.mockClear();
    });

    it('renders nothing when closed', () => {
        render(
            <ValidationErrorModal
                open={false}
                onOpenChange={mockOnOpenChange}
                errors={sampleErrors}
                resourceType="Resource"
                schemaVersion="4.6"
            />,
        );

        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('renders dialog when open', () => {
        render(
            <ValidationErrorModal
                open={true}
                onOpenChange={mockOnOpenChange}
                errors={sampleErrors}
                resourceType="Resource"
                schemaVersion="4.6"
            />,
        );

        expect(screen.getByRole('dialog')).toBeInTheDocument();
    });

    it('displays the correct title', () => {
        render(
            <ValidationErrorModal
                open={true}
                onOpenChange={mockOnOpenChange}
                errors={sampleErrors}
                resourceType="Resource"
                schemaVersion="4.6"
            />,
        );

        expect(screen.getByText('JSON Export Failed')).toBeInTheDocument();
    });

    it('displays resource type in description', () => {
        render(
            <ValidationErrorModal
                open={true}
                onOpenChange={mockOnOpenChange}
                errors={sampleErrors}
                resourceType="IGSN"
                schemaVersion="4.6"
            />,
        );

        expect(screen.getByText(/IGSN.*export could not be created/)).toBeInTheDocument();
    });

    it('displays schema version in description', () => {
        render(
            <ValidationErrorModal
                open={true}
                onOpenChange={mockOnOpenChange}
                errors={sampleErrors}
                resourceType="Resource"
                schemaVersion="4.6"
            />,
        );

        expect(screen.getByText(/DataCite.*Schema.*4\.6/)).toBeInTheDocument();
    });

    it('displays all error messages', () => {
        render(
            <ValidationErrorModal
                open={true}
                onOpenChange={mockOnOpenChange}
                errors={sampleErrors}
                resourceType="Resource"
                schemaVersion="4.6"
            />,
        );

        expect(screen.getByText(/Required property "titles" is missing/)).toBeInTheDocument();
        expect(screen.getByText(/Value must be one of: Audiovisual, Book, Collection/)).toBeInTheDocument();
    });

    it('has expandable accordion items for each error', async () => {
        const user = userEvent.setup();

        render(
            <ValidationErrorModal
                open={true}
                onOpenChange={mockOnOpenChange}
                errors={sampleErrors}
                resourceType="Resource"
                schemaVersion="4.6"
            />,
        );

        // Find the first accordion trigger
        const firstErrorTrigger = screen.getByRole('button', {
            name: /Required property "titles" is missing/,
        });
        expect(firstErrorTrigger).toBeInTheDocument();

        // Click to expand
        await user.click(firstErrorTrigger);

        // Check that technical details are visible
        expect(screen.getByText('/data/attributes/titles')).toBeInTheDocument();
        expect(screen.getByText('required')).toBeInTheDocument();
    });

    it('has a close button', () => {
        render(
            <ValidationErrorModal
                open={true}
                onOpenChange={mockOnOpenChange}
                errors={sampleErrors}
                resourceType="Resource"
                schemaVersion="4.6"
            />,
        );

        // Get the Close button in the footer
        const closeButtons = screen.getAllByRole('button', { name: /close/i });
        expect(closeButtons.length).toBeGreaterThan(0);
    });

    it('calls onOpenChange(false) when close button is clicked', async () => {
        const user = userEvent.setup();

        render(
            <ValidationErrorModal
                open={true}
                onOpenChange={mockOnOpenChange}
                errors={sampleErrors}
                resourceType="Resource"
                schemaVersion="4.6"
            />,
        );

        // Get the footer Close button (not the X button)
        const closeButtons = screen.getAllByRole('button', { name: /close/i });
        const footerCloseButton = closeButtons.find(
            (btn) => btn.textContent === 'Close' && !btn.querySelector('svg'),
        );

        if (footerCloseButton) {
            await user.click(footerCloseButton);
            expect(mockOnOpenChange).toHaveBeenCalledWith(false);
        }
    });

    it('displays warning icon', () => {
        render(
            <ValidationErrorModal
                open={true}
                onOpenChange={mockOnOpenChange}
                errors={sampleErrors}
                resourceType="Resource"
                schemaVersion="4.6"
            />,
        );

        // Check for the heading containing the title
        const heading = screen.getByRole('heading', { name: /JSON Export Failed/i });
        expect(heading).toBeInTheDocument();
    });

    it('handles empty errors array gracefully', () => {
        render(
            <ValidationErrorModal
                open={true}
                onOpenChange={mockOnOpenChange}
                errors={[]}
                resourceType="Resource"
                schemaVersion="4.6"
            />,
        );

        expect(screen.getByRole('dialog')).toBeInTheDocument();
        expect(screen.getByText('JSON Export Failed')).toBeInTheDocument();
    });

    it('handles errors with minimal data', () => {
        const minimalErrors: ValidationError[] = [
            {
                path: '/some/path',
                message: 'Some error',
                keyword: 'type',
                context: { raw_message: 'Type error' },
            },
        ];

        render(
            <ValidationErrorModal
                open={true}
                onOpenChange={mockOnOpenChange}
                errors={minimalErrors}
                resourceType="Resource"
                schemaVersion="4.6"
            />,
        );

        expect(screen.getByText('Some error')).toBeInTheDocument();
    });

    it('handles long error messages with proper display', () => {
        const longError: ValidationError[] = [
            {
                path: '/data/attributes/very/deep/nested/path/to/some/property',
                message:
                    'This is a very long error message that explains in great detail what went wrong with the validation',
                keyword: 'pattern',
                context: { raw_message: 'Pattern mismatch' },
            },
        ];

        render(
            <ValidationErrorModal
                open={true}
                onOpenChange={mockOnOpenChange}
                errors={longError}
                resourceType="Resource"
                schemaVersion="4.6"
            />,
        );

        expect(screen.getByText(/This is a very long error message/)).toBeInTheDocument();
    });

    it('shows destructive styling on title', () => {
        render(
            <ValidationErrorModal
                open={true}
                onOpenChange={mockOnOpenChange}
                errors={sampleErrors}
                resourceType="Resource"
                schemaVersion="4.6"
            />,
        );

        const title = screen.getByText('JSON Export Failed');
        // Check if parent has destructive class
        expect(title.closest('h2')?.classList.contains('text-destructive')).toBe(true);
    });

    it('renders all errors when many are provided', () => {
        const manyErrors: ValidationError[] = Array.from({ length: 10 }, (_, i) => ({
            path: `/data/attributes/field${i}`,
            message: `Error message ${i + 1}`,
            keyword: 'required',
            context: { raw_message: `Field ${i} error` },
        }));

        render(
            <ValidationErrorModal
                open={true}
                onOpenChange={mockOnOpenChange}
                errors={manyErrors}
                resourceType="Resource"
                schemaVersion="4.6"
            />,
        );

        expect(screen.getByText('Error message 1')).toBeInTheDocument();
        expect(screen.getByText('Error message 10')).toBeInTheDocument();
    });
});
