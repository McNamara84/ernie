import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import ViewStatistics from '@/components/landing-pages/shared/ViewStatistics';

// ============================================================================
// Test Data
// ============================================================================

const mockRecentDate = new Date(Date.now() - 2 * 60 * 60 * 1000).toISOString(); // 2 hours ago
const mockOldDate = new Date(Date.now() - 30 * 24 * 60 * 60 * 1000).toISOString(); // 30 days ago
const mockVeryOldDate = new Date(Date.now() - 400 * 24 * 60 * 60 * 1000).toISOString(); // ~13 months ago

// ============================================================================
// Test Suite
// ============================================================================

describe('ViewStatistics', () => {
    // ========================================================================
    // Rendering Tests
    // ========================================================================

    describe('Rendering', () => {
        it('should render with default heading', () => {
            render(<ViewStatistics viewCount={100} lastViewedAt={mockRecentDate} />);

            expect(screen.getByRole('heading', { name: 'Statistics' })).toBeInTheDocument();
        });

        it('should render with custom heading', () => {
            render(
                <ViewStatistics
                    viewCount={100}
                    lastViewedAt={mockRecentDate}
                    heading="Page Analytics"
                />,
            );

            expect(screen.getByRole('heading', { name: 'Page Analytics' })).toBeInTheDocument();
        });

        it('should render total views card', () => {
            render(<ViewStatistics viewCount={100} lastViewedAt={mockRecentDate} />);

            expect(screen.getByText('Total Views')).toBeInTheDocument();
        });

        it('should render last viewed card when date is provided', () => {
            render(<ViewStatistics viewCount={100} lastViewedAt={mockRecentDate} />);

            expect(screen.getByText('Last Viewed')).toBeInTheDocument();
        });

        it('should not render last viewed card when showLastViewed is false', () => {
            render(
                <ViewStatistics
                    viewCount={100}
                    lastViewedAt={mockRecentDate}
                    showLastViewed={false}
                />,
            );

            expect(screen.queryByText('Last Viewed')).not.toBeInTheDocument();
        });

        it('should render icons', () => {
            render(<ViewStatistics viewCount={100} lastViewedAt={mockRecentDate} />);

            const viewsCard = screen.getByText('Total Views').closest('div');
            const eyeIcon = viewsCard?.querySelector('[aria-hidden="true"]');
            expect(eyeIcon).toBeInTheDocument();
        });
    });

    // ========================================================================
    // View Count Formatting Tests
    // ========================================================================

    describe('View Count Formatting', () => {
        it('should format view count with commas', () => {
            render(<ViewStatistics viewCount={1234} lastViewedAt={mockRecentDate} />);

            expect(screen.getByText('1,234')).toBeInTheDocument();
        });

        it('should format large numbers correctly', () => {
            render(<ViewStatistics viewCount={1234567} lastViewedAt={mockRecentDate} />);

            expect(screen.getByText('1,234,567')).toBeInTheDocument();
        });

        it('should handle small numbers without commas', () => {
            render(<ViewStatistics viewCount={42} lastViewedAt={mockRecentDate} />);

            expect(screen.getByText('42')).toBeInTheDocument();
        });

        it('should handle zero views', () => {
            render(<ViewStatistics viewCount={0} lastViewedAt={null} />);

            expect(screen.getByText('0')).toBeInTheDocument();
        });

        it('should handle single digit views', () => {
            render(<ViewStatistics viewCount={5} lastViewedAt={mockRecentDate} />);

            expect(screen.getByText('5')).toBeInTheDocument();
        });

        it('should handle exactly 1000 views', () => {
            render(<ViewStatistics viewCount={1000} lastViewedAt={mockRecentDate} />);

            expect(screen.getByText('1,000')).toBeInTheDocument();
        });

        it('should handle exactly 1 million views', () => {
            render(<ViewStatistics viewCount={1000000} lastViewedAt={mockRecentDate} />);

            expect(screen.getByText('1,000,000')).toBeInTheDocument();
        });
    });

    // ========================================================================
    // Relative Time Tests
    // ========================================================================

    describe('Relative Time', () => {
        it('should show hours for recent views', () => {
            render(<ViewStatistics viewCount={100} lastViewedAt={mockRecentDate} />);

            expect(screen.getByText(/hours ago/)).toBeInTheDocument();
        });

        it('should show days for older views', () => {
            render(<ViewStatistics viewCount={100} lastViewedAt={mockOldDate} />);

            expect(screen.getByText(/days ago/)).toBeInTheDocument();
        });

        it('should show months for very old views', () => {
            render(<ViewStatistics viewCount={100} lastViewedAt={mockVeryOldDate} />);

            expect(screen.getByText(/months ago|year ago/)).toBeInTheDocument();
        });

        it('should show singular form for 1 hour', () => {
            const oneHourAgo = new Date(Date.now() - 1 * 60 * 60 * 1000).toISOString();
            render(<ViewStatistics viewCount={100} lastViewedAt={oneHourAgo} />);

            expect(screen.getByText('1 hour ago')).toBeInTheDocument();
        });

        it('should show singular form for 1 day', () => {
            const oneDayAgo = new Date(Date.now() - 1 * 24 * 60 * 60 * 1000).toISOString();
            render(<ViewStatistics viewCount={100} lastViewedAt={oneDayAgo} />);

            expect(screen.getByText('1 day ago')).toBeInTheDocument();
        });

        it('should show minutes for very recent views', () => {
            const fiveMinutesAgo = new Date(Date.now() - 5 * 60 * 1000).toISOString();
            render(<ViewStatistics viewCount={100} lastViewedAt={fiveMinutesAgo} />);

            expect(screen.getByText(/minutes ago/)).toBeInTheDocument();
        });

        it('should show seconds for just now', () => {
            const tenSecondsAgo = new Date(Date.now() - 10 * 1000).toISOString();
            render(<ViewStatistics viewCount={100} lastViewedAt={tenSecondsAgo} />);

            expect(screen.getByText(/seconds ago/)).toBeInTheDocument();
        });
    });

    // ========================================================================
    // Full Timestamp Tests
    // ========================================================================

    describe('Full Timestamp', () => {
        it('should show full timestamp in title attribute', () => {
            render(<ViewStatistics viewCount={100} lastViewedAt={mockRecentDate} />);

            const relativeTime = screen.getByText(/hours ago/);
            expect(relativeTime).toHaveAttribute('title');
            const title = relativeTime.getAttribute('title');
            expect(title).toMatch(/\d{4}/); // Should contain year
        });

        it('should format full timestamp correctly', () => {
            const knownDate = '2025-10-23T14:30:00Z';
            render(<ViewStatistics viewCount={100} lastViewedAt={knownDate} />);

            const relativeTime = screen.getByText(/ago/);
            const title = relativeTime.getAttribute('title');
            expect(title).toContain('2025');
            expect(title).toContain('October');
        });
    });

    // ========================================================================
    // Never Viewed State Tests
    // ========================================================================

    describe('Never Viewed State', () => {
        it('should show "Never viewed" when no views and no last viewed date', () => {
            render(<ViewStatistics viewCount={0} lastViewedAt={null} />);

            expect(screen.getByText('Never viewed')).toBeInTheDocument();
        });

        it('should show appropriate help text for never viewed', () => {
            render(<ViewStatistics viewCount={0} lastViewedAt={null} />);

            expect(
                screen.getByText(/This landing page has not been viewed yet/),
            ).toBeInTheDocument();
        });

        it('should not show "Never viewed" when views exist but no date', () => {
            render(<ViewStatistics viewCount={5} lastViewedAt={null} />);

            expect(screen.queryByText('Never viewed')).not.toBeInTheDocument();
        });

        it('should not show last viewed card when lastViewedAt is null and showLastViewed is false', () => {
            render(<ViewStatistics viewCount={0} lastViewedAt={null} showLastViewed={false} />);

            expect(screen.queryByText('Last Viewed')).not.toBeInTheDocument();
        });
    });

    // ========================================================================
    // Help Text Tests
    // ========================================================================

    describe('Help Text', () => {
        it('should show tracking message when views exist', () => {
            render(<ViewStatistics viewCount={100} lastViewedAt={mockRecentDate} />);

            expect(
                screen.getByText(/View statistics are tracked automatically/),
            ).toBeInTheDocument();
        });

        it('should show share message when no views', () => {
            render(<ViewStatistics viewCount={0} lastViewedAt={null} />);

            expect(screen.getByText(/Share the URL to start tracking views/)).toBeInTheDocument();
        });

        it('should not show both help texts simultaneously', () => {
            render(<ViewStatistics viewCount={100} lastViewedAt={mockRecentDate} />);

            expect(screen.queryByText(/Share the URL to start tracking views/)).not.toBeInTheDocument();
        });
    });

    // ========================================================================
    // Grid Layout Tests
    // ========================================================================

    describe('Grid Layout', () => {
        it('should render statistics in a grid', () => {
            render(<ViewStatistics viewCount={100} lastViewedAt={mockRecentDate} />);

            const grid = screen.getByText('Total Views').closest('div')?.parentElement;
            expect(grid).toHaveClass('grid', 'gap-4', 'sm:grid-cols-2');
        });

        it('should render each statistic in a card', () => {
            render(<ViewStatistics viewCount={100} lastViewedAt={mockRecentDate} />);

            const viewsCard = screen.getByText('Total Views').closest('div');
            expect(viewsCard).toHaveClass(
                'flex',
                'flex-col',
                'gap-2',
                'rounded-lg',
                'border',
                'border-gray-200',
                'bg-white',
                'p-4',
            );
        });

        it('should show only one card when showLastViewed is false', () => {
            render(
                <ViewStatistics
                    viewCount={100}
                    lastViewedAt={mockRecentDate}
                    showLastViewed={false}
                />,
            );

            const cards = screen.getAllByText(/Total Views|Last Viewed/);
            expect(cards).toHaveLength(1);
        });
    });

    // ========================================================================
    // Edge Cases
    // ========================================================================

    describe('Edge Cases', () => {
        it('should handle empty heading', () => {
            render(<ViewStatistics viewCount={100} lastViewedAt={mockRecentDate} heading="" />);

            const heading = screen.getByRole('heading', { level: 2 });
            expect(heading).toHaveTextContent('');
        });

        it('should handle very large view counts', () => {
            render(<ViewStatistics viewCount={999999999} lastViewedAt={mockRecentDate} />);

            expect(screen.getByText('999,999,999')).toBeInTheDocument();
        });

        it('should handle negative view counts gracefully', () => {
            render(<ViewStatistics viewCount={-5} lastViewedAt={mockRecentDate} />);

            // Should still render (even though negative views shouldn't happen in practice)
            expect(screen.getByText('-5')).toBeInTheDocument();
        });

        it('should handle invalid date strings gracefully', () => {
            // Invalid date should not crash the component
            render(<ViewStatistics viewCount={100} lastViewedAt="invalid-date" />);

            expect(screen.getByText('Total Views')).toBeInTheDocument();
        });

        it('should handle future dates', () => {
            const futureDate = new Date(Date.now() + 24 * 60 * 60 * 1000).toISOString();
            render(<ViewStatistics viewCount={100} lastViewedAt={futureDate} />);

            // Should still render even with future date
            expect(screen.getByText('Last Viewed')).toBeInTheDocument();
        });

        it('should handle very old dates (years)', () => {
            const oldDate = new Date(Date.now() - 3 * 365 * 24 * 60 * 60 * 1000).toISOString();
            render(<ViewStatistics viewCount={100} lastViewedAt={oldDate} />);

            expect(screen.getByText(/years ago/)).toBeInTheDocument();
        });
    });

    // ========================================================================
    // Accessibility Tests
    // ========================================================================

    describe('Accessibility', () => {
        it('should have accessible heading', () => {
            render(<ViewStatistics viewCount={100} lastViewedAt={mockRecentDate} />);

            expect(screen.getByRole('heading', { name: 'Statistics' })).toBeInTheDocument();
        });

        it('should have aria-label on view count', () => {
            render(<ViewStatistics viewCount={1234} lastViewedAt={mockRecentDate} />);

            expect(screen.getByLabelText('1234 total views')).toBeInTheDocument();
        });

        it('should have aria-label on relative time', () => {
            render(<ViewStatistics viewCount={100} lastViewedAt={mockRecentDate} />);

            const relativeTime = screen.getByText(/hours ago/);
            expect(relativeTime).toHaveAttribute('aria-label');
        });

        it('should hide decorative icons from screen readers', () => {
            render(<ViewStatistics viewCount={100} lastViewedAt={mockRecentDate} />);

            const viewsCard = screen.getByText('Total Views').closest('div');
            const icon = viewsCard?.querySelector('[aria-hidden="true"]');
            expect(icon).toHaveAttribute('aria-hidden', 'true');
        });

        it('should provide full timestamp in title for screen readers', () => {
            render(<ViewStatistics viewCount={100} lastViewedAt={mockRecentDate} />);

            const relativeTime = screen.getByText(/hours ago/);
            expect(relativeTime).toHaveAttribute('title');
        });
    });

    // ========================================================================
    // Dark Mode Tests
    // ========================================================================

    describe('Dark Mode', () => {
        it('should apply dark mode classes to heading', () => {
            render(<ViewStatistics viewCount={100} lastViewedAt={mockRecentDate} />);

            const heading = screen.getByRole('heading', { name: 'Statistics' });
            expect(heading).toHaveClass('text-gray-900', 'dark:text-gray-100');
        });

        it('should apply dark mode classes to cards', () => {
            render(<ViewStatistics viewCount={100} lastViewedAt={mockRecentDate} />);

            const viewsCard = screen.getByText('Total Views').closest('div');
            expect(viewsCard).toHaveClass(
                'border-gray-200',
                'bg-white',
                'dark:border-gray-700',
                'dark:bg-gray-800',
            );
        });

        it('should apply dark mode classes to labels', () => {
            render(<ViewStatistics viewCount={100} lastViewedAt={mockRecentDate} />);

            const label = screen.getByText('Total Views');
            expect(label).toHaveClass('text-gray-600', 'dark:text-gray-400');
        });

        it('should apply dark mode classes to view count', () => {
            render(<ViewStatistics viewCount={100} lastViewedAt={mockRecentDate} />);

            const viewCount = screen.getByText('100');
            expect(viewCount).toHaveClass('text-gray-900', 'dark:text-gray-100');
        });

        it('should apply dark mode classes to help text', () => {
            render(<ViewStatistics viewCount={100} lastViewedAt={mockRecentDate} />);

            const helpText = screen.getByText(/View statistics are tracked automatically/);
            expect(helpText).toHaveClass('text-gray-600', 'dark:text-gray-400');
        });

        it('should apply dark mode classes to "Never viewed" text', () => {
            render(<ViewStatistics viewCount={0} lastViewedAt={null} />);

            const neverViewed = screen.getByText('Never viewed');
            expect(neverViewed).toHaveClass('text-gray-500', 'dark:text-gray-400');
        });
    });

    // ========================================================================
    // Props Combination Tests
    // ========================================================================

    describe('Props Combinations', () => {
        it('should work with all props provided', () => {
            render(
                <ViewStatistics
                    viewCount={1234}
                    lastViewedAt={mockRecentDate}
                    heading="Analytics"
                    showLastViewed={true}
                />,
            );

            expect(screen.getByRole('heading', { name: 'Analytics' })).toBeInTheDocument();
            expect(screen.getByText('1,234')).toBeInTheDocument();
            expect(screen.getByText(/hours ago/)).toBeInTheDocument();
        });

        it('should work with minimal props', () => {
            render(<ViewStatistics viewCount={42} lastViewedAt={null} />);

            expect(screen.getByRole('heading', { name: 'Statistics' })).toBeInTheDocument();
            expect(screen.getByText('42')).toBeInTheDocument();
        });

        it('should work with custom heading and no last viewed', () => {
            render(
                <ViewStatistics
                    viewCount={100}
                    lastViewedAt={mockRecentDate}
                    heading="Traffic"
                    showLastViewed={false}
                />,
            );

            expect(screen.getByRole('heading', { name: 'Traffic' })).toBeInTheDocument();
            expect(screen.queryByText('Last Viewed')).not.toBeInTheDocument();
        });
    });
});
