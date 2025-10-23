import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import MetadataLinks from '@/components/landing-pages/shared/MetadataLinks';

// ============================================================================
// Test Data
// ============================================================================

const mockResource = {
    id: 123,
    doi: '10.5880/GFZ.TEST.2024.001',
};

const mockResourceWithoutDoi = {
    id: 456,
    doi: null,
};

// ============================================================================
// Test Suite
// ============================================================================

describe('MetadataLinks', () => {
    // ========================================================================
    // Rendering Tests
    // ========================================================================

    describe('Rendering', () => {
        it('should render with default heading', () => {
            render(<MetadataLinks resource={mockResource} />);

            expect(screen.getByRole('heading', { name: 'Metadata Export' })).toBeInTheDocument();
        });

        it('should render with custom heading', () => {
            render(<MetadataLinks resource={mockResource} heading="Download Metadata" />);

            expect(screen.getByRole('heading', { name: 'Download Metadata' })).toBeInTheDocument();
        });

        it('should render all metadata formats', () => {
            render(<MetadataLinks resource={mockResource} />);

            expect(screen.getByText('DataCite JSON')).toBeInTheDocument();
            expect(screen.getByText('DataCite XML')).toBeInTheDocument();
            expect(screen.getByText('ISO 19115')).toBeInTheDocument();
            expect(screen.getByText('Schema.org')).toBeInTheDocument();
        });

        it('should render format descriptions by default', () => {
            render(<MetadataLinks resource={mockResource} />);

            expect(
                screen.getByText('DataCite Metadata Schema v4.5+ in JSON format'),
            ).toBeInTheDocument();
            expect(
                screen.getByText('DataCite Metadata Schema v4.5+ in XML format'),
            ).toBeInTheDocument();
            expect(
                screen.getByText('Geographic information metadata standard (XML)'),
            ).toBeInTheDocument();
            expect(
                screen.getByText('Structured data for web search engines (JSON-LD)'),
            ).toBeInTheDocument();
        });

        it('should hide descriptions when showDescriptions is false', () => {
            render(<MetadataLinks resource={mockResource} showDescriptions={false} />);

            expect(
                screen.queryByText('DataCite Metadata Schema v4.5+ in JSON format'),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByText('DataCite Metadata Schema v4.5+ in XML format'),
            ).not.toBeInTheDocument();
        });

        it('should render help text', () => {
            render(<MetadataLinks resource={mockResource} />);

            expect(
                screen.getByText(/Download metadata in various formats for use in other systems/),
            ).toBeInTheDocument();
        });
    });

    // ========================================================================
    // DataCite Format Tests (Available)
    // ========================================================================

    describe('DataCite Formats (Available)', () => {
        it('should render DataCite JSON with available badge', () => {
            render(<MetadataLinks resource={mockResource} />);

            const badges = screen.getAllByText('Available');
            expect(badges.length).toBeGreaterThan(0);

            // Check that DataCite JSON has green badge
            const jsonSection = screen.getByText('DataCite JSON').closest('div');
            expect(jsonSection).toBeInTheDocument();
        });

        it('should render DataCite XML with available badge', () => {
            render(<MetadataLinks resource={mockResource} />);

            const badges = screen.getAllByText('Available');
            expect(badges.length).toBe(2); // DataCite JSON + XML
        });

        it('should render download link for DataCite JSON', () => {
            render(<MetadataLinks resource={mockResource} />);

            const jsonLink = screen.getByLabelText('Download DataCite JSON');
            expect(jsonLink).toBeInTheDocument();
            expect(jsonLink).toHaveAttribute('href', '/resources/123/export-datacite-json');
            expect(jsonLink).toHaveAttribute('download');
        });

        it('should render download link for DataCite XML', () => {
            render(<MetadataLinks resource={mockResource} />);

            const xmlLink = screen.getByLabelText('Download DataCite XML');
            expect(xmlLink).toBeInTheDocument();
            expect(xmlLink).toHaveAttribute('href', '/resources/123/export-datacite-xml');
            expect(xmlLink).toHaveAttribute('download');
        });

        it('should render enabled download buttons for DataCite formats', () => {
            render(<MetadataLinks resource={mockResource} />);

            const jsonButton = screen.getByLabelText('Download DataCite JSON');
            const xmlButton = screen.getByLabelText('Download DataCite XML');

            expect(jsonButton).not.toBeDisabled();
            expect(xmlButton).not.toBeDisabled();
        });

        it('should work with resources without DOI', () => {
            render(<MetadataLinks resource={mockResourceWithoutDoi} />);

            const jsonLink = screen.getByLabelText('Download DataCite JSON');
            expect(jsonLink).toHaveAttribute('href', '/resources/456/export-datacite-json');
        });
    });

    // ========================================================================
    // Coming Soon Formats Tests (ISO19115, Schema.org)
    // ========================================================================

    describe('Coming Soon Formats', () => {
        it('should render ISO 19115 with coming soon badge', () => {
            render(<MetadataLinks resource={mockResource} />);

            expect(screen.getByText('ISO 19115')).toBeInTheDocument();
            const badges = screen.getAllByText('Coming Soon');
            expect(badges.length).toBe(2); // ISO19115 + Schema.org
        });

        it('should render Schema.org with coming soon badge', () => {
            render(<MetadataLinks resource={mockResource} />);

            expect(screen.getByText('Schema.org')).toBeInTheDocument();
            expect(screen.getAllByText('Coming Soon')).toHaveLength(2);
        });

        it('should render disabled buttons for coming soon formats', () => {
            render(<MetadataLinks resource={mockResource} />);

            const isoButton = screen.getByLabelText('ISO 19115 not available yet');
            const schemaButton = screen.getByLabelText('Schema.org not available yet');

            expect(isoButton).toBeDisabled();
            expect(schemaButton).toBeDisabled();
        });

        it('should show "Coming Soon" text on disabled buttons', () => {
            render(<MetadataLinks resource={mockResource} />);

            const comingSoonButtons = screen.getAllByRole('button', { name: /Coming Soon/ });
            expect(comingSoonButtons).toHaveLength(2);
        });
    });

    // ========================================================================
    // Badge Color Tests
    // ========================================================================

    describe('Badge Colors', () => {
        it('should apply green badge for available formats', () => {
            render(<MetadataLinks resource={mockResource} />);

            const availableBadges = screen.getAllByText('Available');
            availableBadges.forEach((badge) => {
                expect(badge).toHaveClass('bg-green-100', 'text-green-800');
            });
        });

        it('should apply gray badge for coming soon formats', () => {
            render(<MetadataLinks resource={mockResource} />);

            const comingSoonBadges = screen.getAllByText('Coming Soon');
            comingSoonBadges.forEach((badge) => {
                expect(badge).toHaveClass('bg-gray-100', 'text-gray-600');
            });
        });
    });

    // ========================================================================
    // Format Grid Layout Tests
    // ========================================================================

    describe('Format Grid Layout', () => {
        it('should render formats in a grid', () => {
            render(<MetadataLinks resource={mockResource} />);

            const grid = screen.getByText('DataCite JSON').closest('div')?.parentElement;
            expect(grid).toHaveClass('grid', 'gap-4', 'sm:grid-cols-2');
        });

        it('should render each format in a card', () => {
            render(<MetadataLinks resource={mockResource} />);

            const jsonCard = screen.getByText('DataCite JSON').closest('div');
            expect(jsonCard).toHaveClass(
                'flex',
                'flex-col',
                'gap-3',
                'rounded-lg',
                'border',
                'border-gray-200',
                'bg-white',
                'p-4',
            );
        });
    });

    // ========================================================================
    // Icon Tests
    // ========================================================================

    describe('Icons', () => {
        it('should render icons for all formats', () => {
            render(<MetadataLinks resource={mockResource} />);

            // Check that icons are present (aria-hidden)
            const icons = screen
                .getByText('DataCite JSON')
                .closest('div')
                ?.querySelector('[aria-hidden="true"]');
            expect(icons).toBeInTheDocument();
        });

        it('should hide icons from screen readers', () => {
            render(<MetadataLinks resource={mockResource} />);

            const jsonCard = screen.getByText('DataCite JSON').closest('div');
            const icon = jsonCard?.querySelector('svg');
            expect(icon).toHaveAttribute('aria-hidden', 'true');
        });
    });

    // ========================================================================
    // URL Generation Tests
    // ========================================================================

    describe('URL Generation', () => {
        it('should generate correct URL for DataCite JSON', () => {
            render(<MetadataLinks resource={mockResource} />);

            const jsonLink = screen.getByLabelText('Download DataCite JSON');
            expect(jsonLink).toHaveAttribute('href', '/resources/123/export-datacite-json');
        });

        it('should generate correct URL for DataCite XML', () => {
            render(<MetadataLinks resource={mockResource} />);

            const xmlLink = screen.getByLabelText('Download DataCite XML');
            expect(xmlLink).toHaveAttribute('href', '/resources/123/export-datacite-xml');
        });

        it('should use resource ID in URL', () => {
            render(<MetadataLinks resource={mockResourceWithoutDoi} />);

            const jsonLink = screen.getByLabelText('Download DataCite JSON');
            expect(jsonLink).toHaveAttribute('href', '/resources/456/export-datacite-json');
        });

        it('should not generate URLs for unavailable formats', () => {
            render(<MetadataLinks resource={mockResource} />);

            const isoButton = screen.getByLabelText('ISO 19115 not available yet');
            const schemaButton = screen.getByLabelText('Schema.org not available yet');

            expect(isoButton).not.toHaveAttribute('href');
            expect(schemaButton).not.toHaveAttribute('href');
        });
    });

    // ========================================================================
    // Edge Cases
    // ========================================================================

    describe('Edge Cases', () => {
        it('should handle resource with ID 0', () => {
            const resourceWithZeroId = { id: 0, doi: null };
            render(<MetadataLinks resource={resourceWithZeroId} />);

            const jsonLink = screen.getByLabelText('Download DataCite JSON');
            expect(jsonLink).toHaveAttribute('href', '/resources/0/export-datacite-json');
        });

        it('should handle very long resource IDs', () => {
            const resourceWithLongId = { id: 999999999, doi: null };
            render(<MetadataLinks resource={resourceWithLongId} />);

            const jsonLink = screen.getByLabelText('Download DataCite JSON');
            expect(jsonLink).toHaveAttribute('href', '/resources/999999999/export-datacite-json');
        });

        it('should handle empty heading', () => {
            render(<MetadataLinks resource={mockResource} heading="" />);

            const heading = screen.getByRole('heading', { level: 2 });
            expect(heading).toHaveTextContent('');
        });

        it('should render correctly without DOI field', () => {
            const resourceMinimal = { id: 789 };
            render(<MetadataLinks resource={resourceMinimal} />);

            expect(screen.getByText('DataCite JSON')).toBeInTheDocument();
            expect(screen.getByText('DataCite XML')).toBeInTheDocument();
        });
    });

    // ========================================================================
    // Accessibility Tests
    // ========================================================================

    describe('Accessibility', () => {
        it('should have accessible heading', () => {
            render(<MetadataLinks resource={mockResource} />);

            const heading = screen.getByRole('heading', { name: 'Metadata Export' });
            expect(heading).toBeInTheDocument();
        });

        it('should have aria-labels on download links', () => {
            render(<MetadataLinks resource={mockResource} />);

            expect(screen.getByLabelText('Download DataCite JSON')).toBeInTheDocument();
            expect(screen.getByLabelText('Download DataCite XML')).toBeInTheDocument();
        });

        it('should have aria-labels on disabled buttons', () => {
            render(<MetadataLinks resource={mockResource} />);

            expect(screen.getByLabelText('ISO 19115 not available yet')).toBeInTheDocument();
            expect(screen.getByLabelText('Schema.org not available yet')).toBeInTheDocument();
        });

        it('should have aria-labels on status badges', () => {
            render(<MetadataLinks resource={mockResource} />);

            const badges = screen.getAllByLabelText(/Status:/);
            expect(badges.length).toBe(4); // All 4 formats have status badges
        });

        it('should hide decorative icons from screen readers', () => {
            render(<MetadataLinks resource={mockResource} />);

            const jsonCard = screen.getByText('DataCite JSON').closest('div');
            const icons = jsonCard?.querySelectorAll('[aria-hidden="true"]');
            expect(icons && icons.length).toBeGreaterThan(0);
        });
    });

    // ========================================================================
    // Dark Mode Tests
    // ========================================================================

    describe('Dark Mode', () => {
        it('should apply dark mode classes to heading', () => {
            render(<MetadataLinks resource={mockResource} />);

            const heading = screen.getByRole('heading', { name: 'Metadata Export' });
            expect(heading).toHaveClass('text-gray-900', 'dark:text-gray-100');
        });

        it('should apply dark mode classes to cards', () => {
            render(<MetadataLinks resource={mockResource} />);

            const jsonCard = screen.getByText('DataCite JSON').closest('div');
            expect(jsonCard).toHaveClass(
                'border-gray-200',
                'bg-white',
                'dark:border-gray-700',
                'dark:bg-gray-800',
            );
        });

        it('should apply dark mode classes to badges', () => {
            render(<MetadataLinks resource={mockResource} />);

            const availableBadges = screen.getAllByText('Available');
            availableBadges.forEach((badge) => {
                expect(badge).toHaveClass('dark:bg-green-900/30', 'dark:text-green-400');
            });
        });

        it('should apply dark mode classes to descriptions', () => {
            render(<MetadataLinks resource={mockResource} />);

            const description = screen.getByText(/DataCite Metadata Schema v4.5\+ in JSON format/);
            expect(description).toHaveClass('text-gray-600', 'dark:text-gray-400');
        });

        it('should apply dark mode classes to help text', () => {
            render(<MetadataLinks resource={mockResource} />);

            const helpText = screen.getByText(/Download metadata in various formats/);
            expect(helpText).toHaveClass('text-gray-600', 'dark:text-gray-400');
        });
    });
});
