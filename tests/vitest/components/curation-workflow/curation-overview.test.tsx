import { router } from '@inertiajs/react';
import { act } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { render, screen } from '@tests/vitest/utils/render';
import axios from 'axios';
import type { AnchorHTMLAttributes, ReactNode } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';

import CurationOverview from '@/pages/curation';
import type { CurationRow } from '@/types/curation';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: (props: AnchorHTMLAttributes<HTMLAnchorElement>) => <a {...props} />,
    router: { get: vi.fn() },
}));
vi.mock('@/layouts/app-layout', () => ({ default: ({ children }: { children: ReactNode }) => <>{children}</> }));
vi.mock('axios', () => ({ default: { get: vi.fn() } }));

const row: CurationRow = {
    id: 9,
    title: 'Dataset with handover',
    doi: null,
    datacenter: 'GFZ',
    contact: 'Contact Person',
    created_at: '2026-10-21',
    status: 'draft',
    lead_id: 2,
    curators: [
        { id: 2, name: 'Lead Curator' },
        { id: 3, name: 'Team Member' },
    ],
    progress: 5,
    waiting_days: 16,
    needs_help: true,
    overdue: true,
    deadline: '2026-10-22',
    last_activity_at: '2026-10-21',
};
const props = {
    resources: { data: [row], current_page: 1, last_page: 2, total: 51 },
    filters: { tab: 'all', mine: 1, open: 1 },
    counts: { progress: 20, review: 10, embargo: 1, published: 20 },
    curators: row.curators,
    datacenters: [{ id: 1, name: 'GFZ' }],
};
beforeEach(() => {
    vi.clearAllMocks();
});

it('preserves combined filters in navigation and CSV, including dashboard all-stage links', async () => {
    render(<CurationOverview {...props} />);
    expect(screen.getByRole('checkbox', { name: 'My Resources' })).toBeChecked();
    expect(screen.getByRole('link', { name: 'Export CSV' })).toHaveAttribute('href', '/curation/export?tab=all&mine=1&open=1');
    await userEvent.click(screen.getByRole('checkbox', { name: 'Needs help' }));
    expect(router.get).toHaveBeenCalledWith(
        '/curation',
        { tab: 'all', mine: 1, open: 1, help: 1, page: 1 },
        expect.objectContaining({ preserveScroll: true, preserveState: true }),
    );
    expect(screen.getByRole('link', { name: row.title })).toHaveAttribute('href', '/editor?resourceId=9');
});

it('provides all four stages and clears dashboard-only flags on stage selection', async () => {
    render(<CurationOverview {...props} />);
    for (const label of ['In progress (20)', 'Review (10)', 'Embargo (1)', 'Published (20)'])
        expect(screen.getByRole('button', { name: label })).toBeVisible();
    await userEvent.click(screen.getByRole('button', { name: 'Embargo (1)' }));
    expect(router.get).toHaveBeenCalledWith(
        '/curation',
        { tab: 'embargo', mine: 1, page: 1 },
        expect.objectContaining({ preserveScroll: true, preserveState: true }),
    );
});

it('combines quick filter selections before a server response and ignores cancellation of older requests', async () => {
    render(<CurationOverview {...props} filters={{ tab: 'progress' }} />);
    await userEvent.click(screen.getByRole('checkbox', { name: 'My Resources' }));
    const firstVisit = vi.mocked(router.get).mock.calls[0][2];
    await userEvent.click(screen.getByRole('checkbox', { name: 'Waiting for reply' }));
    act(() => firstVisit?.onCancel?.());
    expect(router.get).toHaveBeenLastCalledWith('/curation', { tab: 'progress', mine: 1, waiting: 1, page: 1 }, expect.any(Object));
    expect(screen.getByRole('checkbox', { name: 'My Resources' })).toBeChecked();
    expect(screen.getByRole('checkbox', { name: 'Waiting for reply' })).toBeChecked();
    const exportUrl = new URL(screen.getByRole('link', { name: 'Export CSV' }).getAttribute('href')!, 'https://example.test');
    expect(exportUrl.searchParams.get('mine')).toBe('1');
    expect(exportUrl.searchParams.get('waiting')).toBe('1');
    await userEvent.selectOptions(screen.getByLabelText('Sort by'), 'deadline');
    await userEvent.click(screen.getByRole('button', { name: 'Review (10)' }));
    expect(router.get).toHaveBeenLastCalledWith('/curation', { tab: 'review', mine: 1, waiting: 1, sort: 'deadline', page: 1 }, expect.any(Object));
});

it('clears pending filters immediately and accepts server filters after navigation or browser history', async () => {
    const view = render(<CurationOverview {...props} />);
    await userEvent.click(screen.getByRole('checkbox', { name: 'Waiting for reply' }));
    await userEvent.click(screen.getByRole('button', { name: 'Clear filters' }));
    expect(screen.getByRole('checkbox', { name: 'My Resources' })).not.toBeChecked();
    await userEvent.click(screen.getByRole('checkbox', { name: 'Needs help' }));
    expect(router.get).toHaveBeenLastCalledWith('/curation', { help: 1, page: 1 }, expect.any(Object));
    const pendingVisit = vi.mocked(router.get).mock.calls.at(-1)?.[2];
    view.rerender(<CurationOverview {...props} filters={{ tab: 'published', curator: 3 }} />);
    act(() => pendingVisit?.onCancel?.());
    expect(screen.getByRole('checkbox', { name: 'Needs help' })).not.toBeChecked();
    expect(screen.getByLabelText('Curator')).toHaveValue('3');
    await userEvent.click(screen.getByRole('checkbox', { name: 'Overdue' }));
    expect(router.get).toHaveBeenLastCalledWith('/curation', { tab: 'published', curator: 3, overdue: 1, page: 1 }, expect.any(Object));
});

it('restores confirmed filters if the current navigation fails', async () => {
    render(<CurationOverview {...props} />);
    await userEvent.click(screen.getByRole('checkbox', { name: 'Needs help' }));
    const visit = vi.mocked(router.get).mock.calls[0][2];
    act(() => {
        visit?.onError?.({ help: 'Invalid filter' });
    });
    expect(screen.getByRole('checkbox', { name: 'Needs help' })).not.toBeChecked();
    await userEvent.click(screen.getByRole('checkbox', { name: 'Waiting for reply' }));
    expect(router.get).toHaveBeenLastCalledWith('/curation', { tab: 'all', mine: 1, open: 1, waiting: 1, page: 1 }, expect.any(Object));
});

it('loads expanded details on demand and safely displays internal paths and notes', async () => {
    vi.mocked(axios.get).mockResolvedValue({
        data: {
            items: [{ key: 'authors_approved', label: 'Authors approved', state: 'open' }],
            latest_note: '<b>Review needed</b>',
            locations: [{ id: 1, type: 'other', location: 'javascript:alert(1)' }],
        },
    });
    render(<CurationOverview {...props} />);
    expect(axios.get).not.toHaveBeenCalled();
    await userEvent.click(screen.getByRole('button', { name: 'Show details' }));
    expect(await screen.findByText('Authors approved')).toBeVisible();
    expect(screen.getByText('<b>Review needed</b>')).toBeVisible();
    expect(screen.getByText('javascript:alert(1)')).not.toHaveAttribute('href');
    await userEvent.click(screen.getByRole('button', { name: 'Hide details' }));
    expect(screen.queryByText('Authors approved')).not.toBeInTheDocument();
});
