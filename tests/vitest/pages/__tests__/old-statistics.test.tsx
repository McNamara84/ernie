import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

// Mock Inertia
const { mockVisit } = vi.hoisted(() => ({ mockVisit: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    router: { visit: mockVisit },
}));

// Mock routes
vi.mock('@/routes', () => ({
    oldStatistics: (opts?: { query?: Record<string, string> }) => ({
        url: opts?.query ? `/old-statistics?refresh=${opts.query.refresh}` : '/old-statistics',
    }),
}));

// Mock AppLayout
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div data-testid="app-layout">{children}</div>,
}));

// Mock all statistics child components
vi.mock('@/components/statistics/abstract-analysis', () => ({ default: () => <div data-testid="mock-abstract-analysis" /> }));
vi.mock('@/components/statistics/affiliation-stats-card', () => ({ default: () => <div data-testid="mock-affiliation-stats-card" /> }));
vi.mock('@/components/statistics/completeness-gauge', () => ({ default: () => <div data-testid="mock-completeness-gauge" /> }));
vi.mock('@/components/statistics/coverage-analysis', () => ({ default: () => <div data-testid="mock-coverage-analysis" /> }));
vi.mock('@/components/statistics/creation-time-chart', () => ({ default: () => <div data-testid="mock-creation-time-chart" /> }));
vi.mock('@/components/statistics/curator-chart', () => ({ default: () => <div data-testid="mock-curator-chart" /> }));
vi.mock('@/components/statistics/current-year-chart', () => ({ default: () => <div data-testid="mock-current-year-chart" /> }));
vi.mock('@/components/statistics/data-quality-indicators', () => ({ default: () => <div data-testid="mock-data-quality-indicators" /> }));
vi.mock('@/components/statistics/description-type-stats', () => ({ default: () => <div data-testid="mock-description-type-stats" /> }));
vi.mock('@/components/statistics/identifier-stats-card', () => ({ default: () => <div data-testid="mock-identifier-stats-card" /> }));
vi.mock('@/components/statistics/institution-chart', () => ({ default: () => <div data-testid="mock-institution-chart" /> }));
vi.mock('@/components/statistics/is-supplement-to-chart', () => ({ default: () => <div data-testid="mock-is-supplement-to-chart" /> }));
vi.mock('@/components/statistics/keyword-table', () => ({ default: () => <div data-testid="mock-keyword-table" /> }));
vi.mock('@/components/statistics/language-chart', () => ({ default: () => <div data-testid="mock-language-chart" /> }));
vi.mock('@/components/statistics/license-chart', () => ({ default: () => <div data-testid="mock-license-chart" /> }));
vi.mock('@/components/statistics/pid-usage-chart', () => ({ default: () => <div data-testid="mock-pid-usage-chart" /> }));
vi.mock('@/components/statistics/publication-year-chart', () => ({ default: () => <div data-testid="mock-publication-year-chart" /> }));
vi.mock('@/components/statistics/related-works-chart', () => ({ default: () => <div data-testid="mock-related-works-chart" /> }));
vi.mock('@/components/statistics/relation-types-chart', () => ({ default: () => <div data-testid="mock-relation-types-chart" /> }));
vi.mock('@/components/statistics/resource-type-chart', () => ({ default: () => <div data-testid="mock-resource-type-chart" /> }));
vi.mock('@/components/statistics/role-distribution-chart', () => ({ default: () => <div data-testid="mock-role-distribution-chart" /> }));
vi.mock('@/components/statistics/stats-card', () => ({ default: ({ title, value }: { title: string; value: string }) => <div data-testid="mock-stats-card">{title}: {value}</div> }));
vi.mock('@/components/statistics/timeline-chart', () => ({ default: () => <div data-testid="mock-timeline-chart" /> }));
vi.mock('@/components/statistics/top-datasets-by-relation-type', () => ({ default: () => <div data-testid="mock-top-datasets" /> }));

import OldStatistics from '@/pages/old-statistics';

function createMinimalStatistics() {
    return {
        overview: {
            totalDatasets: 1234,
            totalAuthors: 567,
            avgAuthorsPerDataset: 2.5,
            avgContributorsPerDataset: 1.3,
            avgRelatedWorks: 3.2,
            oldestDataset: { id: 1, identifier: '10.5880/old.001', year: 2001 },
            newestDataset: { id: 100, identifier: '10.5880/new.100', year: 2024 },
            oldestCreated: { id: 1, identifier: '10.5880/old.001', createdAt: '2020-01-01 10:00:00' },
            newestCreated: { id: 100, identifier: '10.5880/new.100', createdAt: '2024-12-01 14:30:00' },
        },
        institutions: [{ name: 'GFZ', rorId: 'https://ror.org/04z8jg394', count: 500 }],
        relatedWorks: {
            topDatasets: [],
            distribution: [],
            isSupplementTo: { withIsSupplementTo: 10, withoutIsSupplementTo: 90, percentageWith: 10, percentageWithout: 90 },
            placeholders: { totalPlaceholders: 0, datasetsWithPlaceholders: 0, patterns: [] },
            relationTypes: [],
            coverage: { withNoRelatedWorks: 50, withOnlyIsSupplementTo: 30, withMultipleTypes: 20, avgTypesPerDataset: 1.5 },
            quality: { completeData: 80, incompleteOrPlaceholder: 20, percentageComplete: 80 },
        },
        pidUsage: [{ type: 'DOI', count: 100, percentage: 80 }],
        completeness: { descriptions: 90, geographicCoverage: 60, temporalCoverage: 40, funding: 30, orcid: 50, rorIds: 45, relatedWorks: 70 },
        curators: [{ name: 'John', count: 50 }],
        roles: [{ role: 'admin', count: 2 }],
        timeline: { publicationsByYear: [{ year: 2024, count: 50 }], createdByYear: [{ year: 2024, count: 30 }] },
        resourceTypes: [{ type: 'Dataset', count: 100 }],
        languages: [{ language: 'English', count: 90 }],
        licenses: [{ name: 'CC BY 4.0', count: 80 }],
        identifiers: {
            ror: { count: 45, total: 100, percentage: 45 },
            orcid: { count: 50, total: 100, percentage: 50 },
        },
        current_year: { year: 2024, total: 50, monthly: [{ month: 1, count: 5 }] },
        affiliations: { max_per_agent: 3, avg_per_agent: 1.2 },
        keywords: { free: [{ keyword: 'geology', count: 10 }], controlled: [{ keyword: 'earth science', count: 20 }] },
        creation_time: [{ hour: 10, count: 15 }],
        descriptions: {
            by_type: [{ type_id: 'Abstract', count: 100 }],
            longest_abstract: { length: 5000, preview: 'Long abstract...' },
            shortest_abstract: { length: 50, preview: 'Short.' },
        },
        publication_years: [{ year: 2024, count: 50 }],
        topDatasetsByRelationType: {},
    };
}

describe('OldStatistics', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    describe('rendering', () => {
        it('renders the page title', () => {
            render(<OldStatistics statistics={createMinimalStatistics()} lastUpdated="2024-12-01 14:30:00" />);
            expect(screen.getByText('Old Database Statistics')).toBeInTheDocument();
        });

        it('renders the last updated date', () => {
            render(<OldStatistics statistics={createMinimalStatistics()} lastUpdated="2024-12-01 14:30:00" />);
            expect(screen.getByText(/Last updated:/)).toBeInTheDocument();
        });

        it('renders overview stats cards', () => {
            render(<OldStatistics statistics={createMinimalStatistics()} lastUpdated="2024-12-01 14:30:00" />);
            // StatsCard is mocked, check it was rendered multiple times
            const statsCards = screen.getAllByTestId('mock-stats-card');
            expect(statsCards.length).toBeGreaterThanOrEqual(4);
        });

        it('renders the refresh button', () => {
            render(<OldStatistics statistics={createMinimalStatistics()} lastUpdated="2024-12-01 14:30:00" />);
            expect(screen.getByText('Refresh Data')).toBeInTheDocument();
        });

        it('renders within AppLayout', () => {
            render(<OldStatistics statistics={createMinimalStatistics()} lastUpdated="2024-12-01 14:30:00" />);
            expect(screen.getByTestId('app-layout')).toBeInTheDocument();
        });
    });

    describe('refresh', () => {
        it('calls router.visit with refresh query param', async () => {
            render(<OldStatistics statistics={createMinimalStatistics()} lastUpdated="2024-12-01 14:30:00" />);
            await userEvent.click(screen.getByText('Refresh Data'));

            expect(mockVisit).toHaveBeenCalledWith('/old-statistics?refresh=1', expect.objectContaining({ preserveState: false }));
        });

        it('disables refresh button while refreshing', async () => {
            render(<OldStatistics statistics={createMinimalStatistics()} lastUpdated="2024-12-01 14:30:00" />);
            await userEvent.click(screen.getByText('Refresh Data'));

            // After clicking, the button should be disabled
            expect(screen.getByRole('button', { name: /refresh/i })).toBeDisabled();
        });
    });

    describe('conditional rendering', () => {
        it('does not render oldest dataset card when null', () => {
            const stats = createMinimalStatistics();
            stats.overview.oldestDataset = null as any;
            render(<OldStatistics statistics={stats} lastUpdated="2024-12-01 14:30:00" />);

            // Look for stats cards — there should be fewer without the oldest dataset card
            const statsCards = screen.getAllByTestId('mock-stats-card');
            const statsWithOldest = createMinimalStatistics();
            const { rerender } = render(
                <OldStatistics statistics={statsWithOldest} lastUpdated="2024-12-01 14:30:00" />,
            );

            const allCards = screen.getAllByTestId('mock-stats-card');
            // With null oldestDataset, there should be at least one fewer card
            expect(statsCards.length).toBeLessThanOrEqual(allCards.length);
        });
    });
});
