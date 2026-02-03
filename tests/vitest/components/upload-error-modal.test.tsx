import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { UploadErrorModal } from '@/components/upload-error-modal';
import type { UploadError } from '@/types/upload';

describe('UploadErrorModal', () => {
    const defaultProps = {
        open: true,
        onClose: vi.fn(),
        filename: 'test-file.csv',
        message: 'Upload failed due to validation errors',
        errors: [] as UploadError[],
    };

    it('renders modal with title and message', () => {
        render(<UploadErrorModal {...defaultProps} />);

        expect(screen.getByText('Upload Failed')).toBeInTheDocument();
        expect(screen.getByText(/test-file\.csv/)).toBeInTheDocument();
        expect(screen.getByText(/Upload failed due to validation errors/)).toBeInTheDocument();
    });

    it('displays single error correctly', () => {
        const errors: UploadError[] = [
            {
                category: 'validation',
                code: 'file_too_large',
                message: 'File exceeds maximum size of 10MB',
            },
        ];

        render(<UploadErrorModal {...defaultProps} errors={errors} />);

        expect(screen.getByText('Validation Errors')).toBeInTheDocument();
        expect(screen.getByText('File exceeds maximum size of 10MB')).toBeInTheDocument();
    });

    it('groups errors by category', () => {
        const errors: UploadError[] = [
            {
                category: 'validation',
                code: 'missing_field',
                message: 'IGSN is required',
                row: 2,
            },
            {
                category: 'data',
                code: 'duplicate_igsn',
                message: 'IGSN already exists',
                row: 3,
                identifier: 'IGSN001',
            },
            {
                category: 'validation',
                code: 'missing_field',
                message: 'Title is required',
                row: 4,
            },
        ];

        render(<UploadErrorModal {...defaultProps} errors={errors} />);

        expect(screen.getByText('Validation Errors')).toBeInTheDocument();
        expect(screen.getByText('Data Errors')).toBeInTheDocument();

        // Check counts
        expect(screen.getByText('(2)')).toBeInTheDocument(); // 2 validation errors
        expect(screen.getByText('(1)')).toBeInTheDocument(); // 1 data error
    });

    it('displays row numbers when provided', () => {
        const errors: UploadError[] = [
            {
                category: 'data',
                code: 'duplicate_igsn',
                message: 'IGSN already exists',
                row: 5,
            },
        ];

        render(<UploadErrorModal {...defaultProps} errors={errors} />);

        expect(screen.getByText(/Row 5:/)).toBeInTheDocument();
    });

    it('displays identifiers when provided', () => {
        const errors: UploadError[] = [
            {
                category: 'data',
                code: 'duplicate_igsn',
                message: 'IGSN already exists',
                identifier: 'IGSN_TEST_001',
            },
        ];

        render(<UploadErrorModal {...defaultProps} errors={errors} />);

        expect(screen.getByText('IGSN_TEST_001')).toBeInTheDocument();
    });

    it('calls onClose when Close button is clicked', async () => {
        const user = userEvent.setup();
        const onClose = vi.fn();

        render(<UploadErrorModal {...defaultProps} onClose={onClose} />);

        // Use getAllByRole and select the footer button (not the dialog X button)
        const closeButtons = screen.getAllByRole('button', { name: 'Close' });
        // The footer button is the first one in the DOM order within DialogFooter
        const footerCloseButton = closeButtons.find((btn) => btn.getAttribute('data-slot') === 'button');
        expect(footerCloseButton).toBeDefined();
        await user.click(footerCloseButton!);

        expect(onClose).toHaveBeenCalled();
    });

    it('calls onRetry when Try Again button is clicked', async () => {
        const user = userEvent.setup();
        const onRetry = vi.fn();

        render(<UploadErrorModal {...defaultProps} onRetry={onRetry} />);

        await user.click(screen.getByRole('button', { name: 'Try Again' }));

        expect(onRetry).toHaveBeenCalled();
    });

    it('does not render Try Again button when onRetry is not provided', () => {
        render(<UploadErrorModal {...defaultProps} onRetry={undefined} />);

        expect(screen.queryByRole('button', { name: 'Try Again' })).not.toBeInTheDocument();
    });

    it('handles server errors category', () => {
        const errors: UploadError[] = [
            {
                category: 'server',
                code: 'database_error',
                message: 'Failed to save to database',
            },
        ];

        render(<UploadErrorModal {...defaultProps} errors={errors} />);

        expect(screen.getByText('Server Errors')).toBeInTheDocument();
    });

    it('renders nothing when not open', () => {
        render(<UploadErrorModal {...defaultProps} open={false} />);

        expect(screen.queryByText('Upload Failed')).not.toBeInTheDocument();
    });
});
