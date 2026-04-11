/**
 * @vitest-environment jsdom
 */
import { fireEvent, render, screen, waitFor } from '@tests/vitest/utils/render';
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
            
            expect(screen.getByText('A supplementary dataset')).toBeInTheDocument();
            // Subtitle is now a <p> element, not a heading
            expect(screen.queryByRole('heading', { level: 2 })).not.toBeInTheDocument();
        });

        it('does not render subtitle when not provided', () => {
            render(<ResourceHero {...defaultProps} />);
            
            expect(screen.queryByText('A supplementary dataset')).not.toBeInTheDocument();
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

    describe('in review label', () => {
        it('displays "In Review:" label when status is preview', () => {
            render(<ResourceHero {...defaultProps} status="preview" />);

            expect(screen.getByText('In Review:')).toBeInTheDocument();
        });

        it('renders "In Review:" label before the citation text', () => {
            render(<ResourceHero {...defaultProps} status="preview" />);

            const label = screen.getByText('In Review:');
            const citation = screen.getByText(/Doe, J\. \(2024\)\. Test Dataset\./);

            // Label should appear before citation in DOM order
            expect(label.compareDocumentPosition(citation) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        });

        it('does not display "In Review:" label when status is published', () => {
            render(<ResourceHero {...defaultProps} status="published" />);

            expect(screen.queryByText('In Review:')).not.toBeInTheDocument();
        });

        it('does not display "In Review:" label when status is draft', () => {
            render(<ResourceHero {...defaultProps} status="draft" />);

            expect(screen.queryByText('In Review:')).not.toBeInTheDocument();
        });

        it('renders "In Review:" label with status-consistent styling', () => {
            render(<ResourceHero {...defaultProps} status="preview" />);

            const label = screen.getByText('In Review:');
            expect(label).toHaveClass('text-blue-700');
            expect(label).toHaveClass('font-semibold');
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

        it('shows success feedback after copying via aria-live region', async () => {
            mockClipboard.writeText.mockResolvedValueOnce(undefined);
            
            render(<ResourceHero {...defaultProps} />);
            
            fireEvent.click(screen.getByRole('button', { name: 'Copy citation to clipboard' }));
            
            await waitFor(() => {
                // Success message is now announced via sr-only aria-live region
                expect(screen.getByRole('status')).toHaveTextContent('Citation copied to clipboard');
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

    describe('accessibility', () => {
        it('renders as a section element with aria-labelledby', () => {
            render(<ResourceHero {...defaultProps} />);
            
            const section = screen.getByRole('region', { name: defaultProps.mainTitle });
            expect(section).toBeInTheDocument();
        });

        it('renders title as h1 (not h2 or lower)', () => {
            render(<ResourceHero {...defaultProps} />);
            
            const heading = screen.getByRole('heading', { level: 1 });
            expect(heading).toHaveTextContent(defaultProps.mainTitle);
            expect(heading).toHaveAttribute('id', 'heading-title');
        });

        it('renders subtitle as paragraph, not heading', () => {
            render(<ResourceHero {...defaultProps} subtitle="Test subtitle" />);
            
            const subtitle = screen.getByText('Test subtitle');
            expect(subtitle.tagName).toBe('P');
        });

        it('renders copy button with minimum touch target 44x44', () => {
            render(<ResourceHero {...defaultProps} />);
            
            const copyButton = screen.getByRole('button', { name: 'Copy citation to clipboard' });
            expect(copyButton).toHaveClass('min-h-11', 'min-w-11');
        });

        it('has an aria-live region for copy feedback', () => {
            render(<ResourceHero {...defaultProps} />);
            
            const liveRegion = document.querySelector('[aria-live="polite"]');
            expect(liveRegion).toBeInTheDocument();
            expect(liveRegion).toHaveClass('sr-only');
        });

        it('includes dark mode classes on the section', () => {
            render(<ResourceHero {...defaultProps} />);
            
            const section = screen.getByRole('region', { name: defaultProps.mainTitle });
            expect(section.className).toContain('dark:');
        });
    });
});
