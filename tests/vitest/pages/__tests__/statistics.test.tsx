import '@testing-library/jest-dom/vitest';

import { fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    router: { get: vi.fn() },
    Head: ({ title }: { title: string }) => <title>{title}</title>,
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div data-testid="app-layout">{children}</div>,
}));

import { router } from '@inertiajs/react';

import StatisticsPage from '@/pages/statistics';

describe('StatisticsPage', () => {
    const props = {
        filters: { mode: 'counter' as const, metric: 'unique' as const, access_method: 'regular' as const },
        legacyPeriod: { from: '2026-01-01', until: '2026-05-01' },
        usageStatus: {
            state: 'ready' as const,
            startedAt: '2026-05-01',
            lastAggregatedAt: '2026-05-27T08:30:00Z',
            dataThrough: '2026-05-26',
            metricsRevision: 1,
            paused: false,
            incompleteDays: [],
            methodologyUrl: '/usage-statistics',
        },
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
        },
        lastUpdated: '2026-05-27T08:30:00Z',
    };

    it('renders the new statistics dashboard sections', () => {
        render(<StatisticsPage {...props} />);

        expect(screen.getByText('Public engagement statistics')).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Investigations' })).toBeInTheDocument();
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

        expect(screen.getByText(/No aggregated usage is available/i)).toBeInTheDocument();
        expect(screen.getByText(/No landing page views have been recorded yet/i)).toBeInTheDocument();
        expect(screen.getByText(/No portal searches have been tracked yet/i)).toBeInTheDocument();
    });
    it('navigates metric, access and period filters while preserving the other selections', async () => {
        const user = userEvent.setup();
        const { rerender } = render(<StatisticsPage {...props} />);
        await user.click(screen.getByRole('combobox', { name: 'Landing page metric' }));
        await user.click(screen.getByRole('option', { name: 'Total' }));
        expect(router.get).toHaveBeenCalledWith('/statistics', { ...props.filters, metric: 'total' }, { preserveScroll: true });

        const totalFilters = { ...props.filters, metric: 'total' as const };
        rerender(<StatisticsPage {...props} filters={totalFilters} />);
        expect(screen.getByRole('combobox', { name: 'Landing page metric' })).toHaveTextContent('Total');
        await user.click(screen.getByRole('combobox', { name: 'Access method' }));
        await user.click(screen.getByRole('option', { name: 'Machine' }));
        expect(router.get).toHaveBeenCalledWith('/statistics', { ...totalFilters, access_method: 'machine' }, { preserveScroll: true });

        const machineFilters = { ...totalFilters, access_method: 'machine' as const };
        rerender(<StatisticsPage {...props} filters={machineFilters} />);
        expect(screen.getByRole('combobox', { name: 'Access method' })).toHaveTextContent('Machine');
        const card = screen.getByRole('heading', { name: 'Physical Objects' }).closest('[data-slot="card"]')!;
        expect(within(card as HTMLElement).queryByText(/download/i)).not.toBeInTheDocument();
        await user.click(screen.getByRole('combobox', { name: 'Period' }));
        await user.click(screen.getByRole('option', { name: 'Before COUNTER' }));
        expect(router.get).toHaveBeenCalledWith('/statistics', { ...machineFilters, mode: 'legacy' }, { preserveScroll: true });
    });

    it('labels historical counters, hides unsupported controls and allows returning to COUNTER', async () => {
        const user = userEvent.setup();
        render(<StatisticsPage {...props} filters={{ ...props.filters, mode: 'legacy' }} />);
        expect(screen.queryByLabelText('Landing page metric')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Access method')).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Export monthly CSV' })).not.toBeInTheDocument();
        expect(screen.getByText(/These are not COUNTER metrics/)).toBeInTheDocument();
        await user.click(screen.getByRole('combobox', { name: 'Period' }));
        await user.click(screen.getByRole('option', { name: 'COUNTER' }));
        expect(router.get).toHaveBeenCalledWith('/statistics', props.filters, { preserveScroll: true });
    });

    it.each([
        { from: '', to: '' },
        { from: '2026-05', to: '2026-09' },
    ])('submits the selected access method and optional months $from to $to', ({ from, to }) => {
        render(<StatisticsPage {...props} filters={{ ...props.filters, access_method: 'machine' }} />);
        fireEvent.change(screen.getByLabelText('From month'), { target: { value: from } });
        fireEvent.change(screen.getByLabelText('To month'), { target: { value: to } });
        const button = screen.getByRole('button', { name: 'Export monthly CSV' });
        const form = button.closest('form')!;
        expect(button).toHaveAttribute('type', 'submit');
        expect(form).toHaveAttribute('action', '/statistics/usage.csv');
        expect(form).toHaveAttribute('method', 'get');
        expect(Object.fromEntries(new FormData(form))).toEqual({ access_method: 'machine', from, to });
    });
});
