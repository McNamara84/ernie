import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

const routerMock = vi.hoisted(() => ({ visit: vi.fn(), get: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    router: routerMock,
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children?: React.ReactNode }) => <div data-testid="app-layout">{children}</div>,
}));

vi.mock('@/routes', () => ({
    oldStatistics: (opts?: { query?: Record<string, string> }) => ({
        url: opts?.query ? `/old-statistics?${new URLSearchParams(opts.query).toString()}` : '/old-statistics',
        method: 'get',
    }),
}));

// Mock all statistics sub-components with simple stubs
vi.mock('@/components/statistics/abstract-analysis', () => ({ default: () => <div data-testid="abstract-analysis" /> }));
vi.mock('@/components/statistics/affiliation-stats-card', () => ({ default: () => <div data-testid="affiliation-stats" /> }));
vi.mock('@/components/statistics/completeness-gauge', () => ({ default: () => <div data-testid="completeness-gauge" /> }));
vi.mock('@/components/statistics/coverage-analysis', () => ({ default: () => <div data-testid="coverage-analysis" /> }));
vi.mock('@/components/statistics/creation-time-chart', () => ({ default: () => <div data-testid="creation-time" /> }));
vi.mock('@/components/statistics/curator-chart', () => ({ default: () => <div data-testid="curator-chart" /> }));
vi.mock('@/components/statistics/current-year-chart', () => ({ default: () => <div data-testid="current-year" /> }));
vi.mock('@/components/statistics/data-quality-indicators', () => ({ default: () => <div data-testid="data-quality" /> }));
vi.mock('@/components/statistics/description-type-stats', () => ({ default: () => <div data-testid="desc-type-stats" /> }));
vi.mock('@/components/statistics/identifier-stats-card', () => ({ default: () => <div data-testid="identifier-stats" /> }));
vi.mock('@/components/statistics/institution-chart', () => ({ default: () => <div data-testid="institution-chart" /> }));
vi.mock('@/components/statistics/is-supplement-to-chart', () => ({ default: () => <div data-testid="supplement-chart" /> }));
vi.mock('@/components/statistics/keyword-table', () => ({ default: () => <div data-testid="keyword-table" /> }));
vi.mock('@/components/statistics/language-chart', () => ({ default: () => <div data-testid="language-chart" /> }));
vi.mock('@/components/statistics/license-chart', () => ({ default: () => <div data-testid="license-chart" /> }));
vi.mock('@/components/statistics/pid-usage-chart', () => ({ default: () => <div data-testid="pid-usage" /> }));
vi.mock('@/components/statistics/publication-year-chart', () => ({ default: () => <div data-testid="pub-year" /> }));
vi.mock('@/components/statistics/related-works-chart', () => ({ default: () => <div data-testid="related-works" /> }));
vi.mock('@/components/statistics/relation-types-chart', () => ({ default: () => <div data-testid="relation-types" /> }));
vi.mock('@/components/statistics/resource-type-chart', () => ({ default: () => <div data-testid="resource-type" /> }));
vi.mock('@/components/statistics/role-distribution-chart', () => ({ default: () => <div data-testid="role-dist" /> }));
vi.mock('@/components/statistics/stats-card', () => ({
    default: ({ title, value }: { title: string; value: string }) => (
        <div data-testid="stats-card">
            <span>{title}</span>
            <span>{value}</span>
        </div>
    ),
}));
vi.mock('@/components/statistics/timeline-chart', () => ({ default: () => <div data-testid="timeline-chart" /> }));
vi.mock('@/components/statistics/top-datasets-by-relation-type', () => ({ default: () => <div data-testid="top-datasets" /> }));

import OldStatistics from '@/pages/old-statistics';

function createMinimalStats() {
    return {
        statistics: {
            overview: {
                totalDatasets: 1234,
                totalAuthors: 567,
                avgAuthorsPerDataset: 3.2,
                avgContributorsPerDataset: 1.5,
                avgRelatedWorks: 4.1,
                oldestDataset: { id: 1, identifier: '10.5880/oldest', year: 2010 },
                newestDataset: { id: 2, identifier: '10.5880/newest', year: 2024 },
                oldestCreated: { id: 1, identifier: '10.5880/oldest', createdAt: '2019-01-01' },
                newestCreated: { id: 2, identifier: '10.5880/newest', createdAt: '2024-06-15' },
            },
            institutions: [],
            relatedWorks: {
                topDatasets: [],
                distribution: [],
                isSupplementTo: { withIsSupplementTo: 0, withoutIsSupplementTo: 0, percentageWith: 0, percentageWithout: 0 },
                placeholders: { totalPlaceholders: 0, datasetsWithPlaceholders: 0, patterns: [] },
                relationTypes: [],
                coverage: { withNoRelatedWorks: 0, withOnlyIsSupplementTo: 0, withMultipleTypes: 0, avgTypesPerDataset: 0 },
                quality: { completeData: 0, incompleteOrPlaceholder: 0, percentageComplete: 0 },
            },
            pidUsage: [],
            completeness: {
                descriptions: 0,
                geographicCoverage: 0,
                temporalCoverage: 0,
                funding: 0,
                orcid: 0,
                rorIds: 0,
                relatedWorks: 0,
            },
            curators: [],
            roles: [],
            timeline: { publicationsByYear: [], createdByYear: [] },
            resourceTypes: [],
            languages: [],
            licenses: [],
            identifiers: { ror: { count: 0, total: 0, percentage: 0 }, orcid: { count: 0, total: 0, percentage: 0 } },
            current_year: { year: 2024, total: 0, monthly: [] },
            affiliations: { max_per_agent: 0, avg_per_agent: 0 },
            keywords: { free: [], controlled: [] },
            creation_time: [],
            descriptions: { by_type: [], longest_abstract: null, shortest_abstract: null },
            publication_years: [],
            topDatasetsByRelationType: {},
        },
        lastUpdated: '2024-06-15T12:00:00Z',
    };
}

describe('OldStatistics page', () => {
    it('renders the page title', () => {
        render(<OldStatistics {...createMinimalStats()} />);
        expect(screen.getByText('Old Database Statistics')).toBeInTheDocument();
    });

    it('displays overview stats cards', () => {
        render(<OldStatistics {...createMinimalStats()} />);
        const cards = screen.getAllByTestId('stats-card');
        expect(cards.length).toBeGreaterThanOrEqual(4);
    });

    it('shows total datasets value', () => {
        render(<OldStatistics {...createMinimalStats()} />);
        // toLocaleString() uses German locale in test env (1.234 instead of 1,234)
        expect(screen.getByText('1.234')).toBeInTheDocument();
    });

    it('shows total authors value', () => {
        render(<OldStatistics {...createMinimalStats()} />);
        expect(screen.getByText('567')).toBeInTheDocument();
    });

    it('renders the refresh button', () => {
        render(<OldStatistics {...createMinimalStats()} />);
        expect(screen.getByRole('button', { name: /Refresh Data/i })).toBeInTheDocument();
    });

    it('calls router.visit on refresh click', async () => {
        const user = userEvent.setup();
        render(<OldStatistics {...createMinimalStats()} />);

        await user.click(screen.getByRole('button', { name: /Refresh Data/i }));
        expect(routerMock.visit).toHaveBeenCalledWith(
            expect.stringContaining('refresh=1'),
            expect.any(Object),
        );
    });

    it('displays last updated date', () => {
        render(<OldStatistics {...createMinimalStats()} />);
        expect(screen.getByText(/Last updated:/)).toBeInTheDocument();
    });

    it('renders within AppLayout', () => {
        render(<OldStatistics {...createMinimalStats()} />);
        expect(screen.getByTestId('app-layout')).toBeInTheDocument();
    });
});
