import '@testing-library/jest-dom/vitest';

import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { DocsTabs, getDocsTabConfig } from '@/components/docs/docs-tabs';

describe('DocsTabs', () => {
    const defaultProps = {
        activeTab: 'getting-started' as const,
        onTabChange: vi.fn(),
    };

    describe('rendering', () => {
        it('renders all three tabs', () => {
            render(<DocsTabs {...defaultProps} />);

            expect(screen.getByText('Getting Started')).toBeInTheDocument();
            expect(screen.getByText('Datasets')).toBeInTheDocument();
            expect(screen.getByText('Physical Samples')).toBeInTheDocument();
        });

        it('renders tab descriptions on larger screens', () => {
            render(<DocsTabs {...defaultProps} />);

            // Descriptions are rendered but hidden on small screens via CSS
            expect(screen.getByText('Welcome, navigation, and general information')).toBeInTheDocument();
            expect(screen.getByText('DOI curation workflow for research data')).toBeInTheDocument();
            expect(screen.getByText('IGSN registration workflow for samples')).toBeInTheDocument();
        });

        it('renders icons for each tab', () => {
            render(<DocsTabs {...defaultProps} />);

            // Check that SVG elements (icons) are rendered
            const tabs = screen.getAllByRole('tab');
            tabs.forEach((tab) => {
                const svg = tab.querySelector('svg');
                expect(svg).toBeInTheDocument();
            });
        });

        it('applies data-testid to each tab', () => {
            render(<DocsTabs {...defaultProps} />);

            expect(screen.getByTestId('tab-getting-started')).toBeInTheDocument();
            expect(screen.getByTestId('tab-datasets')).toBeInTheDocument();
            expect(screen.getByTestId('tab-physical-samples')).toBeInTheDocument();
        });
    });

    describe('active tab styling', () => {
        it('marks Getting Started as active when selected', () => {
            render(<DocsTabs {...defaultProps} activeTab="getting-started" />);

            const activeTab = screen.getByTestId('tab-getting-started');
            expect(activeTab).toHaveAttribute('data-state', 'active');
        });

        it('marks Datasets as active when selected', () => {
            render(<DocsTabs {...defaultProps} activeTab="datasets" />);

            const activeTab = screen.getByTestId('tab-datasets');
            expect(activeTab).toHaveAttribute('data-state', 'active');
        });

        it('marks Physical Samples as active when selected', () => {
            render(<DocsTabs {...defaultProps} activeTab="physical-samples" />);

            const activeTab = screen.getByTestId('tab-physical-samples');
            expect(activeTab).toHaveAttribute('data-state', 'active');
        });

        it('only marks one tab as active at a time', () => {
            render(<DocsTabs {...defaultProps} activeTab="datasets" />);

            const tabs = screen.getAllByRole('tab');
            const activeTabs = tabs.filter((tab) => tab.getAttribute('data-state') === 'active');
            expect(activeTabs).toHaveLength(1);
        });
    });

    describe('tab switching', () => {
        it('renders all tabs with correct testids for interaction', () => {
            const onTabChange = vi.fn();
            render(<DocsTabs {...defaultProps} activeTab="getting-started" onTabChange={onTabChange} />);

            // Verify the component renders with all tabs available
            const tabsList = screen.getByRole('tablist');
            expect(tabsList).toBeInTheDocument();

            // All tab triggers should be rendered and accessible
            expect(screen.getByTestId('tab-getting-started')).toBeInTheDocument();
            expect(screen.getByTestId('tab-datasets')).toBeInTheDocument();
            expect(screen.getByTestId('tab-physical-samples')).toBeInTheDocument();
        });

        it('passes correct value prop to Tabs component', () => {
            const onTabChange = vi.fn();
            const { rerender } = render(<DocsTabs {...defaultProps} activeTab="getting-started" onTabChange={onTabChange} />);

            // Getting Started should be active
            expect(screen.getByTestId('tab-getting-started')).toHaveAttribute('data-state', 'active');
            expect(screen.getByTestId('tab-datasets')).toHaveAttribute('data-state', 'inactive');

            // Rerender with different active tab
            rerender(<DocsTabs {...defaultProps} activeTab="datasets" onTabChange={onTabChange} />);

            // Now Datasets should be active
            expect(screen.getByTestId('tab-getting-started')).toHaveAttribute('data-state', 'inactive');
            expect(screen.getByTestId('tab-datasets')).toHaveAttribute('data-state', 'active');
        });

        it('passes correct value for Physical Samples tab', () => {
            const onTabChange = vi.fn();
            render(<DocsTabs {...defaultProps} activeTab="physical-samples" onTabChange={onTabChange} />);

            expect(screen.getByTestId('tab-physical-samples')).toHaveAttribute('data-state', 'active');
            expect(screen.getByTestId('tab-getting-started')).toHaveAttribute('data-state', 'inactive');
            expect(screen.getByTestId('tab-datasets')).toHaveAttribute('data-state', 'inactive');
        });
    });

    describe('accessibility', () => {
        it('uses tablist role for the tab container', () => {
            render(<DocsTabs {...defaultProps} />);

            expect(screen.getByRole('tablist')).toBeInTheDocument();
        });

        it('uses tab role for each tab', () => {
            render(<DocsTabs {...defaultProps} />);

            const tabs = screen.getAllByRole('tab');
            expect(tabs).toHaveLength(3);
        });
    });

    describe('className prop', () => {
        it('applies custom className', () => {
            const { container } = render(<DocsTabs {...defaultProps} className="custom-class" />);

            // The root Tabs component should have the custom class
            const tabsRoot = container.firstChild;
            expect(tabsRoot).toHaveClass('custom-class');
        });
    });
});

describe('getDocsTabConfig', () => {
    it('returns tab configuration array', () => {
        const config = getDocsTabConfig();

        expect(config).toHaveLength(3);
        expect(config[0].id).toBe('getting-started');
        expect(config[1].id).toBe('datasets');
        expect(config[2].id).toBe('physical-samples');
    });

    it('each tab has required properties', () => {
        const config = getDocsTabConfig();

        config.forEach((tab) => {
            expect(tab).toHaveProperty('id');
            expect(tab).toHaveProperty('label');
            expect(tab).toHaveProperty('icon');
            expect(tab).toHaveProperty('description');
        });
    });
});
