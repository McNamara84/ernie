/**
 * @vitest-environment jsdom
 */
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ResourceHero } from '@/pages/LandingPages/components/ResourceHero';

// Mock navigator.clipboard
const mockClipboard = {
    writeText: vi.fn(),
};

beforeEach(() => {
    vi.clearAllMocks();
    Object.assign(navigator, {
        clipboard: mockClipboard,
    });
});

const defaultProps = {
    resourceType: 'Dataset',
    status: 'published',
    mainTitle: 'Test Dataset Title',
    citation: 'Doe, J. (2024). Test Dataset. GFZ Data Services. https://doi.org/10.5880/test.2024',
};

describe('ResourceHero', () => {
    describe('rendering', () => {
        it('renders the main title', () => {
            render(<ResourceHero {...defaultProps} />);
            
            expect(screen.getByRole('heading', { level: 1, name: 'Test Dataset Title' })).toBeInTheDocument();
        });

        it('renders subtitle when provided', () => {
            render(<ResourceHero {...defaultProps} subtitle="A supplementary dataset" />);
            
            expect(screen.getByRole('heading', { level: 2, name: 'A supplementary dataset' })).toBeInTheDocument();
        });

        it('does not render subtitle when not provided', () => {
            render(<ResourceHero {...defaultProps} />);
            
            expect(screen.queryByRole('heading', { level: 2 })).not.toBeInTheDocument();
        });

        it('renders the resource type label', () => {
            render(<ResourceHero {...defaultProps} />);
            
            expect(screen.getByText('Dataset')).toBeInTheDocument();
        });

        it('renders the citation text', () => {
            render(<ResourceHero {...defaultProps} />);
            
            expect(screen.getByText(/Doe, J\. \(2024\)\. Test Dataset\./)).toBeInTheDocument();
        });
    });

    describe('resource type icon', () => {
        it('renders an icon for Dataset resource type', () => {
            render(<ResourceHero {...defaultProps} resourceType="Dataset" />);
            
            // Icon is rendered as SVG, check parent container has text
            expect(screen.getByText('Dataset')).toBeInTheDocument();
        });

        it('renders an icon for Software resource type', () => {
            render(<ResourceHero {...defaultProps} resourceType="Software" />);
            
            expect(screen.getByText('Software')).toBeInTheDocument();
        });
    });

    describe('status display', () => {
        it('displays Published status with label', () => {
            render(<ResourceHero {...defaultProps} status="published" />);
            
            expect(screen.getByText('Published')).toBeInTheDocument();
        });

        it('displays Draft status with label', () => {
            render(<ResourceHero {...defaultProps} status="draft" />);
            
            expect(screen.getByText('Draft')).toBeInTheDocument();
        });

        it('displays Preview status with label', () => {
            render(<ResourceHero {...defaultProps} status="preview" />);
            
            // StatusConfig shows "Review Preview" for preview status
            expect(screen.getByText('Review Preview')).toBeInTheDocument();
        });
    });

    describe('copy citation functionality', () => {
        it('renders copy button with correct aria-label', () => {
            render(<ResourceHero {...defaultProps} />);
            
            expect(screen.getByRole('button', { name: 'Copy citation to clipboard' })).toBeInTheDocument();
        });

        it('copies citation to clipboard when copy button is clicked', async () => {
            mockClipboard.writeText.mockResolvedValueOnce(undefined);
            
            render(<ResourceHero {...defaultProps} />);
            
            const copyButton = screen.getByRole('button', { name: 'Copy citation to clipboard' });
            fireEvent.click(copyButton);
            
            await waitFor(() => {
                expect(mockClipboard.writeText).toHaveBeenCalledWith(defaultProps.citation);
            });
        });

        it('shows success message after copying', async () => {
            mockClipboard.writeText.mockResolvedValueOnce(undefined);
            
            render(<ResourceHero {...defaultProps} />);
            
            fireEvent.click(screen.getByRole('button', { name: 'Copy citation to clipboard' }));
            
            await waitFor(() => {
                expect(screen.getByText('Citation copied to clipboard!')).toBeInTheDocument();
            });
        });

        it('updates button title after copying', async () => {
            mockClipboard.writeText.mockResolvedValueOnce(undefined);
            
            render(<ResourceHero {...defaultProps} />);
            
            const copyButton = screen.getByRole('button', { name: 'Copy citation to clipboard' });
            expect(copyButton).toHaveAttribute('title', 'Copy citation');
            
            fireEvent.click(copyButton);
            
            await waitFor(() => {
                expect(copyButton).toHaveAttribute('title', 'Copied!');
            });
        });

        it('handles clipboard write failure silently', async () => {
            mockClipboard.writeText.mockRejectedValueOnce(new Error('Clipboard access denied'));
            
            render(<ResourceHero {...defaultProps} />);
            
            const copyButton = screen.getByRole('button', { name: 'Copy citation to clipboard' });
            
            // Should not throw
            fireEvent.click(copyButton);
            
            await waitFor(() => {
                expect(mockClipboard.writeText).toHaveBeenCalled();
            });
            
            // Success message should not appear
            expect(screen.queryByText('Citation copied to clipboard!')).not.toBeInTheDocument();
        });
    });
});
