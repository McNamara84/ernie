import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div data-testid="app-layout">{children}</div>,
}));

import StatisticsPage from '@/pages/statistics';

describe('StatisticsPage', () => {
    const props = {
        overview: {
            totalPageViews: 120,
            totalDownloadClicks: 45,
            totalPortalSearches: 33,
            trackedLandingPages: 9,
        },
        trends: {
            days: ['2026-05-14', '2026-05-15', '2026-05-16'],
            pageViews: [10, 20, 15],
            downloadClicks: [4, 8, 6],
            portalSearches: [3, 6, 5],
        },
        topLandingPagesByViews: [
            {
                landingPageId: 1,
                title: 'Top Dataset',
                identifier: '10.5880/top.dataset',
                resourceTypeLabel: 'Dataset',
                total: 55,
                publicUrl: '/10.5880/top.dataset/top-dataset',
                isExternal: false,
            },
        ],
        topLandingPagesByDownloads: [
            {
                landingPageId: 2,
                title: 'Downloaded Dataset',
                identifier: '10.5880/downloaded.dataset',
                resourceTypeLabel: 'Dataset',
                total: 18,
                publicUrl: '/10.5880/downloaded.dataset/downloaded-dataset',
                isExternal: false,
            },
        ],
        portalSearchTerms: [
            { term: 'climate', total: 11 },
            { term: 'igsn', total: 6 },
        ],
        typeSplit: {
            resourcePageViews: 90,
            physicalObjectPageViews: 30,
            resourceDownloadClicks: 35,
            physicalObjectDownloadClicks: 10,
        },
        lastUpdated: '2026-05-27T08:30:00Z',
    };

    it('renders the new statistics dashboard sections', () => {
        render(<StatisticsPage {...props} />);

        expect(screen.getByText('Public engagement statistics')).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Landing page views' })).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Download clicks' })).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Portal searches' })).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Top landing pages by views' })).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Top Portal Search Terms' })).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Resources' })).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Physical Objects' })).toBeInTheDocument();
    });

    it('renders empty-state messaging when no analytics exist', () => {
        render(
            <StatisticsPage
                {...props}
                overview={{
                    totalPageViews: 0,
                    totalDownloadClicks: 0,
                    totalPortalSearches: 0,
                    trackedLandingPages: 0,
                }}
                topLandingPagesByViews={[]}
                topLandingPagesByDownloads={[]}
                portalSearchTerms={[]}
                trends={{
                    days: ['2026-05-14'],
                    pageViews: [0],
                    downloadClicks: [0],
                    portalSearches: [0],
                }}
            />,
        );

        expect(screen.getByText(/No analytics have been recorded yet/i)).toBeInTheDocument();
        expect(screen.getByText(/No landing page views have been recorded yet/i)).toBeInTheDocument();
        expect(screen.getByText(/No portal searches have been tracked yet/i)).toBeInTheDocument();
    });
});