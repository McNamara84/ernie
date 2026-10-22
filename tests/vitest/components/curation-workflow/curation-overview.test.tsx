import { router } from '@inertiajs/react';
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
        { preserveScroll: true, preserveState: true },
    );
    expect(screen.getByRole('link', { name: row.title })).toHaveAttribute('href', '/editor?resourceId=9');
});

it('provides all four stages and clears dashboard-only flags on stage selection', async () => {
    render(<CurationOverview {...props} />);
    for (const label of ['In progress (20)', 'Review (10)', 'Embargo (1)', 'Published (20)'])
        expect(screen.getByRole('button', { name: label })).toBeVisible();
    await userEvent.click(screen.getByRole('button', { name: 'Embargo (1)' }));
    expect(router.get).toHaveBeenCalledWith('/curation', { tab: 'embargo', mine: 1, page: 1 }, { preserveScroll: true, preserveState: true });
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
